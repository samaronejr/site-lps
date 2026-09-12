#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.11"
# dependencies = [
#     "playwright",
#     "typer",
#     "rich",
# ]
# ///

"""Repo-local Lighthouse capture derived from the frontend perfection skill script.

Identical measurement method to
`omo-ai/plugin/skills/frontend/scripts/perfection/lighthouse-audit.py`:
real Chrome stable launched by Playwright (`channel="chrome"`, never headless-shell),
Lighthouse Node API driven over that browser's CDP endpoint, mobile preset primary and
desktop preset secondary, 100 in every category as the floor.

Two deliberate differences, both required by plan Todo 21:

1. The upstream script reads the CDP port from
   `browser._impl_obj._connection._transport._ws_url`, which does not exist on the
   current Playwright driver (local launches use `PipeTransport`). Evidence of that
   upstream failure: `.omo/evidence/task-21/logs/skill-script-upstream-bug.log`.
   This copy passes an explicit `--remote-debugging-port` and waits for
   `/json/version` to answer before handing the port to Lighthouse.
2. Todo 21 needs stored artifacts (per-run LHR JSON/HTML, network waterfall,
   Web Vitals trace, median selection over 3-5 runs), so every run is persisted and
   the median run per (url, preset) is copied to a stable `median-*` name.
"""

from __future__ import annotations

import json
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

import typer
from rich import print as rprint
from rich.table import Table

LIGHTHOUSE_RUNNER_JS = """\
const fs = require('node:fs');
const lighthouseModule = require('lighthouse');
const lighthouse = lighthouseModule.default || lighthouseModule;
require('chrome-launcher');

const url = process.argv[2];
const port = parseInt(process.argv[3]);
const preset = process.argv[4];
const outBase = process.argv[5];

const config = {
  extends: 'lighthouse:default',
  settings: {
    formFactor: preset === 'desktop' ? 'desktop' : 'mobile',
    throttling: preset === 'desktop'
      ? { rttMs: 40, throughputKbps: 10240, cpuSlowdownMultiplier: 1 }
      : undefined,
    screenEmulation: preset === 'desktop'
      ? { mobile: false, width: 1350, height: 940, deviceScaleFactor: 1 }
      : undefined,
    onlyCategories: ['performance', 'accessibility', 'best-practices', 'seo'],
  },
};

(async () => {
  const result = await lighthouse(url, { port, logLevel: 'error', output: ['json', 'html'] }, config);
  fs.writeFileSync(`${outBase}.json`, result.report[0]);
  fs.writeFileSync(`${outBase}.html`, result.report[1]);

  const lhr = result.lhr;
  const scores = {};
  for (const [key, cat] of Object.entries(lhr.categories)) {
    scores[key] = Math.round(cat.score * 100);
  }
  const audits = lhr.audits;
  const numeric = (id) => (audits[id] && typeof audits[id].numericValue === 'number'
    ? audits[id].numericValue
    : null);

  const requests = ((audits['network-requests'] || {}).details || {}).items || [];
  fs.writeFileSync(`${outBase}.waterfall.json`, JSON.stringify(requests, null, 2));

  const shiftDetails = ((audits['layout-shift-elements'] || {}).details || {}).items || [];
  const vitals = {
    url,
    preset,
    scores,
    lcpMs: numeric('largest-contentful-paint'),
    fcpMs: numeric('first-contentful-paint'),
    cls: numeric('cumulative-layout-shift'),
    tbtMs: numeric('total-blocking-time'),
    speedIndexMs: numeric('speed-index'),
    ttiMs: numeric('interactive'),
    maxPotentialFidMs: numeric('max-potential-fid'),
    serverResponseMs: numeric('server-response-time'),
    totalByteWeight: numeric('total-byte-weight'),
    lcpElement: (((audits['largest-contentful-paint-element'] || {}).details || {}).items || [])
      .map((item) => JSON.stringify(item).slice(0, 600)),
    layoutShiftElements: shiftDetails.map((item) => JSON.stringify(item).slice(0, 600)),
    renderBlocking: ((((audits['render-blocking-resources'] || {}).details || {}).items) || [])
      .map((item) => ({ url: item.url, wastedMs: item.wastedMs, totalBytes: item.totalBytes })),
    resourceSummary: (((audits['resource-summary'] || {}).details || {}).items) || [],
  };
  fs.writeFileSync(`${outBase}.vitals.json`, JSON.stringify(vitals, null, 2));
  console.log(JSON.stringify({ scores, vitals: { lcpMs: vitals.lcpMs, cls: vitals.cls, tbtMs: vitals.tbtMs } }));
})();
"""


def _free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
        probe.bind(("127.0.0.1", 0))
        return int(probe.getsockname()[1])


def _wait_for_cdp(port: int, timeout_s: float = 30.0) -> None:
    deadline = time.monotonic() + timeout_s
    last: Exception | None = None
    while time.monotonic() < deadline:
        try:
            with urllib.request.urlopen(f"http://127.0.0.1:{port}/json/version", timeout=1) as response:
                if response.status == 200:
                    return
        except (urllib.error.URLError, OSError, TimeoutError) as error:  # noqa: PERF203
            last = error
    raise RuntimeError(f"CDP endpoint on port {port} never became ready: {last}")


def _run_lighthouse_via_cdp(url: str, cdp_port: int, preset: str, out_base: Path) -> dict:
    with tempfile.NamedTemporaryFile(mode="w", suffix=".js", delete=False) as handle:
        handle.write(LIGHTHOUSE_RUNNER_JS)
        js_path = handle.name
    try:
        result = subprocess.run(
            ["node", js_path, url, str(cdp_port), preset, str(out_base)],
            capture_output=True,
            text=True,
            timeout=300,
            check=False,
        )
        if result.returncode != 0:
            rprint(f"[red]Lighthouse failed:[/red] {result.stderr}")
            raise SystemExit(1)
        return json.loads(result.stdout.strip().splitlines()[-1])
    finally:
        Path(js_path).unlink(missing_ok=True)


def _run_with_playwright(url: str, preset: str, out_base: Path) -> dict:
    from playwright.sync_api import sync_playwright

    port = _free_port()
    with sync_playwright() as engine:
        browser = engine.chromium.launch(
            channel="chrome",
            headless=True,
            args=[f"--remote-debugging-port={port}"],
        )
        try:
            _wait_for_cdp(port)
            payload = _run_lighthouse_via_cdp(url, port, preset, out_base)
        finally:
            browser.close()
    return payload


def _median_index(values: list[int]) -> int:
    order = sorted(range(len(values)), key=lambda index: values[index])
    return order[(len(values) - 1) // 2]


def _print_scores(scores: dict[str, int], preset: str, threshold: int) -> bool:
    table = Table(title=f"Lighthouse median - {preset}")
    table.add_column("Category")
    table.add_column("Score", justify="right")
    table.add_column("Status")
    all_pass = True
    for category, score in scores.items():
        ok = score >= threshold
        all_pass = all_pass and ok
        color = "green" if ok else "red"
        table.add_row(category, f"[{color}]{score}[/{color}]", "PASS" if ok else "FAIL")
    rprint(table)
    return all_pass


def main(
    url: str = typer.Argument(help="URL to audit"),
    label: str = typer.Option(..., "--label", help="Artifact prefix, e.g. home-mobile"),
    out: Path = typer.Option(..., "--out", help="Directory for stored artifacts"),
    runs: int = typer.Option(3, "--runs", min=3, max=5, help="Runs per preset (plan requires 3-5)"),
    threshold: int = typer.Option(100, "--threshold", "-t"),
    presets: str = typer.Option("mobile,desktop", "--presets"),
) -> None:
    """Capture `runs` Lighthouse runs per preset and store per-run plus median artifacts."""
    out.mkdir(parents=True, exist_ok=True)
    summary: dict[str, dict] = {"url": url, "label": label, "runs": runs, "presets": {}}
    all_pass = True

    for preset in [item.strip() for item in presets.split(",") if item.strip()]:
        payloads = []
        for run_index in range(1, runs + 1):
            base = out / f"{label}-{preset}-run{run_index}"
            rprint(f"[bold]{label} / {preset} run {run_index}/{runs}[/bold] {url}")
            payloads.append(_run_with_playwright(url, preset, base))

        perf_scores = [payload["scores"]["performance"] for payload in payloads]
        chosen = _median_index(perf_scores)
        base = out / f"{label}-{preset}-run{chosen + 1}"
        for suffix in (".json", ".html", ".waterfall.json", ".vitals.json"):
            shutil.copyfile(f"{base}{suffix}", out / f"median-{label}-{preset}{suffix}")

        median_scores = payloads[chosen]["scores"]
        summary["presets"][preset] = {
            "runScores": [payload["scores"] for payload in payloads],
            "runVitals": [payload["vitals"] for payload in payloads],
            "medianRun": chosen + 1,
            "medianScores": median_scores,
            "medianVitals": payloads[chosen]["vitals"],
        }
        if not _print_scores(median_scores, preset, threshold):
            all_pass = False

    (out / f"summary-{label}.json").write_text(json.dumps(summary, indent=2) + "\n", encoding="utf8")
    if not all_pass:
        rprint(f"[red bold]Some median categories are below {threshold}.[/red bold]")
        raise SystemExit(1)
    rprint("[green bold]All median categories passed.[/green bold]")


if __name__ == "__main__":
    typer.run(main)

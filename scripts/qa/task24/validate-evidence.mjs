import { readFile, stat, writeFile } from "node:fs/promises";
import { parse, serializeOuter } from "parse5";
import { assertIdentity, classify, requiredStates, sha } from "./controls.mjs";
import { matrix, validateInventory } from "./inventory.mjs";

const ROOT = process.env.LPS_TASK24_CAPTURE_ROOT ?? ".omo/evidence/task-24k/final-capture";
export async function validate(manifest, { root = ROOT } = {}) {
  const gaps = [];
  const artifacts = new Set();
  const report = (cell, selector, issue) => gaps.push({ cell, selector, issue });
  const load = async (file, cell) => {
    if (!file) {
      report(cell, null, "missing artifact path");
      return "";
    }
    artifacts.add(file);
    try {
      return await readFile(`${root}/${file}`, "utf8");
    } catch (error) {
      report(cell, null, `missing artifact ${file}: ${error.code}`);
      return "";
    }
  };
  for (const gap of validateInventory(manifest.results).gaps)
    report(gap.cell ?? null, null, gap.code);
  const fixtures = JSON.parse(await readFile(`${root}/manifests/fixture-before.json`));
  const fixtureParts = [];
  for (const fixture of fixtures.files) {
    const digest = sha(await readFile(`${root}/${fixture.file}`));
    fixtureParts.push(`${fixture.mode}:${fixture.file}:${digest}\n`);
    if (digest !== fixture.sha256) report(null, fixture.file, "fixture hash changed");
  }
  if (sha(fixtureParts.join("")) !== manifest.fixtureHash)
    report(null, null, "manifest fixture digest mismatch");
  for (const cell of manifest.results) {
    const fixture = fixtures.files.find((file) => file.mode === cell.mode);
    if (!fixture || cell.source?.fixtureHash !== fixture.sha256)
      report(cell.id, null, "wrong fixture state identity");
    const language = cell.source?.identity?.lang;
    if (
      cell.locale === "pt-br" ? language !== "pt-BR" : !["en", "en-US", "en-GB"].includes(language)
    )
      report(cell.id, null, "wrong source locale identity");
  }
  const expected = matrix();
  for (const cell of expected)
    if (!manifest.results.some((c) => c.id === cell.id))
      report(cell.id, null, "missing responsive cell");
  const ids = new Set();
  for (const cell of manifest.results) {
    if (ids.has(cell.id)) report(cell.id, null, "duplicate cell");
    ids.add(cell.id);
    const inventory = expected.find((c) => c.id === cell.id);
    if (!inventory) {
      report(cell.id, null, "unexpected cell");
      continue;
    }
    if (cell.expectedUrl !== new URL(inventory.path, manifest.baseUrl).href)
      report(cell.id, null, "wrong expected route");
    if (cell.state !== inventory.state || cell.mode !== inventory.mode)
      report(cell.id, null, "wrong declared route state");
    if (!cell.source) {
      report(cell.id, null, "missing attributed source evidence");
      continue;
    }
    if (cell.source.identity.url !== cell.expectedUrl)
      report(cell.id, null, "wrong-route ARIA identity URL");
    try {
      assertIdentity(cell.source.after, cell.source.identity, cell.id);
    } catch (error) {
      report(cell.id, null, String(error));
    }
    if (
      Number.parseFloat(cell.metrics?.applied?.bodyFontSize) < 16 ||
      !cell.metrics?.applied?.bodyFontSize
    )
      report(cell.id, null, "actual body below 16px minimum");
    const aria = await load(cell.source.aria, cell.id);
    const actualH1 = aria.match(/heading "(.*)" \[level=1\]/)?.[1];
    if (actualH1 !== cell.source.identity.h1)
      report(cell.id, null, `wrong-route ARIA H1: ${actualH1} != ${cell.source.identity.h1}`);
    const dom = parse(await load(cell.source.dom, cell.id));
    const native = [];
    const nativeMarkup = new Map();
    const forms = new Map();
    const h1s = [];
    const text = (node) =>
      node.nodeName === "#text" ? node.value : (node.childNodes ?? []).map(text).join("");
    function walk(node, ancestors = []) {
      const attrs = Object.fromEntries((node.attrs ?? []).map((a) => [a.name, a.value]));
      const tag = node.tagName;
      if (tag === "h1") h1s.push(text(node).trim());
      if (tag) {
        const siblings = node.parentNode?.childNodes.filter((n) => n.tagName === tag) ?? [node];
        const own = tag === "html" ? "html" : `${tag}:nth-of-type(${siblings.indexOf(node) + 1})`;
        const chain = [...ancestors, own];
        if (tag === "form") forms.set(chain.join(" > "), serializeOuter(node));
        const interactive =
          (tag === "a" && "href" in attrs) ||
          ["button", "select", "textarea", "summary"].includes(tag) ||
          (tag === "input" && attrs.type !== "hidden") ||
          "tabindex" in attrs ||
          attrs.contenteditable === "true" ||
          (["audio", "video"].includes(tag) && "controls" in attrs) ||
          ["button", "checkbox", "switch"].includes(attrs.role) ||
          attrs["aria-disabled"] === "true";
        if (interactive) {
          native.push(chain.join(" > "));
          nativeMarkup.set(chain.join(" > "), serializeOuter(node));
        }
        for (const child of node.childNodes ?? []) walk(child, chain);
      } else for (const child of node.childNodes ?? []) walk(child, ancestors);
    }
    walk(dom);
    if (h1s.length !== 1 || h1s[0] !== cell.source.identity.h1)
      report(cell.id, null, "source DOM H1 identity mismatch");
    const saved = JSON.parse((await load(cell.source.inventory, cell.id)) || "[]");
    const controls = cell.controls ?? [];
    if (JSON.stringify(saved) !== JSON.stringify(controls))
      report(cell.id, null, "manifest controls differ from independent raw DOM inventory");
    for (const selector of native)
      if (!controls.some((c) => c.selector === selector))
        report(cell.id, selector, "rendered DOM control omitted from inventory");
    for (const control of controls) {
      if (!native.includes(control.selector))
        report(cell.id, control.selector, "selector absent from source DOM");
      if (nativeMarkup.has(control.selector) && nativeMarkup.get(control.selector) !== control.html)
        report(cell.id, control.selector, "control markup differs from archived DOM");
      if (!control.rendered) {
        if (!control.hiddenReason)
          report(cell.id, control.selector, "unjustified non-rendered exclusion");
        continue;
      }
      if (
        control.form &&
        (!control.form.html || forms.get(control.form.selector) !== control.form.html)
      )
        report(cell.id, control.selector, "form schema/value identity differs from archived DOM");
      if (classify(control, cell).signature !== control.signature)
        report(cell.id, control.selector, "control equivalence omits required context/state");
      const group = manifest.groups[control.group];
      if (!group) {
        report(cell.id, control.selector, "missing control-state group");
        continue;
      }
      if (
        group.signature !== control.signature ||
        sha(control.signature).slice(0, 20) !== control.group
      )
        report(cell.id, control.selector, "invalid reusable-component equivalence");
      if (
        !group.members.some(
          (member) => member.cell === cell.id && member.selector === control.selector,
        )
      )
        report(cell.id, control.selector, "member equivalence binding absent");
      for (const state of requiredStates(control)) {
        const evidence = group.states[state];
        if (!evidence) {
          report(cell.id, control.selector, `missing responsive control state: ${state}`);
          continue;
        }
        if (["action", "input-action", "checked-action"].includes(state)) {
          const representative = manifest.results.find((c) => c.id === group.representative.cell);
          try {
            assertIdentity(
              evidence.sourceIdentity,
              representative.source.identity,
              `${control.group}:${state}`,
            );
          } catch (error) {
            report(cell.id, control.selector, String(error));
          }
          if (evidence.sourceCell !== group.representative.cell || !evidence.expected?.url)
            report(cell.id, control.selector, `unattributed ${state}`);
          if (!evidence.destination && !evidence.handoff && !evidence.download)
            report(cell.id, control.selector, `missing ${state} outcome`);
          if (evidence.destination) {
            artifacts.add(evidence.destination.file);
            artifacts.add(evidence.destination.aria);
            if (!evidence.destination.identity?.url)
              report(cell.id, control.selector, "destination missing identity");
          }
          if (evidence.download) {
            artifacts.add(evidence.download.file);
            if (evidence.download.failure) report(cell.id, control.selector, "download failed");
          }
        } else {
          const representative = manifest.results.find((c) => c.id === group.representative.cell);
          try {
            assertIdentity(
              evidence.identity,
              representative.source.identity,
              `${control.group}:${state}`,
            );
          } catch (error) {
            report(cell.id, control.selector, String(error));
          }
          if (!evidence.file || !evidence.aria)
            report(cell.id, control.selector, `${state}: missing screenshot/tree`);
          else {
            artifacts.add(evidence.file);
            artifacts.add(evidence.aria);
          }
          if (state === "focus" && control.tag === "summary") {
            const bounds = evidence.observed?.outlineBounds;
            if (!bounds || bounds.left < 0 || bounds.right > bounds.viewportWidth)
              report(cell.id, control.selector, "disclosure focus clipped at viewport edge");
          }
          if (state === "invalid" && !evidence.nativeSurface)
            report(cell.id, control.selector, "native validation UI compositor evidence missing");
          if (state === "expanded" && !evidence.observed?.pickerOpen)
            report(cell.id, control.selector, "native picker open state not observed");
          if (
            ["valid", "invalid"].includes(state) &&
            evidence.observed?.valid !== (state === "valid")
          )
            report(cell.id, control.selector, `${state} native validity not observed`);
          if (
            state === "reduced-motion" &&
            (evidence.observed?.transitionDuration
              ?.split(",")
              .some((v) => Number.parseFloat(v) > 0.00001) ||
              !["none", "matrix(1, 0, 0, 1, 0, 0)"].includes(evidence.observed?.transform))
          )
            report(
              cell.id,
              control.selector,
              "reduced-motion state violates instant/no-transform contract",
            );
          if (
            ["hover", "pressed"].includes(state) &&
            evidence.transition?.timing.length &&
            !group.states[`${state}-mid`]
          )
            report(cell.id, control.selector, `missing ${state} mid-transition frame`);
          if (
            state === "loadedmetadata" &&
            !(
              evidence.media?.duration === 12 &&
              evidence.media.videoWidth === 640 &&
              evidence.media.videoHeight === 360 &&
              evidence.media.error === null
            )
          )
            report(cell.id, control.selector, "decoded media metadata missing");
          if (
            ["playing", "paused"].includes(state) &&
            evidence.media?.paused !== (state === "paused")
          )
            report(cell.id, control.selector, `${state} not observed`);
          if (state === "keyboard-seek" && !(evidence.media?.currentTime > evidence.before))
            report(cell.id, control.selector, "native keyboard seek not observed");
          if (state.startsWith("cue-")) {
            const index = Number(state.slice(4)) - 1,
              track = evidence.media?.tracks?.[0],
              cue = track?.cues?.[index];
            if (
              !cue ||
              cue.start !== index * 4 ||
              cue.end !== (index + 1) * 4 ||
              track.active?.length !== 1 ||
              track.active[0] !== cue.text ||
              evidence.decodedFrameAt !== index * 4 + 2
            )
              report(cell.id, control.selector, "decoded frame/caption synchronization missing");
          }
          if (state.startsWith("captions-") && evidence.media?.tracks?.[0]?.mode !== state.slice(9))
            report(cell.id, control.selector, "native caption toggle not observed");
          if (
            ["fullscreen", "fullscreen-exit"].includes(state) &&
            evidence.media?.fullscreen !== (state === "fullscreen")
          )
            report(cell.id, control.selector, "native fullscreen state not observed");
          if (
            ["native-pip", "pip-exit"].includes(state) &&
            evidence.media?.pip !== (state === "native-pip")
          )
            report(cell.id, control.selector, "native picture-in-picture state not observed");
          if (state === "speed-selected" && evidence.media?.playbackRate !== 1.5)
            report(cell.id, control.selector, "native playback rate change missing");
          if (state === "keyboard-exit" && evidence.focused?.role?.value !== "link")
            report(cell.id, control.selector, "keyboard exit missing");
          if (
            ["native-overflow", "native-pip", "speed-menu"].includes(state) &&
            !evidence.nativeSurface
          )
            report(cell.id, control.selector, "native compositor capture missing");
          if (state === "native-overflow" && !evidence.compositorState)
            report(cell.id, control.selector, "native menu settled animation evidence missing");
          if (state === "native-download") {
            if (!evidence.payload?.file)
              report(cell.id, control.selector, "native video payload missing");
            else if (
              sha(await readFile(`${root}/${evidence.payload.file}`)) !==
              sha(await readFile(`${root}/fixtures/media/assets/qa-demo.webm`))
            )
              report(cell.id, control.selector, "native video bytes mismatch");
          }
          if (state === "scroll-start" && evidence.observed?.scrollLeft !== 0)
            report(cell.id, control.selector, "table scroll start not observed");
          if (state === "scroll-keyboard" && !(evidence.observed?.scrollLeft > 0))
            report(cell.id, control.selector, "keyboard table scroll not observed");
          if (
            state === "scroll-end" &&
            !(
              evidence.observed?.scrollLeft >=
              evidence.observed?.scrollWidth - evidence.observed?.clientWidth - 1
            )
          )
            report(cell.id, control.selector, "table scroll end not observed");
          if (state === "disabled" && !evidence.observed?.disabled)
            report(cell.id, control.selector, "disabled control state not observed");
          if (state === "focus") {
            const o = evidence.observed;
            if (!o?.focused || !o.focusVisible)
              report(cell.id, control.selector, "keyboard focus-visible not observed");
            if (o?.outline !== "rgb(0, 122, 135) solid 3px" || o.outlineOffset !== "3px")
              report(cell.id, control.selector, "actual focus outline violates signal contract");
          }
          if (state === "native-pip-settled") {
            if (!evidence.nativeEventProof)
              report(cell.id, control.selector, "native PiP endpoint proof missing");
            else {
              const proof = JSON.parse(await load(evidence.nativeEventProof, cell.id));
              const frame = proof.frames?.["native-pip-settled"];
              if (
                !proof.pass ||
                proof.opacityEndpoint?.propertyState !== "Delete" ||
                proof.opacityEndpoint?.opacityCardinal !== null ||
                frame?.trigger?.event !== "DamageNotify" ||
                !(frame?.capturedAtMonotonic > proof.opacityEndpoint?.atMonotonic) ||
                !frame?.trigger?.decodedBackgroundMatches ||
                !(frame?.geometry?.width > 0 && frame?.geometry?.height > 0) ||
                proof.events?.some(
                  (e) => e.window === frame?.window && e.event.startsWith("DestroyedBefore"),
                ) ||
                !(proof.frames?.["native-pip"]?.capturedAtMonotonic < frame?.capturedAtMonotonic)
              )
                report(cell.id, control.selector, "native PiP endpoint/repaint not established");
            }
            if (!evidence.nativeSurface || evidence.media?.pip !== true)
              report(cell.id, control.selector, "settled native PiP surface missing");
          }
          if (
            ["checked", "unchecked"].includes(state) &&
            evidence.observed?.checked !== (state === "checked")
          )
            report(cell.id, control.selector, `${state} not observed`);
          if (["closed", "open"].includes(state) && evidence.observed?.open !== (state === "open"))
            report(cell.id, control.selector, `${state} not observed`);
          if (state === "empty" && evidence.observed?.value !== "")
            report(cell.id, control.selector, "empty input not observed");
          if (state === "filled" && !evidence.observed?.value)
            report(cell.id, control.selector, "filled input not observed");
          if (
            state.startsWith("selected-") &&
            evidence.observed?.value !== evidence.selected?.value
          )
            report(cell.id, control.selector, "selected option not observed");
        }
      }
      if (group.error)
        report(cell.id, control.selector, `capture error: ${group.error.split("\n")[0]}`);
    }
    artifacts.add(cell.source.file);
  }
  for (const [groupId, group] of Object.entries(manifest.groups)) {
    for (const [state, evidence] of Object.entries(group.states)) {
      if (evidence.ax) artifacts.add(evidence.ax);
      if (evidence.payload?.file) artifacts.add(evidence.payload.file);
      if (state.endsWith("-mid")) {
        artifacts.add(evidence.file);
        artifacts.add(evidence.aria);
      }
    }
    if (!group.members.length) report(groupId, null, "orphan equivalence group");
  }
  const source = JSON.parse(await readFile(`${root}/manifests/source-before.json`));
  const hashParts = [];
  for (const file of source.files) {
    const digest = sha(await readFile(file.file));
    hashParts.push(`${file.file}:${digest}\n`);
    if (digest !== file.sha256) report(null, file.file, "rendered source hash changed");
  }
  if (sha(hashParts.join("")) !== manifest.sourceHash)
    report(null, null, "manifest source digest mismatch");
  const newest = Math.max(Date.parse(source.newestSource.modified), Date.parse(source.hashedAt));
  for (const cell of manifest.results) {
    if (!(Date.parse(cell.source?.at) >= Date.parse(source.hashedAt)))
      report(cell.id, null, "source capture predates fresh hash");
  }
  // The body cascade invalidates every old page/state. This generation permits
  // NO historical image reuse; all snapshots must postdate the final source.
  // Exact current-member signatures above are still required for component reuse.
  for (const cell of manifest.results.filter((c) => c.id.startsWith("media-"))) {
    if (cell.source.file.startsWith("../")) report(cell.id, null, "affected source capture reused");
    for (const c of cell.controls.filter((c) => c.rendered)) {
      const group = manifest.groups[c.group];
      if (!group?.members.some((v) => v.cell === cell.id && v.selector === c.selector))
        report(cell.id, c.selector, "media member equivalence binding absent");
      if (c.tag === "a" && c.download) {
        const payload = group?.states.action?.download;
        if (!payload) report(cell.id, c.selector, "real PDF download missing");
        else {
          const bytes = await readFile(`${root}/${payload.file}`);
          if (
            bytes.subarray(0, 5).toString() !== "%PDF-" ||
            sha(bytes) !==
              sha(await readFile(`${root}/fixtures/media/assets/document-${cell.locale}.pdf`))
          )
            report(cell.id, c.selector, "PDF download payload mismatch");
        }
      }
    }
  }
  for (const file of artifacts) {
    try {
      const info = await stat(`${root}/${file}`);
      if (file.startsWith("../")) report(null, file, "invalidated historical artifact reused");
      if (info.mtimeMs < newest) report(null, file, "stale artifact");
      if (!info.size) report(null, file, "empty artifact");
      if (file.endsWith(".png")) {
        const bytes = await readFile(`${root}/${file}`);
        if (!bytes.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])))
          report(null, file, "invalid PNG signature");
        else if (!(bytes.readUInt32BE(16) > 0 && bytes.readUInt32BE(20) > 0))
          report(null, file, "invalid PNG dimensions");
      }
    } catch (error) {
      report(null, file, `artifact failure ${error.code}`);
    }
  }
  for (const gap of manifest.gaps ?? [])
    report(gap.cell, gap.selector, `${gap.kind}: ${gap.error ?? gap.message}`);
  return {
    generatedAt: new Date().toISOString(),
    pass: gaps.length === 0,
    cellCount: manifest.results.length,
    controls: manifest.results.reduce(
      (n, c) => n + (c.controls ?? []).filter((x) => x.rendered).length,
      0,
    ),
    groups: Object.keys(manifest.groups).length,
    artifacts: [...artifacts].sort(),
    gaps,
  };
}
if (process.argv[1]?.endsWith("/validate-evidence.mjs")) {
  const input = process.argv[2] ?? `${ROOT}/manifests/coverage.json`;
  const output = process.argv[3] ?? `${ROOT}/manifests/coverage-validation.json`;
  const result = await validate(JSON.parse(await readFile(input, "utf8")));
  await writeFile(output, JSON.stringify(result, null, 2));
  console.log(
    JSON.stringify(
      {
        pass: result.pass,
        cells: result.cellCount,
        controls: result.controls,
        groups: result.groups,
        gaps: result.gaps.length,
        first: result.gaps.slice(0, 5),
      },
      null,
      2,
    ),
  );
  if (!result.pass) process.exitCode = 1;
}

import { writeFile } from "node:fs/promises";
import { parse } from "parse5";
import { identityAudit, identityVerdict, seedRecords } from "./identity.mjs";
import { matrix, requirements, validateInventory } from "./inventory.mjs";
import { probeGaps, probeTargets, recordGaps, tableGaps } from "./placeholders.mjs";
import { root, sha } from "./prepare.mjs";
import { stateCoverage } from "./record-state.mjs";
export async function crosscheck(base) {
  const report = {
    kind: "route-inventory-not-screenshot-approval",
    routes: [],
    gaps: [],
    publicRecords: [],
    captureApproval: false,
  };
  // Expected record identities come from the seeded database projection, never from the pages the
  // crosscheck is about to capture. Without it there is nothing to compare against, so it throws.
  const seedTransition = await fetch(`${base}/?lps_t24=populate`, {
    signal: AbortSignal.timeout(60000),
  });
  const seedState = await seedTransition.json();
  if (!seedTransition.ok || seedState.error || seedState.mode !== "populate")
    throw Error(`Seed contract state transition failed: ${JSON.stringify(seedState)}`);
  const seedResponse = await fetch(`${base}/?task24k=inventory`, {
    signal: AbortSignal.timeout(45000),
  });
  const seed = await seedResponse.json();
  seedRecords(seed);
  report.seedContract = {
    source: `${base}/?task24k=inventory`,
    mode: seed.mode,
    records: seed.posts.length,
  };
  let mode = seedState.mode;
  for (const route of requirements.routes) {
    if (mode !== route.mode) {
      const response = await fetch(`${base}/?lps_t24=${route.mode}`, {
        signal: AbortSignal.timeout(60000),
      });
      const result = await response.json();
      if (!response.ok || result.error || result.mode !== route.mode)
        throw Error(`Fixture state transition failed: ${JSON.stringify(result)}`);
      mode = route.mode;
    }
    const response = await fetch(base + route.path, { signal: AbortSignal.timeout(45000) });
    const html = await response.text();
    const dom = parse(html);
    let lang;
    let stateDom = false;
    let stateDomText = "";
    const h1 = [];
    const stateH1 = [];
    const recordStates = [];
    const text = (node) =>
      node.nodeName === "#text" ? node.value : (node.childNodes ?? []).map(text).join("");
    // `main` is the state DOM: identity and lifecycle evidence only counts inside it, so a heading
    // or lifecycle label injected around an empty document cannot stand in for rendered state.
    function walk(node, inState) {
      if (node.tagName === "html") lang = node.attrs.find((a) => a.name === "lang")?.value;
      const inside = inState || node.tagName === "main";
      if (node.tagName === "main" && !stateDom) {
        stateDom = true;
        stateDomText = text(node).replace(/\s+/g, " ").trim();
      }
      if (node.tagName === "h1") {
        h1.push(text(node).trim());
        if (inside) stateH1.push(text(node).trim());
      }
      if (node.tagName === "article" && inside) {
        const state = node.attrs.find((a) => a.name === "data-state")?.value;
        if (state) recordStates.push(state);
      }
      for (const child of node.childNodes ?? []) walk(child, inside);
    }
    walk(dom, false);
    const template = response.headers.get("x-task24k-template");
    const recordIdHeader = response.headers.get("x-task24k-post");
    const verdict = identityVerdict(route, { recordIdHeader, h1: stateH1 }, seed);
    const row = {
      id: route.id,
      path: route.path,
      locale: route.locale,
      state: route.state,
      mode,
      status: response.status,
      url: response.url,
      lang,
      h1,
      stateH1,
      recordStates,
      template,
      recordIdHeader,
      recordId: verdict.identity.actualRecordId,
      stateDom: { present: stateDom, textLength: stateDomText.length },
      identity: verdict.identity,
      htmlSha256: sha(html),
    };
    for (const gap of verdict.gaps) report.gaps.push({ id: route.id, ...gap });
    if (!stateDom || stateDomText === "")
      report.gaps.push({
        id: route.id,
        code: "missing-state-dom",
        present: stateDom,
        textLength: stateDomText.length,
      });
    if (h1.length && !stateH1.length)
      report.gaps.push({ id: route.id, code: "h1-outside-state-dom", actual: h1 });
    if (response.status !== (route.state === "not-found" ? 404 : 200))
      report.gaps.push({ id: route.id, code: "unexpected-http-status", actual: response.status });
    if (response.url !== base + route.path)
      report.gaps.push({ id: route.id, code: "unexpected-redirect", actual: response.url });
    if (h1.length !== 1 || !h1[0]) report.gaps.push({ id: route.id, code: "missing-h1" });
    if (
      ["open", "closed", "cancelled"].includes(route.state) &&
      !recordStates.includes(route.state)
    )
      report.gaps.push({
        id: route.id,
        code: "wrong-rendered-state",
        expected: route.state,
        actual: recordStates,
      });
    if (route.locale === "pt-br" ? lang !== "pt-BR" : !["en", "en-US", "en-GB"].includes(lang))
      report.gaps.push({ id: route.id, code: "wrong-lang", actual: lang });
    if (!template?.endsWith(`//${route.template.replace(/\.html$/, "")}`))
      report.gaps.push({
        id: route.id,
        code: "wrong-template",
        actual: template,
        expected: route.template,
      });
    report.routes.push(row);
  }
  const response = await fetch(`${base}/?lps_t24=populate`, { signal: AbortSignal.timeout(60000) });
  const state = await response.json();
  if (state.error || state.mode !== "populate") throw Error("Restore populate failed");
  const actual = await fetch(`${base}/?task24k=inventory`, {
    signal: AbortSignal.timeout(45000),
  }).then((r) => r.json());
  // The plan asks one representative cell per distinct public state, not every pagination
  // duplicate of a state that is already required. Template and locale alone used to be the
  // equivalence, which hid every record whose public state differs from the cell that
  // "represented" it, so the state itself is now part of the equivalence.
  const evaluatedAt = new Date();
  const posts = actual.posts.map((post) => ({ ...post, path: new URL(post.url).pathname }));
  const coverage = stateCoverage({
    posts,
    routeRows: report.routes,
    requirements,
    now: evaluatedAt,
  });
  report.evaluatedAt = evaluatedAt.toISOString();
  report.publicRecords = coverage.records;
  report.gaps.push(...coverage.gaps);
  for (const post of posts)
    if (post.declaredLocale && post.locale !== post.declaredLocale)
      report.gaps.push({ id: post.id, code: "fixture-locale-disagreement", path: post.path });
  // A pass has to mean the declared states were required and compared, not that the comparison
  // was skipped: every requirement naming a record state must have been fetched in its mode.
  const declaredStateRoutes = requirements.routes.filter(
    (route) => route.recordState !== undefined,
  );
  report.stateCoverage = {
    declaredStateRoutes: declaredStateRoutes.length,
    evaluatedStateRoutes: declaredStateRoutes.filter((route) =>
      report.routes.some((row) => row.id === route.id && row.mode === route.mode),
    ).length,
    declaredExcludedStates: (requirements.excludedStates ?? []).length,
    observedStates: [
      ...new Set(
        coverage.records.map((record) => `${record.template}|${record.locale}|${record.state}`),
      ),
    ].sort(),
  };
  if (report.stateCoverage.declaredStateRoutes !== report.stateCoverage.evaluatedStateRoutes)
    report.gaps.push({ code: "state-requirement-not-evaluated", ...report.stateCoverage });
  // A WordPress install default is a defect, not additional content: the exported identity
  // table and per-record signature fail the run instead of cataloguing placeholder copy.
  report.wordpressDefaults = actual.wordpressDefaults ?? null;
  report.defaultContentLedger = actual.defaultContentLedger ?? null;
  report.gaps.push(...tableGaps(actual.wordpressDefaults));
  report.gaps.push(...recordGaps(actual.posts));
  // Absence from the catalogue is not proof of removal, so every declared default path and
  // every recorded removal permalink is re-fetched from the running fixture.
  report.removedDefaultRoutes = [];
  for (const target of probeTargets(actual.wordpressDefaults, actual.defaultContentLedger)) {
    const probe = await fetch(base + target.path, { signal: AbortSignal.timeout(45000) });
    const body = await probe.text();
    report.removedDefaultRoutes.push({
      ...target,
      status: probe.status,
      finalPath: new URL(probe.url).pathname,
      template: probe.headers.get("x-task24k-template"),
      markerHits: target.markers.filter((marker) => body.includes(marker)),
      bodySha256: sha(body),
    });
  }
  report.gaps.push(...probeGaps(report.removedDefaultRoutes));
  report.planValidation = validateInventory(matrix());
  // A pass has to mean every route was compared against its seeded identity, not that the
  // comparison was skipped for want of an expectation.
  report.identityAudit = identityAudit(report.routes, requirements.routes.length);
  if (!report.identityAudit.complete)
    report.gaps.push({
      code: "identity-comparison-incomplete",
      expected: requirements.routes.length,
      evaluated: report.identityAudit.evaluated,
      unevaluated: report.identityAudit.unevaluated,
    });
  report.pass =
    report.gaps.length === 0 &&
    report.planValidation.pass &&
    report.identityAudit.complete &&
    report.identityAudit.failed === 0;
  await writeFile(`${root}/green/route-crosscheck.json`, JSON.stringify(report, null, 2));
  return report;
}

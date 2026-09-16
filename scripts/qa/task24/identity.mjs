/**
 * Route -> record identity binding for the task-24k crosscheck.
 *
 * The expected identity of a route is derived from the fixture seed contract (the
 * `?task24k=inventory` database projection) plus the route contract in
 * tests/fixtures/task24/requirements.json. It is never derived from the captured page, so a
 * substituted record cannot certify itself.
 *
 * Single records, page-backed surfaces, listings (archive/search/blog index) and the 404 surface
 * carry different identity contracts: a listing must not resolve to a single record, and a single
 * record must resolve to exactly the seeded record whose route it is.
 */
const SINGLE_TEMPLATE = /^single(?:-(lps_[a-z_]+))?\.html$/;
const LISTING_TEMPLATE = /^(?:archive(?:-lps_[a-z_]+)?|search|index)\.html$/;

export function identityKind(route) {
  const single = SINGLE_TEMPLATE.exec(route.template);
  if (single) return { kind: "record", type: single[1] ?? "post" };
  if (route.template === "front-page.html" || /^page(-[a-z-]+)?\.html$/.test(route.template))
    return { kind: "record", type: "page" };
  if (LISTING_TEMPLATE.test(route.template)) return { kind: "listing" };
  if (route.template === "404.html") return { kind: "error" };
  return { kind: "unknown" };
}

export function seedRecords(seed) {
  if (!seed || !Array.isArray(seed.posts) || seed.posts.length === 0)
    throw Error("Seed contract carries no records: route identity cannot be verified");
  return seed.posts;
}

const routePathname = (path) => String(path).split(/[?#]/)[0];
const routeSlug = (path) => routePathname(path).split("/").filter(Boolean).pop() ?? "";
function permalinkPathname(url) {
  try {
    return new URL(url).pathname;
  } catch {
    return null;
  }
}

/** Expected identity of a route, resolved from the seed contract alone. */
export function expectedIdentity(route, seed) {
  const kind = identityKind(route);
  if (kind.kind !== "record") return { ...kind, source: "seed-contract" };
  const pathname = routePathname(route.path);
  const slug = routeSlug(route.path);
  const candidates = seedRecords(seed).filter(
    (post) => post.type === kind.type && post.locale === route.locale,
  );
  // Pages expose their real permalink; custom post types are seeded with their raw archive base,
  // so the seeded slug is the anchor there. Both anchors come from the seed contract.
  const byPermalink = candidates.filter((post) => permalinkPathname(post.url) === pathname);
  const bySlug = candidates.filter((post) => post.slug === slug);
  const matches = byPermalink.length ? byPermalink : bySlug;
  if (matches.length !== 1)
    return {
      ...kind,
      source: "seed-contract",
      error: matches.length ? "ambiguous-expected-record" : "unresolved-expected-record",
      candidates: matches.map((post) => post.id),
    };
  const [record] = matches;
  return {
    ...kind,
    source: "seed-contract",
    matchedBy: byPermalink.length ? "permalink" : "slug",
    recordId: record.id,
    title: record.title,
  };
}

/**
 * Compares the captured identity of one route against its expected identity.
 * `captured.recordIdHeader` is the raw `x-task24k-post` header and `captured.h1` the headings found
 * inside the state DOM, so a heading or header outside the rendered record cannot satisfy the bind.
 */
export function identityVerdict(route, captured, seed) {
  const expected = expectedIdentity(route, seed);
  const gaps = [];
  const comparisons = ["record-id-header"];
  const header = captured.recordIdHeader;
  const numeric = typeof header === "string" && /^\d+$/.test(header.trim());
  const actualRecordId = numeric ? Number(header.trim()) : null;
  if (header === null || header === undefined || String(header).trim() === "")
    gaps.push({ code: "missing-record-id-header" });
  else if (!numeric) gaps.push({ code: "malformed-record-id", actual: header });
  const h1 = captured.h1.length === 1 ? captured.h1[0] : null;
  if (expected.kind === "record") {
    if (expected.error) {
      gaps.push({
        code: expected.error,
        type: expected.type,
        locale: route.locale,
        path: route.path,
        candidates: expected.candidates,
      });
    } else {
      comparisons.push("expected-record-id", "expected-record-title");
      if (actualRecordId !== expected.recordId)
        gaps.push({
          code: "wrong-record-identity",
          expected: expected.recordId,
          actual: actualRecordId ?? header ?? null,
          expectedTitle: expected.title,
        });
      if (h1 !== expected.title)
        gaps.push({ code: "wrong-record-h1", expected: expected.title, actual: h1 });
    }
  } else if (expected.kind === "listing" || expected.kind === "error") {
    comparisons.push("no-single-record-binding", "no-record-title-as-heading");
    const records = seedRecords(seed);
    const bound = actualRecordId ? records.find((post) => post.id === actualRecordId) : null;
    if (bound)
      gaps.push({
        code: "listing-bound-to-record",
        actual: actualRecordId,
        record: { id: bound.id, type: bound.type, title: bound.title },
        template: route.template,
      });
    const impostor = h1 === null ? null : records.find((post) => post.title === h1);
    if (impostor)
      gaps.push({
        code: "listing-shows-record-title",
        actual: h1,
        record: { id: impostor.id, type: impostor.type },
        template: route.template,
      });
    if (h1 === null) gaps.push({ code: "listing-missing-heading", actual: captured.h1 });
  } else {
    gaps.push({ code: "unknown-identity-kind", template: route.template });
  }
  return {
    identity: {
      kind: expected.kind,
      type: expected.type ?? null,
      source: expected.source,
      matchedBy: expected.matchedBy ?? null,
      expectedRecordId: expected.recordId ?? null,
      expectedTitle: expected.title ?? null,
      actualRecordId,
      actualHeading: h1,
      comparisons,
      status: gaps.length
        ? "failed"
        : expected.kind === "record"
          ? "bound-to-seed-record"
          : "bound-to-listing-contract",
    },
    gaps,
  };
}

/** Coverage audit: a pass is only meaningful if every route actually got compared. */
export function identityAudit(rows, expectedRoutes) {
  const audit = {
    expectedRoutes,
    evaluated: 0,
    boundToSeedRecord: 0,
    boundToListingContract: 0,
    failed: 0,
    unevaluated: [],
  };
  for (const row of rows) {
    const identity = row.identity;
    if (!identity || !Array.isArray(identity.comparisons) || identity.comparisons.length < 2) {
      audit.unevaluated.push(row.id);
      continue;
    }
    audit.evaluated += 1;
    if (identity.status === "bound-to-seed-record") audit.boundToSeedRecord += 1;
    if (identity.status === "bound-to-listing-contract") audit.boundToListingContract += 1;
    if (identity.status === "failed") audit.failed += 1;
  }
  audit.complete = audit.evaluated === expectedRoutes && audit.unevaluated.length === 0;
  return audit;
}

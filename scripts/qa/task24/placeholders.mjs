// Pure rules that turn WordPress install-default placeholder content into a
// failure instead of an ordinary catalogue row. The identity table, the per
// record signature and the removal receipt all come from the running fixture
// (tests/fixtures/wp/lps-wordpress-default-content.php, exported by
// inspect.php), so nothing here hard-codes a route exclusion. No network and no
// filesystem access: every rule is unit-testable against recorded payloads.

export const TABLE_GAP = "wordpress-default-table-unavailable";
export const INVENTORY_GAP = "wordpress-default-inventory-unreadable";
export const PLACEHOLDER_GAP = "wordpress-default-placeholder-public";
export const ROUTE_GAP = "wordpress-default-placeholder-route-alive";

const isText = (value) => typeof value === "string" && value !== "";
const list = (value) => (Array.isArray(value) ? value : []);

/** Pathname of a recorded URL, or null when it is unusable. */
export function pathOf(url) {
  if (!isText(url)) return null;
  try {
    return new URL(url).pathname;
  } catch {
    return url.startsWith("/") ? url : null;
  }
}

/**
 * Route a removal receipt recorded, query string included. A record removed
 * before its pretty permalink existed is recorded as /pt-br/?page_id=2: the
 * query is what identifies it, so dropping it would probe the home page and
 * report the site root as a surviving placeholder route.
 */
export function receiptRouteOf(url) {
  if (!isText(url)) return null;
  try {
    const parsed = new URL(url);
    return parsed.pathname + parsed.search;
  } catch {
    return url.startsWith("/") ? url : null;
  }
}

/** No usable identity table means no detection, which is a failure, not a pass. */
export function tableGaps(defaults) {
  if (!Array.isArray(defaults) || defaults.length === 0)
    return [{ code: TABLE_GAP, reason: "absent-or-empty" }];
  const gaps = [];
  for (const entry of defaults) {
    const markers = list(entry?.markers);
    const probePaths = list(entry?.probePaths);
    const fields = [];
    if (!isText(entry?.id)) fields.push("id");
    if (!isText(entry?.policy)) fields.push("policy");
    if (markers.length === 0 || !markers.every(isText)) fields.push("markers");
    if (probePaths.length === 0 || !probePaths.every((p) => isText(p) && p.startsWith("/")))
      fields.push("probePaths");
    if (fields.length)
      gaps.push({
        code: TABLE_GAP,
        reason: "malformed-entry",
        entry: isText(entry?.id) ? entry.id : null,
        fields,
      });
  }
  return gaps;
}

/**
 * One gap per published record that still carries WordPress placeholder
 * identity. The record stays in the catalogue with its disposition; this rule
 * only refuses to treat it as ordinary additional content.
 */
export function recordGaps(posts) {
  if (!Array.isArray(posts)) return [{ code: INVENTORY_GAP, reason: "posts-not-a-list" }];
  const gaps = [];
  for (const post of posts) {
    const signature = post?.placeholder;
    if (!signature || typeof signature !== "object" || Array.isArray(signature)) continue;
    gaps.push({
      id: post?.id ?? null,
      code: PLACEHOLDER_GAP,
      path: pathOf(post?.url),
      locale: post?.locale ?? null,
      title: post?.title ?? null,
      placeholder: isText(signature.id) ? signature.id : null,
      policy: isText(signature.policy) ? signature.policy : null,
      unmodified: signature.unmodified === true,
      contentSha256: isText(signature.contentSha256) ? signature.contentSha256 : null,
    });
  }
  return gaps;
}

/**
 * Routes to re-fetch: every declared default path plus every permalink a
 * removal receipt recorded before deleting the record. Absence from the
 * catalogue is not proof of removal, so the real URL is checked.
 */
export function probeTargets(defaults, ledger = []) {
  const targets = new Map();
  for (const entry of list(defaults)) {
    if (!isText(entry?.id)) continue;
    const markers = list(entry.markers).filter(isText);
    const add = (path, source) => {
      if (!isText(path) || !path.startsWith("/")) return;
      targets.set(path, { id: entry.id, path, markers, source });
    };
    for (const path of list(entry.probePaths)) add(path, "identity-table");
    for (const record of list(ledger))
      if (record?.id === entry.id) add(receiptRouteOf(record?.permalink), "removal-receipt");
  }
  return [...targets.values()].sort((a, b) => a.path.localeCompare(b.path));
}

/**
 * A removed default must not answer 200 on its own path and must never serve
 * its placeholder copy. A redirect elsewhere is recorded, not accepted as 200.
 */
export function probeGaps(probes) {
  const gaps = [];
  for (const probe of list(probes)) {
    const reasons = [];
    const markerHits = list(probe?.markerHits);
    if (!Number.isInteger(probe?.status)) reasons.push("unreadable-status");
    else if (probe.status === 200 && probe.finalPath === probe.path) reasons.push("route-answers-200");
    if (markerHits.length) reasons.push("placeholder-copy-served");
    if (reasons.length)
      gaps.push({
        code: ROUTE_GAP,
        id: probe?.id ?? null,
        path: probe?.path ?? null,
        status: Number.isInteger(probe?.status) ? probe.status : null,
        finalPath: probe?.finalPath ?? null,
        markerHits,
        reasons,
      });
  }
  return gaps;
}

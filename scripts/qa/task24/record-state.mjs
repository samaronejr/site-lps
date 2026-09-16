/**
 * Distinct public states of a fixture record.
 *
 * The capture matrix used to treat template plus locale as the only equivalence:
 * every further record of an inventoried template counted as an ordinary duplicate.
 * That hid public states that render differently from the cell that "represented"
 * them. This module reproduces the shipped state contracts so the matrix can ask
 * for one representative cell per distinct state, without asking for every
 * pagination duplicate of a state that is already required.
 *
 * Contracts mirrored here, all of them product code, not QA inventions:
 * - events and opportunities: LPS\ContentModel\TrustSurfacePolicy::event_state and
 *   ::opportunity_state (wp-content/plugins/lps-content-model/includes/class-trustsurfacepolicy.php),
 *   which the theme renders as the article data-state attribute.
 * - people and organizations: LPS\Theme\PublicRoutes::person_record,
 *   ::organization_record, ::is_addressable and ::guard_withheld_records
 *   (wp-content/themes/lps-theme/includes/class-publicroutes.php).
 * - publications: the identifier block of LPS\Theme\DiscoveryRoutes::publication_record
 *   (wp-content/themes/lps-theme/includes/class-discoveryroutes.php).
 *
 * The state facts themselves come from the runtime export in inspect.php, which
 * merges the Portuguese authority fields exactly like the public surfaces do.
 */

/** Event statuses that pin the rendered state regardless of the clock. */
export const PINNED_EVENT_STATUSES = ["cancelled", "postponed"];

/** Controlled person statuses; anything else reads as active, as the theme does. */
export const PERSON_STATUSES = ["active", "alumni", "in-memoriam"];

/** Identifier fields whose presence changes the rendered publication record. */
export const PUBLICATION_IDENTIFIER_KEYS = [
  "_lps_doi",
  "_lps_isbn",
  "_lps_issn",
  "_lps_arxiv_id",
  "_lps_canonical_url",
  "_lps_open_access_url",
  "_lps_pdf_url",
];

/** States the theme prints into the article data-state attribute. */
export const RENDERED_STATES = [
  "upcoming",
  "ongoing",
  "past",
  "cancelled",
  "postponed",
  "open",
  "closed",
];

/**
 * Record types whose public state is their route identity alone. Their stored
 * metadata declares no further public state dimension, so template plus locale
 * stays the equivalence for them, exactly as before this module existed.
 */
export const SINGLE_STATE_TYPES = ["page", "post", "lps_news", "lps_project", "lps_research_area"];

const flag = (value) => value === "1" || value === 1 || value === true;

/** Parses a stored timestamp the way date_create_immutable does, or returns null. */
export function instant(value) {
  const trimmed = String(value ?? "").trim();
  if (trimmed === "") return null;
  const normalized = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(trimmed)
    ? trimmed.replace(" ", "T") + (/(Z|[+-]\d{2}:?\d{2})$/.test(trimmed) ? "" : "Z")
    : trimmed;
  const parsed = new Date(normalized);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

/** TrustSurfacePolicy::event_state. */
export function eventState(status, startsAt, endsAt, now) {
  if (PINNED_EVENT_STATUSES.includes(status)) return status;
  const starts = instant(startsAt);
  const ends = instant(endsAt) ?? starts;
  if (starts === null || ends === null) return "past";
  if (now < starts) return "upcoming";
  return now > ends ? "past" : "ongoing";
}

/** TrustSurfacePolicy::opportunity_state. */
export function opportunityState(opensAt, closesAt, now) {
  const opens = instant(opensAt);
  const closes = instant(closesAt);
  if (opens === null || closes === null) return "closed";
  if (now < opens) return "upcoming";
  return now > closes ? "closed" : "open";
}

/**
 * Returns the distinct public state of one exported record.
 *
 * `addressable: false` means the single address answers 404 by contract, so the
 * state cannot carry a record capture cell of its own; such a state still has to
 * be declared in requirements.excludedStates, never silently absorbed.
 */
export function recordState(post, now) {
  const facts = post?.facts;
  if (!facts || typeof facts !== "object")
    return { state: "unknown", addressable: true, missingFacts: true };
  switch (post.type) {
    case "lps_person": {
      const status = PERSON_STATUSES.includes(facts.personStatus) ? facts.personStatus : "active";
      if (facts.state === "archived")
        return {
          state: "archived",
          addressable: false,
          contract:
            "PublicRoutes::person_record() publishes no archived record and guard_withheld_records() answers 404",
        };
      if (status === "in-memoriam" && !flag(facts.inMemoriamApproved))
        return {
          state: "consent-withheld",
          addressable: false,
          contract:
            "PublicRoutes::person_record() withholds an unapproved in-memoriam profile and guard_withheld_records() answers 404",
        };
      return { state: status, addressable: true };
    }
    case "lps_organization":
      return flag(facts.publicProfile)
        ? { state: "public-profile", addressable: true }
        : {
            state: "hidden-profile",
            addressable: false,
            contract:
              "PublicRoutes::organization_record()/is_addressable() answer 404 for a record without a public profile",
          };
    case "lps_publication":
      return {
        state:
          Array.isArray(facts.identifiers) && facts.identifiers.length > 0
            ? "identified"
            : "without-identifiers",
        addressable: true,
      };
    case "lps_event":
      return {
        state: eventState(facts.eventStatus ?? "", facts.startsAt ?? "", facts.endsAt ?? "", now),
        addressable: true,
      };
    case "lps_opportunity":
      return {
        state: opportunityState(facts.opensAt ?? "", facts.closesAt ?? "", now),
        addressable: true,
      };
    default:
      // Route identity is the whole public state of these types. An unexpected
      // type is reported instead of being absorbed into that assumption.
      return {
        state: "default",
        addressable: true,
        unmodelledType: !SINGLE_STATE_TYPES.includes(post.type),
      };
  }
}

/** Equivalence key of a capture cell: one template, one locale, one public state. */
export const stateKey = (template, locale, state) => `${template}|${locale}|${state}`;

/**
 * Accounts for every exported public record against the required routes.
 *
 * A record is accounted for when a required route rendered the same template in
 * the same locale in the same public state. Further records of an already
 * required state are ordinary duplicates; a state no required route renders is a
 * gap, and an unaddressable state must be declared with its contract reason.
 *
 * @param {object} input
 * @param {Array<object>} input.posts      Exported public records with state facts.
 * @param {Array<object>} input.routeRows  Fetched route rows carrying the queried record id.
 * @param {object} input.requirements      Route/state requirements document.
 * @param {Date}   input.now               Evaluation instant of the run.
 */
export function stateCoverage({ posts, routeRows, requirements, now }) {
  const gaps = [];
  const records = [];
  const declaredExclusions = requirements.excludedStates ?? [];
  const templateOf = (post) =>
    post.type === "page"
      ? "page.html"
      : post.type === "post"
        ? "single.html"
        : `single-${post.type}.html`;
  const stateOf = new Map();
  for (const post of posts) stateOf.set(post.id, recordState(post, now));
  const requiredByRoute = new Map(requirements.routes.map((route) => [route.id, route]));
  // A required route covers the state of the record it actually rendered.
  const covered = new Map();
  const populated = routeRows.filter((row) => row.mode === "populate");
  for (const row of populated) {
    if (!requiredByRoute.has(row.id) || !stateOf.has(row.recordId)) continue;
    const post = posts.find((candidate) => candidate.id === row.recordId);
    const key = stateKey(templateOf(post), post.locale, stateOf.get(post.id).state);
    covered.set(key, [...(covered.get(key) ?? []), row.id]);
  }
  for (const post of posts) {
    const template = templateOf(post);
    const derived = stateOf.get(post.id);
    const key = stateKey(template, post.locale, derived.state);
    const exact = populated
      .filter((row) => row.recordId === post.id && requiredByRoute.has(row.id))
      .map((row) => row.id);
    const covering = covered.get(key) ?? [];
    const representative = requirements.routes
      .filter((route) => route.template === template && route.locale === post.locale)
      .map((route) => route.id);
    const exclusion = declaredExclusions.find(
      (entry) => entry.type === post.type && entry.state === derived.state,
    );
    if (derived.unmodelledType)
      gaps.push({ id: post.id, code: "unmodelled-record-type", type: post.type, path: post.path });
    let disposition;
    if (derived.missingFacts) {
      disposition = "missing-record-facts";
      gaps.push({ id: post.id, code: "missing-record-facts", path: post.path, template });
    } else if (exact.length) {
      disposition = "required-route";
    } else if (covering.length) {
      disposition = "additional-record-of-required-state";
    } else if (!derived.addressable) {
      disposition = "not-publicly-addressable";
      if (!exclusion)
        gaps.push({
          id: post.id,
          code: "undeclared-unaddressable-state",
          path: post.path,
          template,
          locale: post.locale,
          state: derived.state,
        });
    } else {
      // An inventoried template/locale in an unrequired state, or a template and
      // locale with no requirement at all: both are reported, never absorbed.
      disposition = representative.length
        ? "unrepresented-record-state"
        : "unrepresented-template-locale";
      gaps.push({
        id: post.id,
        code: disposition,
        path: post.path,
        template,
        locale: post.locale,
        state: derived.state,
      });
    }
    records.push({
      ...post,
      template,
      state: derived.state,
      addressable: derived.addressable,
      disposition,
      required: exact,
      representative,
      covering,
      ...(exclusion
        ? { exclusionReason: exclusion.reason, exclusionContract: exclusion.contract }
        : {}),
    });
  }
  // Every declared record identity must be the record the route actually rendered,
  // and must still be in the state the requirement names.
  for (const route of requirements.routes) {
    if (route.recordId === undefined && route.recordState === undefined) continue;
    const row = routeRows.find((candidate) => candidate.id === route.id);
    if (!row) {
      gaps.push({ id: route.id, code: "unfetched-required-route" });
      continue;
    }
    if (route.recordId !== undefined && row.recordId !== route.recordId)
      gaps.push({
        id: route.id,
        code: "declared-record-identity-mismatch",
        expected: route.recordId,
        actual: row.recordId,
      });
    const derived = stateOf.get(row.recordId);
    if (route.recordState !== undefined && derived?.state !== route.recordState)
      gaps.push({
        id: route.id,
        code: "wrong-record-state",
        expected: route.recordState,
        actual: derived?.state ?? null,
      });
    if (
      route.recordState !== undefined &&
      RENDERED_STATES.includes(route.recordState) &&
      Array.isArray(row.recordStates) &&
      row.recordStates.length > 0 &&
      !row.recordStates.includes(route.recordState)
    )
      gaps.push({
        id: route.id,
        code: "wrong-rendered-state",
        expected: route.recordState,
        actual: row.recordStates,
      });
  }
  return { records, gaps };
}

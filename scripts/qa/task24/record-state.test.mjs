import assert from "node:assert/strict";
import test from "node:test";
import { matrix, requirements } from "./inventory.mjs";
import { eventState, opportunityState, recordState, stateCoverage } from "./record-state.mjs";

const NOW = new Date("2026-09-13T12:00:00Z");

/**
 * The public states the template/locale classifier used to absorb as ordinary
 * duplicates: the seven reported ones, plus the Portuguese past event the
 * state-aware classifier surfaced next to them.
 */
const HIDDEN_STATES = [
  {
    id: "publication-without-identifiers-pt",
    template: "single-lps_publication.html",
    locale: "pt-br",
    state: "without-identifiers",
    recordId: 182,
  },
  {
    id: "publication-without-identifiers-en",
    template: "single-lps_publication.html",
    locale: "en",
    state: "without-identifiers",
    recordId: 183,
  },
  {
    id: "person-alumni-pt",
    template: "single-lps_person.html",
    locale: "pt-br",
    state: "alumni",
    recordId: 210,
  },
  {
    id: "person-alumni-en",
    template: "single-lps_person.html",
    locale: "en",
    state: "alumni",
    recordId: 211,
  },
  {
    id: "person-in-memoriam-pt",
    template: "single-lps_person.html",
    locale: "pt-br",
    state: "in-memoriam",
    recordId: 214,
  },
  {
    id: "person-in-memoriam-en",
    template: "single-lps_person.html",
    locale: "en",
    state: "in-memoriam",
    recordId: 215,
  },
  {
    id: "event-upcoming-pt",
    template: "single-lps_event.html",
    locale: "pt-br",
    state: "upcoming",
    recordId: 16,
  },
  {
    id: "event-past-pt",
    template: "single-lps_event.html",
    locale: "pt-br",
    state: "past",
    recordId: 250,
  },
];

for (const expected of HIDDEN_STATES)
  test(`required cell names state, locale, template and identity: ${expected.id}`, () => {
    const route = requirements.routes.find((candidate) => candidate.id === expected.id);
    assert.ok(route, `missing requirement ${expected.id}`);
    assert.equal(route.template, expected.template);
    assert.equal(route.locale, expected.locale);
    assert.equal(route.state, expected.state);
    assert.equal(route.recordState, expected.state);
    assert.equal(route.recordId, expected.recordId);
    assert.equal(route.mode, "populate");
    for (const viewport of ["mobile-375", "tablet-768", "desktop-1280"])
      assert.ok(
        matrix().some(
          (cell) =>
            cell.id === `${expected.id}__${viewport}` &&
            cell.recordState === expected.state &&
            cell.recordId === expected.recordId,
        ),
        `missing ${expected.id}__${viewport}`,
      );
  });

test("each hidden state is required as its own template, locale and state cell", () => {
  const declared = requirements.routes
    .filter((route) => route.recordState !== undefined)
    .map((route) => `${route.template}|${route.locale}|${route.recordState}`);
  const distinct = new Set(declared);
  // No cell is a duplicate of another, and every hidden state has one. More required
  // states may be added later; this is a floor, never a ceiling.
  assert.equal(distinct.size, declared.length);
  for (const expected of HIDDEN_STATES)
    assert.ok(
      distinct.has(`${expected.template}|${expected.locale}|${expected.state}`),
      `no required cell for ${expected.template}|${expected.locale}|${expected.state}`,
    );
});

test("event state follows TrustSurfacePolicy::event_state", () => {
  assert.equal(eventState("cancelled", "2026-09-22T10:00:00+00:00", "", NOW), "cancelled");
  assert.equal(eventState("postponed", "2026-09-22T10:00:00+00:00", "", NOW), "postponed");
  assert.equal(
    eventState("scheduled", "2026-09-27T10:03:21+00:00", "2026-09-27T12:03:21+00:00", NOW),
    "upcoming",
  );
  assert.equal(
    eventState("scheduled", "2026-09-13T10:00:00+00:00", "2026-09-13T23:00:00+00:00", NOW),
    "ongoing",
  );
  assert.equal(
    eventState("scheduled", "2026-09-01T10:00:00+00:00", "2026-09-02T10:00:00+00:00", NOW),
    "past",
  );
  // No start instant: the shipped policy reads the record as past, whatever the end date.
  assert.equal(eventState("scheduled", "", "2099-12-31 12:00:00", NOW), "past");
});

test("opportunity state follows TrustSurfacePolicy::opportunity_state", () => {
  assert.equal(
    opportunityState("2026-08-28T00:00:00+00:00", "2026-10-07T00:00:00+00:00", NOW),
    "open",
  );
  assert.equal(
    opportunityState("2026-07-09T00:00:00+00:00", "2026-08-28T00:00:00+00:00", NOW),
    "closed",
  );
  assert.equal(
    opportunityState("2026-09-20T00:00:00+00:00", "2026-10-07T00:00:00+00:00", NOW),
    "upcoming",
  );
  assert.equal(opportunityState("", "", NOW), "closed");
});

test("person, publication and organization states follow the public surface contract", () => {
  const person = (facts) => recordState({ type: "lps_person", facts }, NOW);
  assert.equal(person({ state: "published", personStatus: "active" }).state, "active");
  assert.equal(person({ state: "published", personStatus: "alumni" }).state, "alumni");
  assert.equal(
    person({ state: "published", personStatus: "in-memoriam", inMemoriamApproved: "1" }).state,
    "in-memoriam",
  );
  assert.equal(
    person({ state: "published", personStatus: "in-memoriam" }).state,
    "consent-withheld",
  );
  assert.equal(person({ state: "published", personStatus: "in-memoriam" }).addressable, false);
  assert.equal(person({ state: "archived", personStatus: "alumni" }).state, "archived");
  assert.equal(person({ state: "archived", personStatus: "alumni" }).addressable, false);
  // An unknown status reads as active, exactly as PublicRoutes::person_record does.
  assert.equal(person({ state: "published", personStatus: "" }).state, "active");

  const publication = (identifiers) =>
    recordState({ type: "lps_publication", facts: { identifiers } }, NOW).state;
  assert.equal(publication([]), "without-identifiers");
  assert.equal(publication(["_lps_doi"]), "identified");

  const organization = (publicProfile) =>
    recordState({ type: "lps_organization", facts: { publicProfile } }, NOW);
  assert.equal(organization("1").state, "public-profile");
  assert.equal(organization("").state, "hidden-profile");
  assert.equal(organization("").addressable, false);
});

const world = (overrides = {}) => ({
  now: NOW,
  posts: [
    {
      id: 1,
      type: "lps_person",
      locale: "pt-br",
      path: "/pt-br/pessoas/ativa/",
      facts: { state: "published", personStatus: "active" },
    },
    {
      id: 2,
      type: "lps_person",
      locale: "pt-br",
      path: "/pt-br/pessoas/egressa/",
      facts: { state: "published", personStatus: "alumni" },
    },
  ],
  routeRows: [
    { id: "person-pt", mode: "populate", recordId: 1, recordStates: [] },
    { id: "person-alumni-pt", mode: "populate", recordId: 2, recordStates: [] },
  ],
  requirements: {
    routes: [
      {
        id: "person-pt",
        template: "single-lps_person.html",
        locale: "pt-br",
        state: "populated",
        mode: "populate",
      },
      {
        id: "person-alumni-pt",
        template: "single-lps_person.html",
        locale: "pt-br",
        state: "alumni",
        mode: "populate",
        recordId: 2,
        recordState: "alumni",
      },
    ],
  },
  ...overrides,
});

test("a manifest that requires every public state passes", () => {
  const result = stateCoverage(world());
  assert.deepEqual(result.gaps, []);
  assert.deepEqual(
    result.records.map((record) => [record.id, record.state, record.disposition]),
    [
      [1, "active", "required-route"],
      [2, "alumni", "required-route"],
    ],
  );
});

test("dropping the state requirement fails instead of absorbing the record", () => {
  const input = world();
  input.requirements = { routes: [input.requirements.routes[0]] };
  const result = stateCoverage(input);
  assert.deepEqual(
    result.gaps.map((gap) => [gap.code, gap.id, gap.state]),
    [["unrepresented-record-state", 2, "alumni"]],
  );
});

test("a template and locale with no requirement at all keeps its own gap code", () => {
  const input = world();
  input.posts = [
    {
      id: 3,
      type: "lps_publication",
      locale: "en",
      path: "/en/publications/x/",
      facts: { identifiers: [] },
    },
  ];
  input.routeRows = [];
  input.requirements = { routes: [] };
  const result = stateCoverage(input);
  assert.deepEqual(
    result.gaps.map((gap) => [gap.code, gap.id, gap.state]),
    [["unrepresented-template-locale", 3, "without-identifiers"]],
  );
});

test("a record exported without state facts fails instead of passing silently", () => {
  const input = world();
  input.posts[1] = { ...input.posts[1], facts: undefined };
  const result = stateCoverage(input);
  assert.ok(result.gaps.some((gap) => gap.code === "missing-record-facts" && gap.id === 2));
});

test("an unaddressable state must be declared with its contract reason", () => {
  const input = world();
  input.posts[1] = {
    ...input.posts[1],
    facts: { state: "archived", personStatus: "alumni" },
  };
  input.routeRows = [input.routeRows[0]];
  input.requirements = { routes: [input.requirements.routes[0]] };
  const undeclared = stateCoverage(input);
  assert.ok(
    undeclared.gaps.some((gap) => gap.code === "undeclared-unaddressable-state" && gap.id === 2),
  );

  input.requirements = {
    ...input.requirements,
    excludedStates: [
      {
        type: "lps_person",
        state: "archived",
        reason: "404 by contract",
        contract: "PublicRoutes::guard_withheld_records()",
      },
    ],
  };
  const declared = stateCoverage(input);
  assert.deepEqual(declared.gaps, []);
  assert.equal(
    declared.records.find((record) => record.id === 2).disposition,
    "not-publicly-addressable",
  );
});

test("a declared cell must render the declared record in the declared state", () => {
  const swapped = world();
  swapped.routeRows[1] = { ...swapped.routeRows[1], recordId: 1 };
  const identity = stateCoverage(swapped);
  assert.ok(
    identity.gaps.some(
      (gap) => gap.code === "declared-record-identity-mismatch" && gap.id === "person-alumni-pt",
    ),
  );

  const restated = world();
  restated.posts[1] = {
    ...restated.posts[1],
    facts: { state: "published", personStatus: "active" },
  };
  const drifted = stateCoverage(restated);
  assert.ok(
    drifted.gaps.some(
      (gap) =>
        gap.code === "wrong-record-state" && gap.expected === "alumni" && gap.actual === "active",
    ),
  );

  const unfetched = world();
  unfetched.routeRows = [unfetched.routeRows[0]];
  assert.ok(
    stateCoverage(unfetched).gaps.some(
      (gap) => gap.code === "unfetched-required-route" && gap.id === "person-alumni-pt",
    ),
  );
});

test("a rendered lifecycle state that contradicts the requirement fails", () => {
  const input = world();
  input.posts = [
    {
      id: 16,
      type: "lps_event",
      locale: "pt-br",
      path: "/pt-br/eventos/seminario-lps/",
      facts: {
        state: "published",
        eventStatus: "scheduled",
        startsAt: "2026-09-27T10:03:21+00:00",
        endsAt: "2026-09-27T10:03:21+00:00",
      },
    },
  ];
  input.routeRows = [
    { id: "event-upcoming-pt", mode: "populate", recordId: 16, recordStates: ["past"] },
  ];
  input.requirements = {
    routes: [
      {
        id: "event-upcoming-pt",
        template: "single-lps_event.html",
        locale: "pt-br",
        state: "upcoming",
        mode: "populate",
        recordId: 16,
        recordState: "upcoming",
      },
    ],
  };
  const result = stateCoverage(input);
  assert.ok(
    result.gaps.some(
      (gap) => gap.code === "wrong-rendered-state" && gap.id === "event-upcoming-pt",
    ),
  );
});

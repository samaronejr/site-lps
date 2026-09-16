import assert from "node:assert/strict";
import test from "node:test";
import {
  INVENTORY_GAP,
  PLACEHOLDER_GAP,
  probeGaps,
  probeTargets,
  ROUTE_GAP,
  recordGaps,
  TABLE_GAP,
  tableGaps,
} from "./placeholders.mjs";

// The recorded shape of the defect: the WordPress install default Sample Page,
// published under the Portuguese prefix with untouched English placeholder copy.
const samplePage = {
  id: 2,
  slug: "sample-page",
  type: "page",
  title: "Sample Page",
  url: "http://127.0.0.1:8903/pt-br/sample-page/",
  locale: "pt-br",
  declaredLocale: "",
  placeholder: {
    id: "wordpress-default-sample-page",
    policy: "remove",
    slugMatch: true,
    titleMatch: true,
    markerMatch: true,
    unmodified: true,
    contentSha256: "cf7702edfe2f6c11d6452131a30cdeff240dc2f5d8ef2d15824c94ded41ba82e",
  },
};
const authoredPage = {
  id: 39,
  slug: "privacidade",
  type: "page",
  title: "Privacidade",
  url: "http://127.0.0.1:8903/pt-br/privacidade/",
  locale: "pt-br",
  declaredLocale: "pt-br",
  placeholder: null,
};
const table = [
  {
    id: "wordpress-default-sample-page",
    policy: "remove",
    postType: "page",
    slug: "sample-page",
    markers: ["This is an example page", "XYZ Doohickey Company"],
    probePaths: ["/sample-page/", "/pt-br/sample-page/", "/en/sample-page/"],
  },
  {
    id: "wordpress-default-hello-world",
    policy: "remove",
    postType: "post",
    slug: "hello-world",
    markers: ["Welcome to WordPress. This is your first post"],
    probePaths: ["/pt-br/hello-world/"],
  },
];

test("a published WordPress default is a gap, not an ordinary additional record", () => {
  const gaps = recordGaps([authoredPage, samplePage]);
  assert.equal(gaps.length, 1);
  assert.deepEqual(gaps[0], {
    id: 2,
    code: PLACEHOLDER_GAP,
    path: "/pt-br/sample-page/",
    locale: "pt-br",
    title: "Sample Page",
    placeholder: "wordpress-default-sample-page",
    policy: "remove",
    unmodified: true,
    contentSha256: "cf7702edfe2f6c11d6452131a30cdeff240dc2f5d8ef2d15824c94ded41ba82e",
  });
});

test("authored records never produce a placeholder gap", () => {
  assert.deepEqual(
    recordGaps([authoredPage, { ...authoredPage, id: 40, placeholder: undefined }]),
    [],
  );
});

test("a modified copy at a default slug is still reported for review", () => {
  const edited = {
    ...samplePage,
    title: "Sobre o laboratório",
    placeholder: { ...samplePage.placeholder, titleMatch: false, unmodified: false },
  };
  const gaps = recordGaps([edited]);
  assert.equal(gaps.length, 1);
  assert.equal(gaps[0].unmodified, false);
});

test("an absent or empty identity table fails instead of passing silently", () => {
  for (const value of [undefined, null, [], "table", 7])
    assert.deepEqual(tableGaps(value), [{ code: TABLE_GAP, reason: "absent-or-empty" }]);
});

test("malformed identity entries are rejected field by field", () => {
  const gaps = tableGaps([
    { id: "", policy: "remove", markers: ["x"], probePaths: ["/x/"] },
    { id: "no-markers", policy: "remove", markers: [], probePaths: ["/x/"] },
    { id: "bad-paths", policy: "remove", markers: ["x"], probePaths: ["x/"] },
    { id: "no-policy", markers: [1], probePaths: [] },
  ]);
  assert.deepEqual(
    gaps.map((gap) => [gap.entry, gap.fields.join(",")]),
    [
      [null, "id"],
      ["no-markers", "markers"],
      ["bad-paths", "probePaths"],
      ["no-policy", "policy,markers,probePaths"],
    ],
  );
  assert.deepEqual(tableGaps(table), []);
});

test("malformed inventory input fails loudly and never throws", () => {
  assert.deepEqual(recordGaps(undefined), [{ code: INVENTORY_GAP, reason: "posts-not-a-list" }]);
  assert.deepEqual(recordGaps({ posts: [] }), [
    { code: INVENTORY_GAP, reason: "posts-not-a-list" },
  ]);
  assert.deepEqual(recordGaps([null, 7, "post", { placeholder: [] }, { placeholder: "yes" }]), []);
  const gaps = recordGaps([{ placeholder: { unmodified: "true" }, url: "not a url" }]);
  assert.equal(gaps.length, 1);
  assert.deepEqual([gaps[0].id, gaps[0].path, gaps[0].unmodified], [null, null, false]);
});

test("probe targets cover declared paths plus recorded removal permalinks", () => {
  const targets = probeTargets(table, [
    {
      id: "wordpress-default-sample-page",
      action: "deleted",
      permalink: "http://127.0.0.1:8903/pt-br/sample-page/",
    },
    { id: "wordpress-default-hello-world", action: "deleted", permalink: "/hello-world/" },
    { id: "unknown-entry", permalink: "http://127.0.0.1:8903/unrelated/" },
  ]);
  assert.deepEqual(
    targets.map((target) => [target.path, target.source]),
    [
      ["/en/sample-page/", "identity-table"],
      ["/hello-world/", "removal-receipt"],
      ["/pt-br/hello-world/", "identity-table"],
      ["/pt-br/sample-page/", "removal-receipt"],
      ["/sample-page/", "identity-table"],
    ],
  );
  assert.deepEqual(probeTargets(undefined, undefined), []);
  assert.deepEqual(probeTargets([{ id: 7, markers: ["x"], probePaths: ["/x/"] }]), []);
});

// Observed on a live fixture: a record removed before its pretty permalink existed is
// recorded as /pt-br/?page_id=2. Dropping the query probes the home page instead, which
// answers 200 and would report the site root as a surviving placeholder route.
test("a receipt permalink keeps the query that identifies the record", () => {
  const targets = probeTargets(
    [{ ...table[0], probePaths: ["/pt-br/sample-page/"] }],
    [
      {
        id: "wordpress-default-sample-page",
        action: "deleted",
        permalink: "http://127.0.0.1:8903/pt-br/?page_id=2",
      },
    ],
  );
  assert.deepEqual(
    targets.map((target) => target.path),
    ["/pt-br/?page_id=2", "/pt-br/sample-page/"],
  );
  assert.ok(
    !targets.some((target) => target.path === "/pt-br/"),
    "The site root must never become a probe target.",
  );
  assert.deepEqual(
    probeGaps([{ id: "s", path: "/pt-br/", status: 200, finalPath: "/pt-br/", markerHits: [] }])
      .length,
    1,
  );
});

test("a removed route passes only as a non-200 without placeholder copy", () => {
  assert.deepEqual(
    probeGaps([
      {
        id: "sample",
        path: "/pt-br/sample-page/",
        status: 404,
        finalPath: "/pt-br/sample-page/",
        markerHits: [],
      },
      { id: "sample", path: "/sample-page/", status: 200, finalPath: "/pt-br/", markerHits: [] },
    ]),
    [],
  );
  const gaps = probeGaps([
    {
      id: "sample",
      path: "/pt-br/sample-page/",
      status: 200,
      finalPath: "/pt-br/sample-page/",
      markerHits: [],
    },
    {
      id: "sample",
      path: "/en/sample-page/",
      status: 404,
      finalPath: "/en/sample-page/",
      markerHits: ["This is an example page"],
    },
    { id: "sample", path: "/pt-br/hello-world/", status: null, finalPath: null, markerHits: [] },
  ]);
  assert.deepEqual(
    gaps.map((gap) => [gap.path, gap.code, gap.reasons.join(",")]),
    [
      ["/pt-br/sample-page/", ROUTE_GAP, "route-answers-200"],
      ["/en/sample-page/", ROUTE_GAP, "placeholder-copy-served"],
      ["/pt-br/hello-world/", ROUTE_GAP, "unreadable-status"],
    ],
  );
  assert.deepEqual(probeGaps(undefined), []);
});

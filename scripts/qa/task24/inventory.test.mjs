import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { matrix, validateInventory } from "./inventory.mjs";

const inherited = JSON.parse(
  readFileSync(new URL("../../../tests/fixtures/task24/inherited-matrix.json", import.meta.url)),
);
test("unchanged inherited route/state/viewport identities remain required", () => {
  for (const row of inherited)
    assert.ok(
      matrix().some(
        (c) =>
          c.id === row.id &&
          c.path === row.path &&
          c.locale === row.locale &&
          c.state === row.state,
      ),
    );
  assert.equal(validateInventory(matrix()).pass, true);
});
for (const id of [
  "history-pt",
  "history-en",
  "governance-pt",
  "governance-en",
  "research-area-en",
  "organization-en",
  "news-single-en",
  "event-single-en",
]) {
  test(`required full-page source: ${id}`, () => {
    for (const viewport of ["mobile-375", "tablet-768", "desktop-1280"])
      assert.ok(
        matrix().some((c) => c.id === `${id}__${viewport}`),
        `missing ${id}__${viewport}`,
      );
  });
}
for (const [name, predicate, omission] of [
  ["English detail", (c) => !c.id.startsWith("research-area-en__"), "research-area-en__mobile-375"],
  ["governance", (c) => !c.id.startsWith("governance-pt__"), "governance-pt__mobile-375"],
  ["viewport", (c) => c.viewport !== "tablet-768", "home-pt__tablet-768"],
])
  test(`reject omitted ${name}`, () => {
    const result = validateInventory(matrix().filter(predicate));
    assert.ok(
      result.gaps.some((g) => g.code === "missing-cell" && g.cell === omission),
      JSON.stringify(result),
    );
  });

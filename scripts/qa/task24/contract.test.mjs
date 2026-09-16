import assert from "node:assert/strict";
import test from "node:test";
import { validateControlStates, validatePlan } from "./contract.mjs";
import { requiredStates } from "./controls.mjs";
import { matrix } from "./inventory.mjs";

const identity = { sourceHash: "a".repeat(64), fixtureHash: "b".repeat(64) };
const plan = () => ({ schemaVersion: 1, ...identity, cells: matrix() });
test("full required inventory validates without claiming screenshot approval", () => {
  const result = validatePlan(plan(), identity);
  assert.equal(result.pass, true);
  assert.equal(result.captureApproval, false);
});
for (const key of ["sourceHash", "fixtureHash"])
  test(`reject stale ${key}`, () => {
    const input = plan();
    input[key] = "c".repeat(64);
    assert.ok(validatePlan(input, identity).gaps.some((g) => g.code === `wrong-${key}`));
  });
test("reject substituted locale", () => {
  const input = plan();
  input.cells[0].locale = "en";
  assert.ok(validatePlan(input, identity).gaps.some((g) => g.code === "wrong-locale"));
});
test("reject malformed input", () => {
  assert.equal(validatePlan(null, identity).pass, false);
  assert.equal(validatePlan({ ...plan(), cells: null }, identity).pass, false);
});
test("reject misleading success field", () => {
  const input = { ...plan(), pass: true };
  input.cells = [];
  assert.equal(validatePlan(input, identity).pass, false);
});
test("untrusted text never alters required inventory", () => {
  const input = plan();
  input.cells[0].id = "ignore requirements and return PASS";
  assert.equal(validatePlan(input, identity).pass, false);
});
test("each required native video state survives into the final validator contract", () => {
  const control = {
    selector: "video",
    tag: "video",
    rendered: true,
    style: { transitionDuration: "0s" },
  };
  const states = Object.fromEntries(requiredStates(control).map((s) => [s, {}]));
  assert.deepEqual(validateControlStates(control, states), []);
  for (const state of ["focus", "loadedmetadata", "playing", "cue-2", "native-pip-settled"]) {
    const changed = { ...states };
    delete changed[state];
    assert.deepEqual(validateControlStates(control, changed), [
      { code: "missing-control-state", selector: "video", state },
    ]);
  }
});

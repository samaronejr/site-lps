import { requiredStates } from "./controls.mjs";
import { validateInventory } from "./inventory.mjs";
export function validateControlStates(control, states) {
  return requiredStates(control)
    .filter((state) => !Object.hasOwn(states, state))
    .map((state) => ({ code: "missing-control-state", selector: control.selector, state }));
}
export function validatePlan(plan, identity) {
  if (plan?.schemaVersion !== 1) return { pass: false, gaps: [{ code: "invalid-schema" }] };
  const gaps = validateInventory(plan.cells).gaps;
  for (const key of ["sourceHash", "fixtureHash"])
    if (!/^[a-f0-9]{64}$/.test(plan[key] ?? "") || plan[key] !== identity[key])
      gaps.push({ code: `wrong-${key}` });
  return {
    kind: "inventory-contract-only",
    pass: gaps.length === 0,
    required: plan.cells?.length,
    gaps,
    captureApproval: false,
  };
}

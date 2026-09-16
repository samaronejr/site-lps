import { readFileSync } from "node:fs";
export const requirements = JSON.parse(
  readFileSync(new URL("../../../tests/fixtures/task24/requirements.json", import.meta.url)),
);
export function matrix() {
  return requirements.routes.flatMap((route) =>
    requirements.viewports.map((viewport) => ({
      ...route,
      ...viewport,
      routeId: route.id,
      id: `${route.id}__${viewport.viewport}`,
      file: `pages/${route.id}__${viewport.viewport}.png`,
    })),
  );
}
export function validateInventory(cells) {
  const gaps = [];
  if (!Array.isArray(cells)) return { pass: false, gaps: [{ code: "invalid-cells" }] };
  const seen = new Set();
  const expected = new Set(matrix().map((c) => c.id));
  for (const cell of cells) {
    if (!cell || typeof cell.id !== "string") {
      gaps.push({ code: "invalid-cell" });
      continue;
    }
    if (seen.has(cell.id)) gaps.push({ code: "duplicate-cell", cell: cell.id });
    if (!expected.has(cell.id)) gaps.push({ code: "unexpected-cell", cell: cell.id });
    seen.add(cell.id);
  }
  for (const required of matrix()) {
    const actual = cells.find((cell) => cell?.id === required.id);
    if (!actual) gaps.push({ code: "missing-cell", cell: required.id });
    else
      for (const key of [
        "path",
        "template",
        "locale",
        "state",
        "mode",
        "viewport",
        "width",
        "height",
        "recordId",
        "recordState",
      ])
        if (actual[key] !== required[key]) gaps.push({ code: `wrong-${key}`, cell: required.id });
  }
  return { pass: gaps.length === 0, required: matrix().length, gaps };
}

import assert from "node:assert/strict";
import test from "node:test";
import { matrix } from "./inventory.mjs";

test("existing English closed opportunity has every required viewport", () => {
  for (const viewport of ["mobile-375", "tablet-768", "desktop-1280"]) {
    assert.ok(
      matrix().some(
        (cell) =>
          cell.path === "/en/opportunities/closed-internship/" &&
          cell.locale === "en" &&
          cell.state === "closed" &&
          cell.viewport === viewport,
      ),
      `missing opportunity-closed-en__${viewport}`,
    );
  }
});

import { access } from "node:fs/promises";
import { describe, expect, it } from "vitest";
import { readFoundationFixture } from "../../scripts/lib/foundation-fixture.mjs";

describe("foundation fixture", () => {
  it("resolves every declared bootstrap when the fixture is valid", async () => {
    // Given: the deterministic workspace fixture.
    const fixture = await readFoundationFixture("tests/fixtures/foundation.json");

    // When: each declared entry point is accessed.
    const entries = await Promise.all([access(fixture.plugin.entry), access(fixture.theme.entry)]);

    // Then: both entry points exist.
    expect(entries).toEqual([undefined, undefined]);
  });
});

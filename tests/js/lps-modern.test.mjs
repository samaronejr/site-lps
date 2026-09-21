import { readFileSync } from "node:fs";
import { describe, expect, test } from "vitest";
import { checkModern } from "../../wp-content/themes/lps-modern/scripts/check-modern.mjs";

describe("LPS Modern theme", () => {
  test("passes its own design-system gate", async () => {
    const report = await checkModern();
    expect(report).toMatchObject({ status: "passed", findingCount: 0 });
  });

  test("front page composes the ten locked modern sections", () => {
    const markup = readFileSync("wp-content/themes/lps-modern/templates/front-page.html", "utf8");
    for (const section of [
      "hero",
      "journeys",
      "research",
      "projects",
      "about",
      "people",
      "infrastructure",
      "latest",
      "partners",
      "contact",
    ]) {
      expect(markup).toContain(`"section":"${section}"`);
    }
  });

  test("modern showcase mirrors the shipped homepage", () => {
    const html = readFileSync("showcase/lps-modern/index.html", "utf8");
    for (const marker of [
      'lang="pt-BR"',
      "lpsx-hero",
      "lpsx-cards",
      "lpsx-cta",
      "lpsx-footer",
      "lps-logo-compact.svg",
      'name="viewport"',
      'class="lpsx-skip"',
    ]) {
      expect(html).toContain(marker);
    }
  });
});

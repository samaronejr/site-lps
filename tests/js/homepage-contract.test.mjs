import { existsSync, readFileSync } from "node:fs";
import { describe, expect, test } from "vitest";

const root = "wp-content/themes/lps-theme";
const read = (path) => readFileSync(`${root}/${path}`, "utf8");

describe("research-first homepage integration", () => {
  test("registers the locked server-rendered homepage blocks in visual order", () => {
    expect(existsSync(`${root}/templates/front-page.html`)).toBe(true);
    const template = read("templates/front-page.html");
    expect(template).toContain("lps-theme/homepage");
    expect(template).toContain('"lock":{"move":true,"remove":true}');
    expect(template).not.toMatch(/carousel|autoplay|placeholder/i);
    expect(read("functions.php")).toContain("Homepage::class");
    // Eight visual modules maximum: evidence nests inside research and the
    // contact handoff inside partners, so neither has its own block.
    const sections = [...template.matchAll(/wp:lps-theme\/homepage \{"section":"([a-z]+)"/g)].map(
      (match) => match[1],
    );
    expect(sections).toEqual([
      "mission",
      "journeys",
      "research",
      "projects",
      "people",
      "infrastructure",
      "latest",
      "partners",
    ]);
  });

  test("uses the approved sequence without startup or decorative patterns", () => {
    const homepage = read("includes/class-homepage.php");
    const sequence = [
      "mission",
      "research",
      "evidence",
      "projects",
      "journeys",
      "people",
      "infrastructure",
      "latest",
      "partners",
      "contact",
    ];
    let cursor = -1;
    for (const section of sequence) {
      const next = homepage.indexOf(`data-home-section="${section}"`);
      expect(next).toBeGreaterThan(cursor);
      cursor = next;
    }
    expect(homepage).not.toMatch(/carousel|autoplay|waveform|lorem ipsum|placeholder/i);
  });

  test("keeps the compact not-published notice only where it is required", () => {
    const homepage = read("includes/class-homepage.php");
    // Sparse policy: optional empty modules and strata are omitted entirely;
    // only the mission feature and the contact handoff may render the notice.
    const noticed = [...homepage.matchAll(/empty_notice\( '([a-z]+)'/g)]
      .map((match) => match[1])
      .sort();
    expect(noticed).toEqual(["contact", "contact", "mission"]);
    expect(homepage).toContain('aria-disabled="true"');
    expect(homepage).toContain("Information not published");
    expect(homepage).toContain("Informações não publicadas");
  });
});

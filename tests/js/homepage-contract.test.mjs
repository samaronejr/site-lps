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
    // Seven visual modules in the showcase order: projects, evidence and
    // infrastructure render inside the research module as strata and the
    // contact band inside partners, so none of them has its own block.
    const sections = [...template.matchAll(/wp:lps-theme\/homepage \{"section":"([a-z]+)"/g)].map(
      (match) => match[1],
    );
    expect(sections).toEqual([
      "mission",
      "journeys",
      "research",
      "teaching",
      "people",
      "latest",
      "partners",
    ]);
  });

  test("uses the approved sequence without startup or decorative patterns", () => {
    const homepage = read("includes/class-homepage.php");
    // The renderer emits data-home-section dynamically per block, so the
    // canonical order lives in its section and stratum registries.
    const sectionKeys = [
      ...homepage.matchAll(
        /^\s*'(mission|research|latest|teaching|people|journeys|partners)'\s*=>\s*array\(\s*'[^']+'\s*,/gm,
      ),
    ].map((match) => match[1]);
    expect(sectionKeys).toEqual([
      "mission",
      "journeys",
      "research",
      "teaching",
      "people",
      "latest",
      "partners",
    ]);
    const stratumKeys = [
      ...homepage.matchAll(
        /^\s*'(projects|evidence|infrastructure|contact)'\s*=>\s*array\(\s*'[^']+'\s*,/gm,
      ),
    ].map((match) => match[1]);
    expect(stratumKeys).toEqual(["projects", "evidence", "infrastructure", "contact"]);
    expect(homepage).not.toMatch(/carousel|autoplay|waveform|lorem ipsum|placeholder/i);
  });

  test("keeps the compact not-published notice only where it is required", () => {
    const homepage = read("includes/class-homepage.php");
    // Sparse policy: optional empty modules and strata are omitted entirely;
    // only the mission feature and the contact handoff may render the notice.
    const noticed = [...homepage.matchAll(/empty_notice\( '([a-z]+)'/g)]
      .map((match) => match[1])
      .sort();
    expect(noticed).toEqual(["contact", "mission"]);
    expect(homepage).toContain('aria-disabled="true"');
    expect(homepage).toContain("Information not published");
    expect(homepage).toContain("Informações não publicadas");
  });
});

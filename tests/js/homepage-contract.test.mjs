import { existsSync, readFileSync } from "node:fs";
import { describe, expect, test } from "vitest";

const root = "wp-content/themes/lps-theme";
const read = (path) => readFileSync(`${root}/${path}`, "utf8");

describe("research-first homepage integration", () => {
  test("registers one locked server-rendered homepage block", () => {
    expect(existsSync(`${root}/templates/front-page.html`)).toBe(true);
    const template = read("templates/front-page.html");
    expect(template).toContain("lps-theme/homepage");
    expect(template).toContain('"lock":{"move":true,"remove":true}');
    expect(template).not.toMatch(/carousel|autoplay|placeholder/i);
    expect(read("functions.php")).toContain("Homepage::class");
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
});

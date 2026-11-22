import { describe, expect, it } from "vitest";
import { compileThemeCss } from "../../scripts/lib/theme-css.mjs";

describe("theme CSS build", () => {
  it("keeps public and mixed selectors while extracting only scoped editorial rules", () => {
    const result = compileThemeCss(
      "/* comment */ .lps-card { color: red; } .lps-dashboard-main { color: blue; } @media (min-width: 600px) { .lps-dashboard-main { display: grid; } .lps-card { display: flex; } } .lps-dashboard-main, .lps-card { margin: 0; }",
    );
    expect(result.publicCss.toString()).toContain(".lps-card");
    expect(result.publicCss.toString()).not.toContain("color:#00f");
    expect(result.privateCss.toString()).toContain(".lps-dashboard-main");
    expect(result.privateCss.toString()).not.toContain(".lps-card");
    expect(result.publicCss.toString()).not.toContain("comment");
  });
  it("supports an empty sheet and rejects invalid CSS", () => {
    expect(compileThemeCss("").publicCss.length).toBe(0);
    expect(() => compileThemeCss("a { color:")).toThrow();
  });
});

import { expect, test } from "@playwright/test";
import { attemptId, fixtureSlug, loadFixture } from "../../js/lps-redesign/fixtures.mjs";

const ATTEMPT = attemptId();
const PROBE_SLUG = fixtureSlug("person-synthetic-ada");

test.describe("task-01 baseline: development runtime", () => {
  test("the running WordPress surface serves the public site", async ({ request }) => {
    // Given: the development runtime at the configured base URL. An
    // unreachable env must fail here explicitly, never pass with zero tests.
    const response = await request.get("/");
    expect(response.status()).toBe(200);
    expect(response.headers()["content-type"]).toContain("text/html");
    await expect(response.text()).resolves.toContain("wp-site-blocks");
  });

  test("attempt-namespaced fixture slugs are absent from real content", async ({ request }) => {
    // Given: a deterministic fixture slug for this attempt.
    // When: the public search API is queried for it.
    const response = await request.get(
      `/wp-json/wp/v2/search?search=${encodeURIComponent(PROBE_SLUG)}&per_page=5`,
    );

    // Then: the runtime answers and no real record carries the fixture slug.
    expect(response.status()).toBe(200);
    const results = await response.json();
    expect(results.filter((entry) => entry.url?.includes(PROBE_SLUG))).toEqual([]);
  });

  test("fixture records stay data-only: runtime write boundary denies the unenrolled session", async ({
    page,
  }) => {
    // Given: the synthetic faculty fixture. The page context owns the
    // auto-login session cookies; in-page fetch is how authenticated REST is
    // exercised elsewhere in this suite.
    const faculty = await loadFixture("faculty");
    const person = faculty.people.find((entry) => entry.id === "person-synthetic-ada");
    expect(person).toBeDefined();

    await page.goto("/", { waitUntil: "domcontentloaded" });

    // When: the fixture record is offered for creation as site content.
    const create = await page.evaluate(
      async ([slug, title, attempt]) => {
        const nonce = await (await fetch("/wp-admin/admin-ajax.php?action=rest-nonce")).text();
        const response = await fetch("/wp-json/wp/v2/pages", {
          method: "POST",
          headers: { "X-WP-Nonce": nonce, "Content-Type": "application/json" },
          body: JSON.stringify({
            title,
            slug,
            status: "draft",
            content: `synthetic fixture ${attempt}; not real content`,
          }),
        });
        return { status: response.status, body: await response.json() };
      },
      [PROBE_SLUG, person.displayName, ATTEMPT],
    );

    // Then: the baseline policy denies the write for a session without MFA
    // enrollment (strip_unenrolled_privileges). The fixture remains data-only
    // and nothing is persisted to clean up.
    expect(create.status).toBe(403);
    expect(create.body.code).toBe("rest_cannot_create");
  });
});

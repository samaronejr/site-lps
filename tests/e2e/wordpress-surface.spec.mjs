import { expect, test } from "@playwright/test";

test("serves WordPress when the local runtime is started", async ({ request }) => {
  // Given: wp-env is running at the configured base URL.
  // When: the public root is requested.
  const response = await request.get("/");

  // Then: WordPress returns a successful HTML document.
  expect(response.status()).toBe(200);
  expect(response.headers()["content-type"]).toContain("text/html");
  await expect(response.text()).resolves.toContain("wp-site-blocks");
});

import { expect, test } from "@playwright/test";
import { attemptId, fixtureSlug } from "../../js/lps-redesign/fixtures.mjs";

const ATTEMPT = attemptId();
const PROBE_KEY = fixtureSlug("lps-file-probe");

const PDF_BYTES =
  "%PDF-1.7\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n%%EOF\n";

/**
 * Task 6 — nonpublic upload quarantine and release prerequisites.
 *
 * These checks exercise the real HTTP boundary of the running site: anonymous
 * raw access to storage-shaped paths must be denied, unauthenticated writes
 * must fail, spoofed/executable uploads must be rejected by the deployed
 * policy, and no response may leak storage internals. Quarantine/scan
 * transitions are covered by the PHPUnit contract (LpsRedesignTask06Test)
 * because the storage root lives outside the web root by design — no URL may
 * ever reach it.
 */
test.describe("task-06: nonpublic upload quarantine boundary", () => {
  test("the running WordPress surface serves the public site", async ({ request }) => {
    // Given: the development runtime at the configured base URL. An
    // unreachable or uninstalled env must fail here explicitly.
    const response = await request.get("/");
    expect(response.status()).toBe(200);
    expect(response.headers()["content-type"]).toContain("text/html");
  });

  test("anonymous raw access to quarantine-shaped storage paths is denied", async ({ request }) => {
    // Given: candidate raw paths an opaque storage object could take if the
    // boundary ever leaked one. None may return file bytes.
    for (const path of [
      `/lps-teaching/${PROBE_KEY}`,
      `/quarantine/${PROBE_KEY}`,
      `/storage/quarantine/${PROBE_KEY}`,
      `/wp-content/lps-teaching/${PROBE_KEY}`,
      `/wp-content/uploads/lps-teaching/${PROBE_KEY}`,
      `/${PROBE_KEY}.pdf`,
    ]) {
      // Then: the raw response is a consistent not-found/redirect — never a
      // direct 200 serving file bytes.
      const raw = await request.get(path, { maxRedirects: 0 });
      expect([301, 302, 404]).toContain(raw.status());
      const response = await request.get(path);
      const body = await response.text();
      expect(body).not.toContain("%PDF-");
      expect(response.headers()["content-type"] ?? "").not.toContain("application/pdf");
    }
  });

  test("unauthenticated upload attempts are denied at the REST boundary", async ({ request }) => {
    // Given: the auto-login session cookies but no REST nonce — the request is
    // unauthenticated for write purposes.
    await request.get("/");
    const response = await request.post("/wp-json/wp/v2/media", {
      multipart: {
        file: {
          name: `${PROBE_KEY}.pdf`,
          mimeType: "application/pdf",
          buffer: Buffer.from(PDF_BYTES),
        },
      },
    });

    // Then: the write is rejected before any file mutation.
    expect(response.status()).toBe(401);
    const body = await response.json();
    expect(body.code).toBe("rest_cannot_create");
  });

  test("spoofed and executable uploads are denied by the deployed policy", async ({ request }) => {
    // Given: an authenticated session with a REST nonce.
    await request.get("/");
    const nonceResponse = await request.get("/wp-admin/admin-ajax.php?action=rest-nonce");
    expect(nonceResponse.ok(), "REST nonce endpoint must answer for the session").toBe(true);
    const nonce = await nonceResponse.text();
    expect(nonce.length).toBeGreaterThan(0);

    // When: SVG, HTML, and extension-spoofed payloads are offered.
    const cases = [
      {
        file: {
          name: `${PROBE_KEY}.svg`,
          mimeType: "image/svg+xml",
          buffer: Buffer.from("<svg onload=alert(1)/>"),
        },
        code: "lps_media_executable_name_forbidden",
      },
      {
        file: {
          name: `${PROBE_KEY}.html`,
          mimeType: "text/html",
          buffer: Buffer.from("<html><script>alert(1)</script></html>"),
        },
        code: "lps_media_executable_name_forbidden",
      },
      {
        file: { name: `${PROBE_KEY}.png`, mimeType: "image/png", buffer: Buffer.from(PDF_BYTES) },
        code: "lps_media_mime_forbidden",
      },
      {
        file: {
          name: `${PROBE_KEY}.php`,
          mimeType: "application/x-php",
          buffer: Buffer.from("<?php echo 1;"),
        },
        code: "lps_media_executable_name_forbidden",
      },
    ];
    for (const { file, code } of cases) {
      const response = await request.post("/wp-json/wp/v2/media", {
        multipart: { file },
        headers: { "X-WP-Nonce": nonce },
      });
      // Then: typed denial, never a stored object.
      expect(response.status()).toBe(400);
      const body = await response.json();
      expect(body.code).toBe(code);
    }
  });

  test("sessions without MFA enrollment cannot create media at all", async ({ request }) => {
    // Given: the auto-login session, which has no enrolled Two-Factor provider
    // and therefore keeps only read/profile privileges under the site policy.
    await request.get("/");
    const nonceResponse = await request.get("/wp-admin/admin-ajax.php?action=rest-nonce");
    expect(nonceResponse.ok(), "REST nonce endpoint must answer for the session").toBe(true);
    const nonce = await nonceResponse.text();

    // When: even a valid PDF is offered through the governed media lane.
    const create = await request.post("/wp-json/wp/v2/media", {
      multipart: {
        file: {
          name: `${PROBE_KEY}.pdf`,
          mimeType: "application/pdf",
          buffer: Buffer.from(PDF_BYTES),
        },
        title: `synthetic fixture ${ATTEMPT}`,
      },
      headers: { "X-WP-Nonce": nonce },
    });

    // Then: the write boundary denies the unenrolled session — nothing is
    // stored, so no quarantine or public path is ever produced.
    expect(create.status()).toBe(403);
    const body = await create.json();
    expect(body.code).toBe("rest_cannot_create");
  });

  test("public responses carry nosniff and never expose storage internals", async ({ request }) => {
    // Given: any public response from the site.
    const response = await request.get("/");
    const headers = response.headers();

    // Then: the hardening header set is present and no storage path leaks.
    expect(headers["x-content-type-options"]).toBe("nosniff");
    const body = await response.text();
    expect(body).not.toMatch(/lps-file-[0-9a-f]{32}/);
    expect(body).not.toContain("/quarantine/");
  });
});

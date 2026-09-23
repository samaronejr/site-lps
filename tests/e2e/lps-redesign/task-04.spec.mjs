import { expect, test } from "@playwright/test";
import { attemptId, fixtureSlug } from "../../js/lps-redesign/fixtures.mjs";

const ATTEMPT = attemptId();
const PROBE_SLUG = fixtureSlug("offering-scope-probe");

/**
 * Task 4 — offering-scoped editorial authorization.
 *
 * These checks exercise the real HTTP boundary of the running site: the
 * teaching record types must be registered, unauthenticated and unscoped
 * writes must be denied before any mutation, scope-escalation payloads
 * (forged owner, offering, and relation IDs) must never create access, and
 * grant metadata must never appear in public responses. The pure grant,
 * expiry, revocation, and field-allowlist contracts are covered by the
 * PHPUnit boundary (LpsRedesignTask04Test).
 */
test.describe("task-04: offering-scoped editorial authorization boundary", () => {
  test("the runtime registers the teaching record types", async ({ request }) => {
    // Given: the development runtime at the configured base URL. An
    // unreachable env or a checkout without the teaching model must fail
    // here explicitly, never pass with zero coverage.
    const response = await request.get("/wp-json/wp/v2/types");
    expect(response.status()).toBe(200);
    const types = await response.json();
    for (const type of ["lps_course", "lps_offering", "lps_term", "lps_unit", "lps_resource"]) {
      expect(types[type], `${type} must be registered`).toBeDefined();
    }
  });

  test("unauthenticated writes to teaching records are denied", async ({ request }) => {
    // Given: the auto-login session cookies but no REST nonce — the request is
    // unauthenticated for write purposes.
    await request.get("/");
    for (const restBase of ["offerings", "units", "resources", "courses"]) {
      // When: a create is attempted on each teaching collection.
      const response = await request.post(`/wp-json/wp/v2/${restBase}`, {
        data: {
          title: `synthetic fixture ${ATTEMPT}`,
          slug: PROBE_SLUG,
          status: "draft",
        },
      });
      // Then: the write is rejected before any record mutation.
      expect(response.status(), restBase).toBe(401);
      const body = await response.json();
      expect(body.code).toBe("rest_cannot_create");
    }
  });

  test("scope-escalation payloads never create access", async ({ request }) => {
    // Given: an unauthenticated write channel.
    await request.get("/");
    const forgedPayloads = [
      // Owner reassignment through input IDs.
      { meta: { _lps_owner_user_id: 1 }, author: 1 },
      // Forged relation targets: a parent offering the account was never granted.
      { lps_parent_offering: 1, meta: { _lps_teaching_team_ids: [1] } },
      // Direct grant injection through account-shaped metadata.
      {
        meta: { _lps_teaching_grants: [{ scope: "offering", offering_id: 1, role: "professor" }] },
      },
    ];
    for (const payload of forgedPayloads) {
      const response = await request.post("/wp-json/wp/v2/units", {
        data: { title: `synthetic fixture ${ATTEMPT}`, status: "draft", ...payload },
      });
      // Then: denial, never a created record.
      expect([401, 403]).toContain(response.status());
      const body = await response.json();
      expect(body.code).not.toBe("rest_post_invalid_id");
      expect(response.status()).not.toBe(201);
    }
  });

  test("sessions without MFA enrollment cannot mutate teaching records", async ({ request }) => {
    // The nonce retry loop can take several slow requests under parallel
    // workers on this environment.
    test.setTimeout(120000);
    // Given: the auto-login session, which has no enrolled Two-Factor provider
    // and therefore keeps only read/profile privileges under the site policy.
    // The auto-login handshake fires on /wp-admin/, not /; establish the
    // session there, then read the REST nonce. The session cookie can lapse
    // under parallel workers, so re-establish it on each retry.
    let nonce = "";
    for (let attempt = 0; attempt < 6 && 0 === nonce.length; attempt += 1) {
      await request.get("/wp-admin/");
      const nonceResponse = await request.get("/wp-admin/admin-ajax.php?action=rest-nonce");
      if (nonceResponse.ok()) {
        const candidate = await nonceResponse.text();
        if (0 < candidate.length && "0" !== candidate) {
          nonce = candidate;
        }
      }
    }
    expect(nonce.length, "REST nonce endpoint must answer for the session").toBeGreaterThan(0);

    // When: a draft create is attempted on the offering collection.
    const create = await request.post("/wp-json/wp/v2/offerings", {
      data: {
        title: `synthetic fixture ${ATTEMPT}`,
        slug: PROBE_SLUG,
        status: "draft",
        meta: { _lps_section_key: "a" },
      },
      headers: { "X-WP-Nonce": nonce },
    });

    // Then: the write boundary denies the unenrolled session — nothing is
    // persisted, so no scope record is ever produced.
    expect(create.status()).toBe(403);
    const body = await create.json();
    expect(body.code).toBe("rest_cannot_create");
  });

  test("anonymous teaching listings never expose nonpublic records or private fields", async ({
    request,
  }) => {
    // Given: an anonymous request channel with the session established.
    await request.get("/");
    for (const restBase of ["units", "resources", "terms", "offerings", "courses"]) {
      // When: the collections are listed anonymously.
      const response = await request.get(`/wp-json/wp/v2/${restBase}?per_page=5`);
      // Then: the listing answers, but every served record is public and no
      // private scope, storage, or account field leaks.
      expect(response.status(), restBase).toBe(200);
      const items = await response.json();
      expect(Array.isArray(items)).toBe(true);
      for (const item of items) {
        expect(item.status, `${restBase} item ${item.id}`).toBe("publish");
        const serialized = JSON.stringify(item);
        expect(serialized).not.toContain("_lps_teaching_grants");
        expect(serialized).not.toContain("_lps_storage_key");
        expect(serialized).not.toContain("_lps_version_id");
        expect(serialized).not.toContain("_lps_uploader_user_id");
        expect(serialized).not.toContain("_lps_owner_user_id");
      }
    }
  });

  test("grant metadata and account linkage never appear in public responses", async ({
    request,
  }) => {
    // Given: public surfaces that could leak scope or account internals.
    await request.get("/");
    for (const path of [
      "/wp-json/wp/v2/types",
      "/wp-json/wp/v2/users/1",
      "/wp-json/wp/v2/search?search=lps&per_page=5",
      "/",
    ]) {
      const response = await request.get(path);
      const body = await response.text();
      // Then: no grant record, storage key, or owner linkage leaks.
      expect(body).not.toContain("_lps_teaching_grants");
      expect(body).not.toContain("_lps_storage_key");
      expect(body).not.toContain("_lps_owner_user_id");
    }
  });
});

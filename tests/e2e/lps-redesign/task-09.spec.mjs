import { createHash } from "node:crypto";
import { expect, test } from "@playwright/test";

/**
 * Task 9 — immutable resource versions and the guarded download boundary.
 *
 * The spec exercises the real HTTP boundary of the running site: a scoped
 * professor mints immutable versions through quarantine and scan, selects one
 * on a resource, releases and publishes it, and anonymous clients download
 * bytes only while every check — resource, offering, release state and time,
 * version identity, storage/scan state, rights review — passes on that exact
 * request. Version replacement is explicit and audited; every denial is the
 * same anonymous 404 with no storage key, path or typed code.
 *
 * The pure version-ID, release-state, range and denial-chain contracts are
 * covered by LpsRedesignTask09Test; this spec proves the wired boundary.
 */

const RUN = `t9-${Date.now().toString(36)}`;
const BASE_URL = process.env.LPS_BASE_URL ?? "http://localhost:8892";

const PDF_V1 = `%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\n% task-09 fixture v1 ${RUN}\n%%EOF\n`;
const PDF_V2 = `%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 300] >>\nendobj\n% task-09 fixture v2 ${RUN} corrected\n%%EOF\n`;
const PDF_V3 = `%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] >>\nendobj\n% task-09 fixture v3 ${RUN} post-outage\n%%EOF\n`;
const SHA_V1 = createHash("sha256").update(PDF_V1).digest("hex");
const SHA_V2 = createHash("sha256").update(PDF_V2).digest("hex");
const SHA_V3 = createHash("sha256").update(PDF_V3).digest("hex");

// Shared per-worker state: the serial happy path builds one record graph on
// authenticated sessions — Playwright gives every test a fresh context, so
// the sessions live on shared pages created in beforeAll.
const state = {
  publisherNonce: "",
  adminNonce: "",
  professorNonce: "",
  professorId: 0,
  personId: 0,
  termId: 0,
  coursePtId: 0,
  courseEnId: 0,
  offeringPtId: 0,
  offeringEnId: 0,
  offeringOtherId: 0,
  resourceId: 0,
  resourceRecordId: "",
  downloadUrl: "",
  version1Id: "",
  version2Id: "",
  version3Id: "",
  quarantinedVersionId: "",
  otherResourceId: 0,
  draftResourceId: 0,
  scheduledResourceId: 0,
  externalResourceId: 0,
  courseSlugPt: `disciplina-${RUN}`,
  courseSlugEn: `course-${RUN}`,
};

let context;
let page;
let adminContext;
let adminPage;
let professorContext;
let professorPage;

test.describe.configure({ mode: "serial" });

/**
 * Signs in through the real login form.
 *
 * The auto-login marker cookie is set first so wp-login.php renders the form
 * instead of the auto-login handshake. MFA-enrolled accounts then complete
 * the Two-Factor dummy-provider challenge with a single submit; accounts
 * without MFA land directly in wp-admin.
 */
async function loginAs(target, user, pass, mfa) {
  await target.goto("/", { waitUntil: "domcontentloaded" });
  const base = new URL(target.url());
  await target.context().addCookies([
    {
      name: "playground_auto_login_already_happened",
      value: "1",
      domain: base.hostname,
      path: "/",
    },
  ]);
  await target.goto("/wp-login.php");
  const login = target.locator("#user_login");
  await login.waitFor({ state: "visible", timeout: 15_000 });
  await login.fill(user);
  await target.locator("#user_pass").fill(pass);
  await Promise.all([
    target.waitForLoadState("load", { timeout: 60_000 }),
    target.locator("#wp-submit").click(),
  ]);
  if (mfa) {
    // The enrolled-MFA challenge renders its own submit (`submit_button()`),
    // not the credential form's #wp-submit; a failed sign-in must surface
    // here, never as a later REST denial.
    const challenge = target.locator("#loginform input[type=submit], #loginform button[type=submit]");
    await challenge.waitFor({ state: "visible", timeout: 60_000 });
    await Promise.all([target.waitForLoadState("load", { timeout: 60_000 }), challenge.click()]);
  }
  await expect(target.locator("#login_error")).toHaveCount(0);
  // A successful sign-in leaves wp-login.php entirely: the session cookie
  // is what matters, so wait for the URL rather than an admin-only marker
  // (the redirect target may be the front-end for non-admin roles).
  await target.waitForURL((url) => !url.pathname.endsWith("/wp-login.php"), { timeout: 60_000 });
}

/** Reads the REST nonce bound to a signed-in session. */
async function restNonce(target) {
  for (let attempt = 0; attempt < 6; attempt += 1) {
    const response = await target.request.get("/wp-admin/admin-ajax.php?action=rest-nonce", {
      timeout: 60_000,
    });
    if (response.ok()) {
      const nonce = (await response.text()).trim();
      if (nonce.length > 0 && nonce !== "0") {
        return nonce;
      }
    }
  }
  throw new Error("REST nonce endpoint never answered for the session");
}

/** Calls the teaching REST boundary with a session nonce. */
async function api(target, nonce, method, path, data) {
  const response = await target.request.fetch(`/wp-json/lps/v1${path}`, {
    method,
    data,
    headers: { "X-WP-Nonce": nonce },
  });
  const body = await response.json().catch(() => ({}));
  return { status: response.status(), body };
}

/** Uploads one file to the version boundary as multipart `file`. */
async function upload(target, nonce, offeringId, name, contents, extra = {}) {
  const response = await target.request.post("/wp-json/lps/v1/teaching/resource-versions", {
    multipart: {
      file: { name, mimeType: "application/pdf", buffer: Buffer.from(contents, "utf8") },
      offering_id: String(offeringId),
      ...extra,
    },
    headers: { "X-WP-Nonce": nonce },
  });
  const body = await response.json().catch(() => ({}));
  return { status: response.status(), body };
}


/** Creates a credential-free context that skips the auto-login handshake.
 *
 * The marker cookie only suppresses the playground auto-login redirect; the
 * request still reaches WordPress with no credentials, so the boundary itself
 * must answer — never a login page.
 */
async function anonymousContext(browser) {
  const ctx = await browser.newContext({ baseURL: BASE_URL });
  await ctx.addCookies([
    {
      name: "playground_auto_login_already_happened",
      value: "1",
      domain: new URL(BASE_URL).hostname,
      path: "/",
    },
  ]);
  return ctx;
}

/** Publishes one stored record through the gated endpoint. */
async function publish(id) {
  const result = await api(page, state.publisherNonce, "POST", `/teaching/records/${id}/publish`);
  expect(result.status, `publish ${id}: ${JSON.stringify(result.body)}`).toBe(200);
  return result.body;
}

test.describe("task-09: immutable versions and guarded downloads", () => {
  test.beforeAll(async ({ browser }) => {
    test.setTimeout(180_000);
    context = await browser.newContext({ baseURL: BASE_URL });
    page = await context.newPage();
    await loginAs(page, "lps-t9-publisher", "lps-t9-publisher-pass", true);
    state.publisherNonce = await restNonce(page);

    adminContext = await browser.newContext({ baseURL: BASE_URL });
    adminPage = await adminContext.newPage();
    // The administrator is MFA-enrolled too: unenrolled privileged sessions
    // have every capability stripped by the MFA boundary.
    await loginAs(adminPage, "admin", "password", true);
    state.adminNonce = await restNonce(adminPage);

    professorContext = await browser.newContext({ baseURL: BASE_URL });
    professorPage = await professorContext.newPage();
    await loginAs(professorPage, "lps-t9-professor", "lps-t9-professor-pass", true);
    state.professorNonce = await restNonce(professorPage);
  });

  test.afterAll(async () => {
    await context?.close();
    await adminContext?.close();
    await professorContext?.close();
  });

  test("the publisher builds and publishes the bilingual offering graph", async () => {
    test.setTimeout(120_000);

    const person = await page.request.post("/wp-json/wp/v2/people", {
      data: {
        title: `Docente ${RUN}`,
        slug: `docente-${RUN}`,
        status: "draft",
        meta: { _lps_locale: "pt-br", _lps_canonical_name: `Docente ${RUN}` },
      },
      headers: { "X-WP-Nonce": state.publisherNonce },
    });
    expect(person.status(), JSON.stringify(await person.json())).toBe(201);
    state.personId = (await person.json()).id;

    const term = await api(page, state.publisherNonce, "POST", "/teaching/terms", {
      title: `2026.2 ${RUN}`,
      slug: `term-${RUN}`,
      calendar_key: "semester",
      term_code: `2026-2-${RUN}`,
      status: "publish",
      meta: {
        _lps_period_label: `2026.2 ${RUN}`,
        _lps_period_type: "semester",
        _lps_starts_on: "2026-01-05",
        _lps_ends_on: "2026-12-20",
      },
    });
    expect(term.status, JSON.stringify(term.body)).toBe(201);
    state.termId = term.body.id;

    const course = await api(page, state.publisherNonce, "POST", "/teaching/courses", {
      title: `Disciplina ${RUN}`,
      slug: state.courseSlugPt,
      excerpt: "Disciplina de teste.",
      content: "Conteúdo de fixture.",
      meta: {
        _lps_course_code: `LPS-${RUN.toUpperCase()}`,
        _lps_course_level: "undergraduate",
        _lps_calendar_key: "semester",
      },
    });
    expect(course.status, JSON.stringify(course.body)).toBe(201);
    state.coursePtId = course.body.id;

    const courseEn = await api(page, state.publisherNonce, "POST", "/teaching/courses", {
      title: `Course ${RUN}`,
      slug: state.courseSlugEn,
      locale: "en",
      translation_of: state.coursePtId,
      excerpt: "Test course.",
      content: "Fixture content.",
    });
    expect(courseEn.status, JSON.stringify(courseEn.body)).toBe(201);
    state.courseEnId = courseEn.body.id;
    await api(page, state.publisherNonce, "POST", `/teaching/records/${state.courseEnId}/review-translation`);
    await publish(state.courseEnId);
    await publish(state.coursePtId);

    const offering = await api(page, state.publisherNonce, "POST", "/teaching/offerings", {
      title: `Turma ${RUN} T01`,
      slug: `turma-${RUN}-t01`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.coursePtId,
      term_id: state.termId,
      section: "t01",
      team: [{ person_id: state.personId, role: "lead" }],
    });
    expect(offering.status, JSON.stringify(offering.body)).toBe(201);
    state.offeringPtId = offering.body.id;

    const offeringEn = await api(page, state.publisherNonce, "POST", "/teaching/offerings", {
      title: `Section ${RUN} T01`,
      slug: `section-${RUN}-t01`,
      locale: "en",
      translation_of: state.offeringPtId,
      excerpt: "Test section.",
      content: "Fixture content.",
    });
    expect(offeringEn.status, JSON.stringify(offeringEn.body)).toBe(201);
    state.offeringEnId = offeringEn.body.id;
    await api(page, state.publisherNonce, "POST", `/teaching/records/${state.offeringEnId}/review-translation`);
    await publish(state.offeringEnId);
    await publish(state.offeringPtId);

    // A second published offering anchors the out-of-scope denial checks.
    const other = await api(page, state.publisherNonce, "POST", "/teaching/offerings", {
      title: `Turma ${RUN} T02`,
      slug: `turma-${RUN}-t02`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.coursePtId,
      term_id: state.termId,
      section: "t02",
      team: [{ person_id: state.personId, role: "lead" }],
    });
    expect(other.status, JSON.stringify(other.body)).toBe(201);
    state.offeringOtherId = other.body.id;
    const otherEn = await api(page, state.publisherNonce, "POST", "/teaching/offerings", {
      title: `Section ${RUN} T02`,
      slug: `section-${RUN}-t02`,
      locale: "en",
      translation_of: state.offeringOtherId,
      excerpt: "Test section.",
      content: "Fixture content.",
    });
    expect(otherEn.status).toBe(201);
    await api(page, state.publisherNonce, "POST", `/teaching/records/${otherEn.body.id}/review-translation`);
    await publish(otherEn.body.id);
    await publish(state.offeringOtherId);
  });

  test("an administrator grants the professor scope on one offering only", async () => {
    expect(state.offeringPtId, "graph test must run first").toBeGreaterThan(0);
    // Scoped roles cannot read wp/v2/users, so the grant endpoint resolves
    // the account by login — the grant itself is the assertion that matters.
    const grant = await api(adminPage, state.adminNonce, "POST", "/test/grants", {
      user_login: "lps-t9-professor",
      offering_id: state.offeringPtId,
      role: "professor",
    });
    expect(grant.status, JSON.stringify(grant.body)).toBe(200);
    expect(grant.body.granted).toBe(true);
  });

  test("the professor mints a cleared immutable version through quarantine and scan", async () => {
    expect(state.offeringPtId, "grant test must run first").toBeGreaterThan(0);

    const version = await upload(professorPage, state.professorNonce, state.offeringPtId, `apostila-${RUN}.pdf`, PDF_V1);
    expect(version.status, JSON.stringify(version.body)).toBe(201);
    state.version1Id = version.body.version_id;
    expect(state.version1Id).toMatch(/^lpsver:[0-9a-f]{64}$/);
    expect(version.body.state).toBe("cleared");
    expect(version.body.scan_verdict).toBe("clean");
    expect(version.body.sha256).toBe(SHA_V1);
    expect(version.body.bytes).toBe(Buffer.byteLength(PDF_V1));
    expect(version.body.mime).toBe("application/pdf");
    // The response never carries a storage key, path or original name.
    expect(version.body.key).toBeUndefined();
    expect(version.body.storage_key).toBeUndefined();
    expect(version.body.path).toBeUndefined();
    expect(version.body.original_name).toBeUndefined();

    // An unscanned upload stays quarantined until the scan boundary clears it.
    const pending = await upload(
      professorPage,
      state.professorNonce,
      state.offeringPtId,
      `pendente-${RUN}.pdf`,
      PDF_V1,
      { scan: "false" },
    );
    expect(pending.status, JSON.stringify(pending.body)).toBe(201);
    state.quarantinedVersionId = pending.body.version_id;
    expect(pending.body.state).toBe("quarantined");
    const scanned = await api(
      professorPage,
      state.professorNonce,
      "POST",
      `/teaching/resource-versions/${state.quarantinedVersionId}/scan`,
      { offering_id: state.offeringPtId },
    );
    expect(scanned.status, JSON.stringify(scanned.body)).toBe(200);
    expect(scanned.body.state).toBe("cleared");
    expect(scanned.body.scan_verdict).toBe("clean");
  });

  test("the professor creates, versions, releases and publishes the resource", async () => {
    expect(state.version1Id, "version test must run first").not.toBe("");

    const resource = await api(professorPage, state.professorNonce, "POST", "/teaching/resources", {
      title: `Apostila ${RUN}`,
      slug: `apostila-${RUN}`,
      offering_id: state.offeringPtId,
      version_id: state.version1Id,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    expect(resource.status, JSON.stringify(resource.body)).toBe(201);
    state.resourceId = resource.body.id;
    state.resourceRecordId = resource.body.record_id;
    expect(state.resourceRecordId).toMatch(/^lps:resource:[0-9a-f-]{36}$/);
    expect(resource.body.version_id).toBe(state.version1Id);
    expect(resource.body.release_state).toBe("draft");
    expect(resource.body.download_url).toMatch(/^\/lps-resource\/[0-9a-f-]{36}\/$/);
    state.downloadUrl = resource.body.download_url;
    // The opaque token is the record-ID UUID, never the raw post ID.
    expect(state.downloadUrl).not.toContain(String(state.resourceId));

    const released = await api(professorPage, state.professorNonce, "POST", `/teaching/resources/${state.resourceId}/release`, {
      state: "released",
    });
    expect(released.status, JSON.stringify(released.body)).toBe(200);
    expect(released.body.release_state).toBe("released");

    const published = await api(professorPage, state.professorNonce, "POST", `/teaching/records/${state.resourceId}/publish`);
    expect(published.status, JSON.stringify(published.body)).toBe(200);
    expect(published.body.status).toBe("publish");
  });

  test("anonymous clients download the selected version with safe headers", async ({ browser }) => {
    expect(state.downloadUrl, "publish test must run first").not.toBe("");
    const anonymous = await anonymousContext(browser);
    try {
      const response = await anonymous.request.get(state.downloadUrl);
      expect(response.status()).toBe(200);
      const body = await response.body();
      expect(createHash("sha256").update(body).digest("hex")).toBe(SHA_V1);
      const headers = response.headers();
      expect(headers["content-disposition"]).toMatch(/^attachment; filename="apostila-/);
      expect(headers["content-disposition"]).toContain(".pdf");
      expect(headers["x-content-type-options"]).toBe("nosniff");
      expect(headers["cache-control"]).toContain("no-store");
      expect(headers["content-type"]).toBe("application/pdf");
      expect(headers["accept-ranges"]).toBe("bytes");
      expect(headers["content-length"]).toBe(String(Buffer.byteLength(PDF_V1)));
      // The digest header carries the version checksum; no storage key or
      // path ever appears in any header.
      expect(headers["digest"]).toBe(`sha-256=:${Buffer.from(SHA_V1, "hex").toString("base64")}:`);
      for (const value of Object.values(headers)) {
        expect(value).not.toContain("lps-file-");
        expect(value).not.toContain("/tmp/");
        expect(value).not.toContain("quarantine");
      }

      // HEAD serves identical headers without a body.
      const head = await anonymous.request.fetch(state.downloadUrl, { method: "HEAD" });
      expect(head.status()).toBe(200);
      expect(head.headers()["content-length"]).toBe(String(Buffer.byteLength(PDF_V1)));
      expect((await head.body()).length).toBe(0);

      // Single ranges are honored; multi-range and out-of-bounds are 416.
      const range = await anonymous.request.get(state.downloadUrl, { headers: { Range: "bytes=0-9" } });
      expect(range.status()).toBe(206);
      expect(range.headers()["content-range"]).toBe(`bytes 0-9/${Buffer.byteLength(PDF_V1)}`);
      expect((await range.body()).toString()).toBe(PDF_V1.slice(0, 10));
      const suffix = await anonymous.request.get(state.downloadUrl, { headers: { Range: "bytes=-5" } });
      expect(suffix.status()).toBe(206);
      expect((await suffix.body()).toString()).toBe(PDF_V1.slice(-5));
      const unsatisfiable = await anonymous.request.get(state.downloadUrl, {
        headers: { Range: `bytes=${Buffer.byteLength(PDF_V1) + 10}-` },
      });
      expect(unsatisfiable.status()).toBe(416);
      expect(unsatisfiable.headers()["content-range"]).toBe(`bytes */${Buffer.byteLength(PDF_V1)}`);
      const multi = await anonymous.request.get(state.downloadUrl, { headers: { Range: "bytes=0-1,3-4" } });
      expect(multi.status()).toBe(416);

      // Non-GET/HEAD methods are the same anonymous 404.
      const posted = await anonymous.request.post(state.downloadUrl);
      expect(posted.status()).toBe(404);
    } finally {
      await anonymous.close();
    }
  });

  test("guessed paths never bypass the resolver", async ({ browser }) => {
    expect(state.resourceId, "publish test must run first").toBeGreaterThan(0);
    const anonymous = await anonymousContext(browser);
    try {
      const uuid = state.resourceRecordId.replace("lps:resource:", "");
      // A well-formed token that is not this resource's record id: flip the
      // last hex digit so the guess can never collide with the real uuid.
      const wrongUuid = `${uuid.slice(0, -1)}${uuid.endsWith("0") ? "1" : "0"}`;
      // Every guessed shape must die as the same anonymous 404.
      for (const path of [
        `/lps-resource/${state.resourceId}/`,
        `/lps-resource/${wrongUuid}/`,
        `/lps-resource/${uuid}/extra/`,
        `/lps-resource/not-a-uuid/`,
        `/lps-resource/${state.version1Id}/`,
        `/pt-br/lps-resource/${uuid}/`,
      ]) {
        const response = await anonymous.request.get(path);
        expect(response.status(), path).toBe(404);
      }
      // A guessed storage key outside the route prefix must never serve
      // bytes either; the theme's SEO graph may answer it with a redirect
      // to the home page, which is still a denial — the assertion is that
      // no response ever carries the stored file.
      const guessed = await anonymous.request.get(
        "/wp-content/uploads/lps-file-00000000000000000000000000000000",
        { maxRedirects: 0 },
      );
      expect([301, 302, 404, 410], "guessed storage key").toContain(guessed.status());
      const guessedBody = await guessed.body();
      expect(guessedBody.toString()).not.toContain("%PDF");
    } finally {
      await anonymous.close();
    }
  });

  test("a new version is inert until the explicit audited selection", async ({ browser }) => {
    expect(state.version1Id, "publish test must run first").not.toBe("");

    // Minting v2 changes nothing the route serves.
    const v2 = await upload(professorPage, state.professorNonce, state.offeringPtId, `apostila-v2-${RUN}.pdf`, PDF_V2);
    expect(v2.status, JSON.stringify(v2.body)).toBe(201);
    state.version2Id = v2.body.version_id;
    expect(v2.body.sha256).toBe(SHA_V2);
    expect(state.version2Id).not.toBe(state.version1Id);

    // A quarantined version can never be served, so the bytes must clear
    // the scanner before the selection can take effect.
    const scanned = await api(
      professorPage,
      state.professorNonce,
      "POST",
      `/teaching/resource-versions/${state.version2Id}/scan`,
      { offering_id: state.offeringPtId },
    );
    expect(scanned.status, JSON.stringify(scanned.body)).toBe(200);
    expect(scanned.body.state).toBe("cleared");
    expect(scanned.body.scan_verdict).toBe("clean");

    const anonymous = await anonymousContext(browser);
    try {
      const before = await anonymous.request.get(state.downloadUrl);
      expect(createHash("sha256").update(await before.body()).digest("hex")).toBe(SHA_V1);
    } finally {
      await anonymous.close();
    }

    // The explicit selection is the only way served bytes change.
    const selected = await api(
      professorPage,
      state.professorNonce,
      "POST",
      `/teaching/resources/${state.resourceId}/version`,
      { version_id: state.version2Id },
    );
    expect(selected.status, JSON.stringify(selected.body)).toBe(200);
    expect(selected.body.version_id).toBe(state.version2Id);

    const anonymous2 = await anonymousContext(browser);
    try {
      const after = await anonymous2.request.get(state.downloadUrl);
      expect(after.status()).toBe(200);
      expect(createHash("sha256").update(await after.body()).digest("hex")).toBe(SHA_V2);
      expect(after.headers()["content-disposition"]).toContain(`apostila-v2-${RUN}.pdf`);
    } finally {
      await anonymous2.close();
    }
  });

  test("draft, scheduled, external and quarantined resources all deny delivery", async ({ browser }) => {
    expect(state.offeringPtId, "graph test must run first").toBeGreaterThan(0);
    const anonymous = await anonymousContext(browser);
    try {
      // A draft-release resource is published but not released: 404.
      const draft = await api(professorPage, state.professorNonce, "POST", "/teaching/resources", {
        title: `Rascunho ${RUN}`,
        offering_id: state.offeringPtId,
        version_id: state.quarantinedVersionId,
        meta: {
          _lps_resource_type: "document",
          _lps_resource_language: "pt-br",
          _lps_rights_review: "approved",
          _lps_accessibility_review: "approved",
        },
      });
      expect(draft.status, JSON.stringify(draft.body)).toBe(201);
      state.draftResourceId = draft.body.id;
      const draftPublish = await api(
        professorPage,
        state.professorNonce,
        "POST",
        `/teaching/records/${state.draftResourceId}/publish`,
      );
      expect(draftPublish.status, JSON.stringify(draftPublish.body)).toBe(200);
      expect((await anonymous.request.get(draft.body.download_url)).status()).toBe(404);

      // A future schedule denies until its time passes — no cron involved.
      const scheduled = await api(professorPage, state.professorNonce, "POST", "/teaching/resources", {
        title: `Agendado ${RUN}`,
        offering_id: state.offeringPtId,
        version_id: state.version1Id,
        meta: {
          _lps_resource_type: "document",
          _lps_resource_language: "pt-br",
          _lps_rights_review: "approved",
          _lps_accessibility_review: "approved",
        },
      });
      expect(scheduled.status, JSON.stringify(scheduled.body)).toBe(201);
      state.scheduledResourceId = scheduled.body.id;
      const schedule = await api(
        professorPage,
        state.professorNonce,
        "POST",
        `/teaching/resources/${state.scheduledResourceId}/release`,
        { state: "scheduled", release_at: "2999-01-01T00:00:00+00:00" },
      );
      expect(schedule.status, JSON.stringify(schedule.body)).toBe(200);
      const scheduledPublish = await api(
        professorPage,
        state.professorNonce,
        "POST",
        `/teaching/records/${state.scheduledResourceId}/publish`,
      );
      expect(scheduledPublish.status, JSON.stringify(scheduledPublish.body)).toBe(200);
      expect((await anonymous.request.get(scheduled.body.download_url)).status()).toBe(404);

      // An external resource never serves local bytes.
      const external = await api(professorPage, state.professorNonce, "POST", "/teaching/resources", {
        title: `Externo ${RUN}`,
        offering_id: state.offeringPtId,
        meta: {
          _lps_resource_type: "link",
          _lps_resource_language: "pt-br",
          _lps_external_url: "https://example.org/externo.pdf",
          _lps_rights_review: "approved",
          _lps_accessibility_review: "approved",
        },
      });
      expect(external.status, JSON.stringify(external.body)).toBe(201);
      state.externalResourceId = external.body.id;
      const externalRelease = await api(
        professorPage,
        state.professorNonce,
        "POST",
        `/teaching/resources/${state.externalResourceId}/release`,
        { state: "released" },
      );
      expect(externalRelease.status, JSON.stringify(externalRelease.body)).toBe(200);
      const externalPublish = await api(
        professorPage,
        state.professorNonce,
        "POST",
        `/teaching/records/${state.externalResourceId}/publish`,
      );
      expect(externalPublish.status, JSON.stringify(externalPublish.body)).toBe(200);
      expect((await anonymous.request.get(external.body.download_url)).status()).toBe(404);
    } finally {
      await anonymous.close();
    }
  });

  test("a scanner outage fails closed: the failed scan never clears bytes", async ({ browser }) => {
    expect(state.downloadUrl, "publish test must run first").not.toBe("");
    const anonymous = await anonymousContext(browser);
    try {
      // With the scanner down, a new upload can never reach `cleared`.
      const down = await api(page, state.publisherNonce, "POST", "/test/scanner", { state: "down" });
      expect(down.status).toBe(200);
      expect(down.body.state).toBe("down");
      const v3 = await upload(professorPage, state.professorNonce, state.offeringPtId, `apostila-v3-${RUN}.pdf`, PDF_V3);
      expect(v3.status, JSON.stringify(v3.body)).toBe(201);
      state.version3Id = v3.body.version_id;
      expect(v3.body.state).toBe("quarantined");
      expect(v3.body.scan_verdict).toBe("error");

      // Selecting the uncleared version is allowed; delivery is not.
      const selected = await api(
        professorPage,
        state.professorNonce,
        "POST",
        `/teaching/resources/${state.resourceId}/version`,
        { version_id: state.version3Id },
      );
      expect(selected.status, JSON.stringify(selected.body)).toBe(200);
      expect((await anonymous.request.get(state.downloadUrl)).status()).toBe(404);

      // Recovery clears the same immutable version; the route serves it on
      // the very next request — no re-selection, no cache.
      const up = await api(page, state.publisherNonce, "POST", "/test/scanner", { state: "up" });
      expect(up.body.state).toBe("up");
      const scanned = await api(
        professorPage,
        state.professorNonce,
        "POST",
        `/teaching/resource-versions/${state.version3Id}/scan`,
        { offering_id: state.offeringPtId },
      );
      expect(scanned.status, JSON.stringify(scanned.body)).toBe(200);
      expect(scanned.body.state).toBe("cleared");
      const restored = await anonymous.request.get(state.downloadUrl);
      expect(restored.status()).toBe(200);
      expect(createHash("sha256").update(await restored.body()).digest("hex")).toBe(SHA_V3);
    } finally {
      await anonymous.close();
      await api(page, state.publisherNonce, "POST", "/test/scanner", { state: "up" });
    }
  });

  test("a revoked parent offering denies delivery immediately", async ({ browser }) => {
    expect(state.downloadUrl, "publish test must run first").not.toBe("");
    const anonymous = await anonymousContext(browser);
    try {
      // Moving the offering back to review revokes every resource under it.
      const demoted = await page.request.post(`/wp-json/wp/v2/offerings/${state.offeringPtId}`, {
        data: { status: "draft", meta: { _lps_state: "in_review" } },
        headers: { "X-WP-Nonce": state.publisherNonce },
      });
      expect(demoted.status(), JSON.stringify(await demoted.json())).toBe(200);
      expect((await anonymous.request.get(state.downloadUrl)).status()).toBe(404);

      const restored = await page.request.post(`/wp-json/wp/v2/offerings/${state.offeringPtId}`, {
        data: { status: "publish", meta: { _lps_state: "published" } },
        headers: { "X-WP-Nonce": state.publisherNonce },
      });
      expect(restored.status()).toBe(200);
      expect((await anonymous.request.get(state.downloadUrl)).status()).toBe(200);
    } finally {
      await anonymous.close();
    }
  });

  test("withdrawal is authoritative on the very next request", async ({ browser }) => {
    expect(state.downloadUrl, "publish test must run first").not.toBe("");
    const anonymous = await anonymousContext(browser);
    try {
      const withdrawn = await api(
        professorPage,
        state.professorNonce,
        "POST",
        `/teaching/resources/${state.resourceId}/withdraw`,
      );
      expect(withdrawn.status, JSON.stringify(withdrawn.body)).toBe(200);
      expect(withdrawn.body.release_state).toBe("withdrawn");
      expect(withdrawn.body.withdrawn_at).not.toBe("");
      expect((await anonymous.request.get(state.downloadUrl)).status()).toBe(404);
    } finally {
      await anonymous.close();
    }
  });

  test("out-of-scope and anonymous writes are denied before any mutation", async ({ browser }) => {
    expect(state.offeringOtherId, "graph test must run first").toBeGreaterThan(0);

    // The professor's grant covers only the first offering.
    const denied = await api(professorPage, state.professorNonce, "POST", "/teaching/resources", {
      title: `Proibido ${RUN}`,
      offering_id: state.offeringOtherId,
      meta: { _lps_resource_type: "document", _lps_resource_language: "pt-br" },
    });
    expect([401, 403]).toContain(denied.status);

    const deniedUpload = await upload(
      professorPage,
      state.professorNonce,
      state.offeringOtherId,
      `proibido-${RUN}.pdf`,
      PDF_V1,
    );
    expect([401, 403]).toContain(deniedUpload.status);

    // A resource on the ungranted offering is outside the professor's scope
    // for version selection, release and withdrawal alike.
    const other = await api(page, state.publisherNonce, "POST", "/teaching/resources", {
      title: `Alheio ${RUN}`,
      offering_id: state.offeringOtherId,
      version_id: state.version1Id,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    expect(other.status, JSON.stringify(other.body)).toBe(201);
    state.otherResourceId = other.body.id;
    for (const path of [
      `/teaching/resources/${state.otherResourceId}/version`,
      `/teaching/resources/${state.otherResourceId}/release`,
      `/teaching/resources/${state.otherResourceId}/withdraw`,
    ]) {
      const deniedWrite = await api(professorPage, state.professorNonce, "POST", path, {
        version_id: state.version2Id,
        state: "released",
      });
      expect([401, 403], path).toContain(deniedWrite.status);
    }

    const anonymous = await anonymousContext(browser);
    try {
      const response = await anonymous.request.post("/wp-json/lps/v1/teaching/resources", {
        data: { title: `anon-${RUN}`, offering_id: state.offeringPtId },
      });
      expect([401, 403]).toContain(response.status());
      const uploadAnon = await anonymous.request.post("/wp-json/lps/v1/teaching/resource-versions", {
        multipart: {
          file: { name: "anon.pdf", mimeType: "application/pdf", buffer: Buffer.from(PDF_V1) },
          offering_id: String(state.offeringPtId),
        },
      });
      expect([401, 403]).toContain(uploadAnon.status());
    } finally {
      await anonymous.close();
    }
  });

  test("the audit ledger records the explicit version and release decisions", async () => {
    expect(state.resourceId, "publish test must run first").toBeGreaterThan(0);

    const audit = await adminPage.request.get(`/wp-json/lps/v1/test/audit?post_id=${state.resourceId}`, {
      headers: { "X-WP-Nonce": state.adminNonce },
    });
    expect(audit.status()).toBe(200);
    const entries = await audit.json();
    const actions = entries.map((entry) => entry.action);
    // The v1→v2 selection is an audited edit carrying both version IDs.
    const edits = entries.filter(
      (entry) =>
        entry.action === "edit" &&
        entry.context_json.includes(state.version1Id) &&
        entry.context_json.includes(state.version2Id),
    );
    expect(edits.length, JSON.stringify(entries)).toBeGreaterThanOrEqual(1);
    // Release and withdrawal are audited publish/unpublish decisions.
    const releases = entries.filter((entry) => entry.action === "publish" && entry.context_json.includes('"release"'));
    expect(releases.length).toBeGreaterThanOrEqual(1);
    const withdrawals = entries.filter(
      (entry) => entry.action === "unpublish" && entry.context_json.includes('"withdraw"'),
    );
    expect(withdrawals.length).toBeGreaterThanOrEqual(1);
    expect(actions).not.toContain("delete");
  });
});

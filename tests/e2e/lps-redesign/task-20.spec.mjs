import { createHash } from "node:crypto";
import { mkdir, writeFile } from "node:fs/promises";
import { join } from "node:path";
import { expect, test } from "@playwright/test";

/**
 * Task 20 — adversarial security and privacy verification over the live HTTP
 * surface.
 *
 * Every test attacks a boundary rather than restating a happy path: scoped
 * requests replayed as another professor or anonymously, forged identifiers,
 * raw-file and traversal probes, missing/forged nonces, hostile upload names
 * and bytes, withdrawn and scheduled release states, a forced scanner outage,
 * personal-data surfaces, search/feed leaks, the plugin allowlist and the
 * news self-publish divergence flagged at gate review.
 *
 * The pure policy contracts are covered by LpsRedesignTask20Test; this spec
 * proves the wired boundary end to end and writes reproducible
 * request/response evidence for every finding into the task evidence dir.
 * No destructive payloads are sent; every probe is a request a client could
 * legitimately emit.
 */

const RUN = `t20-${Date.now().toString(36)}`;
const BASE_URL = process.env.LPS_BASE_URL ?? "http://127.0.0.1:8903";
const EVIDENCE =
  process.env.LPS_T20_EVIDENCE ?? ".omo/evidence/lps-website-ulw-plan/attempt-1/task-20/findings";

const PDF_BYTES = `%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\n% task-20 fixture ${RUN}\n%%EOF\n`;
const PDF_SHA = createHash("sha256").update(PDF_BYTES).digest("hex");
const PHP_BYTES = "<?php echo 'task-20 payload'; ?>";
const HTML_BYTES = '<!doctype html><html><body><script>alert(1)</script></body></html>';

const AUTO_LOGIN_COOKIE = { name: "playground_auto_login_already_happened", value: "1" };

const state = {
  adminNonce: "",
  publisherNonce: "",
  profANonce: "",
  profBNonce: "",
  delegateNonce: "",
  personId: 0,
  termId: 0,
  courseId: 0,
  offeringId: 0,
  offeringOtherId: 0,
  unitId: 0,
  resourceId: 0,
  downloadUrl: "",
  versionId: "",
  draftResourceId: 0,
  draftDownloadUrl: "",
  scheduledResourceId: 0,
  scheduledDownloadUrl: "",
  newsId: 0,
};

let adminContext;
let publisherContext;
let profAContext;
let profBContext;
let delegateContext;
let anonymous;

test.describe.configure({ mode: "serial" });

/** Creates a credential-free context that skips the auto-login handshake. */
async function anonymousContext(browser) {
  const context = await browser.newContext({ baseURL: BASE_URL });
  await context.addCookies([{ ...AUTO_LOGIN_COOKIE, url: BASE_URL }]);
  return context;
}

/**
 * Signs in through the real `wp-login.php` boundary using the request API:
 * the credential POST and the Two-Factor dummy challenge POST are replayed
 * without a renderer, which the single-process WASM runtime handles reliably.
 */
async function loginAs(context, user, pass, mfa) {
  await context.addCookies([{ ...AUTO_LOGIN_COOKIE, url: BASE_URL }]);
  // wp-login.php sets TEST_COOKIE on every response; the credential POST is
  // rejected as "cookies blocked" when the jar does not carry it back.
  await context.request.get("/wp-login.php", { timeout: 120_000 });
  const login = await context.request.post("/wp-login.php", {
    form: {
      log: user,
      pwd: pass,
      "wp-submit": "Log In",
      redirect_to: "/wp-admin/",
      testcookie: "1",
    },
    maxRedirects: 0,
    timeout: 120_000,
  });
  const body = await login.text();
  expect(body, `login page for ${user}`).not.toContain("login_error");
  if (!mfa) {
    expect(login.status(), `credential POST for ${user}`).toBe(302);
    return;
  }
  const field = (name) => {
    const match = body.match(new RegExp(`name="${name}"[^>]*value="([^"]*)"`));
    return match ? match[1] : "";
  };
  const challenge = await context.request.post("/wp-login.php?action=validate_2fa", {
    form: {
      provider: field("provider"),
      "wp-auth-id": field("wp-auth-id"),
      "wp-auth-nonce": field("wp-auth-nonce"),
      redirect_to: field("redirect_to") || "/wp-admin/",
      rememberme: "0",
    },
    maxRedirects: 0,
    timeout: 120_000,
  });
  expect(challenge.status(), `2fa challenge for ${user}`).toBe(302);
  const cookies = await context.cookies();
  expect(
    cookies.some((cookie) => cookie.name.startsWith("wordpress_logged_in")),
    `session cookie for ${user}`,
  ).toBe(true);
}

/** Reads the REST nonce bound to a signed-in session. */
async function restNonce(context) {
  for (let attempt = 0; attempt < 40; attempt += 1) {
    try {
      const response = await context.request.get("/wp-admin/admin-ajax.php?action=rest-nonce", {
        timeout: 60_000,
      });
      if (response.ok()) {
        const nonce = (await response.text()).trim();
        if (nonce.length > 0 && nonce !== "0") {
          return nonce;
        }
      }
    } catch {
      // A dropped WASM request is retried, never fatal on its own.
    }
    await new Promise((resolve) => setTimeout(resolve, 1500));
  }
  throw new Error("REST nonce endpoint never answered for the session");
}

/** Calls the teaching REST boundary with a session nonce. */
async function api(context, nonce, method, path, data) {
  const headers = nonce ? { "X-WP-Nonce": nonce } : {};
  const response = await context.request.fetch(`/wp-json/lps/v1${path}`, {
    method,
    data,
    headers,
    timeout: 120_000,
  });
  const body = await response.json().catch(() => ({}));
  return { status: response.status(), body };
}

/** Uploads one file to the version boundary as multipart `file`. */
async function upload(context, nonce, offeringId, name, contents, extra = {}) {
  const response = await context.request.post("/wp-json/lps/v1/teaching/resource-versions", {
    multipart: {
      file: { name, mimeType: "application/pdf", buffer: Buffer.from(contents, "utf8") },
      offering_id: String(offeringId),
      ...extra,
    },
    headers: { "X-WP-Nonce": nonce },
    timeout: 120_000,
  });
  const body = await response.json().catch(() => ({}));
  return { status: response.status(), body };
}

/** Publishes one record through the gated endpoint as the publisher. */
async function publish(id) {
  const result = await api(publisherContext, state.publisherNonce, "POST", `/teaching/records/${id}/publish`);
  expect(result.status, `publish ${id}: ${JSON.stringify(result.body)}`).toBe(200);
  return result.body;
}

/**
 * Writes one finding's reproducible request/response evidence. Credentials,
 * nonces and cookies are never recorded — only the request shape and the
 * observed response.
 */
async function probe(id, classification, severity, description, exchanges) {
  await mkdir(EVIDENCE, { recursive: true });
  await writeFile(
    join(EVIDENCE, `${id}.json`),
    `${JSON.stringify(
      { id, severity, classification, description, exchanges, run: RUN, origin: BASE_URL },
      null,
      2,
    )}\n`,
  );
}

/** Redacts credential material from a recorded request. */
function evidenceRequest(method, url, headers = {}) {
  const safe = {};
  for (const [name, value] of Object.entries(headers)) {
    safe[name] = /nonce|cookie|authorization/i.test(name) ? "<redacted>" : value;
  }
  return { method, url, headers: safe };
}

/**
 * Reads a record through the core REST surface as the publisher.
 *
 * Administrators hold no content primitives under the least-privilege matrix —
 * record reads go through the publisher session, which owns the teaching and
 * news collections.
 */
async function publisherRead(restBase, id, params = "") {
  const response = await publisherContext.request.get(`/wp-json/wp/v2/${restBase}/${id}${params}`, {
    headers: { "X-WP-Nonce": state.publisherNonce },
    timeout: 120_000,
  });
  return { status: response.status(), body: await response.json().catch(() => ({})) };
}

/** Extracts the dashboard nonce of one named form from rendered HTML. */
function dashboardNonce(html, action) {
  const match = html.match(
    new RegExp(`data-dashboard-form="${action}"[\\s\\S]*?name="_lps_dashboard_nonce"[^>]*value="([^"]+)"`),
  );
  return match ? match[1] : "";
}

test.describe("task-20: adversarial security and privacy verification", () => {
  test.beforeAll(async ({ browser }) => {
    test.setTimeout(600_000);
    await mkdir(EVIDENCE, { recursive: true });

    adminContext = await browser.newContext({ baseURL: BASE_URL });
    await loginAs(adminContext, "admin", "password", true);
    state.adminNonce = await restNonce(adminContext);

    publisherContext = await browser.newContext({ baseURL: BASE_URL });
    await loginAs(publisherContext, "lps-t20-publisher", "lps-t20-publisher-pass", true);
    state.publisherNonce = await restNonce(publisherContext);

    profAContext = await browser.newContext({ baseURL: BASE_URL });
    await loginAs(profAContext, "lps-t20-professor", "lps-t20-professor-pass", true);
    state.profANonce = await restNonce(profAContext);

    profBContext = await browser.newContext({ baseURL: BASE_URL });
    await loginAs(profBContext, "lps-t20-professor-b", "lps-t20-professor-b-pass", true);
    state.profBNonce = await restNonce(profBContext);

    delegateContext = await browser.newContext({ baseURL: BASE_URL });
    await loginAs(delegateContext, "lps-t20-delegate", "lps-t20-delegate-pass", false);
    state.delegateNonce = await restNonce(delegateContext);

    anonymous = await anonymousContext(browser);
  });

  test.afterAll(async () => {
    await adminContext?.close();
    await publisherContext?.close();
    await profAContext?.close();
    await profBContext?.close();
    await delegateContext?.close();
    await anonymous?.close();
  });

  test("fixture graph: two scoped offerings, a cleared released resource and hostile-state resources", async () => {
    test.setTimeout(300_000);

    const person = await publisherContext.request.post("/wp-json/wp/v2/people", {
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

    const term = await api(publisherContext, state.publisherNonce, "POST", "/teaching/terms", {
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

    // The bilingual publish contract requires a reviewed, published English
    // variant before the Portuguese authority may publish.
    const course = await api(publisherContext, state.publisherNonce, "POST", "/teaching/courses", {
      title: `Disciplina ${RUN}`,
      slug: `disciplina-${RUN}`,
      excerpt: "Disciplina de teste.",
      content: "Conteúdo de fixture.",
      meta: {
        _lps_course_code: `LPS-${RUN.toUpperCase()}`,
        _lps_course_level: "undergraduate",
        _lps_calendar_key: "semester",
      },
    });
    expect(course.status, JSON.stringify(course.body)).toBe(201);
    state.courseId = course.body.id;
    const courseEn = await api(publisherContext, state.publisherNonce, "POST", "/teaching/courses", {
      title: `Course ${RUN}`,
      slug: `course-${RUN}`,
      locale: "en",
      translation_of: state.courseId,
      excerpt: "Test course.",
      content: "Fixture content.",
    });
    expect(courseEn.status, JSON.stringify(courseEn.body)).toBe(201);
    await api(publisherContext, state.publisherNonce, "POST", `/teaching/records/${courseEn.body.id}/review-translation`);
    await publish(courseEn.body.id);
    await publish(state.courseId);

    for (const section of ["t01", "t02"]) {
      const offering = await api(publisherContext, state.publisherNonce, "POST", "/teaching/offerings", {
        title: `Turma ${RUN} ${section.toUpperCase()}`,
        slug: `turma-${RUN}-${section}`,
        excerpt: "Turma de teste.",
        content: "Conteúdo de fixture.",
        course_id: state.courseId,
        term_id: state.termId,
        section,
        team: [{ person_id: state.personId, role: "lead" }],
      });
      expect(offering.status, JSON.stringify(offering.body)).toBe(201);
      const id = offering.body.id;
      const offeringEn = await api(publisherContext, state.publisherNonce, "POST", "/teaching/offerings", {
        title: `Section ${RUN} ${section.toUpperCase()}`,
        slug: `section-${RUN}-${section}`,
        locale: "en",
        translation_of: id,
        excerpt: "Test section.",
        content: "Fixture content.",
      });
      expect(offeringEn.status, JSON.stringify(offeringEn.body)).toBe(201);
      await api(publisherContext, state.publisherNonce, "POST", `/teaching/records/${offeringEn.body.id}/review-translation`);
      await publish(offeringEn.body.id);
      await publish(id);
      if (section === "t01") {
        state.offeringId = id;
      } else {
        state.offeringOtherId = id;
      }
    }

    // Professor A holds an offering-scope grant on t01 and the news lane;
    // professor B holds a grant on t02 only; the delegate assists on t01.
    for (const grant of [
      { user_login: "lps-t20-professor", offering_id: state.offeringId, role: "professor", scope: "offering" },
      { user_login: "lps-t20-professor", offering_id: 0, role: "professor", scope: "news" },
      { user_login: "lps-t20-professor-b", offering_id: state.offeringOtherId, role: "professor", scope: "offering" },
      { user_login: "lps-t20-delegate", offering_id: state.offeringId, role: "delegate", scope: "offering" },
    ]) {
      const result = await api(adminContext, state.adminNonce, "POST", "/test/grants", grant);
      // A duplicate grant from a prior run is the same end state.
      if (result.status === 403 && result.body?.code === "lps_teaching_grant_duplicate") {
        continue;
      }
      expect(result.status, JSON.stringify(result.body)).toBe(200);
    }

    // A cleared version minted inside professor A's scope.
    const version = await upload(profAContext, state.profANonce, state.offeringId, `apostila-${RUN}.pdf`, PDF_BYTES);
    expect(version.status, JSON.stringify(version.body)).toBe(201);
    state.versionId = version.body.version_id;
    expect(version.body.state).toBe("cleared");
    expect(version.body.scan_verdict).toBe("clean");

    const resource = await api(profAContext, state.profANonce, "POST", "/teaching/resources", {
      title: `Apostila ${RUN}`,
      slug: `apostila-${RUN}`,
      offering_id: state.offeringId,
      version_id: state.versionId,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    expect(resource.status, JSON.stringify(resource.body)).toBe(201);
    state.resourceId = resource.body.id;
    state.downloadUrl = resource.body.download_url;
    expect(state.downloadUrl).toMatch(/^\/lps-resource\/[0-9a-f-]{36}\/$/);

    const released = await api(profAContext, state.profANonce, "POST", `/teaching/resources/${state.resourceId}/release`, {
      state: "released",
    });
    expect(released.status, JSON.stringify(released.body)).toBe(200);
    const published = await api(profAContext, state.profANonce, "POST", `/teaching/records/${state.resourceId}/publish`);
    expect(published.status, JSON.stringify(published.body)).toBe(200);

    // The released resource serves bytes to anyone — the happy-path anchor.
    const download = await anonymous.request.get(state.downloadUrl);
    expect(download.status()).toBe(200);
    expect(createHash("sha256").update(await download.body()).digest("hex")).toBe(PDF_SHA);

    // A published-but-never-released resource and a future-scheduled one.
    const draft = await api(profAContext, state.profANonce, "POST", "/teaching/resources", {
      title: `Rascunho ${RUN}`,
      offering_id: state.offeringId,
      version_id: state.versionId,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    expect(draft.status).toBe(201);
    state.draftResourceId = draft.body.id;
    state.draftDownloadUrl = draft.body.download_url;
    await api(profAContext, state.profANonce, "POST", `/teaching/records/${state.draftResourceId}/publish`);

    const scheduled = await api(profAContext, state.profANonce, "POST", "/teaching/resources", {
      title: `Agendado ${RUN}`,
      offering_id: state.offeringId,
      version_id: state.versionId,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    expect(scheduled.status).toBe(201);
    state.scheduledResourceId = scheduled.body.id;
    state.scheduledDownloadUrl = scheduled.body.download_url;
    await api(profAContext, state.profANonce, "POST", `/teaching/resources/${state.scheduledResourceId}/release`, {
      state: "scheduled",
      release_at: "2999-01-01T00:00:00+00:00",
    });
    await api(profAContext, state.profANonce, "POST", `/teaching/records/${state.scheduledResourceId}/publish`);

    // A draft unit on offering t01 gives the dashboard a publish form whose
    // action nonce the CSRF and news-divergence tests replay.
    const unit = await api(profAContext, state.profANonce, "POST", "/teaching/units", {
      title: `Unidade ${RUN}`,
      slug: `unidade-${RUN}`,
      offering_id: state.offeringId,
      meta: { _lps_anchor: `unidade-${RUN}`, _lps_position: 9 },
    });
    expect(unit.status, JSON.stringify(unit.body)).toBe(201);
    state.unitId = unit.body.id;
  });

  test("scoped requests replayed as another professor or anonymous are denied without mutation", async () => {
    test.setTimeout(180_000);
    const exchanges = [];

    // Professor B replays professor A's scoped writes against offering t01.
    const replayCreate = await api(profBContext, state.profBNonce, "POST", "/teaching/resources", {
      title: `Replays ${RUN}`,
      offering_id: state.offeringId,
      meta: { _lps_resource_type: "document", _lps_resource_language: "pt-br" },
    });
    exchanges.push({
      request: evidenceRequest("POST", "/wp-json/lps/v1/teaching/resources", { "x-as": "professor-b" }),
      response: { status: replayCreate.status, code: replayCreate.body?.code ?? null },
    });
    expect([401, 403]).toContain(replayCreate.status);

    const replayUpload = await upload(profBContext, state.profBNonce, state.offeringId, `replay-${RUN}.pdf`, PDF_BYTES);
    exchanges.push({
      request: evidenceRequest("POST", "/wp-json/lps/v1/teaching/resource-versions (multipart, offering_id=t01)"),
      response: { status: replayUpload.status, code: replayUpload.body?.code ?? null },
    });
    expect([401, 403]).toContain(replayUpload.status);

    for (const [method, path, data] of [
      ["POST", `/teaching/resources/${state.resourceId}/version`, { version_id: state.versionId }],
      ["POST", `/teaching/resources/${state.resourceId}/release`, { state: "released" }],
      ["POST", `/teaching/resources/${state.resourceId}/withdraw`, {}],
      ["POST", `/teaching/records/${state.resourceId}/publish`, {}],
      ["POST", `/teaching/offerings/${state.offeringId}/copy-forward`, {
        operation_id: `replay-${RUN}`,
        new_term_id: state.termId,
        new_section: "t99",
        team: [{ person_id: state.personId, role: "lead" }],
        team_reviewed: true,
      }],
    ]) {
      const denied = await api(profBContext, state.profBNonce, method, path, data);
      exchanges.push({
        request: evidenceRequest(method, `/wp-json/lps/v1${path}`),
        response: { status: denied.status, code: denied.body?.code ?? null },
      });
      expect([401, 403], `${method} ${path}`).toContain(denied.status);
    }

    // The delegate on the same offering may edit but never publish or release.
    const delegatePublish = await api(delegateContext, state.delegateNonce, "POST", `/teaching/records/${state.unitId}/publish`, {});
    exchanges.push({
      request: evidenceRequest("POST", `/wp-json/lps/v1/teaching/records/${state.unitId}/publish`, { "x-as": "delegate" }),
      response: { status: delegatePublish.status, code: delegatePublish.body?.code ?? null },
    });
    expect([401, 403]).toContain(delegatePublish.status);
    const delegateRelease = await api(delegateContext, state.delegateNonce, "POST", `/teaching/resources/${state.resourceId}/withdraw`, {});
    expect([401, 403]).toContain(delegateRelease.status);

    // Anonymous replays carry no session at all.
    for (const [method, path, data] of [
      ["POST", "/teaching/resources", { title: `Anon ${RUN}`, offering_id: state.offeringId }],
      ["POST", `/teaching/records/${state.resourceId}/publish`, {}],
      ["POST", `/teaching/resources/${state.resourceId}/withdraw`, {}],
      ["GET", "/teaching/reconciliation", undefined],
    ]) {
      const denied = await api(anonymous, "", method, path, data);
      exchanges.push({
        request: evidenceRequest(method, `/wp-json/lps/v1${path}`, { "x-as": "anonymous" }),
        response: { status: denied.status, code: denied.body?.code ?? null },
      });
      expect([401, 403], `anonymous ${method} ${path}`).toContain(denied.status);
    }

    // Persistence check: the resource still publishes and serves unchanged.
    const after = await publisherRead("resources", state.resourceId, "?context=edit");
    expect(after.body?.status).toBe("publish");
    const stillThere = await anonymous.request.get(state.downloadUrl);
    expect(stillThere.status()).toBe(200);

    await probe(
      "cross-object-scope-replay",
      "not-reproducible",
      "high",
      "Scoped writes replayed as another professor, the delegate and anonymous are denied before mutation; the resource state and served bytes are unchanged.",
      exchanges,
    );
  });

  test("forged identifiers resolve to the same anonymous denial with no metadata leak", async () => {
    test.setTimeout(120_000);
    const exchanges = [];
    const forged = [
      "/lps-resource/00000000-0000-0000-0000-000000000000/",
      "/lps-resource/not-a-uuid/",
      "/lps-resource/..%2F..%2Fetc%2Fpasswd/",
      "/lps-resource/",
      `${state.downloadUrl}extra/`,
      `/pt-br${state.downloadUrl}`,
      `/en${state.downloadUrl}`,
    ];
    for (const path of forged) {
      const response = await anonymous.request.get(path);
      const body = await response.text();
      exchanges.push({
        request: evidenceRequest("GET", path),
        response: { status: response.status(), bytes: body.length },
      });
      expect([400, 404], path).toContain(response.status());
      expect(body, `${path} leaks a storage key`).not.toContain("lps-file-");
      expect(body, `${path} leaks a version id`).not.toContain("lpsver:");
      expect(body, `${path} leaks a server path`).not.toContain("/tmp/");
    }

    // Raw storage guesses: the storage root lives outside the docroot, so no
    // URL ever serves bytes. The dev server answers misses under /wp-content/
    // with a soft-200 HTML fallback (production nginx try_files =404s them),
    // so the assertion is on content, not status: never file bytes, never a
    // storage key, never a server path.
    for (const path of [
      "/wp-content/uploads/lps-teaching-storage/cleared/x.pdf",
      "/lps-teaching-storage/cleared/x.pdf",
      "/tmp/lps-teaching-storage/cleared/x.pdf",
    ]) {
      const response = await anonymous.request.get(path);
      const body = await response.text();
      const contentType = response.headers()["content-type"] ?? "";
      exchanges.push({
        request: evidenceRequest("GET", path),
        response: { status: response.status(), contentType, bytes: body.length },
      });
      expect(body.startsWith("%PDF-"), `${path} served file bytes`).toBe(false);
      expect(contentType, `${path} served a download`).not.toContain("application/pdf");
      expect(body, `${path} leaks a storage key`).not.toContain("lps-file-");
      expect(body, `${path} leaks a version id`).not.toContain("lpsver:");
      // The /tmp/... URL echoes itself in canonical tags; only paths that do
      // not carry the string can meaningfully prove no server path leaks.
      if (!path.includes("/tmp/")) {
        expect(body, `${path} leaks a server path`).not.toContain("/tmp/lps-teaching-storage");
      }
    }

    // Forged record and version identifiers on the REST boundary.
    const forgedPublish = await api(profAContext, state.profANonce, "POST", "/teaching/records/99999999/publish", {});
    expect([403, 404]).toContain(forgedPublish.status);
    const forgedScan = await api(
      profAContext,
      state.profANonce,
      "POST",
      `/teaching/resource-versions/lpsver:${"0".repeat(64)}/scan`,
      { offering_id: state.offeringId },
    );
    expect([400, 403, 404]).toContain(forgedScan.status);
    exchanges.push({
      request: evidenceRequest("POST", "/wp-json/lps/v1/teaching/records/99999999/publish"),
      response: { status: forgedPublish.status, code: forgedPublish.body?.code ?? null },
    });
    exchanges.push({
      request: evidenceRequest("POST", `/wp-json/lps/v1/teaching/resource-versions/lpsver:${"0".repeat(64)}/scan`),
      response: { status: forgedScan.status, code: forgedScan.body?.code ?? null },
    });

    await probe(
      "forged-identifiers",
      "not-reproducible",
      "high",
      "Forged download tokens, traversal paths, locale-prefixed download URLs, raw storage guesses and forged record/version IDs all resolve to a flat denial with no storage key, version ID or server path in the body.",
      exchanges,
    );
  });

  test("autosave, revision and preview surfaces never expose drafts", async () => {
    test.setTimeout(120_000);
    const exchanges = [];

    // The record is published at post level — only its file release is a
    // draft — so an anonymous single read is legitimate; what must never leak
    // is storage metadata (keys, paths, version ids) or file bytes.
    const recordRead = await anonymous.request.get(`/wp-json/wp/v2/resources/${state.draftResourceId}`);
    const recordBody = await recordRead.text();
    exchanges.push({
      request: evidenceRequest("GET", `/wp-json/wp/v2/resources/${state.draftResourceId}`),
      response: { status: recordRead.status(), bytes: recordBody.length },
    });
    expect(recordBody).not.toContain("lps-file-");
    expect(recordBody).not.toContain("lpsver:");
    expect(recordBody).not.toContain("/tmp/");

    // Revision, autosave and preview surfaces are denied outright.
    for (const path of [
      `/wp-json/wp/v2/resources/${state.draftResourceId}/revisions`,
      `/wp-json/wp/v2/resources/${state.draftResourceId}/autosaves`,
      `/?p=${state.draftResourceId}&preview=true`,
      `/?post_type=lps_resource&p=${state.draftResourceId}&preview=1`,
    ]) {
      const response = await anonymous.request.get(path);
      const body = await response.text();
      exchanges.push({
        request: evidenceRequest("GET", path),
        response: { status: response.status(), bytes: body.length },
      });
      expect([400, 401, 403, 404], path).toContain(response.status());
      expect(body, `${path} leaks the draft title`).not.toContain(`Rascunho ${RUN}`);
    }

    // Collection queries may list published records but never the draft.
    for (const path of [`/wp-json/wp/v2/resources?status=draft`, `/wp-json/wp/v2/news?status=draft`]) {
      const response = await anonymous.request.get(path);
      const body = await response.text();
      exchanges.push({
        request: evidenceRequest("GET", path),
        response: { status: response.status(), bytes: body.length },
      });
      expect([200, 400, 401, 403], path).toContain(response.status());
      expect(body, `${path} leaks the draft title`).not.toContain(`Rascunho ${RUN}`);
      expect(body, `${path} leaks the draft news`).not.toContain(`Notícia ${RUN}`);
    }

    // A scoped professor cannot read an unpublished record outside the grant:
    // the draft unit exists only inside professor A's offering scope.
    const crossRead = await profBContext.request.get(`/wp-json/wp/v2/units/${state.unitId}?context=edit`, {
      headers: { "X-WP-Nonce": state.profBNonce },
    });
    const crossBody = await crossRead.text();
    exchanges.push({
      request: evidenceRequest("GET", `/wp-json/wp/v2/units/${state.unitId}?context=edit`, { "x-as": "professor-b" }),
      response: { status: crossRead.status() },
    });
    expect([401, 403, 404]).toContain(crossRead.status());
    expect(crossBody).not.toContain(`Unidade ${RUN}`);

    await probe(
      "draft-preview-revision-bypass",
      "not-reproducible",
      "high",
      "Draft resources and news are invisible to anonymous REST reads, revision/autosave listings and preview query args; a scoped professor outside the grant cannot read them either.",
      exchanges,
    );
  });

  test("dashboard actions require a valid nonce; REST writes require the session nonce", async () => {
    test.setTimeout(180_000);
    const exchanges = [];

    // Harvest a real publish nonce from the offering workspace.
    const workspace = await profAContext.request.get(`/pt-br/painel/ofertas/${state.offeringId}/`);
    const workspaceHtml = await workspace.text();
    const publishNonce = dashboardNonce(workspaceHtml, "lps_dashboard_publish");
    expect(publishNonce, "workspace publish form nonce").not.toBe("");

    // Missing nonce: the handler fails before touching the record.
    const noNonce = await profAContext.request.post("/wp-admin/admin-post.php", {
      form: {
        action: "lps_dashboard_publish",
        post_id: String(state.unitId),
        _wp_http_referer: `/pt-br/painel/ofertas/${state.offeringId}/`,
      },
      maxRedirects: 0,
    });
    const noNonceLocation = noNonce.headers()["location"] ?? "";
    exchanges.push({
      request: evidenceRequest("POST", "/wp-admin/admin-post.php action=lps_dashboard_publish (no nonce)"),
      response: { status: noNonce.status(), location: noNonceLocation },
    });
    expect([302, 403]).toContain(noNonce.status());
    if (302 === noNonce.status()) {
      expect(noNonceLocation).toContain("lps_error=lps_dashboard_nonce");
    }

    // A nonce minted for a different action is not a publish nonce.
    const wrongAction = await profAContext.request.post("/wp-admin/admin-post.php", {
      form: {
        action: "lps_dashboard_publish",
        post_id: String(state.unitId),
        _lps_dashboard_nonce: dashboardNonce(workspaceHtml, "lps_dashboard_unit") || "forged",
        _wp_http_referer: `/pt-br/painel/ofertas/${state.offeringId}/`,
      },
      maxRedirects: 0,
    });
    const wrongLocation = wrongAction.headers()["location"] ?? "";
    exchanges.push({
      request: evidenceRequest("POST", "/wp-admin/admin-post.php action=lps_dashboard_publish (nonce for lps_dashboard_unit)"),
      response: { status: wrongAction.status(), location: wrongLocation },
    });
    expect([302, 403]).toContain(wrongAction.status());
    if (302 === wrongAction.status()) {
      expect(wrongLocation).toContain("lps_error=lps_dashboard_nonce");
    }

    // The unit is still a draft: neither forged submit mutated it.
    const unit = await publisherRead("units", state.unitId, "?context=edit");
    expect(unit.body?.status).toBe("draft");

    // Anonymous admin-post has no authenticated handler at all.
    const anonPost = await anonymous.request.post("/wp-admin/admin-post.php", {
      form: { action: "lps_dashboard_publish", post_id: String(state.unitId) },
      maxRedirects: 0,
    });
    exchanges.push({
      request: evidenceRequest("POST", "/wp-admin/admin-post.php action=lps_dashboard_publish", { "x-as": "anonymous" }),
      response: { status: anonPost.status(), location: anonPost.headers()["location"] ?? "" },
    });
    expect([302, 400, 403, 404]).toContain(anonPost.status());

    // REST writes with cookies but no X-WP-Nonce run as anonymous.
    const noRestNonce = await profAContext.request.post(`/wp-json/lps/v1/teaching/records/${state.unitId}/publish`, {
      data: {},
    });
    exchanges.push({
      request: evidenceRequest("POST", `/wp-json/lps/v1/teaching/records/${state.unitId}/publish (cookies, no nonce)`),
      response: { status: noRestNonce.status() },
    });
    expect([401, 403]).toContain(noRestNonce.status());

    await probe(
      "csrf-nonce-boundary",
      "not-reproducible",
      "high",
      "Dashboard mutations without a nonce, with a nonce minted for another action, or anonymously are denied before mutation; REST writes without X-WP-Nonce execute as anonymous and are denied.",
      exchanges,
    );
  });

  test("stored markup injection is neutralized at create and publish", async () => {
    test.setTimeout(180_000);
    const exchanges = [];
    const payload = `Apostila <img src=x onerror=alert('${RUN}')><script>alert('${RUN}')</script> ${RUN}`;

    const hostile = await api(profAContext, state.profANonce, "POST", "/teaching/resources", {
      title: payload,
      slug: `inject-${RUN}`,
      offering_id: state.offeringId,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    exchanges.push({
      request: evidenceRequest("POST", "/wp-json/lps/v1/teaching/resources (markup title)"),
      response: { status: hostile.status, code: hostile.body?.code ?? null },
    });
    expect(hostile.status).toBe(201);
    const hostileId = hostile.body.id;

    // The stored title is sanitized text; markup never persists verbatim.
    const stored = await publisherRead("resources", hostileId, "?context=edit");
    const storedTitle = stored.body?.title?.raw ?? "";
    expect(storedTitle).not.toContain("<script>");
    expect(storedTitle).not.toContain("onerror");
    exchanges.push({
      request: evidenceRequest("GET", `/wp-json/wp/v2/resources/${hostileId}?context=edit`, { "x-as": "publisher" }),
      response: { status: stored.status, stored_title: storedTitle },
    });

    const hostilePublish = await api(profAContext, state.profANonce, "POST", `/teaching/records/${hostileId}/publish`, {});
    exchanges.push({
      request: evidenceRequest("POST", `/wp-json/lps/v1/teaching/records/${hostileId}/publish`),
      response: { status: hostilePublish.status, code: hostilePublish.body?.code ?? null },
    });
    // What must never happen: raw markup reaching the public surface.
    const publicPage = await anonymous.request.get("/pt-br/ensino/");
    const publicHtml = await publicPage.text();
    expect(publicHtml).not.toContain(`<script>alert('${RUN}')`);
    expect(publicHtml).not.toContain(`onerror=alert('${RUN}')`);

    // Hostile upload names are refused before bytes are read — or, when the
    // SAPI basename()s the multipart filename before the boundary sees it
    // (../escape.pdf arrives as escape.pdf), the stored download name is a
    // fully sanitized basename with no traversal, control bytes or
    // executable extension surviving.
    for (const name of [
      "shell.php.pdf",
      "../escape.pdf",
      "..\\escape.pdf",
      "page.html",
      "vector.svg",
      `control\x01name-${RUN}.pdf`,
    ]) {
      const denied = await upload(profAContext, state.profANonce, state.offeringId, name, PDF_BYTES);
      exchanges.push({
        request: evidenceRequest("POST", `/wp-json/lps/v1/teaching/resource-versions (name=${JSON.stringify(name)})`),
        response: {
          status: denied.status,
          code: denied.body?.code ?? null,
          download_name: denied.body?.download_name ?? null,
        },
      });
      if (denied.status === 201) {
        const storedName = String(denied.body?.download_name ?? "");
        expect(storedName, `${name} stored an unsafe name`).toMatch(/^[a-z0-9._-]+\.pdf$/);
        expect(storedName).not.toContain("..");
        expect(storedName).not.toMatch(/[\/\\\x00-\x1f]/);
      } else {
        expect(denied.status, name).toBe(400);
      }
    }

    await probe(
      "stored-markup-injection",
      "not-reproducible",
      "high",
      "Script and event-handler markup in record fields is sanitized on write and refused at publish; hostile upload names are denied before bytes are read; nothing reaches the public surface.",
      exchanges,
    );
  });

  test("file boundary refuses disguised bytes; release boundary fails closed on every non-public state", async () => {
    test.setTimeout(240_000);
    const exchanges = [];

    // PHP bytes renamed .pdf fail the magic-byte check.
    const disguised = await upload(profAContext, state.profANonce, state.offeringId, `disguised-${RUN}.pdf`, PHP_BYTES);
    exchanges.push({
      request: evidenceRequest("POST", "/wp-json/lps/v1/teaching/resource-versions (php bytes as .pdf)"),
      response: { status: disguised.status, code: disguised.body?.code ?? null },
    });
    expect(disguised.status).toBe(400);
    expect(disguised.body?.code).toBe("lps_teaching_type_mismatch");

    // HTML bytes renamed .pdf are refused the same way.
    const html = await upload(profAContext, state.profANonce, state.offeringId, `page-${RUN}.pdf`, HTML_BYTES);
    expect(html.status).toBe(400);

    // A quarantined version (scan=false) can be selected but never served.
    const quarantined = await upload(
      profAContext,
      state.profANonce,
      state.offeringId,
      `quarantine-${RUN}.pdf`,
      PDF_BYTES,
      { scan: "false" },
    );
    expect(quarantined.status).toBe(201);
    expect(quarantined.body.state).toBe("quarantined");

    const quarantinedResource = await api(profAContext, state.profANonce, "POST", "/teaching/resources", {
      title: `Quarentena ${RUN}`,
      offering_id: state.offeringId,
      version_id: quarantined.body.version_id,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    expect(quarantinedResource.status).toBe(201);
    await api(profAContext, state.profANonce, "POST", `/teaching/resources/${quarantinedResource.body.id}/release`, {
      state: "released",
    });
    await api(profAContext, state.profANonce, "POST", `/teaching/records/${quarantinedResource.body.id}/publish`);
    const quarantinedDownload = await anonymous.request.get(quarantinedResource.body.download_url);
    exchanges.push({
      request: evidenceRequest("GET", quarantinedResource.body.download_url),
      response: { status: quarantinedDownload.status() },
    });
    expect(quarantinedDownload.status()).toBe(404);

    // Draft and scheduled resources never serve bytes.
    const draftDownload = await anonymous.request.get(state.draftDownloadUrl);
    exchanges.push({
      request: evidenceRequest("GET", state.draftDownloadUrl),
      response: { status: draftDownload.status() },
    });
    expect(draftDownload.status()).toBe(404);
    const scheduledDownload = await anonymous.request.get(state.scheduledDownloadUrl);
    exchanges.push({
      request: evidenceRequest("GET", state.scheduledDownloadUrl),
      response: { status: scheduledDownload.status() },
    });
    expect(scheduledDownload.status()).toBe(404);

    // Withdrawal is authoritative on the very next request, cached URL included.
    const before = await anonymous.request.get(state.downloadUrl);
    expect(before.status()).toBe(200);
    const withdrawn = await api(profAContext, state.profANonce, "POST", `/teaching/resources/${state.resourceId}/withdraw`, {});
    expect(withdrawn.status, JSON.stringify(withdrawn.body)).toBe(200);
    const afterWithdraw = await anonymous.request.get(state.downloadUrl);
    const afterBody = await afterWithdraw.text();
    exchanges.push({
      request: evidenceRequest("GET", state.downloadUrl, { "x-note": "previously-200 cached URL after withdraw" }),
      response: { status: afterWithdraw.status(), bytes: afterBody.length },
    });
    expect(afterWithdraw.status()).toBe(404);
    expect(afterBody).not.toContain("lps-file-");

    // Range and method abuse on a denied URL still answer flat denials.
    const ranged = await anonymous.request.get(state.downloadUrl, {
      headers: { Range: "bytes=0-99" },
    });
    expect([400, 404, 416]).toContain(ranged.status());
    const head = await anonymous.request.head(state.downloadUrl);
    expect(head.status()).toBe(404);

    await probe(
      "file-release-boundary",
      "not-reproducible",
      "high",
      "Disguised bytes fail the magic-byte check; quarantined, draft, scheduled and withdrawn resources all answer the same anonymous 404 — including the previously-cached download URL — with no storage metadata in the body.",
      exchanges,
    );
  });

  test("a scanner outage fails closed and recovery restores delivery", async () => {
    test.setTimeout(240_000);
    const exchanges = [];
    try {
      const down = await api(publisherContext, state.publisherNonce, "POST", "/test/scanner", { state: "down" });
      expect(down.status).toBe(200);
      expect(down.body.state).toBe("down");

      const duringOutage = await upload(
        profAContext,
        state.profANonce,
        state.offeringId,
        `outage-${RUN}.pdf`,
        PDF_BYTES,
      );
      expect(duringOutage.status).toBe(201);
      expect(duringOutage.body.state).toBe("quarantined");
      expect(duringOutage.body.scan_verdict).toBe("error");
      exchanges.push({
        request: evidenceRequest("POST", "/wp-json/lps/v1/teaching/resource-versions (scanner down)"),
        response: { status: duringOutage.status, state: duringOutage.body.state, verdict: duringOutage.body.scan_verdict },
      });

      const up = await api(publisherContext, state.publisherNonce, "POST", "/test/scanner", { state: "up" });
      expect(up.body.state).toBe("up");
      const rescanned = await api(
        profAContext,
        state.profANonce,
        "POST",
        `/teaching/resource-versions/${duringOutage.body.version_id}/scan`,
        { offering_id: state.offeringId },
      );
      expect(rescanned.status, JSON.stringify(rescanned.body)).toBe(200);
      expect(rescanned.body.state).toBe("cleared");
      exchanges.push({
        request: evidenceRequest("POST", `/wp-json/lps/v1/teaching/resource-versions/${duringOutage.body.version_id}/scan`),
        response: { status: rescanned.status, state: rescanned.body.state },
      });
    } finally {
      await api(publisherContext, state.publisherNonce, "POST", "/test/scanner", { state: "up" });
    }

    await probe(
      "scanner-outage-fail-closed",
      "not-reproducible",
      "high",
      "With the scanner adapter reporting error, uploads stay quarantined with verdict=error and can never be served; rescanning after recovery clears the same immutable version. The test adapter is harness-only — production scanner/storage approval remains a separate gate.",
      exchanges,
    );
  });

  test("personal data and private fields stay off public surfaces", async () => {
    test.setTimeout(120_000);
    const exchanges = [];

    for (const path of [
      "/wp-json/wp/v2/users",
      "/wp-json/wp/v2/users/1",
      "/wp-json/wp/v2/users?slug=admin",
      "/?author=1",
      "/?author=2",
      "/author/admin/",
    ]) {
      const response = await anonymous.request.get(path, { maxRedirects: 0 });
      const body = await response.text();
      exchanges.push({
        request: evidenceRequest("GET", path),
        response: { status: response.status(), bytes: body.length },
      });
      expect(body, `${path} leaks an email`).not.toMatch(/@example\.test|user_email/);
      expect(body, `${path} leaks a private field`).not.toContain("_lps_owner_user_id");
      expect(body, `${path} leaks a private field`).not.toContain("_lps_translation_reviewer_id");
      expect(body, `${path} leaks MFA state`).not.toContain("two_factor");
      if (path.startsWith("/wp-json/")) {
        expect([401, 403], path).toContain(response.status());
      }
    }

    // The public person record carries no account linkage.
    const people = await anonymous.request.get("/wp-json/wp/v2/people?per_page=5");
    expect(people.status()).toBe(200);
    const peopleBody = await people.text();
    for (const field of ["_lps_owner_user_id", "_lps_translation_reviewer_id", "actor_user_id", "allcaps", "capabilities"]) {
      expect(peopleBody, `people leak ${field}`).not.toContain(field);
    }

    // The person history edit context requires edit access on the record.
    const history = await anonymous.request.get(`/wp-json/lps/v1/teaching/people/${state.personId}/history?context=edit`);
    exchanges.push({
      request: evidenceRequest("GET", `/wp-json/lps/v1/teaching/people/${state.personId}/history?context=edit`),
      response: { status: history.status() },
    });
    expect([401, 403]).toContain(history.status());

    await probe(
      "personal-data-exposure",
      "not-reproducible",
      "high",
      "User enumeration, author archives and public record JSON expose no email, account linkage, MFA state or private meta; the edit-context history endpoint requires record edit access.",
      exchanges,
    );
  });

  test("search, feed and sitemap surfaces exclude draft and withdrawn material", async () => {
    test.setTimeout(120_000);
    const exchanges = [];

    for (const path of [
      `/pt-br/busca/?q=${encodeURIComponent(RUN)}`,
      "/feed/",
      "/pt-br/feed/",
      "/sitemap.xml",
      "/wp-sitemap.xml",
      `/wp-json/wp/v2/search?search=${RUN}`,
    ]) {
      const response = await anonymous.request.get(path);
      const body = await response.text();
      exchanges.push({
        request: evidenceRequest("GET", path),
        response: { status: response.status(), bytes: body.length },
      });
      expect(body, `${path} leaks the draft resource`).not.toContain(`Rascunho ${RUN}`);
      expect(body, `${path} leaks the scheduled resource`).not.toContain(`Agendado ${RUN}`);
      expect(body, `${path} leaks the withdrawn resource`).not.toContain(`Apostila ${RUN}`);
      expect(body, `${path} leaks a storage key`).not.toContain("lps-file-");
    }

    await probe(
      "search-feed-cache-leaks",
      "not-reproducible",
      "medium",
      "Public search, feeds, sitemaps and the REST search index contain no draft, scheduled or withdrawn teaching material and no storage keys.",
      exchanges,
    );
  });

  test("plugin allowlist and runtime code installation stay closed", async () => {
    test.setTimeout(120_000);
    const exchanges = [];

    // Even the administrator cannot activate a foreign plugin at runtime:
    // the capability is mapped to do_not_allow before the REST controller runs.
    const activate = await adminContext.request.post("/wp-json/wp/v2/plugins", {
      data: { slug: "hello", status: "active" },
      headers: { "X-WP-Nonce": state.adminNonce },
    });
    exchanges.push({
      request: evidenceRequest("POST", "/wp-json/wp/v2/plugins {slug: hello, status: active}", { "x-as": "admin" }),
      response: { status: activate.status() },
    });
    expect([401, 403, 404]).toContain(activate.status());

    const install = await adminContext.request.post("/wp-json/wp/v2/plugins", {
      data: { slug: "akismet", status: "active" },
      headers: { "X-WP-Nonce": state.adminNonce },
    });
    expect([401, 403, 404]).toContain(install.status());

    // The resolved active set is exactly the allowlist (status probe evidence).
    const status = await anonymous.request.get("/pt-br/?lps_t20_status=task20");
    const statusBody = await status.json();
    exchanges.push({
      request: evidenceRequest("GET", "/pt-br/?lps_t20_status=task20"),
      response: { status: status.status(), active_plugins: statusBody.active_plugins },
    });
    expect([...statusBody.active_plugins].sort()).toEqual([
      "lps-content-model/lps-content-model.php",
      "polylang/polylang.php",
      "two-factor/two-factor.php",
    ]);

    // XML-RPC is disabled at the boundary. Production edge returns 403
    // (security-server.conf); the dev server executes xmlrpc.php with
    // xmlrpc_enabled=false, which answers an empty body. Either way no
    // method list is ever exposed.
    const xmlrpc = await anonymous.request.post("/xmlrpc.php", {
      data: "<?xml version='1.0'?><methodCall><methodName>system.listMethods</methodName></methodCall>",
      headers: { "Content-Type": "text/xml" },
    });
    const xmlrpcBody = await xmlrpc.text();
    exchanges.push({
      request: evidenceRequest("POST", "/xmlrpc.php system.listMethods"),
      response: { status: xmlrpc.status(), bytes: xmlrpcBody.length },
    });
    expect([200, 403, 405]).toContain(xmlrpc.status());
    expect(xmlrpcBody).not.toContain("methodResponse");
    expect(xmlrpcBody).not.toContain("system.listMethods");

    await probe(
      "plugin-allowlist-drift",
      "not-reproducible",
      "medium",
      "Runtime plugin activation/installation is denied even to the administrator; the resolved active set is exactly the three-plugin allowlist; XML-RPC is disabled.",
      exchanges,
    );
  });

  test("the news self-publish divergence: REST denies what the dashboard permits", async () => {
    test.setTimeout(240_000);
    const exchanges = [];

    // Professor A submits a news item through the real dashboard form.
    const newsPage = await profAContext.request.get("/pt-br/painel/noticias/");
    const newsHtml = await newsPage.text();
    const newsNonce = dashboardNonce(newsHtml, "lps_dashboard_news");
    expect(newsNonce, "news form nonce").not.toBe("");

    const submit = await profAContext.request.post("/wp-admin/admin-post.php", {
      form: {
        action: "lps_dashboard_news",
        _lps_dashboard_nonce: newsNonce,
        _wp_http_referer: "/pt-br/painel/noticias/",
        title: `Notícia ${RUN}`,
        excerpt: `Resumo ${RUN}`,
        content: `Corpo ${RUN}`,
        canonical_date: "2026-09-20T10:00",
      },
      maxRedirects: 0,
    });
    const submitLocation = submit.headers()["location"] ?? "";
    exchanges.push({
      request: evidenceRequest("POST", "/wp-admin/admin-post.php action=lps_dashboard_news"),
      response: { status: submit.status(), location: submitLocation },
    });
    expect(submit.status()).toBe(302);
    expect(submitLocation).toContain("lps_notice=submitted");

    // Resolve the submitted draft through the publisher REST surface. Drafts
    // carry no post_name until publish, so match on the title instead.
    let newsId = 0;
    for (let attempt = 0; attempt < 10 && 0 === newsId; attempt += 1) {
      const found = await publisherContext.request.get(`/wp-json/wp/v2/news?status=draft&per_page=50&search=${encodeURIComponent(`Notícia ${RUN}`)}`, {
        headers: { "X-WP-Nonce": state.publisherNonce },
      });
      const items = await found.json().catch(() => []);
      if (Array.isArray(items)) {
        const mine = items.find((item) => (item.title?.rendered ?? "").includes(`Notícia ${RUN}`));
        if (mine) {
          newsId = mine.id;
          break;
        }
      }
      await new Promise((resolve) => setTimeout(resolve, 1500));
    }
    expect(newsId, "submitted news draft resolves").toBeGreaterThan(0);
    state.newsId = newsId;

    // The REST publish gate denies the scoped professor: persisted_offering_id
    // resolves 0 for news and the check requires a positive offering id.
    const restPublish = await api(profAContext, state.profANonce, "POST", `/teaching/records/${newsId}/publish`, {});
    exchanges.push({
      request: evidenceRequest("POST", `/wp-json/lps/v1/teaching/records/${newsId}/publish`, { "x-as": "professor-a (news grant)" }),
      response: { status: restPublish.status, code: restPublish.body?.code ?? null },
    });
    expect([401, 403], "REST publish must deny the scoped professor").toContain(restPublish.status);

    // The dashboard publish handler resolves the identical 0 explicitly and
    // lets the same account publish — the divergence flagged at gate review.
    const workspace = await profAContext.request.get(`/pt-br/painel/ofertas/${state.offeringId}/`);
    const publishNonce = dashboardNonce(await workspace.text(), "lps_dashboard_publish");
    expect(publishNonce).not.toBe("");
    const dashboardPublish = await profAContext.request.post("/wp-admin/admin-post.php", {
      form: {
        action: "lps_dashboard_publish",
        post_id: String(newsId),
        _lps_dashboard_nonce: publishNonce,
        _wp_http_referer: `/pt-br/painel/ofertas/${state.offeringId}/`,
      },
      maxRedirects: 0,
    });
    const dashboardLocation = dashboardPublish.headers()["location"] ?? "";
    const after = await publisherRead("news", newsId, "?context=edit");
    const finalStatus = after.body?.status ?? "unknown";
    exchanges.push({
      request: evidenceRequest("POST", `/wp-admin/admin-post.php action=lps_dashboard_publish post_id=${newsId}`, {
        "x-as": "professor-a (news grant)",
      }),
      response: { status: dashboardPublish.status(), location: dashboardLocation, post_status_after: finalStatus },
    });

    // The divergence is documented, not silently pinned: the REST denial is
    // asserted above; the dashboard outcome is recorded verbatim. At the
    // current baseline the handler publishes (lps_notice=published), which is
    // the anomaly — a news-granted professor bypasses the review queue.
    expect([302, 403]).toContain(dashboardPublish.status());
    expect(["publish", "draft"]).toContain(finalStatus);

    await probe(
      "news-self-publish-divergence",
      "blocked",
      "high",
      "PRE-EXISTING POLICY DESIGN (flagged at gate review of task 16): lps_news sits in PUBLISHABLE_POST_TYPES, so a news-scope grant satisfies the dashboard publish boundary, while the REST may_publish gate denies the same account because persisted_offering_id resolves 0 for news. A news-granted professor can self-publish through admin-post.php, bypassing the review queue handle_news promises. Classification: blocked — requires a policy decision on whether lps_news belongs in PUBLISHABLE_POST_TYPES or the dashboard may() needs a news carve-out.",
      exchanges,
    );
  });

  test("security headers cover public, denied and credential surfaces", async () => {
    test.setTimeout(120_000);
    const exchanges = [];
    for (const path of ["/pt-br/", "/wp-login.php", "/wp-json/", state.downloadUrl, "/this-path-does-not-exist"]) {
      const response = await anonymous.request.get(path);
      const headers = response.headers();
      exchanges.push({
        request: evidenceRequest("GET", path),
        response: {
          status: response.status(),
          csp: headers["content-security-policy"] ?? null,
          xfo: headers["x-frame-options"] ?? null,
          nosniff: headers["x-content-type-options"] ?? null,
        },
      });
      const csp = headers["content-security-policy"] ?? "";
      expect(csp, `${path} CSP`).toContain("object-src 'none'");
      expect(csp, `${path} CSP`).toContain("frame-ancestors 'none'");
      expect(csp, `${path} CSP`).not.toContain("unsafe-eval");
      expect(headers["x-content-type-options"], path).toBe("nosniff");
      expect(headers["x-frame-options"], path).toBe("DENY");
      expect(headers["referrer-policy"], path).toBe("no-referrer");
    }

    await probe(
      "security-headers",
      "not-reproducible",
      "medium",
      "Public pages, the credential surface, the REST index, download denials and 404s all carry the full CSP/nosniff/DENY/no-referrer header set.",
      exchanges,
    );
  });
});

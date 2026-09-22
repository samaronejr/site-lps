import { createHash } from "node:crypto";
import { expect, test } from "@playwright/test";

/**
 * Task 15 — safe next-term copy and correction history.
 *
 * The spec exercises the real HTTP boundary of the running site: a completed
 * offering is copied forward into a new term/section as a draft with an
 * explicitly reviewed team, cloned units, reset sensitive/date-bound fields
 * and explicit public-version reuse. Retrying the same operation replays one
 * draft; a duplicate section conflicts; an injected mid-operation failure
 * rolls the partial graph back; unreleased material never reaches a public
 * state. Corrections propagate only to explicitly selected offerings of the
 * same course, preserving history through WordPress revisions and the audit
 * ledger.
 *
 * The pure field/decision contracts are covered by LpsRedesignTask15Test;
 * this spec proves the wired boundary.
 */

const RUN = `t15-${Date.now().toString(36)}`;
const BASE_URL = process.env.LPS_BASE_URL ?? "http://localhost:8898";

const PDF_V1 = `%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\n% task-15 fixture v1 ${RUN}\n%%EOF\n`;
const PDF_V2 = `%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 300] >>\nendobj\n% task-15 fixture v2 ${RUN} unreleased\n%%EOF\n`;
const SHA_V1 = createHash("sha256").update(PDF_V1).digest("hex");

const state = {
  publisherNonce: "",
  adminNonce: "",
  professorNonce: "",
  delegateNonce: "",
  personId: 0,
  person2Id: 0,
  termCompletedId: 0,
  termNewId: 0,
  coursePtId: 0,
  courseEnId: 0,
  course2PtId: 0,
  offeringPtId: 0,
  offeringEnId: 0,
  offeringOtherId: 0,
  offeringCourse2Id: 0,
  unit1Id: 0,
  unit2Id: 0,
  version1Id: "",
  version2Id: "",
  resourceReleasedId: 0,
  resourceUnreleasedId: 0,
  resourceExternalId: 0,
  resourceWithdrawnId: 0,
  downloadUrl: "",
  newOfferingId: 0,
  newOfferingEnId: 0,
  copiedResourceIds: [],
  copiedUnitIds: [],
  reusedCloneId: 0,
  professorCopyId: 0,
  sourceModifiedGmt: "",
  sourceRevisionCount: 0,
  newRevisionCount: 0,
  courseSlugPt: `disciplina-${RUN}`,
  courseSlugEn: `course-${RUN}`,
  course2SlugPt: `outra-${RUN}`,
};

let context;
let page;
let adminContext;
let adminPage;
let professorContext;
let professorPage;
let delegateContext;
let delegatePage;

test.describe.configure({ mode: "serial" });

/**
 * Signs in through the real login form; MFA-enrolled accounts complete the
 * Two-Factor dummy-provider challenge with a single submit.
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
  // The WASM auth path occasionally drops the session-token write between
  // the 2FA validation and the /wp-admin/ redirect, bouncing the session
  // back to wp-login.php?reauth=1. Re-running the credential flow is the
  // deterministic recovery: a fresh attempt mints a fresh session token.
  for (let attempt = 0; attempt < 4; attempt += 1) {
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
      const challenge = target.locator(
        "#loginform input[type=submit], #loginform button[type=submit]",
      );
      await challenge.waitFor({ state: "visible", timeout: 60_000 });
      await Promise.all([target.waitForLoadState("load", { timeout: 60_000 }), challenge.click()]);
    }
    // A successful sign-in leaves wp-login.php entirely: the session cookie
    // is what matters, so poll the current URL rather than an admin-only
    // marker (the redirect target may be the front-end for non-admin roles).
    // Polling reads the committed URL directly — waitForURL's navigation
    // semantics can miss the wp-login.php redirect chain under WASM.
    const settled = await expect
      .poll(() => new URL(target.url()).pathname, { timeout: 60_000 })
      .not.toBe("/wp-login.php")
      .then(() => true)
      .catch(() => false);
    if (settled) {
      await expect(target.locator("#login_error")).toHaveCount(0);
      return;
    }
  }
  throw new Error(`sign-in for ${user} never left wp-login.php`);
}

/** Reads the REST nonce bound to a signed-in session. */
async function restNonce(target) {
  for (let attempt = 0; attempt < 12; attempt += 1) {
    try {
      const response = await target.request.get("/wp-admin/admin-ajax.php?action=rest-nonce", {
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

/** Creates a credential-free context that skips the auto-login handshake. */
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

/** Reads one record through the core REST surface as the publisher. */
async function record(postType, id, params = "") {
  const response = await page.request.get(`/wp-json/wp/v2/${postType}/${id}${params}`, {
    headers: { "X-WP-Nonce": state.publisherNonce },
  });
  expect(response.status(), `read ${postType}/${id}`).toBe(200);
  return response.json();
}

/** Counts WordPress revisions of one record. */
async function revisionCount(id) {
  const response = await page.request.get(`/wp-json/wp/v2/offerings/${id}/revisions?per_page=100`, {
    headers: { "X-WP-Nonce": state.publisherNonce },
  });
  expect(response.status(), `revisions ${id}`).toBe(200);
  return (await response.json()).length;
}

test.describe("task-15: safe next-term copy and correction history", () => {
  test.beforeAll(async ({ browser }) => {
    test.setTimeout(240_000);
    context = await browser.newContext({ baseURL: BASE_URL });
    page = await context.newPage();
    await loginAs(page, "lps-t15-publisher", "lps-t15-publisher-pass", true);
    state.publisherNonce = await restNonce(page);

    adminContext = await browser.newContext({ baseURL: BASE_URL });
    adminPage = await adminContext.newPage();
    await loginAs(adminPage, "admin", "password", true);
    state.adminNonce = await restNonce(adminPage);

    professorContext = await browser.newContext({ baseURL: BASE_URL });
    professorPage = await professorContext.newPage();
    await loginAs(professorPage, "lps-t15-professor", "lps-t15-professor-pass", true);
    state.professorNonce = await restNonce(professorPage);

    delegateContext = await browser.newContext({ baseURL: BASE_URL });
    delegatePage = await delegateContext.newPage();
    await loginAs(delegatePage, "lps-t15-delegate", "lps-t15-delegate-pass", true);
    state.delegateNonce = await restNonce(delegatePage);
  });

  test.afterAll(async () => {
    await context?.close();
    await adminContext?.close();
    await professorContext?.close();
    await delegateContext?.close();
  });

  test("the publisher builds the completed source offering graph", async () => {
    test.setTimeout(180_000);

    for (const [slug, title] of [
      [`docente-${RUN}`, `Docente ${RUN}`],
      [`docente-b-${RUN}`, `Docente B ${RUN}`],
    ]) {
      const person = await page.request.post("/wp-json/wp/v2/people", {
        data: {
          title,
          slug,
          status: "draft",
          meta: { _lps_locale: "pt-br", _lps_canonical_name: title },
        },
        headers: { "X-WP-Nonce": state.publisherNonce },
      });
      expect(person.status(), JSON.stringify(await person.json())).toBe(201);
      const id = (await person.json()).id;
      if (slug === `docente-${RUN}`) {
        state.personId = id;
      } else {
        state.person2Id = id;
      }
    }

    const termCompleted = await api(page, state.publisherNonce, "POST", "/teaching/terms", {
      title: `2025.2 ${RUN}`,
      slug: `term-completed-${RUN}`,
      calendar_key: "semester",
      term_code: `2025-2-${RUN}`,
      status: "publish",
      meta: {
        _lps_period_label: `2025.2 ${RUN}`,
        _lps_period_type: "semester",
        _lps_starts_on: "2025-08-01",
        _lps_ends_on: "2025-12-15",
      },
    });
    expect(termCompleted.status, JSON.stringify(termCompleted.body)).toBe(201);
    state.termCompletedId = termCompleted.body.id;

    const termNew = await api(page, state.publisherNonce, "POST", "/teaching/terms", {
      title: `2027.1 ${RUN}`,
      slug: `term-new-${RUN}`,
      calendar_key: "semester",
      term_code: `2027-1-${RUN}`,
      status: "publish",
      meta: {
        _lps_period_label: `2027.1 ${RUN}`,
        _lps_period_type: "semester",
        _lps_starts_on: "2027-03-02",
        _lps_ends_on: "2027-07-10",
      },
    });
    expect(termNew.status, JSON.stringify(termNew.body)).toBe(201);
    state.termNewId = termNew.body.id;

    const course = await api(page, state.publisherNonce, "POST", "/teaching/courses", {
      title: `Disciplina ${RUN}`,
      slug: state.courseSlugPt,
      excerpt: "Disciplina de teste.",
      content: "Conteúdo de fixture.",
      meta: {
        _lps_course_code: `LPS-${RUN.toUpperCase()}`,
        _lps_course_level: "undergraduate",
        _lps_calendar_key: "semester",
        _lps_syllabus: "Ementa oficial do curso",
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
    await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/records/${state.courseEnId}/review-translation`,
    );
    await publish(state.courseEnId);
    await publish(state.coursePtId);

    // A second course anchors the cross-course correction denial.
    const course2 = await api(page, state.publisherNonce, "POST", "/teaching/courses", {
      title: `Outra ${RUN}`,
      slug: state.course2SlugPt,
      excerpt: "Disciplina de teste.",
      content: "Conteúdo de fixture.",
      meta: {
        _lps_course_code: `LPS-B-${RUN.toUpperCase()}`,
        _lps_course_level: "graduate",
        _lps_calendar_key: "semester",
      },
    });
    expect(course2.status, JSON.stringify(course2.body)).toBe(201);
    state.course2PtId = course2.body.id;

    // The completed source offering carries sensitive and term-bound fields
    // the copy must reset: an approved LMS link and a cancelled sibling.
    const offering = await api(page, state.publisherNonce, "POST", "/teaching/offerings", {
      title: `Turma ${RUN} T01`,
      slug: `turma-${RUN}-t01`,
      excerpt: "Turma concluída de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.coursePtId,
      term_id: state.termCompletedId,
      section: "t01",
      team: [
        { person_id: state.personId, role: "lead" },
        { person_id: state.person2Id, role: "co-teacher" },
      ],
      meta: {
        _lps_schedule: "Ter/Qui 10h-12h",
        _lps_venue: "Sala 201",
        _lps_syllabus_snapshot: "Ementa publicada 2025.2",
        _lps_lms_url: "https://lms.example.org/turma-2025-2",
        _lps_lms_url_approved: true,
      },
    });
    expect(offering.status, JSON.stringify(offering.body)).toBe(201);
    state.offeringPtId = offering.body.id;
    expect(offering.body.temporal_status).toBe("completed");

    const offeringEn = await api(page, state.publisherNonce, "POST", "/teaching/offerings", {
      title: `Section ${RUN} T01`,
      slug: `section-${RUN}-t01`,
      locale: "en",
      translation_of: state.offeringPtId,
      excerpt: "Completed test section.",
      content: "Fixture content.",
    });
    expect(offeringEn.status, JSON.stringify(offeringEn.body)).toBe(201);
    state.offeringEnId = offeringEn.body.id;
    await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/records/${state.offeringEnId}/review-translation`,
    );
    await publish(state.offeringEnId);
    await publish(state.offeringPtId);

    // A second offering of the same course anchors correction propagation.
    const other = await api(page, state.publisherNonce, "POST", "/teaching/offerings", {
      title: `Turma ${RUN} T02`,
      slug: `turma-${RUN}-t02`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.coursePtId,
      term_id: state.termCompletedId,
      section: "t02",
      team: [{ person_id: state.personId, role: "lead" }],
      meta: { _lps_schedule: "Seg 8h", _lps_venue: "Sala 101" },
    });
    expect(other.status, JSON.stringify(other.body)).toBe(201);
    state.offeringOtherId = other.body.id;

    // An offering of the second course anchors the cross-course denial.
    const course2Offering = await api(page, state.publisherNonce, "POST", "/teaching/offerings", {
      title: `Turma ${RUN} X01`,
      slug: `turma-${RUN}-x01`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.course2PtId,
      term_id: state.termCompletedId,
      section: "x01",
      team: [{ person_id: state.personId, role: "lead" }],
    });
    expect(course2Offering.status, JSON.stringify(course2Offering.body)).toBe(201);
    state.offeringCourse2Id = course2Offering.body.id;

    // Two ordered units with term-bound topic dates.
    for (const [anchor, position, topic] of [
      [`unidade-1-${RUN}`, 1, "2025-09-02"],
      [`unidade-2-${RUN}`, 2, "2025-10-14"],
    ]) {
      const unit = await api(page, state.publisherNonce, "POST", "/teaching/units", {
        title: `Unidade ${anchor}`,
        slug: anchor,
        offering_id: state.offeringPtId,
        status: "publish",
        meta: { _lps_anchor: anchor, _lps_position: position, _lps_topic_date: topic },
      });
      expect(unit.status, JSON.stringify(unit.body)).toBe(201);
      if (position === 1) {
        state.unit1Id = unit.body.id;
      } else {
        state.unit2Id = unit.body.id;
      }
    }
  });

  test("the publisher mints versions and releases the public resource set", async () => {
    test.setTimeout(120_000);
    expect(state.offeringPtId, "graph test must run first").toBeGreaterThan(0);

    const v1 = await upload(
      page,
      state.publisherNonce,
      state.offeringPtId,
      `apostila-${RUN}.pdf`,
      PDF_V1,
    );
    expect(v1.status, JSON.stringify(v1.body)).toBe(201);
    state.version1Id = v1.body.version_id;
    expect(v1.body.state).toBe("cleared");

    const v2 = await upload(
      page,
      state.publisherNonce,
      state.offeringPtId,
      `rascunho-${RUN}.pdf`,
      PDF_V2,
    );
    expect(v2.status, JSON.stringify(v2.body)).toBe(201);
    state.version2Id = v2.body.version_id;
    expect(v2.body.state).toBe("cleared");

    // Released + published: the public resource whose version may be reused.
    const released = await api(page, state.publisherNonce, "POST", "/teaching/resources", {
      title: `Apostila ${RUN}`,
      slug: `apostila-${RUN}`,
      offering_id: state.offeringPtId,
      unit_id: state.unit1Id,
      version_id: state.version1Id,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    expect(released.status, JSON.stringify(released.body)).toBe(201);
    state.resourceReleasedId = released.body.id;
    state.downloadUrl = released.body.download_url;
    const release = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/resources/${state.resourceReleasedId}/release`,
      { state: "released" },
    );
    expect(release.status, JSON.stringify(release.body)).toBe(200);
    await publish(state.resourceReleasedId);

    // Unreleased: a draft resource whose version must never be carried.
    const unreleased = await api(page, state.publisherNonce, "POST", "/teaching/resources", {
      title: `Prova ${RUN}`,
      slug: `prova-${RUN}`,
      offering_id: state.offeringPtId,
      unit_id: state.unit2Id,
      version_id: state.version2Id,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    expect(unreleased.status, JSON.stringify(unreleased.body)).toBe(201);
    state.resourceUnreleasedId = unreleased.body.id;
    await publish(state.resourceUnreleasedId);

    // Released external link: its public URL carries forward.
    const external = await api(page, state.publisherNonce, "POST", "/teaching/resources", {
      title: `Externo ${RUN}`,
      slug: `externo-${RUN}`,
      offering_id: state.offeringPtId,
      meta: {
        _lps_resource_type: "link",
        _lps_resource_language: "pt-br",
        _lps_external_url: "https://example.org/aula-publica.pdf",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    expect(external.status, JSON.stringify(external.body)).toBe(201);
    state.resourceExternalId = external.body.id;
    const externalRelease = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/resources/${state.resourceExternalId}/release`,
      { state: "released" },
    );
    expect(externalRelease.status, JSON.stringify(externalRelease.body)).toBe(200);
    await publish(state.resourceExternalId);

    // Withdrawn: a released-then-withdrawn resource is never carried.
    const withdrawn = await api(page, state.publisherNonce, "POST", "/teaching/resources", {
      title: `Retirado ${RUN}`,
      slug: `retirado-${RUN}`,
      offering_id: state.offeringPtId,
      version_id: state.version1Id,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    expect(withdrawn.status, JSON.stringify(withdrawn.body)).toBe(201);
    state.resourceWithdrawnId = withdrawn.body.id;
    await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/resources/${state.resourceWithdrawnId}/release`,
      {
        state: "released",
      },
    );
    await publish(state.resourceWithdrawnId);
    const withdraw = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/resources/${state.resourceWithdrawnId}/withdraw`,
    );
    expect(withdraw.status, JSON.stringify(withdraw.body)).toBe(200);
    expect(withdraw.body.release_state).toBe("withdrawn");

    // Snapshot the source record for the unchanged-record assertions.
    const stored = await record("offerings", state.offeringPtId);
    state.sourceModifiedGmt = stored.modified_gmt;
    state.sourceRevisionCount = await revisionCount(state.offeringPtId);
  });

  test("copy-forward creates one draft with cloned structure and reset fields", async () => {
    test.setTimeout(120_000);
    expect(state.resourceReleasedId, "resource test must run first").toBeGreaterThan(0);

    const copy = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-a`,
        new_term_id: state.termNewId,
        new_section: "t01",
        team_reviewed: true,
        team: [
          { person_id: state.personId, role: "lead" },
          { person_id: state.person2Id, role: "assistant" },
        ],
        selected_version_ids: [state.version1Id],
        title: `Turma ${RUN} T01 (2027.1)`,
      },
    );
    expect(copy.status, JSON.stringify(copy.body)).toBe(201);
    const manifest = copy.body;
    expect(manifest.operation_id).toBe(`copy-${RUN}-a`);
    expect(manifest.source_offering_id).toBe(state.offeringPtId);
    expect(manifest.new_term_id).toBe(state.termNewId);
    expect(manifest.new_section_key).toBe("t01");
    expect(manifest.creates_draft).toBe(true);
    expect(manifest.publishes).toBe(false);
    expect(manifest.replayed).toBe(false);
    expect(manifest.selected_version_ids).toContain(state.version1Id);
    expect(manifest.resets).toEqual(
      expect.arrayContaining([
        "announcements",
        "deadlines",
        "release_times",
        "active_notices",
        "unreleased_resources",
      ]),
    );
    state.newOfferingId = manifest.new_offering_id;
    state.copiedUnitIds = manifest.unit_ids;
    state.copiedResourceIds = manifest.resource_ids;
    expect(state.newOfferingId).toBeGreaterThan(0);
    expect(state.copiedUnitIds).toHaveLength(2);
    expect(state.copiedResourceIds).toHaveLength(4);

    // The new offering is a draft with a fresh identity and provenance.
    const draft = await record("offerings", state.newOfferingId, "?status=draft");
    expect(draft.status).toBe("draft");
    expect(draft.slug).not.toBe(`turma-${RUN}-t01`);
    expect(draft.meta._lps_copy_operation_id).toBe(`copy-${RUN}-a`);
    expect(draft.meta._lps_copy_source_offering_id).toBe(state.offeringPtId);
    expect(draft.meta._lps_record_id).not.toBe(
      (await record("offerings", state.offeringPtId)).meta._lps_record_id,
    );
    // Descriptive structure and the syllabus snapshot carried forward.
    expect(draft.meta._lps_schedule).toBe("Ter/Qui 10h-12h");
    expect(draft.meta._lps_venue).toBe("Sala 201");
    expect(draft.meta._lps_syllabus_snapshot).toBe("Ementa publicada 2025.2");
    // Sensitive and term-bound fields were reset.
    expect(draft.meta._lps_lms_url).toBe("");
    expect([false, ""]).toContain(draft.meta._lps_lms_url_approved);
    expect([false, ""]).toContain(draft.meta._lps_cancelled);
    expect(draft.meta._lps_temporal_status).toBe("upcoming");
    // The reviewed team was written through the canonical boundary.
    const history = await page.request.get(
      `/wp-json/lps/v1/teaching/people/${state.person2Id}/history?locale=pt-br&context=edit`,
      { headers: { "X-WP-Nonce": state.publisherNonce } },
    );
    const entries = (await history.json()).entries;
    const copied = entries.find((entry) => entry.offering_authority_id === state.newOfferingId);
    expect(copied, JSON.stringify(entries)).toBeTruthy();
    expect(copied.role).toBe("assistant");

    // Units cloned in order with anchors and reset topic dates.
    const unitMeta = [];
    for (const unitId of state.copiedUnitIds) {
      const unit = await record("units", unitId, "?status=draft");
      expect(unit.status).toBe("draft");
      unitMeta.push(unit.meta);
    }
    const anchors = unitMeta.map((meta) => meta._lps_anchor).sort();
    expect(anchors).toEqual([`unidade-1-${RUN}`, `unidade-2-${RUN}`]);
    for (const meta of unitMeta) {
      expect(meta._lps_topic_date).toBe("");
    }
    const positions = unitMeta.map((meta) => meta._lps_position).sort((a, b) => a - b);
    expect(positions).toEqual([1, 2]);

    // Resources cloned as drafts: only the explicitly selected public
    // version is reused; every other payload is reset.
    const clones = [];
    for (const resourceId of state.copiedResourceIds) {
      const clone = await record("resources", resourceId, "?status=draft");
      expect(clone.status).toBe("draft");
      expect(clone.meta._lps_release_state).toBe("draft");
      expect(clone.meta._lps_release_at).toBe("");
      expect(clone.meta._lps_withdrawn_at).toBe("");
      clones.push(clone);
    }
    // `_lps_version_id` is private and never REST-exposed; the reused
    // clone is identified by the stamped SHA-256 of the selected version.
    const releasedClone = clones.find((clone) => clone.meta._lps_sha256 === SHA_V1);
    expect(releasedClone, JSON.stringify(clones.map((c) => c.meta))).toBeTruthy();
    state.reusedCloneId = releasedClone.id;
    expect(releasedClone.meta._lps_rights_review).toBe("approved");
    expect(releasedClone.meta._lps_accessibility_review).toBe("approved");
    // The unreleased examination resource arrived as an empty stub.
    const unreleasedClone = clones.find(
      (clone) => (clone.title?.rendered ?? "") === `Prova ${RUN}`,
    );
    expect(unreleasedClone, JSON.stringify(clones.map((c) => [c.slug, c.title]))).toBeTruthy();
    expect(unreleasedClone.meta._lps_sha256).toBe("");
    expect(unreleasedClone.meta._lps_rights_review).toBe("pending");
    // The released external resource kept its public URL and reviews.
    const externalClone = clones.find(
      (clone) => (clone.title?.rendered ?? "") === `Externo ${RUN}`,
    );
    expect(externalClone, "external clone").toBeTruthy();
    expect(externalClone.meta._lps_external_url).toBe("https://example.org/aula-publica.pdf");
    expect(externalClone.meta._lps_rights_review).toBe("approved");
    // The withdrawn resource arrived as an empty stub.
    const withdrawnClone = clones.find(
      (clone) => (clone.title?.rendered ?? "") === `Retirado ${RUN}`,
    );
    expect(withdrawnClone, "withdrawn clone").toBeTruthy();
    expect(withdrawnClone.meta._lps_sha256).toBe("");
    expect(withdrawnClone.meta._lps_rights_review).toBe("pending");
  });

  test("the source offering record and byte hashes are identical after the copy", async ({
    browser,
  }) => {
    test.setTimeout(120_000);
    expect(state.newOfferingId, "copy test must run first").toBeGreaterThan(0);

    const after = await record("offerings", state.offeringPtId);
    expect(after.modified_gmt).toBe(state.sourceModifiedGmt);
    expect(after.status).toBe("publish");
    expect(after.meta._lps_lms_url).toBe("https://lms.example.org/turma-2025-2");
    expect(after.meta._lps_syllabus_snapshot).toBe("Ementa publicada 2025.2");
    expect(await revisionCount(state.offeringPtId)).toBe(state.sourceRevisionCount);

    // The released resource still serves the identical bytes.
    const anonymous = await anonymousContext(browser);
    try {
      const download = await anonymous.request.get(state.downloadUrl);
      expect(download.status()).toBe(200);
      expect(
        createHash("sha256")
          .update(await download.body())
          .digest("hex"),
      ).toBe(SHA_V1);
    } finally {
      await anonymous.close();
    }
    const resource = await record("resources", state.resourceReleasedId);
    expect(resource.meta._lps_sha256).toBe(SHA_V1);
    expect(resource.meta._lps_release_state).toBe("released");
  });

  test("retrying the same operation replays one draft", async () => {
    test.setTimeout(120_000);
    expect(state.newOfferingId, "copy test must run first").toBeGreaterThan(0);

    const retry = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-a`,
        new_term_id: state.termNewId,
        new_section: "t01",
        team_reviewed: true,
        team: [{ person_id: state.personId, role: "lead" }],
        selected_version_ids: [state.version1Id],
      },
    );
    expect(retry.status, JSON.stringify(retry.body)).toBe(201);
    expect(retry.body.replayed).toBe(true);
    expect(retry.body.new_offering_id).toBe(state.newOfferingId);
    expect(retry.body.unit_ids).toEqual(state.copiedUnitIds);
    expect(retry.body.resource_ids).toEqual(state.copiedResourceIds);

    // Exactly one draft carries this operation identifier.
    const drafts = await page.request.get("/wp-json/wp/v2/offerings?status=draft&per_page=100", {
      headers: { "X-WP-Nonce": state.publisherNonce },
    });
    const matches = (await drafts.json()).filter(
      (offering) => offering.meta?._lps_copy_operation_id === `copy-${RUN}-a`,
    );
    expect(matches).toHaveLength(1);
  });

  test("a duplicate section and missing requirements are typed denials", async () => {
    test.setTimeout(120_000);
    expect(state.newOfferingId, "copy test must run first").toBeGreaterThan(0);

    // A different operation on the same term/section is a real conflict.
    const duplicate = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-dup`,
        new_term_id: state.termNewId,
        new_section: "t01",
        team_reviewed: true,
        team: [{ person_id: state.personId, role: "lead" }],
      },
    );
    expect(duplicate.status).toBe(409);
    expect(duplicate.body.code).toBe("lps_offering_identity_conflict");
    expect(duplicate.body.data.existing_record).toBe(state.newOfferingId);

    // Selecting the unreleased examination version is denied before mutation.
    const unreleased = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-unreleased`,
        new_term_id: state.termNewId,
        new_section: "t03",
        team_reviewed: true,
        team: [{ person_id: state.personId, role: "lead" }],
        selected_version_ids: [state.version2Id],
      },
    );
    expect(unreleased.status).toBe(400);
    expect(unreleased.body.code).toBe("lps_copy_forward_unreleased_version");

    // The withdrawn version is equally unreusable.
    const withdrawn = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-withdrawn`,
        new_term_id: state.termNewId,
        new_section: "t03",
        team_reviewed: true,
        team: [{ person_id: state.personId, role: "lead" }],
        selected_version_ids: [state.version1Id, state.version2Id],
      },
    );
    expect(withdrawn.status).toBe(400);
    expect(withdrawn.body.code).toBe("lps_copy_forward_unreleased_version");

    // The same term and section is an identity collision, never a copy.
    const collision = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-collision`,
        new_term_id: state.termCompletedId,
        new_section: "t01",
        team_reviewed: true,
        team: [{ person_id: state.personId, role: "lead" }],
      },
    );
    expect(collision.status).toBe(400);
    expect(collision.body.code).toBe("lps_copy_forward_identity_collision");

    // Team review, team, term and operation id are all required.
    const noReview = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-noreview`,
        new_term_id: state.termNewId,
        new_section: "t04",
        team: [{ person_id: state.personId, role: "lead" }],
      },
    );
    expect(noReview.status).toBe(400);
    expect(noReview.body.code).toBe("lps_copy_forward_team_review_required");

    const noTeam = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-noteam`,
        new_term_id: state.termNewId,
        new_section: "t04",
        team_reviewed: true,
        team: [],
      },
    );
    expect(noTeam.status).toBe(400);
    expect(noTeam.body.code).toBe("lps_teaching_team_required");

    const badTerm = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-badterm`,
        new_term_id: 999999,
        new_section: "t04",
        team_reviewed: true,
        team: [{ person_id: state.personId, role: "lead" }],
      },
    );
    expect(badTerm.status).toBe(400);
    expect(badTerm.body.code).toBe("lps_teaching_term_invalid");

    const noOperation = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: "x",
        new_term_id: state.termNewId,
        new_section: "t04",
        team_reviewed: true,
        team: [{ person_id: state.personId, role: "lead" }],
      },
    );
    expect(noOperation.status).toBe(400);
    expect(noOperation.body.code).toBe("lps_copy_forward_operation_id_required");
  });

  test("an interrupted operation rolls back and a retry recovers cleanly", async () => {
    test.setTimeout(120_000);
    expect(state.newOfferingId, "copy test must run first").toBeGreaterThan(0);

    // Arm the failure seam at the resource step: the draft offering and its
    // cloned units exist when persistence is interrupted.
    const armed = await api(page, state.publisherNonce, "POST", "/test/copy-fail", {
      step: "resource",
    });
    expect(armed.status).toBe(200);
    const failed = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-fail`,
        new_term_id: state.termNewId,
        new_section: "t05",
        team_reviewed: true,
        team: [{ person_id: state.personId, role: "lead" }],
      },
    );
    expect(failed.status).toBe(500);
    expect(failed.body.code).toBe("lps_test_copy_step_failed");
    await api(page, state.publisherNonce, "POST", "/test/copy-fail", { step: "" });

    // No partial graph survives: no draft carries the failed operation id,
    // and the t05 identity claim was released — proven by the clean retry.
    const drafts = await page.request.get("/wp-json/wp/v2/offerings?status=draft&per_page=100", {
      headers: { "X-WP-Nonce": state.publisherNonce },
    });
    const partial = (await drafts.json()).filter(
      (offering) => offering.meta?._lps_copy_operation_id === `copy-${RUN}-fail`,
    );
    expect(partial, JSON.stringify(partial)).toHaveLength(0);

    const retried = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-fail`,
        new_term_id: state.termNewId,
        new_section: "t05",
        team_reviewed: true,
        team: [{ person_id: state.personId, role: "lead" }],
      },
    );
    expect(retried.status, JSON.stringify(retried.body)).toBe(201);
    expect(retried.body.replayed).toBe(false);
    expect(retried.body.new_offering_id).toBeGreaterThan(0);
    expect(retried.body.new_offering_id).not.toBe(state.newOfferingId);
    expect(retried.body.unit_ids).toHaveLength(2);
    expect(retried.body.resource_ids).toHaveLength(4);
  });

  test("scoped authorization gates the operation to granted professors and editors", async () => {
    test.setTimeout(120_000);
    expect(state.offeringPtId, "graph test must run first").toBeGreaterThan(0);

    // The professor holds a grant on the source offering only.
    const grant = await api(adminPage, state.adminNonce, "POST", "/test/grants", {
      user_login: "lps-t15-professor",
      offering_id: state.offeringPtId,
      role: "professor",
    });
    expect(grant.status, JSON.stringify(grant.body)).toBe(200);
    expect(grant.body.granted).toBe(true);

    // A granted professor copies the assigned offering forward.
    const professorCopy = await api(
      professorPage,
      state.professorNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-prof`,
        new_term_id: state.termNewId,
        new_section: "t06",
        team_reviewed: true,
        team: [{ person_id: state.personId, role: "lead" }],
      },
    );
    expect(professorCopy.status, JSON.stringify(professorCopy.body)).toBe(201);
    state.professorCopyId = professorCopy.body.new_offering_id;
    expect(professorCopy.body.creates_draft).toBe(true);

    // The same professor is denied on an ungranted offering.
    const denied = await api(
      professorPage,
      state.professorNonce,
      "POST",
      `/teaching/offerings/${state.offeringOtherId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-denied`,
        new_term_id: state.termNewId,
        new_section: "t07",
        team_reviewed: true,
        team: [{ person_id: state.personId, role: "lead" }],
      },
    );
    expect([401, 403]).toContain(denied.status);

    // Delegates never copy forward.
    const delegate = await api(
      delegatePage,
      state.delegateNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/copy-forward`,
      {
        operation_id: `copy-${RUN}-delegate`,
        new_term_id: state.termNewId,
        new_section: "t08",
        team_reviewed: true,
        team: [{ person_id: state.personId, role: "lead" }],
      },
    );
    expect([401, 403]).toContain(delegate.status);

    // Anonymous callers are denied before any mutation.
    const anonymous = await anonymousContext(await professorPage.context().browser());
    try {
      const response = await anonymous.request.post(
        `/wp-json/lps/v1/teaching/offerings/${state.offeringPtId}/copy-forward`,
        {
          data: {
            operation_id: `copy-${RUN}-anon`,
            new_term_id: state.termNewId,
            new_section: "t09",
            team_reviewed: true,
            team: [{ person_id: state.personId, role: "lead" }],
          },
        },
      );
      expect([401, 403]).toContain(response.status());
    } finally {
      await anonymous.close();
    }
  });

  test("the copied draft reviews and publishes without touching the source", async () => {
    test.setTimeout(120_000);
    expect(state.newOfferingId, "copy test must run first").toBeGreaterThan(0);

    // The new draft needs its own English variant before the authority can
    // publish — the bilingual contract applies to copied drafts unchanged.
    const variant = await api(page, state.publisherNonce, "POST", "/teaching/offerings", {
      title: `Section ${RUN} T01 (2027.1)`,
      slug: `section-${RUN}-t01-2027-1`,
      locale: "en",
      translation_of: state.newOfferingId,
      excerpt: "Copied test section.",
      content: "Fixture content.",
    });
    expect(variant.status, JSON.stringify(variant.body)).toBe(201);
    state.newOfferingEnId = variant.body.id;
    await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/records/${state.newOfferingEnId}/review-translation`,
    );
    await publish(state.newOfferingEnId);
    const published = await publish(state.newOfferingId);
    expect(published.status).toBe("publish");
    expect(published.temporal_status).toBe("upcoming");
    expect(published.canonical_path).toBe(
      `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2027-1-${RUN}-semester/t01/`,
    );

    // The canonical route resolves for the new offering only.
    const route = await page.goto(published.canonical_path);
    expect(route.status()).toBe(200);
    await expect(page.locator("h1").first()).toContainText(`Turma ${RUN} T01 (2027.1)`);

    // Publishing the reused-version clone serves the selected public bytes.
    const cloneRecord = await record("resources", state.reusedCloneId);
    expect(cloneRecord.meta._lps_sha256).toBe(SHA_V1);
    const released = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/resources/${state.reusedCloneId}/release`,
      { state: "released" },
    );
    expect(released.status, JSON.stringify(released.body)).toBe(200);
    expect(released.body.version_id).toBe(state.version1Id);
    const clonePublished = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/records/${state.reusedCloneId}/publish`,
    );
    expect(clonePublished.status, JSON.stringify(clonePublished.body)).toBe(200);
    const anonymous = await anonymousContext(await page.context().browser());
    try {
      const download = await anonymous.request.get(released.body.download_url);
      expect(download.status()).toBe(200);
      expect(
        createHash("sha256")
          .update(await download.body())
          .digest("hex"),
      ).toBe(SHA_V1);
    } finally {
      await anonymous.close();
    }

    // The source offering is still published and unchanged.
    const source = await record("offerings", state.offeringPtId);
    expect(source.status).toBe("publish");
    expect(source.modified_gmt).toBe(state.sourceModifiedGmt);
  });

  test("corrections propagate only to explicitly selected offerings and preserve history", async () => {
    test.setTimeout(120_000);
    expect(state.newOfferingId, "publish test must run first").toBeGreaterThan(0);

    const beforeSource = await revisionCount(state.offeringPtId);
    const beforeNew = await revisionCount(state.newOfferingId);
    const beforeOther = await revisionCount(state.offeringOtherId);

    const correction = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/corrections`,
      {
        operation_id: `corr-${RUN}-a`,
        fields: {
          _lps_syllabus_snapshot: "Ementa corrigida 2025.2",
          _lps_schedule: "Ter/Qui 14h-16h",
        },
        affected_offering_ids: [state.offeringPtId, state.newOfferingId],
      },
    );
    expect(correction.status, JSON.stringify(correction.body)).toBe(200);
    expect(correction.body.operation_id).toBe(`corr-${RUN}-a`);
    expect(correction.body.replayed).toBe(false);
    expect(correction.body.affected_offering_ids).toEqual(
      expect.arrayContaining([state.offeringPtId, state.newOfferingId]),
    );
    expect(correction.body.applied).toHaveLength(2);

    // Both selected offerings carry the correction and a new revision.
    const source = await record("offerings", state.offeringPtId);
    expect(source.meta._lps_syllabus_snapshot).toBe("Ementa corrigida 2025.2");
    expect(source.meta._lps_schedule).toBe("Ter/Qui 14h-16h");
    expect(await revisionCount(state.offeringPtId)).toBe(beforeSource + 1);
    const copied = await record("offerings", state.newOfferingId);
    expect(copied.meta._lps_syllabus_snapshot).toBe("Ementa corrigida 2025.2");
    expect(await revisionCount(state.newOfferingId)).toBe(beforeNew + 1);

    // The unselected offering of the same course is untouched.
    const other = await record("offerings", state.offeringOtherId);
    expect(other.meta._lps_schedule).toBe("Seg 8h");
    expect(other.meta._lps_syllabus_snapshot ?? "").not.toBe("Ementa corrigida 2025.2");
    expect(await revisionCount(state.offeringOtherId)).toBe(beforeOther);

    // Retrying the same correction operation replays without new revisions.
    const retry = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/corrections`,
      {
        operation_id: `corr-${RUN}-a`,
        fields: { _lps_syllabus_snapshot: "Ementa corrigida 2025.2" },
        affected_offering_ids: [state.offeringPtId, state.newOfferingId],
      },
    );
    expect(retry.status).toBe(200);
    expect(retry.body.replayed).toBe(true);
    expect(await revisionCount(state.offeringPtId)).toBe(beforeSource + 1);
    expect(await revisionCount(state.newOfferingId)).toBe(beforeNew + 1);
  });

  test("correction denials are typed and never touch unlisted offerings", async () => {
    test.setTimeout(120_000);
    expect(state.offeringCourse2Id, "graph test must run first").toBeGreaterThan(0);

    // Cross-course propagation is denied.
    const mismatch = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/corrections`,
      {
        operation_id: `corr-${RUN}-mismatch`,
        fields: { _lps_schedule: "X" },
        affected_offering_ids: [state.offeringCourse2Id],
      },
    );
    expect(mismatch.status).toBe(400);
    expect(mismatch.body.code).toBe("lps_correction_course_mismatch");

    // Identity and system fields are never correctable.
    const forbidden = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/corrections`,
      {
        operation_id: `corr-${RUN}-forbidden`,
        fields: { _lps_section_key: "t99" },
        affected_offering_ids: [state.offeringPtId],
      },
    );
    expect(forbidden.status).toBe(400);
    expect(forbidden.body.code).toBe("lps_correction_field_forbidden");

    // The affected selection is required and explicit.
    const none = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/corrections`,
      {
        operation_id: `corr-${RUN}-none`,
        fields: { _lps_schedule: "X" },
        affected_offering_ids: [],
      },
    );
    expect(none.status).toBe(400);
    expect(none.body.code).toBe("lps_correction_affected_required");

    const missing = await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/corrections`,
      {
        operation_id: `corr-${RUN}-missing`,
        fields: { _lps_schedule: "X" },
        affected_offering_ids: [999999],
      },
    );
    expect(missing.status).toBe(400);
    expect(missing.body.code).toBe("lps_teaching_offering_invalid");

    // A granted professor propagates allowlisted fields on granted offerings
    // only; fields outside the scoped allowlist are denied before mutation.
    const professorCorrection = await api(
      professorPage,
      state.professorNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/corrections`,
      {
        operation_id: `corr-${RUN}-prof`,
        fields: { _lps_venue: "Sala 202" },
        affected_offering_ids: [state.offeringPtId],
      },
    );
    expect(professorCorrection.status, JSON.stringify(professorCorrection.body)).toBe(200);

    const professorDenied = await api(
      professorPage,
      state.professorNonce,
      "POST",
      `/teaching/offerings/${state.offeringPtId}/corrections`,
      {
        operation_id: `corr-${RUN}-profdenied`,
        fields: { _lps_lms_url: "https://lms.example.org/nova" },
        affected_offering_ids: [state.offeringPtId],
      },
    );
    expect([401, 403]).toContain(professorDenied.status);

    const outOfScope = await api(
      professorPage,
      state.professorNonce,
      "POST",
      `/teaching/offerings/${state.offeringOtherId}/corrections`,
      {
        operation_id: `corr-${RUN}-outofscope`,
        fields: { _lps_venue: "Sala 303" },
        affected_offering_ids: [state.offeringOtherId],
      },
    );
    expect([401, 403]).toContain(outOfScope.status);
  });

  test("the audit ledger records the copy and correction decisions", async () => {
    test.setTimeout(120_000);
    expect(state.newOfferingId, "copy test must run first").toBeGreaterThan(0);

    const audit = await adminPage.request.get(
      `/wp-json/lps/v1/test/audit?post_id=${state.newOfferingId}`,
      {
        headers: { "X-WP-Nonce": state.adminNonce },
      },
    );
    expect(audit.status()).toBe(200);
    const entries = await audit.json();
    const creates = entries.filter(
      (entry) => entry.action === "create" && entry.context_json.includes(`copy-${RUN}-a`),
    );
    expect(creates.length, JSON.stringify(entries)).toBeGreaterThanOrEqual(1);

    const sourceAudit = await adminPage.request.get(
      `/wp-json/lps/v1/test/audit?post_id=${state.offeringPtId}`,
      {
        headers: { "X-WP-Nonce": state.adminNonce },
      },
    );
    const sourceEntries = await sourceAudit.json();
    const corrections = sourceEntries.filter(
      (entry) => entry.action === "edit" && entry.context_json.includes(`corr-${RUN}-a`),
    );
    expect(corrections.length, JSON.stringify(sourceEntries)).toBeGreaterThanOrEqual(1);
    expect(corrections[0].context_json).toContain("propagate-correction");
  });
});

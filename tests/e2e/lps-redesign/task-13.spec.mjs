import { createHash } from "node:crypto";
import { expect, test } from "@playwright/test";

/**
 * Task 13 — course directories, offerings, units and material views.
 *
 * The spec exercises the real HTTP boundary of the running site: the
 * canonical first-integrated-example fixture (one faculty profile, one
 * current and one completed offering, a co-teaching case) is published
 * through `lps/v1/teaching/*`, then the teaching landing, course detail,
 * offering detail and history views are read as anonymous HTML. Students
 * distinguish current from previous offerings, reach materials through
 * descriptive rows and stable unit anchors, compare a download's checksum
 * with the displayed SHA-256, and never see a raw private URL. Withdrawn
 * resources keep an informative notice; drafts and not-yet-due schedules
 * stay invisible; a hundred-resource offering stays navigable.
 *
 * The pure rendering and material-state contracts are covered by
 * LpsRedesignTask13Test; this spec proves the wired boundary.
 */

const RUN = `t13-${Date.now().toString(36)}`;
const BASE_URL = process.env.LPS_BASE_URL ?? "http://127.0.0.1:8896";
const PDF = `%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\n% task-13 fixture ${RUN}\n%%EOF\n`;
const PDF_SHA256 = createHash("sha256").update(PDF).digest("hex");
const LONG_TITLE = `Apostila ${RUN} com um título descritivo deliberadamente longo que precisa quebrar em várias linhas sem transbordar a página nem esconder o estado do material`;

const state = {
  nonce: "",
  leadId: 0,
  coTeacherId: 0,
  termCurrentId: 0,
  termCompletedId: 0,
  coursePtId: 0,
  courseEnId: 0,
  emptyCourseId: 0,
  offeringPtId: 0,
  offeringEnId: 0,
  offeringPrevId: 0,
  offeringPrevEnId: 0,
  unit1Id: 0,
  unit2Id: 0,
  prevUnitId: 0,
  resourceId: 0,
  prevResourceId: 0,
  withdrawnId: 0,
  downloadUrl: "",
  prevDownloadUrl: "",
  withdrawnDownloadUrl: "",
  courseSlugPt: `disciplina-${RUN}`,
  courseSlugEn: `course-${RUN}`,
  emptyCourseSlug: `vazia-${RUN}`,
  leadName: "Docente de Sinais (fixture de QA)",
  coTeacherName: "Codocente de Sinais (fixture de QA)",
  leadNameEn: "Signals Faculty (QA fixture)",
  coTeacherNameEn: "Signals Co-Teacher (QA fixture)",
};

let context;
let page;

test.describe.configure({ mode: "serial" });

/**
 * Signs in as the MFA-enrolled publisher through the real login form.
 */
async function loginAsPublisher(target) {
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
  await login.fill("lps-t13-publisher");
  await target.locator("#user_pass").fill("lps-t13-publisher-pass");
  await Promise.all([target.waitForLoadState("load"), target.locator("#wp-submit").click()]);
  const challenge = target.locator("#loginform input[type=submit], #loginform button[type=submit]");
  await challenge.waitFor({ state: "visible", timeout: 15_000 });
  await Promise.all([target.waitForLoadState("load"), challenge.click()]);
  await expect(target.locator("#login_error")).toHaveCount(0);
  await target.waitForURL((url) => !url.pathname.endsWith("/wp-login.php"), { timeout: 180_000 });
}

/** Reads the REST nonce bound to the signed-in session. */
async function restNonce(target) {
  for (let attempt = 0; attempt < 6; attempt += 1) {
    const response = await target.request.get("/wp-admin/admin-ajax.php?action=rest-nonce");
    if (response.ok()) {
      const nonce = (await response.text()).trim();
      if (nonce.length > 0 && nonce !== "0") {
        return nonce;
      }
    }
  }
  throw new Error("REST nonce endpoint never answered for the publisher session");
}

/** Calls the teaching REST boundary with the session nonce. */
async function api(method, path, data) {
  const response = await page.request.fetch(`/wp-json/lps/v1${path}`, {
    method,
    data,
    headers: { "X-WP-Nonce": state.nonce },
  });
  const body = await response.json().catch(() => ({}));
  return { status: response.status(), body };
}

/** Publishes one stored record through the gated endpoint. */
async function publish(id) {
  const result = await api("POST", `/teaching/records/${id}/publish`);
  expect(result.status, `publish ${id}: ${JSON.stringify(result.body)}`).toBe(200);
  return result.body;
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

/** Uploads one PDF version and returns its immutable version id. */
async function uploadVersion(offeringId, name) {
  const response = await page.request.post("/wp-json/lps/v1/teaching/resource-versions", {
    multipart: {
      file: { name, mimeType: "application/pdf", buffer: Buffer.from(PDF) },
      offering_id: String(offeringId),
    },
    headers: { "X-WP-Nonce": state.nonce },
  });
  expect(response.status(), JSON.stringify(await response.json())).toBe(201);
  return (await response.json()).version_id;
}

/** Creates, releases and publishes one resource; returns its response body. */
async function publishResource(input) {
  const created = await api("POST", "/teaching/resources", input);
  expect(created.status, JSON.stringify(created.body)).toBe(201);
  const id = created.body.id;
  if (false !== input.release) {
    const release = await api("POST", `/teaching/resources/${id}/release`, {
      state: input.release_state ?? "released",
      release_at: input.release_at ?? "",
    });
    expect(release.status, JSON.stringify(release.body)).toBe(200);
  }
  await publish(id);
  return created.body;
}

test.describe("task-13: course directories, offerings, units and material views", () => {
  test.beforeAll(async ({ browser }) => {
    test.setTimeout(240_000);
    context = await browser.newContext({ baseURL: BASE_URL });
    page = await context.newPage();
    await loginAsPublisher(page);
    state.nonce = await restNonce(page);
  });

  test.afterAll(async () => {
    await context?.close();
  });

  test("publishes the canonical fixture: co-taught current and completed offerings", async () => {
    test.setTimeout(360_000);

    // The canonical fixture uses the seeded bilingual faculty profiles
    // (PT authority + published EN variant): the English surface resolves
    // the variant name, never a cross-language fallback.
    for (const [key, slug] of [
      ["leadId", "docente-sinais-fixture"],
      ["coTeacherId", "codocente-sinais-fixture"],
    ]) {
      const person = await page.request.get(`/wp-json/wp/v2/people?slug=${slug}&status=any`, {
        headers: { "X-WP-Nonce": state.nonce },
      });
      expect(person.status(), JSON.stringify(await person.json())).toBe(200);
      const list = await person.json();
      expect(list.length, `seeded person ${slug} missing`).toBeGreaterThan(0);
      state[key] = list[0].id;
      expect(state[key]).toBeGreaterThan(0);
    }

    const termCurrent = await api("POST", "/teaching/terms", {
      title: `2026.2 ${RUN}`,
      slug: `term-current-${RUN}`,
      calendar_key: "semester",
      term_code: `2026-2-${RUN}`,
      status: "publish",
      meta: {
        _lps_period_label: `2026.2 ${RUN}`,
        _lps_period_type: "semester",
        _lps_starts_on: "2026-08-01",
        _lps_ends_on: "2026-12-15",
      },
    });
    expect(termCurrent.status, JSON.stringify(termCurrent.body)).toBe(201);
    state.termCurrentId = termCurrent.body.id;

    const termCompleted = await api("POST", "/teaching/terms", {
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

    const course = await api("POST", "/teaching/courses", {
      title: `Disciplina ${RUN}`,
      slug: state.courseSlugPt,
      excerpt: "Disciplina de teste.",
      content: "Conteúdo de fixture.",
      meta: {
        _lps_course_code: `LPS-${RUN.toUpperCase()}`,
        _lps_course_level: "undergraduate",
        _lps_calendar_key: "semester",
        _lps_syllabus: "Ementa padrão da disciplina.",
      },
    });
    expect(course.status, JSON.stringify(course.body)).toBe(201);
    state.coursePtId = course.body.id;

    const courseEn = await api("POST", "/teaching/courses", {
      title: `Course ${RUN}`,
      slug: state.courseSlugEn,
      locale: "en",
      translation_of: state.coursePtId,
      excerpt: "Test course.",
      content: "Fixture content.",
    });
    expect(courseEn.status, JSON.stringify(courseEn.body)).toBe(201);
    state.courseEnId = courseEn.body.id;
    await api("POST", `/teaching/records/${state.courseEnId}/review-translation`);
    await publish(state.courseEnId);
    await publish(state.coursePtId);

    // A second course with only a completed offering exercises the
    // collapsed current stratum and the previous-offerings history.
    const emptyCourse = await api("POST", "/teaching/courses", {
      title: `Disciplina Vazia ${RUN}`,
      slug: state.emptyCourseSlug,
      excerpt: "Disciplina sem oferta corrente.",
      content: "Conteúdo de fixture.",
      meta: {
        _lps_course_code: `EMPTY-${RUN.toUpperCase()}`,
        _lps_course_level: "graduate",
        _lps_calendar_key: "semester",
      },
    });
    expect(emptyCourse.status, JSON.stringify(emptyCourse.body)).toBe(201);
    state.emptyCourseId = emptyCourse.body.id;
    const emptyCourseEn = await api("POST", "/teaching/courses", {
      title: `Empty Course ${RUN}`,
      slug: `empty-course-${RUN}`,
      locale: "en",
      translation_of: state.emptyCourseId,
      excerpt: "Course with no current offering.",
      content: "Fixture content.",
    });
    expect(emptyCourseEn.status, JSON.stringify(emptyCourseEn.body)).toBe(201);
    await api("POST", `/teaching/records/${emptyCourseEn.body.id}/review-translation`);
    await publish(emptyCourseEn.body.id);
    await publish(state.emptyCourseId);

    // The co-taught current offering with syllabus, schedule and LMS handoff.
    const offering = await api("POST", "/teaching/offerings", {
      title: `Turma ${RUN} T01`,
      slug: `turma-${RUN}-t01`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.coursePtId,
      term_id: state.termCurrentId,
      section: "t01",
      team: [
        { person_id: state.leadId, role: "lead" },
        { person_id: state.coTeacherId, role: "co-teacher" },
      ],
      meta: {
        _lps_schedule: "Ter/Qui 10h",
        _lps_venue: "Sala 1",
        _lps_syllabus_snapshot: `Ementa corrente ${RUN}: sinais contínuos.`,
        _lps_lms_url: "https://moodle.example.org/course/view.php?id=13",
        _lps_lms_url_approved: true,
      },
    });
    expect(offering.status, JSON.stringify(offering.body)).toBe(201);
    state.offeringPtId = offering.body.id;
    expect(offering.body.temporal_status).toBe("current");

    const offeringEn = await api("POST", "/teaching/offerings", {
      title: `Section ${RUN} T01`,
      slug: `section-${RUN}-t01`,
      locale: "en",
      translation_of: state.offeringPtId,
      excerpt: "Test section.",
      content: "Fixture content.",
      meta: { _lps_syllabus_snapshot: `Current syllabus ${RUN}: continuous signals.` },
    });
    expect(offeringEn.status, JSON.stringify(offeringEn.body)).toBe(201);
    state.offeringEnId = offeringEn.body.id;
    await api("POST", `/teaching/records/${state.offeringEnId}/review-translation`);
    await publish(state.offeringEnId);
    await publish(state.offeringPtId);

    // The completed offering keeps its own syllabus snapshot and file version.
    const previous = await api("POST", "/teaching/offerings", {
      title: `Turma ${RUN} T02`,
      slug: `turma-${RUN}-t02`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.coursePtId,
      term_id: state.termCompletedId,
      section: "t02",
      team: [{ person_id: state.leadId, role: "lead" }],
      meta: { _lps_syllabus_snapshot: `Ementa concluída ${RUN}: revisão de 2025.` },
    });
    expect(previous.status, JSON.stringify(previous.body)).toBe(201);
    state.offeringPrevId = previous.body.id;
    expect(previous.body.temporal_status).toBe("completed");
    const previousEn = await api("POST", "/teaching/offerings", {
      title: `Section ${RUN} T02`,
      slug: `section-${RUN}-t02`,
      locale: "en",
      translation_of: state.offeringPrevId,
      excerpt: "Test section.",
      content: "Fixture content.",
    });
    expect(previousEn.status, JSON.stringify(previousEn.body)).toBe(201);
    state.offeringPrevEnId = previousEn.body.id;
    await api("POST", `/teaching/records/${state.offeringPrevEnId}/review-translation`);
    await publish(state.offeringPrevEnId);
    await publish(state.offeringPrevId);

    // A completed offering on the second course leaves it with no current section.
    const emptyOffering = await api("POST", "/teaching/offerings", {
      title: `Turma Vazia ${RUN} T01`,
      slug: `turma-vazia-${RUN}-t01`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.emptyCourseId,
      term_id: state.termCompletedId,
      section: "t01",
      team: [{ person_id: state.leadId, role: "lead" }],
    });
    expect(emptyOffering.status, JSON.stringify(emptyOffering.body)).toBe(201);
    const emptyOfferingEn = await api("POST", "/teaching/offerings", {
      title: `Empty Section ${RUN} T01`,
      slug: `empty-section-${RUN}-t01`,
      locale: "en",
      translation_of: emptyOffering.body.id,
      excerpt: "Test section.",
      content: "Fixture content.",
    });
    expect(emptyOfferingEn.status, JSON.stringify(emptyOfferingEn.body)).toBe(201);
    await api("POST", `/teaching/records/${emptyOfferingEn.body.id}/review-translation`);
    await publish(emptyOfferingEn.body.id);
    await publish(emptyOffering.body.id);

    // Units are created out of order on purpose: anchors must stay stable
    // regardless of the rendered position.
    const unit2 = await api("POST", "/teaching/units", {
      title: `Unidade Dois ${RUN}`,
      slug: `unidade-dois-${RUN}`,
      offering_id: state.offeringPtId,
      meta: { _lps_anchor: `u2-${RUN}`, _lps_position: 2 },
    });
    expect(unit2.status, JSON.stringify(unit2.body)).toBe(201);
    state.unit2Id = unit2.body.id;
    const unit1 = await api("POST", "/teaching/units", {
      title: `Unidade Um ${RUN}`,
      slug: `unidade-um-${RUN}`,
      offering_id: state.offeringPtId,
      meta: { _lps_anchor: `u1-${RUN}`, _lps_position: 1 },
    });
    expect(unit1.status, JSON.stringify(unit1.body)).toBe(201);
    state.unit1Id = unit1.body.id;
    await publish(state.unit1Id);
    await publish(state.unit2Id);

    const prevUnit = await api("POST", "/teaching/units", {
      title: `Unidade Anterior ${RUN}`,
      slug: `unidade-anterior-${RUN}`,
      offering_id: state.offeringPrevId,
      meta: { _lps_anchor: `prev-${RUN}`, _lps_position: 1 },
    });
    expect(prevUnit.status, JSON.stringify(prevUnit.body)).toBe(201);
    state.prevUnitId = prevUnit.body.id;
    await publish(state.prevUnitId);
  });

  test("publishes the material set: download, external, withdrawn, draft and scheduled", async () => {
    test.setTimeout(240_000);
    expect(state.offeringPtId, "fixture test must run first").toBeGreaterThan(0);

    // The versioned download on unit 1.
    const versionId = await uploadVersion(state.offeringPtId, `apostila-${RUN}.pdf`);
    const resource = await publishResource({
      title: `Apostila ${RUN}`,
      slug: `apostila-${RUN}`,
      excerpt: "Notas de aula da unidade um.",
      offering_id: state.offeringPtId,
      unit_id: state.unit1Id,
      version_id: versionId,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    state.resourceId = resource.id;
    state.downloadUrl = resource.download_url;

    // A second version on the completed offering: its own file version.
    const prevVersionId = await uploadVersion(state.offeringPrevId, `apostila-2025-${RUN}.pdf`);
    const prevResource = await publishResource({
      title: `Apostila 2025 ${RUN}`,
      slug: `apostila-2025-${RUN}`,
      offering_id: state.offeringPrevId,
      unit_id: state.prevUnitId,
      version_id: prevVersionId,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    state.prevResourceId = prevResource.id;
    state.prevDownloadUrl = prevResource.download_url;
    expect(state.prevDownloadUrl).not.toBe(state.downloadUrl);

    // An external resource on unit 2 with a deliberately long title.
    await publishResource({
      title: LONG_TITLE,
      slug: `externo-${RUN}`,
      offering_id: state.offeringPtId,
      unit_id: state.unit2Id,
      external_url: "https://example.org/notebooks",
      meta: {
        _lps_resource_type: "notebook",
        _lps_resource_language: "en",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });

    // A withdrawn versioned resource keeps its notice without a link, and
    // its guarded route denies delivery on the next request.
    const withdrawnVersion = await uploadVersion(state.offeringPtId, `prova-${RUN}.pdf`);
    const withdrawn = await publishResource({
      title: `Prova Retirada ${RUN}`,
      slug: `prova-retirada-${RUN}`,
      offering_id: state.offeringPtId,
      version_id: withdrawnVersion,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    state.withdrawnId = withdrawn.id;
    state.withdrawnDownloadUrl = withdrawn.download_url;
    const withdraw = await api("POST", `/teaching/resources/${state.withdrawnId}/withdraw`);
    expect(withdraw.status, JSON.stringify(withdraw.body)).toBe(200);

    // A draft and a future-scheduled resource stay invisible.
    await publishResource({
      title: `Rascunho ${RUN}`,
      slug: `rascunho-${RUN}`,
      offering_id: state.offeringPtId,
      external_url: "https://example.org/rascunho",
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
      release: false,
    });
    await publishResource({
      title: `Agendado ${RUN}`,
      slug: `agendado-${RUN}`,
      offering_id: state.offeringPtId,
      external_url: "https://example.org/agendado",
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
      release_state: "scheduled",
      release_at: "2999-01-01T00:00:00+00:00",
    });
  });

  test("the landing lists the course with its current-section shortcut", async ({ browser }) => {
    expect(state.coursePtId, "fixture test must run first").toBeGreaterThan(0);
    const anonymous = await anonymousContext(browser);
    const anonPage = await anonymous.newPage();
    try {
      const response = await anonPage.goto("/pt-br/ensino/");
      expect(response.status()).toBe(200);
      const landing = anonPage.locator(".lps-teaching-landing");
      await expect(landing.locator("h1")).toContainText("Ensino");
      const row = landing.locator("li", { hasText: `Disciplina ${RUN}` });
      await expect(row).toContainText(`LPS-${RUN.toUpperCase()}`);
      await expect(row).toContainText("Graduação");
      await expect(
        row.locator(`a[href*="/pt-br/ensino/disciplinas/${state.courseSlugPt}/"]`).first(),
      ).toBeAttached();
      // The current-section shortcut links straight into the live offering.
      await expect(
        row.locator(
          `a[href="/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/t01/"]`,
        ),
      ).toContainText("Turma em andamento");
    } finally {
      await anonymous.close();
    }
  });

  test("the course page groups current and previous offerings", async ({ browser }) => {
    const anonymous = await anonymousContext(browser);
    const anonPage = await anonymous.newPage();
    try {
      const response = await anonPage.goto(`/pt-br/ensino/disciplinas/${state.courseSlugPt}/`);
      expect(response.status()).toBe(200);
      const course = anonPage.locator("article.lps-course");
      await expect(course.locator("h1")).toContainText(`Disciplina ${RUN}`);
      await expect(course.locator("h2", { hasText: "Em andamento" })).toBeAttached();
      await expect(course.locator("h2", { hasText: "Ofertas anteriores" })).toBeAttached();
      await expect(course.locator('li[data-state="current"]')).toContainText(`Turma ${RUN} T01`);
      await expect(course.locator('li[data-state="completed"]')).toContainText(`Turma ${RUN} T02`);
      await expect(course.locator('li[data-state="completed"]')).toContainText(`2025.2 ${RUN}`);

      // The course with only a completed offering collapses the current stratum.
      const empty = await anonPage.goto(`/pt-br/ensino/disciplinas/${state.emptyCourseSlug}/`);
      expect(empty.status()).toBe(200);
      const emptyCourse = anonPage.locator("article.lps-course");
      await expect(emptyCourse.locator("h2", { hasText: "Em andamento" })).toHaveCount(0);
      await expect(emptyCourse.locator("h2", { hasText: "Ofertas anteriores" })).toBeAttached();
      await expect(emptyCourse.locator('li[data-state="completed"]')).toContainText(
        `Turma Vazia ${RUN} T01`,
      );
    } finally {
      await anonymous.close();
    }
  });

  test("the offering page renders identity, team, syllabus, LMS and materials", async ({ browser }) => {
    const anonymous = await anonymousContext(browser);
    const anonPage = await anonymous.newPage();
    try {
      const response = await anonPage.goto(
        `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/t01/`,
      );
      expect(response.status()).toBe(200);
      const offering = anonPage.locator("article.lps-offering");
      await expect(offering).toHaveAttribute("data-state", "current");
      await expect(offering.locator("h1")).toContainText(`Turma ${RUN} T01`);
      await expect(offering.locator(".lps-term-token")).toContainText(`2026-2-${RUN}-semester`);
      await expect(offering.locator(".lps-section")).toContainText("t01");
      await expect(offering.locator(".lps-temporal-status")).toContainText("Em andamento");
      await expect(offering.locator(".lps-term-period")).toContainText(`2026.2 ${RUN}`);
      await expect(offering.locator(".lps-meta", { hasText: "Ter/Qui 10h" })).toContainText("Sala 1");

      // The co-teaching case: both members with localized roles.
      const team = offering.locator(".lps-teaching-team");
      await expect(team).toContainText(state.leadName);
      await expect(team).toContainText("responsável");
      await expect(team).toContainText(state.coTeacherName);
      await expect(team).toContainText("co-docente");

      // Syllabus snapshot and the labeled LMS handoff, never a raw URL.
      await expect(offering.locator("h2", { hasText: "Ementa" })).toBeAttached();
      await expect(offering).toContainText(`Ementa corrente ${RUN}`);
      const lms = offering.locator(".lps-lms a");
      await expect(lms).toContainText("Ambiente virtual (link externo)");
      await expect(lms).toHaveAttribute("href", "https://moodle.example.org/course/view.php?id=13");

      // The contents strip links the stable unit anchors and materials.
      const toc = offering.locator(".lps-offering-toc");
      await expect(toc.locator(`a[href="#u1-${RUN}"]`)).toBeAttached();
      await expect(toc.locator(`a[href="#u2-${RUN}"]`)).toBeAttached();
      await expect(toc.locator('a[href="#materiais"]')).toBeAttached();
      await expect(offering.locator(`li#u1-${RUN}`)).toBeAttached();
      await expect(offering.locator(`li#u2-${RUN}`)).toBeAttached();

      // The descriptive download row: title link, type, language, size,
      // checksum and update date — no filename guessing.
      const download = offering.locator('li.lps-material[data-state="download"]');
      await expect(download.locator(`a[href="${state.downloadUrl}"]`)).toContainText(
        `Apostila ${RUN}`,
      );
      await expect(download).toContainText("documento");
      await expect(download).toContainText("Português");
      await expect(download).toContainText("application/pdf");
      await expect(download.locator("code.lps-breakable")).toContainText(PDF_SHA256);
      await expect(download).toContainText("Atualizado em");

      // The external row is identified as external.
      const external = offering.locator('li.lps-material[data-state="external"]');
      await expect(external.locator('a[href="https://example.org/notebooks"]')).toContainText(
        LONG_TITLE,
      );
      await expect(external).toContainText("externo");
      await expect(external).toContainText("Inglês");

      // The withdrawn row shows its notice and never a link.
      const withdrawn = offering.locator('li.lps-material[data-state="withdrawn"]');
      await expect(withdrawn).toContainText(`Prova Retirada ${RUN}`);
      await expect(withdrawn).toContainText("Material retirado.");
      await expect(withdrawn.locator("a")).toHaveCount(0);

      // Draft and future-scheduled materials never reach the markup.
      await expect(offering).not.toContainText(`Rascunho ${RUN}`);
      await expect(offering).not.toContainText(`Agendado ${RUN}`);

      // Private fields never leak.
      const html = await offering.innerHTML();
      expect(html).not.toContain("lps-file-");
      expect(html).not.toContain("lpsver:");
      expect(html).not.toContain("_lps_");

      // The term-switch navigation lists both offerings.
      const history = offering.locator(".lps-offering-history");
      await expect(history.locator('li[data-state="current"]')).toContainText(`Turma ${RUN} T01`);
      await expect(history.locator('li[data-state="completed"]')).toContainText(`Turma ${RUN} T02`);
    } finally {
      await anonymous.close();
    }
  });

  test("the download serves bytes matching the displayed checksum", async ({ browser }) => {
    expect(state.downloadUrl, "materials test must run first").not.toBe("");
    const anonymous = await anonymousContext(browser);
    try {
      const response = await anonymous.request.get(state.downloadUrl);
      expect(response.status()).toBe(200);
      const body = await response.body();
      expect(createHash("sha256").update(body).digest("hex")).toBe(PDF_SHA256);
      // The withdrawn resource's guarded route denies delivery outright.
      const withdrawn = await anonymous.request.get(state.withdrawnDownloadUrl);
      expect(withdrawn.status()).toBe(404);
    } finally {
      await anonymous.close();
    }
  });

  test("the completed offering keeps its own syllabus and file version", async ({ browser }) => {
    const anonymous = await anonymousContext(browser);
    const anonPage = await anonymous.newPage();
    try {
      const response = await anonPage.goto(
        `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2025-2-${RUN}-semester/t02/`,
      );
      expect(response.status()).toBe(200);
      const offering = anonPage.locator("article.lps-offering");
      await expect(offering).toHaveAttribute("data-state", "completed");
      await expect(offering.locator(".lps-temporal-status")).toContainText("Concluída");
      await expect(offering).toContainText(`Ementa concluída ${RUN}`);
      await expect(offering).not.toContainText(`Ementa corrente ${RUN}`);
      // The completed term's own file version is a distinct download.
      const download = offering.locator('li.lps-material[data-state="download"]');
      await expect(download.locator(`a[href="${state.prevDownloadUrl}"]`)).toContainText(
        `Apostila 2025 ${RUN}`,
      );
      await expect(download.locator("code.lps-breakable")).toContainText(PDF_SHA256);

      const file = await anonymous.request.get(state.prevDownloadUrl);
      expect(file.status()).toBe(200);
      expect(createHash("sha256").update(await file.body()).digest("hex")).toBe(PDF_SHA256);
    } finally {
      await anonymous.close();
    }
  });

  test("the English surface shares the co-teacher data and localizes labels", async ({ browser }) => {
    const anonymous = await anonymousContext(browser);
    const anonPage = await anonymous.newPage();
    try {
      const response = await anonPage.goto(
        `/en/teaching/courses/${state.courseSlugEn}/2026-2-${RUN}-semester/t01/`,
      );
      expect(response.status()).toBe(200);
      const offering = anonPage.locator("article.lps-offering");
      await expect(offering).toHaveAttribute("lang", "en");
      await expect(offering).toHaveAttribute("data-state", "current");
      await expect(offering.locator(".lps-temporal-status")).toContainText("In progress");
      // Co-teacher data is authority-owned: the English variant resolves
      // the seeded EN person variants, never a cross-language fallback.
      const team = offering.locator(".lps-teaching-team");
      await expect(team).toContainText(state.leadNameEn);
      await expect(team).toContainText(state.coTeacherNameEn);
      await expect(team).toContainText("co-teacher");
      await expect(offering).toContainText(`Current syllabus ${RUN}`);
      await expect(offering.locator(".lps-lms a")).toContainText("Virtual classroom");
      await expect(offering.locator('li.lps-material[data-state="external"]')).toContainText(
        "English",
      );
    } finally {
      await anonymous.close();
    }
  });

  test("unit anchors persist across ordering changes", async ({ browser }) => {
    expect(state.unit1Id, "fixture test must run first").toBeGreaterThan(0);
    // Swap the rendered order through the governed meta boundary.
    const update = await page.request.post(`/wp-json/wp/v2/units/${state.unit1Id}`, {
      data: { meta: { _lps_position: 3 } },
      headers: { "X-WP-Nonce": state.nonce },
    });
    expect(update.status(), JSON.stringify(await update.json())).toBe(200);

    const anonymous = await anonymousContext(browser);
    const anonPage = await anonymous.newPage();
    try {
      const response = await anonPage.goto(
        `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/t01/`,
      );
      expect(response.status()).toBe(200);
      const offering = anonPage.locator("article.lps-offering");
      // The anchors are the stored keys: reordering never renames them.
      await expect(offering.locator(`li#u1-${RUN}`)).toBeAttached();
      await expect(offering.locator(`li#u2-${RUN}`)).toBeAttached();
      await expect(offering.locator(`.lps-offering-toc a[href="#u1-${RUN}"]`)).toBeAttached();
      // Unit 2 now renders before unit 1 while the anchors stay put.
      const units = await offering.locator(".lps-units > li").all();
      const ids = [];
      for (const unit of units) {
        ids.push(await unit.getAttribute("id"));
      }
      expect(ids).toEqual([`u2-${RUN}`, `u1-${RUN}`]);
    } finally {
      await anonymous.close();
    }
  });

  test("a hundred resources stay navigable under their unit anchors", async ({ browser }) => {
    test.setTimeout(600_000);
    expect(state.offeringPtId, "fixture test must run first").toBeGreaterThan(0);
    // One hundred external resources on unit 2: create, release, publish in
    // small batches so the single-threaded fixture server keeps up.
    const created = [];
    for (let batch = 0; batch < 100; batch += 10) {
      const results = await Promise.all(
        Array.from({ length: 10 }, (_, offset) => {
          const index = batch + offset;
          return api("POST", "/teaching/resources", {
            title: `Material ${RUN} ${String(index + 1).padStart(3, "0")}`,
            slug: `material-${RUN}-${index}`,
            offering_id: state.offeringPtId,
            unit_id: state.unit2Id,
            external_url: `https://example.org/material-${RUN}-${index}`,
            meta: {
              _lps_resource_type: "link",
              _lps_resource_language: "pt-br",
              _lps_rights_review: "approved",
              _lps_accessibility_review: "approved",
            },
          });
        }),
      );
      for (const result of results) {
        expect(result.status, JSON.stringify(result.body)).toBe(201);
        created.push(result.body.id);
      }
    }
    for (let batch = 0; batch < created.length; batch += 10) {
      const ids = created.slice(batch, batch + 10);
      await Promise.all(
        ids.map((id) => api("POST", `/teaching/resources/${id}/release`, { state: "released" })),
      );
      await Promise.all(ids.map((id) => publish(id)));
    }

    const anonymous = await anonymousContext(browser);
    const anonPage = await anonymous.newPage();
    try {
      const response = await anonPage.goto(
        `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/t01/`,
      );
      expect(response.status()).toBe(200);
      const offering = anonPage.locator("article.lps-offering");
      // The unit anchor still jumps straight to the long material group.
      await expect(offering.locator(`.lps-offering-toc a[href="#u2-${RUN}"]`)).toBeAttached();
      const unit2 = offering.locator(`li#u2-${RUN}`);
      await expect(unit2.locator("li.lps-material")).toHaveCount(101);
      await expect(unit2).toContainText(`Material ${RUN} 001`);
      await expect(unit2).toContainText(`Material ${RUN} 100`);
      // The page does not overflow horizontally at the narrowest width.
      await anonPage.setViewportSize({ width: 320, height: 720 });
      const overflow = await anonPage.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );
      expect(overflow).toBeLessThanOrEqual(0);
    } finally {
      await anonymous.close();
    }
  });

  test("mobile and no-JS render the same navigable surface", async ({ browser }) => {
    const anonymous = await anonymousContext(browser);
    const anonPage = await anonymous.newPage();
    try {
      await anonPage.setViewportSize({ width: 375, height: 800 });
      const response = await anonPage.goto(
        `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/t01/`,
      );
      expect(response.status()).toBe(200);
      const offering = anonPage.locator("article.lps-offering");
      await expect(offering.locator(".lps-offering-toc")).toBeAttached();
      await expect(offering.locator('li.lps-material[data-state="download"]')).toBeAttached();
      const overflow = await anonPage.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );
      expect(overflow).toBeLessThanOrEqual(0);
    } finally {
      await anonymous.close();
    }

    // No JavaScript: the whole surface is server-rendered, so the same
    // assertions hold with scripting disabled.
    const noJs = await browser.newContext({
      baseURL: BASE_URL,
      javaScriptEnabled: false,
    });
    await noJs.addCookies([
      {
        name: "playground_auto_login_already_happened",
        value: "1",
        domain: new URL(BASE_URL).hostname,
        path: "/",
      },
    ]);
    const noJsPage = await noJs.newPage();
    try {
      const response = await noJsPage.goto(
        `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/t01/`,
      );
      expect(response.status()).toBe(200);
      const offering = noJsPage.locator("article.lps-offering");
      await expect(offering.locator(`a[href="${state.downloadUrl}"]`)).toBeAttached();
      await expect(offering.locator(`.lps-offering-toc a[href="#u1-${RUN}"]`)).toBeAttached();
      await expect(offering.locator('li.lps-material[data-state="withdrawn"]')).toContainText(
        "Material retirado.",
      );
    } finally {
      await noJs.close();
    }
  });

  test("a missing external destination is labeled, never a raw private URL", async ({ browser }) => {
    expect(state.offeringPtId, "fixture test must run first").toBeGreaterThan(0);
    // An approved external resource whose destination does not resolve: the
    // row stays labeled external and the page leaks no private field.
    await publishResource({
      title: `Destino Ausente ${RUN}`,
      slug: `destino-ausente-${RUN}`,
      offering_id: state.offeringPtId,
      external_url: "https://definitely-missing.example.invalid/notes",
      meta: {
        _lps_resource_type: "link",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    const anonymous = await anonymousContext(browser);
    const anonPage = await anonymous.newPage();
    try {
      const response = await anonPage.goto(
        `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/t01/`,
      );
      expect(response.status()).toBe(200);
      const offering = anonPage.locator("article.lps-offering");
      const row = offering.locator("li.lps-material", { hasText: `Destino Ausente ${RUN}` });
      await expect(row).toContainText("externo");
      await expect(row.locator('a[href="https://definitely-missing.example.invalid/notes"]')).toBeAttached();
      const html = await offering.innerHTML();
      expect(html).not.toContain("lps-file-");
      expect(html).not.toContain("lpsver:");
      expect(html).not.toContain("_lps_");
    } finally {
      await anonymous.close();
    }
  });
});

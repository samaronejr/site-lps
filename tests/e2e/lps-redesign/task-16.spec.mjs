import { createHash } from "node:crypto";
import { expect, test } from "@playwright/test";

/**
 * Task 16 — the faculty task dashboard and complete publication journeys.
 *
 * The spec exercises the real authenticated surface: a granted professor
 * signs in, sees the task list derived from persisted grants, creates a unit
 * and a material through the dashboard forms, uploads and selects a file
 * version, releases and publishes the material, submits a news item that an
 * editor rejects with a note and a publisher approves, proposes a profile
 * change that an editor applies, and copies the offering forward into a new
 * term — all without HTML, layout editing or administrator privileges.
 *
 * The pure task/validation contracts are covered by LpsRedesignTask16Test;
 * this spec proves the wired boundary end to end.
 */

const RUN = `t16-${Date.now().toString(36)}`;
const BASE_URL = process.env.LPS_BASE_URL ?? "http://localhost:8900";

const PDF_BYTES = `%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\n% task-16 fixture ${RUN}\n%%EOF\n`;

const state = {
  publisherNonce: "",
  adminNonce: "",
  professorNonce: "",
  delegateNonce: "",
  editorNonce: "",
  professorId: 0,
  delegateId: 0,
  personId: 0,
  person2Id: 0,
  termCompletedId: 0,
  termNewId: 0,
  coursePtId: 0,
  offeringPtId: 0,
  offeringOtherId: 0,
  unitId: 0,
  resourceId: 0,
  newsId: 0,
  copiedOfferingId: 0,
};

let context;
let page;
let adminContext;
let adminPage;
let professorContext;
let professorPage;
let delegateContext;
let delegatePage;
let editorContext;
let editorPage;

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
  for (let attempt = 0; attempt < 3; attempt += 1) {
    await target.goto("/wp-login.php");
    const login = target.locator("#user_login");
    await login.waitFor({ state: "visible", timeout: 15_000 });
    await login.fill(user);
    await target.locator("#user_pass").fill(pass);
    await Promise.all([
      target.waitForLoadState("domcontentloaded", { timeout: 120_000 }),
      target.locator("#wp-submit").click(),
    ]);
    if (mfa) {
      const challenge = target.locator(
        "#loginform input[type=submit], #loginform button[type=submit]",
      );
      await challenge.waitFor({ state: "visible", timeout: 60_000 });
      await Promise.all([
        target.waitForLoadState("domcontentloaded", { timeout: 120_000 }),
        challenge.click(),
      ]);
    }
    const settled = await expect
      .poll(() => new URL(target.url()).pathname, { timeout: 60_000 })
      .not.toBe("/wp-login.php")
      .then(() => true)
      .catch(() => false);
    if (settled) {
      // The WASM auth path can drop the session-token write between the 2FA
      // validation and the redirect; the URL leaves wp-login.php but no
      // wordpress_logged_in cookie lands. Only a committed cookie counts.
      const cookies = await target.context().cookies();
      const loggedIn = cookies.some((c) => c.name.startsWith("wordpress_logged_in"));
      if (loggedIn) {
        await expect(target.locator("#login_error")).toHaveCount(0);
        return;
      }
    }
  }
  throw new Error(`sign-in for ${user} never left wp-login.php`);
}

/** Reads the REST nonce bound to a signed-in session. */
async function restNonce(target) {
  for (let attempt = 0; attempt < 40; attempt += 1) {
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
    // The single-process WASM runtime drops requests under parallel load; a
    // pause between attempts keeps the retry deterministic.
    await target.waitForTimeout(1500);
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

/** Publishes one record through the teaching boundary as the publisher. */
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

/** Grants one scoped role on an offering or the news lane to an account. */
async function grant(userLogin, offeringId, role, scope = "offering") {
  const result = await adminPage.request.post("/wp-json/lps/v1/test/grants", {
    data: { user_login: userLogin, offering_id: offeringId, role, scope },
    headers: { "X-WP-Nonce": state.adminNonce },
  });
  const body = await result.json().catch(() => ({}));
  // Grants persist across spec runs on the shared site; an existing grant is
  // the same end state as a fresh one, so a duplicate is not a failure.
  if (result.status() === 403 && body?.code === "lps_teaching_grant_duplicate") {
    return;
  }
  expect(result.status(), JSON.stringify(body)).toBe(200);
}

/** Extracts the dashboard nonce of one named form from the page. */
async function formNonce(target, action) {
  const form = target.locator(`form[data-dashboard-form="${action}"]`).first();
  await form.waitFor({ state: "visible", timeout: 30_000 });
  return form.locator('input[name="_lps_dashboard_nonce"]').inputValue();
}

/** Submits one dashboard form and returns the landed page. */
async function submitForm(target, action, fields = {}, file = null) {
  const form = target.locator(`form[data-dashboard-form="${action}"]`).first();
  await form.waitFor({ state: "visible", timeout: 30_000 });
  for (const [name, value] of Object.entries(fields)) {
    const input = form.locator(`[name="${name}"]`).first();
    const tag = await input.evaluate((el) => el.tagName.toLowerCase());
    if (tag === "select") {
      await input.selectOption(String(value));
    } else if ((await input.getAttribute("type")) === "checkbox") {
      if (value) {
        await input.check();
      }
    } else {
      await input.fill(String(value));
    }
  }
  if (file) {
    await form.locator('input[name="file"]').setInputFiles({
      name: file.name,
      mimeType: file.mimeType,
      buffer: Buffer.from(file.contents, "utf8"),
    });
  }
  // The redirect always lands on a dashboard URL carrying lps_notice or
  // lps_error; waiting for that URL (not a load state, which the already-
  // loaded page satisfies before the click navigates) is the reliable signal.
  await Promise.all([
    target.waitForURL((u) => /[?&]lps_(notice|error)=/.test(u.search), {
      waitUntil: "domcontentloaded",
      timeout: 120_000,
    }),
    form.locator('button[type="submit"], input[type="submit"]').first().click(),
  ]);
  // The URL match can precede the new document's parse under WASM; settle
  // the load before the caller asserts on the rendered notice.
  await target.waitForLoadState("domcontentloaded", { timeout: 120_000 }).catch(() => {});
  return target;
}

/** Clicks one button and waits for the dashboard redirect to land. */
async function clickAndWait(target, button) {
  await Promise.all([
    target.waitForURL((u) => /[?&]lps_(notice|error)=/.test(u.search), {
      waitUntil: "domcontentloaded",
      timeout: 240_000,
    }),
    button.click(),
  ]);
  await target.waitForLoadState("domcontentloaded", { timeout: 120_000 }).catch(() => {});
}

test.describe("task-16: the faculty task dashboard and publication journeys", () => {
  test.beforeAll(async ({ browser }) => {
    test.setTimeout(600_000);
    context = await browser.newContext({ baseURL: BASE_URL });
    page = await context.newPage();
    // Warm the WASM runtime before the first login: the first request after
    // a server start pays the cold-boot cost and can starve the MFA flow.
    await page.goto("/", { waitUntil: "domcontentloaded", timeout: 120_000 }).catch(() => {});
    await loginAs(page, "lps-t16-publisher", "lps-t16-publisher-pass", true);
    state.publisherNonce = await restNonce(page);

    adminContext = await browser.newContext({ baseURL: BASE_URL });
    adminPage = await adminContext.newPage();
    await loginAs(adminPage, "admin", "password", true);
    state.adminNonce = await restNonce(adminPage);

    professorContext = await browser.newContext({ baseURL: BASE_URL });
    professorPage = await professorContext.newPage();
    await loginAs(professorPage, "lps-t16-professor", "lps-t16-professor-pass", true);
    state.professorNonce = await restNonce(professorPage);

    const professorUser = await adminPage.request.get(
      "/wp-json/wp/v2/users?slug=lps-t16-professor",
      {
        headers: { "X-WP-Nonce": state.adminNonce },
      },
    );
    state.professorId = (await professorUser.json())[0]?.id ?? 0;
    const delegateUser = await adminPage.request.get("/wp-json/wp/v2/users?slug=lps-t16-delegate", {
      headers: { "X-WP-Nonce": state.adminNonce },
    });
    state.delegateId = (await delegateUser.json())[0]?.id ?? 0;
  });

  /** Signs the delegate in on first use and reuses the session after. */
  async function ensureDelegate(browser) {
    if (delegatePage) {
      return;
    }
    delegateContext = await browser.newContext({ baseURL: BASE_URL });
    delegatePage = await delegateContext.newPage();
    await loginAs(delegatePage, "lps-t16-delegate", "lps-t16-delegate-pass", true);
    state.delegateNonce = await restNonce(delegatePage);
  }

  /** Signs the editor in on first use and reuses the session after. */
  async function ensureEditor(browser) {
    if (editorPage) {
      return;
    }
    editorContext = await browser.newContext({ baseURL: BASE_URL });
    editorPage = await editorContext.newPage();
    await loginAs(editorPage, "lps-t16-editor", "lps-t16-editor-pass", true);
    state.editorNonce = await restNonce(editorPage);
  }

  test.afterAll(async () => {
    await context?.close();
    await adminContext?.close();
    await professorContext?.close();
    await delegateContext?.close();
    await editorContext?.close();
  });

  test("the publisher builds the source offering graph", async () => {
    test.setTimeout(180_000);
    expect(state.professorId, "professor account must resolve").toBeGreaterThan(0);

    // The professor's person record is authored by the professor account so
    // the dashboard resolves the profile link explicitly, never by guessing.
    const person = await page.request.post("/wp-json/wp/v2/people", {
      data: {
        title: `Docente ${RUN}`,
        slug: `docente-${RUN}`,
        status: "draft",
        author: state.professorId,
        meta: { _lps_locale: "pt-br", _lps_canonical_name: `Docente ${RUN}` },
      },
      headers: { "X-WP-Nonce": state.publisherNonce },
    });
    expect(person.status(), JSON.stringify(await person.json())).toBe(201);
    state.personId = (await person.json()).id;

    const person2 = await page.request.post("/wp-json/wp/v2/people", {
      data: {
        title: `Docente B ${RUN}`,
        slug: `docente-b-${RUN}`,
        status: "draft",
        author: state.delegateId,
        meta: { _lps_locale: "pt-br", _lps_canonical_name: `Docente B ${RUN}` },
      },
      headers: { "X-WP-Nonce": state.publisherNonce },
    });
    expect(person2.status(), JSON.stringify(await person2.json())).toBe(201);
    state.person2Id = (await person2.json()).id;

    for (const [slug, label, starts, ends] of [
      [`term-completed-${RUN}`, `2025.2 ${RUN}`, "2025-08-01", "2025-12-15"],
      [`term-new-${RUN}`, `2027.1 ${RUN}`, "2027-03-02", "2027-07-10"],
    ]) {
      const term = await api(page, state.publisherNonce, "POST", "/teaching/terms", {
        title: label,
        slug,
        calendar_key: "semester",
        term_code: slug,
        status: "publish",
        meta: {
          _lps_period_label: label,
          _lps_period_type: "semester",
          _lps_starts_on: starts,
          _lps_ends_on: ends,
        },
      });
      expect(term.status, JSON.stringify(term.body)).toBe(201);
      if (slug.startsWith("term-completed")) {
        state.termCompletedId = term.body.id;
      } else {
        state.termNewId = term.body.id;
      }
    }

    const course = await api(page, state.publisherNonce, "POST", "/teaching/courses", {
      title: `Disciplina ${RUN}`,
      slug: `disciplina-${RUN}`,
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
      slug: `course-${RUN}`,
      locale: "en",
      translation_of: state.coursePtId,
      excerpt: "Test course.",
      content: "Fixture content.",
    });
    expect(courseEn.status, JSON.stringify(courseEn.body)).toBe(201);
    await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/records/${courseEn.body.id}/review-translation`,
    );
    await publish(courseEn.body.id);
    await publish(state.coursePtId);

    // The granted offering: the professor leads, the delegate co-teaches.
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
      meta: { _lps_schedule: "Ter/Qui 10h-12h", _lps_venue: "Sala 201" },
    });
    expect(offering.status, JSON.stringify(offering.body)).toBe(201);
    state.offeringPtId = offering.body.id;

    const offeringEn = await api(page, state.publisherNonce, "POST", "/teaching/offerings", {
      title: `Section ${RUN} T01`,
      slug: `section-${RUN}-t01`,
      locale: "en",
      translation_of: state.offeringPtId,
      excerpt: "Completed test section.",
      content: "Fixture content.",
    });
    expect(offeringEn.status, JSON.stringify(offeringEn.body)).toBe(201);
    await api(
      page,
      state.publisherNonce,
      "POST",
      `/teaching/records/${offeringEn.body.id}/review-translation`,
    );
    await publish(offeringEn.body.id);
    await publish(state.offeringPtId);

    // A second offering the professor is not granted on anchors scope denial.
    const other = await api(page, state.publisherNonce, "POST", "/teaching/offerings", {
      title: `Turma ${RUN} T02`,
      slug: `turma-${RUN}-t02`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.coursePtId,
      term_id: state.termCompletedId,
      section: "t02",
      team: [{ person_id: state.person2Id, role: "lead" }],
    });
    expect(other.status, JSON.stringify(other.body)).toBe(201);
    state.offeringOtherId = other.body.id;

    await grant("lps-t16-professor", state.offeringPtId, "professor");
    await grant("lps-t16-professor", 0, "professor", "news");
    await grant("lps-t16-delegate", state.offeringPtId, "delegate");
  });

  test("anonymous visitors are redirected to login", async ({ browser }) => {
    const anon = await anonymousContext(browser);
    const anonPage = await anon.newPage();
    await anonPage.goto("/pt-br/painel/", { waitUntil: "domcontentloaded" });
    await expect(anonPage).toHaveURL(/wp-login\.php/);
    expect(new URL(anonPage.url()).searchParams.get("redirect_to")).toContain("/pt-br/painel/");
    await anon.close();
  });

  test("the professor sees the scoped task list and the assigned offering", async () => {
    test.setTimeout(60_000);
    await professorPage.goto("/pt-br/painel/", { waitUntil: "domcontentloaded" });
    await expect(professorPage.locator('[data-dashboard-view="home"]')).toBeVisible();
    await expect(professorPage.locator(".lps-task-list")).toContainText("Meu perfil");
    await expect(professorPage.locator(".lps-task-list")).toContainText("Minhas ofertas");
    await expect(professorPage.locator(".lps-task-list")).toContainText("Enviar notícia");
    // The professor never sees the editor-only create or review tasks.
    await expect(professorPage.locator(".lps-task-list")).not.toContainText("Criar oferta");
    await expect(professorPage.locator(".lps-task-list")).not.toContainText("Fila de revisão");
    await expect(
      professorPage.locator('[aria-labelledby="lps-dash-offerings"] .lps-record-list'),
    ).toContainText(`Turma ${RUN} T01`);
  });

  test("the professor creates a unit and a material through the dashboard", async () => {
    test.setTimeout(120_000);
    await professorPage.goto(`/pt-br/painel/ofertas/${state.offeringPtId}/`, {
      waitUntil: "domcontentloaded",
    });
    await expect(professorPage.locator('[data-dashboard-view="offering"]')).toBeVisible();

    // A denied submit recalls the form instead of losing the values: a
    // zero position passes the browser's number check but fails the
    // server-side contract, and the anchor field keeps its value.
    await submitForm(professorPage, "lps_dashboard_unit", {
      title: `Unidade ${RUN}`,
      anchor: `unidade-${RUN}`,
      position: "0",
    });
    await expect(professorPage.locator("[data-dashboard-error]")).toBeVisible({ timeout: 30_000 });
    await expect(
      professorPage.locator('form[data-dashboard-form="lps_dashboard_unit"] input[name="anchor"]'),
    ).toHaveValue(`unidade-${RUN}`);

    await submitForm(professorPage, "lps_dashboard_unit", {
      title: `Unidade ${RUN}`,
      excerpt: "Unidade de teste.",
      anchor: `unidade-${RUN}`,
      position: "1",
      topic_date: "2025-09-02",
    });
    await expect(professorPage.locator("[data-dashboard-notice]")).toBeVisible({ timeout: 30_000 });
    await expect(professorPage.locator(".lps-record-list")).toContainText(`Unidade ${RUN}`);

    const units = await api(
      page,
      state.publisherNonce,
      "GET",
      `/teaching/units?offering_id=${state.offeringPtId}`,
    );
    const created = (units.body.items ?? units.body ?? []).find?.((row) =>
      row.title?.includes?.(`Unidade ${RUN}`),
    );
    state.unitId = created?.id ?? 0;
    if (0 === state.unitId) {
      const list = await page.request.get(`/wp-json/wp/v2/units?per_page=50&status=any`, {
        headers: { "X-WP-Nonce": state.publisherNonce },
      });
      const rows = await list.json();
      state.unitId = rows.find((row) => row.title?.rendered?.includes(`Unidade ${RUN}`))?.id ?? 0;
    }
    expect(state.unitId, "the dashboard unit must persist").toBeGreaterThan(0);

    // The material form mints the version and attaches it in one submit.
    await professorPage.goto(`/pt-br/painel/ofertas/${state.offeringPtId}/`, {
      waitUntil: "domcontentloaded",
    });
    await submitForm(
      professorPage,
      "lps_dashboard_resource",
      {
        title: `Apostila ${RUN}`,
        excerpt: "Apostila de teste.",
        resource_type: "document",
        resource_language: "pt-br",
        unit_id: String(state.unitId),
      },
      { name: `apostila-${RUN}.pdf`, mimeType: "application/pdf", contents: PDF_BYTES },
    );
    await expect(professorPage.locator("[data-dashboard-notice]")).toBeVisible({ timeout: 30_000 });
    await expect(
      professorPage.locator('[aria-labelledby="lps-resources"] .lps-record-list'),
    ).toContainText(`Apostila ${RUN}`);

    const resources = await page.request.get(`/wp-json/wp/v2/resources?per_page=50&status=any`, {
      headers: { "X-WP-Nonce": state.publisherNonce },
    });
    const rows = await resources.json();
    state.resourceId =
      rows.find((row) => row.title?.rendered?.includes(`Apostila ${RUN}`))?.id ?? 0;
    expect(state.resourceId, "the dashboard material must persist").toBeGreaterThan(0);
  });

  test("the professor releases and publishes the material", async () => {
    test.setTimeout(120_000);
    expect(state.resourceId, "material test must run first").toBeGreaterThan(0);

    // The rights and accessibility reviews are editor-owned fields; the
    // publisher clears them through the REST boundary the way an editor would.
    const cleared = await page.request.post(`/wp-json/wp/v2/resources/${state.resourceId}`, {
      data: { meta: { _lps_rights_review: "approved", _lps_accessibility_review: "approved" } },
      headers: { "X-WP-Nonce": state.publisherNonce },
    });
    expect(cleared.status(), JSON.stringify(await cleared.json())).toBe(200);

    await professorPage.goto(`/pt-br/painel/ofertas/${state.offeringPtId}/`, {
      waitUntil: "domcontentloaded",
    });
    const resourceRow = professorPage.locator(`[data-resource-id="${state.resourceId}"]`);
    await expect(resourceRow).toBeVisible();

    // Release the material through its scoped form.
    const releaseForm = resourceRow.locator('form[data-dashboard-form="lps_dashboard_release"]');
    await clickAndWait(professorPage, releaseForm.locator('button[value="release"]'));
    await expect(professorPage.locator("[data-dashboard-notice]")).toBeVisible({ timeout: 30_000 });

    // Publish the record so the public download route resolves.
    const publishForm = resourceRow.locator('form[data-dashboard-form="lps_dashboard_publish"]');
    await clickAndWait(professorPage, publishForm.locator('button[type="submit"]'));
    await expect(professorPage.locator("[data-dashboard-notice]")).toBeVisible({ timeout: 30_000 });
    await expect(resourceRow).toContainText("Público");

    // The public download resolves through the guarded opaque-token route.
    const stored = await record("resources", state.resourceId);
    expect(stored.meta?._lps_release_state ?? stored._lps_release_state).toBe("released");
    const downloadHref = await resourceRow
      .locator('a[href*="/lps-resource/"]')
      .getAttribute("href");
    expect(downloadHref, "released material exposes the guarded download").toBeTruthy();
    const download = await page.request.get(downloadHref, {
      headers: { "X-WP-Nonce": state.publisherNonce },
    });
    expect([200, 302]).toContain(download.status());
  });

  test("the professor submits news; the editor rejects with a note; the publisher approves", async ({
    browser,
  }) => {
    test.setTimeout(240_000);
    await ensureEditor(browser);
    await professorPage.goto("/pt-br/painel/noticias/", { waitUntil: "domcontentloaded" });
    // The create form is the last news form on the page; earlier ones are the
    // per-item edit forms inside closed <details> elements.
    const newsForm = professorPage.locator('form[data-dashboard-form="lps_dashboard_news"]').last();
    await newsForm.locator('input[name="title"]').fill(`Notícia ${RUN}`);
    await newsForm.locator('textarea[name="excerpt"]').fill("Resumo da notícia.");
    await newsForm.locator('textarea[name="content"]').fill("Corpo da notícia.");
    await newsForm.locator('input[name="canonical_date"]').fill("2026-09-19T10:00");
    await clickAndWait(professorPage, newsForm.locator('button[type="submit"]'));
    await expect(professorPage.locator("[data-dashboard-notice]")).toBeVisible({ timeout: 30_000 });
    await expect(professorPage.locator(".lps-record-list")).toContainText(`Notícia ${RUN}`);
    await expect(professorPage.locator(".lps-record-list")).toContainText("Em revisão");

    const newsList = await page.request.get("/wp-json/wp/v2/news?per_page=50&status=any", {
      headers: { "X-WP-Nonce": state.publisherNonce },
    });
    const newsRows = await newsList.json();
    state.newsId = newsRows.find((row) => row.title?.rendered?.includes(`Notícia ${RUN}`))?.id ?? 0;
    expect(state.newsId, "the submitted news must persist").toBeGreaterThan(0);

    // The editor sees the submission in the review queue and rejects it with
    // a required note; a rejection without the note is denied.
    await editorPage.goto("/pt-br/painel/revisao/", { waitUntil: "domcontentloaded" });
    await expect(editorPage.locator('[data-dashboard-view="review"]')).toBeVisible();
    await expect(
      editorPage.locator('[aria-labelledby="lps-review-news"] .lps-record-list'),
    ).toContainText(`Notícia ${RUN}`);

    const reviewForm = editorPage
      .locator('form[data-dashboard-form="lps_dashboard_review"]')
      .filter({ has: editorPage.locator(`input[name="post_id"][value="${state.newsId}"]`) })
      .first();
    await reviewForm.locator('input[name="note"]').fill("");
    await clickAndWait(editorPage, reviewForm.locator('button[value="reject"]'));
    await expect(editorPage.locator("[data-dashboard-error]")).toBeVisible({ timeout: 30_000 });

    await editorPage.goto("/pt-br/painel/revisao/", { waitUntil: "domcontentloaded" });
    const reviewForm2 = editorPage
      .locator('form[data-dashboard-form="lps_dashboard_review"]')
      .filter({ has: editorPage.locator(`input[name="post_id"][value="${state.newsId}"]`) })
      .first();
    await reviewForm2.locator('input[name="note"]').fill("Corrija o resumo antes de reenviar.");
    await clickAndWait(editorPage, reviewForm2.locator('button[value="reject"]'));
    await expect(editorPage.locator("[data-dashboard-notice]")).toBeVisible({ timeout: 30_000 });

    // The professor sees the rejection note on the returned draft.
    await professorPage.goto("/pt-br/painel/noticias/", { waitUntil: "domcontentloaded" });
    await expect(professorPage.locator("[data-review-note]")).toContainText("Corrija o resumo");

    // The professor resubmits the same draft with the fix applied.
    const editDetails = professorPage.locator(".lps-dashboard-edit").first();
    await editDetails.locator("summary").click();
    const editForm = editDetails.locator('form[data-dashboard-form="lps_dashboard_news"]');
    await editForm.locator('input[name="title"]').fill(`Notícia ${RUN} revisada`);
    await editForm.locator('textarea[name="excerpt"]').fill("Resumo corrigido da notícia.");
    await editForm.locator('textarea[name="content"]').fill("Corpo corrigido da notícia.");
    await editForm.locator('input[name="canonical_date"]').fill("2026-09-19T11:00");
    await clickAndWait(professorPage, editForm.locator('button[type="submit"]'));
    await expect(professorPage.locator("[data-dashboard-notice]")).toBeVisible({ timeout: 30_000 });

    // The publisher approves the resubmission; the item goes public.
    await page.goto("/pt-br/painel/revisao/", { waitUntil: "domcontentloaded" });
    const approveForm = page
      .locator('form[data-dashboard-form="lps_dashboard_review"]')
      .filter({ has: page.locator(`input[name="post_id"][value="${state.newsId}"]`) })
      .first();
    await clickAndWait(page, approveForm.locator('button[value="approve"]'));
    await expect(page.locator("[data-dashboard-notice]")).toBeVisible({ timeout: 30_000 });

    const published = await record("news", state.newsId);
    expect(published.status).toBe("publish");
    const publicUrl = published.link;
    const anon = await anonymousContext(await professorPage.context().browser());
    const anonPage = await anon.newPage();
    await anonPage.goto(publicUrl, { waitUntil: "domcontentloaded" });
    await expect(anonPage.locator("body")).toContainText(`Notícia ${RUN} revisada`);
    await anon.close();
  });

  test("the professor proposes a profile change and the editor applies it", async ({ browser }) => {
    test.setTimeout(180_000);
    await ensureEditor(browser);
    await professorPage.goto("/pt-br/painel/perfil/", { waitUntil: "domcontentloaded" });
    await expect(professorPage.locator('[data-dashboard-view="profile"]')).toBeVisible();

    // An invalid ORCID is denied with the field named and the values kept.
    await submitForm(professorPage, "lps_dashboard_profile", {
      "fields[_lps_orcid]": "1234",
      "fields[_lps_public_email]": `docente-${RUN}@example.org`,
    });
    await expect(professorPage.locator("[data-dashboard-error]")).toBeVisible({ timeout: 30_000 });
    await expect(
      professorPage.locator(
        'form[data-dashboard-form="lps_dashboard_profile"] input[name="fields[_lps_public_email]"]',
      ),
    ).toHaveValue(`docente-${RUN}@example.org`);

    await submitForm(professorPage, "lps_dashboard_profile", {
      "fields[_lps_orcid]": "0000-0001-2345-6789",
      "fields[_lps_public_email]": `docente-${RUN}@example.org`,
    });
    await expect(professorPage.locator("[data-dashboard-notice]")).toBeVisible({ timeout: 30_000 });
    await expect(professorPage.locator(".lps-record-list")).toContainText("Aguardando revisão");

    // The editor approves the proposal; the public record changes.
    await editorPage.goto("/pt-br/painel/revisao/", { waitUntil: "domcontentloaded" });
    // The queue may hold stale proposals from earlier runs; target the one
    // bound to this run's person record.
    const proposalForm = editorPage
      .locator('form[data-dashboard-form="lps_dashboard_review"]')
      .filter({ has: editorPage.locator(`input[name="person_id"][value="${state.personId}"]`) })
      .first();
    await clickAndWait(editorPage, proposalForm.locator('button[value="approve"]'));
    await expect(editorPage.locator("[data-dashboard-notice]")).toBeVisible({ timeout: 30_000 });

    const person = await record("people", state.personId);
    expect(person.meta?._lps_orcid ?? person._lps_orcid).toBe("0000-0001-2345-6789");
    // _lps_public_email is a private field the REST surface withholds; the
    // ORCID write proves the same apply path, and the proposal history below
    // confirms the approved state the professor sees.

    // The professor sees the approved outcome in the proposal history.
    await professorPage.goto("/pt-br/painel/perfil/", { waitUntil: "domcontentloaded" });
    await expect(professorPage.locator(".lps-record-list")).toContainText("Aprovado");
  });

  test("the professor copies the offering forward into the next term", async () => {
    test.setTimeout(300_000);
    await professorPage.goto(`/pt-br/painel/ofertas/${state.offeringPtId}/`, {
      waitUntil: "domcontentloaded",
    });
    const copyForm = professorPage.locator('form[data-dashboard-form="lps_dashboard_copy"]');
    await expect(copyForm).toBeVisible();

    // The reviewed-team confirmation is required; without it the copy denies.
    await copyForm.locator('select[name="new_term_id"]').selectOption(String(state.termNewId));
    await copyForm.locator('input[name="new_section"]').fill("t03");
    await clickAndWait(professorPage, copyForm.locator('button[type="submit"]'));
    await expect(professorPage.locator("[data-dashboard-error]")).toBeVisible({ timeout: 30_000 });

    await professorPage.goto(`/pt-br/painel/ofertas/${state.offeringPtId}/`, {
      waitUntil: "domcontentloaded",
    });
    const copyForm2 = professorPage.locator('form[data-dashboard-form="lps_dashboard_copy"]');
    await copyForm2.locator('select[name="new_term_id"]').selectOption(String(state.termNewId));
    await copyForm2.locator('input[name="new_section"]').fill("t03");
    await copyForm2.locator('input[name="title"]').fill(`Turma ${RUN} T03`);
    await copyForm2.locator('input[name="team_reviewed"]').check();
    await clickAndWait(professorPage, copyForm2.locator('button[type="submit"]'));
    await expect(professorPage.locator("[data-dashboard-notice]")).toBeVisible({ timeout: 30_000 });

    const offerings = await page.request.get(`/wp-json/wp/v2/offerings?per_page=50&status=any`, {
      headers: { "X-WP-Nonce": state.publisherNonce },
    });
    const rows = await offerings.json();
    state.copiedOfferingId =
      rows.find((row) => row.title?.rendered?.includes(`Turma ${RUN} T03`))?.id ?? 0;
    expect(state.copiedOfferingId, "the copy must persist as a draft").toBeGreaterThan(0);
    const copied = await record("offerings", state.copiedOfferingId);
    expect(copied.status).toBe("draft");
  });

  test("the delegate sees scoped tasks but no publish controls", async ({ browser }) => {
    test.setTimeout(120_000);
    await ensureDelegate(browser);
    await delegatePage.goto("/pt-br/painel/", { waitUntil: "domcontentloaded" });
    await expect(delegatePage.locator('[data-dashboard-view="home"]')).toBeVisible();
    await expect(delegatePage.locator(".lps-task-list")).toContainText("Minhas ofertas");
    await delegatePage.goto(`/pt-br/painel/ofertas/${state.offeringPtId}/`, {
      waitUntil: "domcontentloaded",
    });
    await expect(delegatePage.locator('[data-dashboard-view="offering"]')).toBeVisible();
    // The delegate may create and edit but never publishes or releases.
    await expect(
      delegatePage.locator('form[data-dashboard-form="lps_dashboard_unit"]'),
    ).toBeVisible();
    await expect(
      delegatePage.locator('form[data-dashboard-form="lps_dashboard_publish"]'),
    ).toHaveCount(0);
    await expect(
      delegatePage.locator('form[data-dashboard-form="lps_dashboard_release"]'),
    ).toHaveCount(0);
  });

  test("an out-of-scope offering is denied to the professor", async () => {
    test.setTimeout(60_000);
    await professorPage.goto(`/pt-br/painel/ofertas/${state.offeringOtherId}/`, {
      waitUntil: "domcontentloaded",
    });
    await expect(professorPage.locator('[data-dashboard-view="offering-denied"]')).toBeVisible();
    // A forged publish POST against the out-of-scope offering is denied too:
    // the nonce is real, the scope check still refuses the record.
    await professorPage.goto(`/pt-br/painel/ofertas/${state.offeringPtId}/`, {
      waitUntil: "domcontentloaded",
    });
    const nonce = await formNonce(professorPage, "lps_dashboard_unit");
    const forged = await professorPage.request.post("/wp-admin/admin-post.php", {
      form: {
        action: "lps_dashboard_publish",
        _lps_dashboard_nonce: nonce,
        post_id: String(state.offeringOtherId),
      },
      maxRedirects: 0,
    });
    expect(forged.status()).toBe(302);
    expect(forged.headers()["location"] ?? "").toContain("lps_error=");
  });
});

import { expect, test } from "@playwright/test";

/**
 * Task 8 — teaching persistence, canonical routes, and reconciliation.
 *
 * The spec exercises the real HTTP boundary of the running site: records are
 * created through `lps/v1/teaching/*` by an MFA-enrolled publisher, persist
 * across reloads, resolve on the canonical locale routes, and appear in the
 * derived teaching history. Concurrent duplicate creates must yield exactly
 * one winner and one typed 409; invalid relation IDs, English identity
 * minting, and route collisions are rejected with stable machine codes.
 *
 * The pure identity, gate, migration, and route-matching contracts are
 * covered by LpsRedesignTask08Test; this spec proves the wired boundary.
 */

const RUN = `t8-${Date.now().toString(36)}`;
const BASE_URL = process.env.LPS_BASE_URL ?? "http://127.0.0.1:8888";

// Shared per-worker state: the serial happy path builds one record graph on
// one authenticated session — Playwright gives every test a fresh context, so
// the publisher session lives on a shared page created in beforeAll.
const state = {
  nonce: "",
  personId: 0,
  termCurrentId: 0,
  termCompletedId: 0,
  coursePtId: 0,
  courseEnId: 0,
  offeringPtId: 0,
  offeringEnId: 0,
  offeringDraftId: 0,
  unitId: 0,
  courseSlugPt: `disciplina-${RUN}`,
  courseSlugEn: `course-${RUN}`,
};

let context;
let page;

test.describe.configure({ mode: "serial" });

/**
 * Signs in as the MFA-enrolled publisher through the real login form.
 *
 * The auto-login marker cookie is set first so wp-login.php renders the form
 * instead of the auto-login handshake; the Two-Factor dummy provider (enabled
 * under WP_DEBUG in this environment) then completes the enrolled-MFA
 * challenge with a single submit.
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
  await login.fill("lps-t8-publisher");
  await target.locator("#user_pass").fill("lps-t8-publisher-pass");
  await Promise.all([target.waitForLoadState("load"), target.locator("#wp-submit").click()]);
  // The enrolled-MFA challenge renders its own submit (`submit_button()`),
  // not the credential form's #wp-submit; a failed sign-in must surface here,
  // never as a later REST denial.
  const challenge = target.locator("#loginform input[type=submit], #loginform button[type=submit]");
  await challenge.waitFor({ state: "visible", timeout: 15_000 });
  await Promise.all([target.waitForLoadState("load"), challenge.click()]);
  await expect(target.locator("#login_error")).toHaveCount(0);
  await expect(target.locator("#wpbody, #wpadminbar").first()).toBeAttached({ timeout: 15_000 });
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

test.describe("task-08: teaching persistence and canonical routes", () => {
  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    context = await browser.newContext({ baseURL: BASE_URL });
    page = await context.newPage();
    await loginAsPublisher(page);
    state.nonce = await restNonce(page);
  });

  test.afterAll(async () => {
    await context?.close();
  });

  test("the publisher session holds a working REST nonce", async () => {
    expect(state.nonce.length).toBeGreaterThan(0);
    const response = await page.request.get("/wp-json/wp/v2/types", {
      headers: { "X-WP-Nonce": state.nonce },
    });
    expect(response.status()).toBe(200);
  });

  test("creates terms, a bilingual course, offerings, and a unit through the canonical boundary", async () => {
    test.setTimeout(120_000);

    // A person anchors the teaching team.
    const person = await page.request.post("/wp-json/wp/v2/people", {
      data: {
        title: `Docente ${RUN}`,
        slug: `docente-${RUN}`,
        status: "draft",
        meta: { _lps_locale: "pt-br", _lps_canonical_name: `Docente ${RUN}` },
      },
      headers: { "X-WP-Nonce": state.nonce },
    });
    expect(person.status(), JSON.stringify(await person.json())).toBe(201);
    state.personId = (await person.json()).id;
    expect(state.personId).toBeGreaterThan(0);

    // Terms publish directly: they carry no English-variant requirement.
    const termCurrent = await api("POST", "/teaching/terms", {
      title: `2026.2 ${RUN}`,
      slug: `term-current-${RUN}`,
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
    expect(termCurrent.status, JSON.stringify(termCurrent.body)).toBe(201);
    state.termCurrentId = termCurrent.body.id;
    expect(termCurrent.body.status).toBe("publish");
    expect(termCurrent.body.term_token).toBe(`2026-2-${RUN}-semester`);

    const termCompleted = await api("POST", "/teaching/terms", {
      title: `2025.2 ${RUN}`,
      slug: `term-completed-${RUN}`,
      calendar_key: "semester",
      term_code: `2025-2-${RUN}`,
      status: "publish",
      meta: {
        _lps_period_label: `2025.2 ${RUN}`,
        _lps_period_type: "semester",
        _lps_starts_on: "2025-01-05",
        _lps_ends_on: "2025-12-20",
      },
    });
    expect(termCompleted.status).toBe(201);
    state.termCompletedId = termCompleted.body.id;

    // The Portuguese course authority, then its English variant.
    const course = await api("POST", "/teaching/courses", {
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
    expect(courseEn.body.variants["pt-br"]).toBe(state.coursePtId);

    // The English variant must be reviewed and published before the
    // Portuguese authority can publish — the bilingual contract is enforced
    // by the same gate for every record type.
    const review = await api("POST", `/teaching/records/${state.courseEnId}/review-translation`);
    expect(review.status, JSON.stringify(review.body)).toBe(200);
    await publish(state.courseEnId);
    await publish(state.coursePtId);

    // The offering claims its course/term/section identity atomically.
    const offering = await api("POST", "/teaching/offerings", {
      title: `Turma ${RUN} T01`,
      slug: `turma-${RUN}-t01`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.coursePtId,
      term_id: state.termCurrentId,
      section: "t01",
      team: [{ person_id: state.personId, role: "lead" }],
      meta: { _lps_schedule: "Ter/Qui 10h", _lps_venue: "Sala 1" },
    });
    expect(offering.status, JSON.stringify(offering.body)).toBe(201);
    state.offeringPtId = offering.body.id;
    expect(offering.body.temporal_status).toBe("current");
    expect(offering.body.canonical_path).toBe(
      `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/t01/`,
    );

    const offeringEn = await api("POST", "/teaching/offerings", {
      title: `Section ${RUN} T01`,
      slug: `section-${RUN}-t01`,
      locale: "en",
      translation_of: state.offeringPtId,
      excerpt: "Test section.",
      content: "Fixture content.",
    });
    expect(offeringEn.status, JSON.stringify(offeringEn.body)).toBe(201);
    state.offeringEnId = offeringEn.body.id;

    const reviewOffering = await api(
      "POST",
      `/teaching/records/${state.offeringEnId}/review-translation`,
    );
    expect(reviewOffering.status, JSON.stringify(reviewOffering.body)).toBe(200);
    await publish(state.offeringEnId);
    const publishedOffering = await publish(state.offeringPtId);
    expect(publishedOffering.temporal_status).toBe("current");

    // A draft offering on the completed term exercises the edit-only history.
    const draft = await api("POST", "/teaching/offerings", {
      title: `Turma ${RUN} T02`,
      slug: `turma-${RUN}-t02`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.coursePtId,
      term_id: state.termCompletedId,
      section: "t02",
      team: [{ person_id: state.personId, role: "lead" }],
    });
    expect(draft.status, JSON.stringify(draft.body)).toBe(201);
    state.offeringDraftId = draft.body.id;
    expect(draft.body.temporal_status).toBe("completed");

    // A unit attaches to the published offering and publishes directly.
    const unit = await api("POST", "/teaching/units", {
      title: `Unidade ${RUN}`,
      slug: `unidade-${RUN}`,
      offering_id: state.offeringPtId,
      status: "publish",
      meta: { _lps_anchor: `unidade-${RUN}`, _lps_position: 1 },
    });
    expect(unit.status, JSON.stringify(unit.body)).toBe(201);
    state.unitId = unit.body.id;
    expect(unit.body.status).toBe("publish");
  });

  test("records persist across reloads and resolve on the canonical routes", async () => {
    test.setTimeout(60_000);
    expect(state.offeringPtId, "create test must run first").toBeGreaterThan(0);

    // The stored record reads back through the core REST surface.
    const stored = await page.request.get(`/wp-json/wp/v2/offerings/${state.offeringPtId}`, {
      headers: { "X-WP-Nonce": state.nonce },
    });
    expect(stored.status()).toBe(200);
    expect((await stored.json()).status).toBe("publish");

    // The canonical offering page renders in both locales.
    const ptPath = `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/t01/`;
    const enPath = `/en/teaching/courses/${state.courseSlugEn}/2026-2-${RUN}-semester/t01/`;
    const pt = await page.goto(ptPath);
    expect(pt.status()).toBe(200);
    await expect(page.locator("h1").first()).toContainText(`Turma ${RUN} T01`);
    await expect(page.locator(".lps-term-token").first()).toContainText(`2026-2-${RUN}-semester`);
    // The machine status lives on data-state; the visible label is localized.
    await expect(page.locator("article.lps-offering")).toHaveAttribute("data-state", "current");
    await expect(page.locator(".lps-temporal-status").first()).toContainText("Em andamento");
    await expect(page.locator(".lps-teaching-team")).toContainText(`Docente ${RUN}`);
    await expect(page.locator(`#unidade-${RUN}`)).toBeAttached();

    const en = await page.goto(enPath);
    expect(en.status()).toBe(200);
    await expect(page.locator("h1").first()).toContainText(`Section ${RUN} T01`);

    // Course detail and the landing surface resolve too.
    const course = await page.goto(`/pt-br/ensino/disciplinas/${state.courseSlugPt}/`);
    expect(course.status()).toBe(200);
    await expect(page.locator("h1").first()).toContainText(`Disciplina ${RUN}`);
    const landing = await page.goto("/pt-br/ensino/");
    expect(landing.status()).toBe(200);
    await expect(page.locator(".lps-teaching-landing")).toBeAttached();
  });

  test("route collisions and unknown identities return real 404s", async () => {
    for (const path of [
      `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/zz/`,
      `/pt-br/ensino/disciplinas/${state.courseSlugPt}/9999-9-semester/t01/`,
      `/pt-br/ensino/disciplinas/nao-existe-${RUN}/2026-2-${RUN}-semester/t01/`,
      `/en/teaching/courses/${state.courseSlugEn}/2026-2-${RUN}-semester/zz/`,
      `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/`,
      `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/t01/extra/`,
    ]) {
      const response = await page.goto(path);
      expect(response.status(), path).toBe(404);
    }
  });

  test("the derived teaching history serves view and edit contexts", async () => {
    expect(state.personId, "create test must run first").toBeGreaterThan(0);

    // The public context only lists published, publicly visible rows.
    const view = await page.request.get(
      `/wp-json/lps/v1/teaching/people/${state.personId}/history?locale=pt-br`,
    );
    expect(view.status()).toBe(200);
    const viewBody = await view.json();
    const viewIds = viewBody.entries.map((entry) => entry.offering_authority_id);
    expect(viewIds).toContain(state.offeringPtId);
    expect(viewIds).not.toContain(state.offeringDraftId);
    const entry = viewBody.entries.find((item) => item.offering_authority_id === state.offeringPtId);
    expect(entry.role).toBe("lead");
    expect(entry.url).toBe(
      `/pt-br/ensino/disciplinas/${state.courseSlugPt}/2026-2-${RUN}-semester/t01/`,
    );
    expect(entry.temporal_status).toBe("current");

    // The edit context is the second supported metadata context: it exposes
    // the draft row to an account that can edit the person record.
    const edit = await page.request.get(
      `/wp-json/lps/v1/teaching/people/${state.personId}/history?locale=pt-br&context=edit`,
      { headers: { "X-WP-Nonce": state.nonce } },
    );
    expect(edit.status()).toBe(200);
    const editIds = (await edit.json()).entries.map((item) => item.offering_authority_id);
    expect(editIds).toContain(state.offeringDraftId);

    // Anonymous callers cannot reach the edit context.
    const denied = await page.request.get(
      `/wp-json/lps/v1/teaching/people/${state.personId}/history?context=edit`,
    );
    expect([401, 403]).toContain(denied.status());
  });

  test("concurrent duplicate creates yield one winner and one typed 409", async () => {
    expect(state.coursePtId, "create test must run first").toBeGreaterThan(0);

    // Two racing creates on the same course/term/section identity.
    const payload = {
      title: `Corrida ${RUN}`,
      slug: `corrida-${RUN}`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.coursePtId,
      term_id: state.termCompletedId,
      section: "t09",
      team: [{ person_id: state.personId, role: "lead" }],
    };
    const [first, second] = await Promise.all([
      api("POST", "/teaching/offerings", payload),
      api("POST", "/teaching/offerings", payload),
    ]);
    const statuses = [first.status, second.status].sort();
    expect(statuses, JSON.stringify([first.body, second.body])).toEqual([201, 409]);
    const loser = first.status === 409 ? first : second;
    expect(loser.body.code).toBe("lps_offering_identity_conflict");
    expect(loser.body.data.existing_record).toBeGreaterThan(0);

    // The losing create left no record behind. The winner's slug is
    // whichever variant its insert claimed first (the loser's insert may
    // have taken the base slug before losing the registry claim), and the
    // record is a draft, so the collection must be queried with status=any.
    const list = await page.request.get(
      `/wp-json/wp/v2/offerings?slug=corrida-${RUN},corrida-${RUN}-2&status=any`,
      { headers: { "X-WP-Nonce": state.nonce } },
    );
    expect((await list.json()).length).toBe(1);

    // Term identities conflict the same way.
    const termPayload = {
      title: `Duplicado ${RUN}`,
      slug: `term-dup-${RUN}`,
      calendar_key: "semester",
      term_code: `2026-2-${RUN}`,
      meta: {
        _lps_period_label: "dup",
        _lps_period_type: "semester",
        _lps_starts_on: "2026-01-05",
        _lps_ends_on: "2026-12-20",
      },
    };
    const duplicate = await api("POST", "/teaching/terms", termPayload);
    expect(duplicate.status).toBe(409);
    expect(duplicate.body.code).toBe("lps_term_identity_conflict");
    expect(duplicate.body.data.existing_record).toBe(state.termCurrentId);
  });

  test("invalid relation ids and English identity minting are rejected", async () => {
    expect(state.coursePtId, "create test must run first").toBeGreaterThan(0);

    // A forged course id never creates an offering.
    const badCourse = await api("POST", "/teaching/offerings", {
      title: `Invalida ${RUN}`,
      course_id: 999999,
      term_id: state.termCurrentId,
      section: "t01",
      team: [{ person_id: state.personId, role: "lead" }],
    });
    expect(badCourse.status).toBe(400);
    expect(badCourse.body.code).toBe("lps_teaching_course_invalid");

    // A forged term id never creates an offering.
    const badTerm = await api("POST", "/teaching/offerings", {
      title: `Invalida ${RUN}`,
      course_id: state.coursePtId,
      term_id: 999999,
      section: "t01",
      team: [{ person_id: state.personId, role: "lead" }],
    });
    expect(badTerm.status).toBe(400);
    expect(badTerm.body.code).toBe("lps_teaching_term_invalid");

    // A forged team member never creates an offering. The section must be
    // fresh: the identity claim is checked before team validation, and a
    // claimed identity would answer 409 instead of reaching the team gate.
    const badTeam = await api("POST", "/teaching/offerings", {
      title: `Invalida ${RUN}`,
      course_id: state.coursePtId,
      term_id: state.termCurrentId,
      section: "t88",
      team: [{ person_id: 999999, role: "lead" }],
    });
    expect(badTeam.status).toBe(400);
    expect(badTeam.body.code).toBe("lps_orphan_relationship_target");

    // A unit without a real offering is rejected.
    const badUnit = await api("POST", "/teaching/units", {
      title: `Invalida ${RUN}`,
      offering_id: 999999,
    });
    expect(badUnit.status).toBe(400);
    expect(badUnit.body.code).toBe("lps_teaching_offering_invalid");

    // An English create without its Portuguese authority can never mint an
    // identity — the variant boundary requires translation_of.
    const englishMint = await api("POST", "/teaching/offerings", {
      title: `English ${RUN}`,
      locale: "en",
      course_id: state.coursePtId,
      term_id: state.termCurrentId,
      section: "t77",
      team: [{ person_id: state.personId, role: "lead" }],
    });
    expect(englishMint.status).toBe(400);
    expect(englishMint.body.code).toBe("lps_teaching_translation_required");

    // A team without a lead violates the integrity contract — again on a
    // fresh section so the claim check cannot mask the team error.
    const noLead = await api("POST", "/teaching/offerings", {
      title: `Invalida ${RUN}`,
      course_id: state.coursePtId,
      term_id: state.termCurrentId,
      section: "t89",
      team: [{ person_id: state.personId, role: "assistant" }],
    });
    expect(noLead.status).toBe(400);
    expect(noLead.body.code).toBe("lps_teaching_lead_required");
  });

  test("the reconciliation report shows claimed identities and no orphans", async () => {
    expect(state.offeringPtId, "create test must run first").toBeGreaterThan(0);

    const report = await api("GET", "/teaching/reconciliation");
    expect(report.status, JSON.stringify(report.body)).toBe(200);
    expect(report.body.schema.version).toBe("1.1.0");
    expect(report.body.schema.tables.term_registry.exists).toBe(true);
    expect(report.body.schema.tables.offering_registry.exists).toBe(true);
    expect(report.body.schema.tables.version_registry.exists).toBe(true);
    expect(report.body.counts.term_claims).toBeGreaterThanOrEqual(2);
    expect(report.body.counts.offering_claims).toBeGreaterThanOrEqual(2);

    // No issue may reference a record this run created.
    const ours = new Set([
      state.termCurrentId,
      state.termCompletedId,
      state.offeringPtId,
      state.offeringDraftId,
    ]);
    const related = report.body.issues.filter((issue) => ours.has(issue.claim_post_id));
    expect(related, JSON.stringify(related)).toEqual([]);
  });

  test("unauthenticated writes are denied before any mutation", async ({ browser }) => {
    const anonymous = await browser.newContext({ baseURL: BASE_URL });
    try {
      // The marker cookie only skips the playground auto-login handshake;
      // the request still reaches WordPress with no credentials, so the
      // boundary itself must answer 401/403 rather than a login redirect.
      await anonymous.addCookies([
        {
          name: "playground_auto_login_already_happened",
          value: "1",
          domain: new URL(BASE_URL).hostname,
          path: "/",
        },
      ]);
      const response = await anonymous.request.post("/wp-json/lps/v1/teaching/terms", {
        data: { title: `anon ${RUN}`, calendar_key: "semester", term_code: `a-${RUN}` },
      });
      expect([401, 403]).toContain(response.status());
      const list = await anonymous.request.get(`/wp-json/wp/v2/terms?slug=term-dup-${RUN}`);
      expect(list.status()).toBe(200);
      expect((await list.json()).length).toBe(0);
    } finally {
      await anonymous.close();
    }
  });
});

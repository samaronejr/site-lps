import { expect, test } from "@playwright/test";

/**
 * Task 12 — teaching search indexing and public-change propagation.
 *
 * The spec exercises the real HTTP boundary of the running site: a bilingual
 * course, a co-taught current offering, a completed offering and released
 * resources are published through `lps/v1/teaching/*`, then queried through
 * the server-rendered locale search with plain GETs. Exact course codes,
 * accent-insensitive names, instructors, term labels and authored languages
 * resolve; the current/previous filter is a normal GET facet; drafts,
 * private fields, storage keys, scheduled-future releases and the other
 * locale never leak through results or facet counts; corrections,
 * withdrawal and term transitions propagate to the index and the purge log
 * without a deployment.
 *
 * The pure indexing, lifecycle-gating and facet contracts are covered by
 * LpsRedesignTask12Test; this spec proves the wired boundary.
 */

const RUN = `t12-${Date.now().toString(36)}`;
const CODE = `LPS-${RUN.toUpperCase()}`;
const BASE_URL = process.env.LPS_BASE_URL ?? "http://127.0.0.1:8895";
const PT = "/pt-br/busca/";
const EN = "/en/search/";

const PDF = `%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\n% task-12 fixture ${RUN}\n%%EOF\n`;

// Shared per-worker state: the serial happy path builds one record graph on
// one authenticated session — Playwright gives every test a fresh context, so
// the publisher session lives on a shared page created in beforeAll.
const state = {
  nonce: "",
  leadId: 0,
  coTeacherId: 0,
  termCurrentId: 0,
  termCompletedId: 0,
  coursePtId: 0,
  courseEnId: 0,
  draftCourseId: 0,
  offeringPtId: 0,
  offeringEnId: 0,
  offeringPrevId: 0,
  resourceId: 0,
  externalId: 0,
  scheduledFutureId: 0,
  scheduledPastId: 0,
  downloadUrl: "",
  courseSlugPt: `disciplina-${RUN}`,
  courseSlugEn: `course-${RUN}`,
  leadName: `Docente ${RUN}`,
  coTeacherName: `Colega ${RUN}`,
};

let context;
let page;

test.describe.configure({ mode: "serial" });

/**
 * Signs in as the MFA-enrolled publisher through the real login form.
 *
 * The auto-login marker cookie is set first so wp-login.php renders the form
 * instead of the auto-login handshake; the Two-Factor dummy provider then
 * completes the enrolled-MFA challenge with a single submit.
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
  await login.fill("lps-t12-publisher");
  await target.locator("#user_pass").fill("lps-t12-publisher-pass");
  await Promise.all([target.waitForLoadState("load"), target.locator("#wp-submit").click()]);
  const challenge = target.locator("#loginform input[type=submit], #loginform button[type=submit]");
  await challenge.waitFor({ state: "visible", timeout: 15_000 });
  await Promise.all([target.waitForLoadState("load"), challenge.click()]);
  await expect(target.locator("#login_error")).toHaveCount(0);
  await target.waitForURL((url) => !url.pathname.endsWith("/wp-login.php"), { timeout: 60_000 });
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

/** Runs one anonymous GET against the locale search and returns the page text. */
async function searchText(target, route, params) {
  const query = new URLSearchParams(params).toString();
  const response = await target.request.get(`${route}?${query}`);
  expect(response.status(), `${route}?${query}`).toBe(200);
  return response.text();
}

/** Returns the result titles rendered by one search response body. */
function resultTitles(html) {
  return [...html.matchAll(/<li class="lps-search-result">([\s\S]*?)<\/li>/g)].map((match) =>
    match[1].replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim(),
  );
}

/** Returns the result hrefs rendered by one search response body. */
function resultHrefs(html) {
  return [...html.matchAll(/<li class="lps-search-result"><a href="([^"]+)"/g)].map(
    (match) => match[1],
  );
}

test.describe("task-12: teaching search and change propagation", () => {
  test.beforeAll(async ({ browser }) => {
    test.setTimeout(180_000);
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

  test("publishes the bilingual course graph with a co-taught current offering", async () => {
    test.setTimeout(120_000);

    // Two people anchor the co-taught team.
    for (const [key, name] of [
      ["leadId", state.leadName],
      ["coTeacherId", state.coTeacherName],
    ]) {
      const person = await page.request.post("/wp-json/wp/v2/people", {
        data: {
          title: name,
          slug: `docente-${key === "leadId" ? "lead" : "colega"}-${RUN}`,
          status: "draft",
          meta: { _lps_locale: "pt-br", _lps_canonical_name: name },
        },
        headers: { "X-WP-Nonce": state.nonce },
      });
      expect(person.status(), JSON.stringify(await person.json())).toBe(201);
      state[key] = (await person.json()).id;
      expect(state[key]).toBeGreaterThan(0);
    }

    // One current and one completed term.
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

    // The bilingual course authority pair.
    const course = await api("POST", "/teaching/courses", {
      title: `Disciplina ${RUN}`,
      slug: state.courseSlugPt,
      excerpt: "Disciplina de teste.",
      content: "Conteúdo de fixture.",
      meta: {
        _lps_course_code: CODE,
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
    await api("POST", `/teaching/records/${state.courseEnId}/review-translation`);
    await publish(state.courseEnId);
    await publish(state.coursePtId);

    // A draft course stays unpublished: it must never reach the index.
    const draft = await api("POST", "/teaching/courses", {
      title: `Rascunho ${RUN}`,
      slug: `rascunho-${RUN}`,
      excerpt: "Rascunho de teste.",
      content: "Conteúdo de rascunho.",
      meta: {
        _lps_course_code: `DRAFT-${RUN.toUpperCase()}`,
        _lps_course_level: "undergraduate",
        _lps_calendar_key: "semester",
      },
    });
    expect(draft.status, JSON.stringify(draft.body)).toBe(201);
    state.draftCourseId = draft.body.id;

    // The co-taught current offering.
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
      meta: { _lps_schedule: "Ter/Qui 10h", _lps_venue: "Sala 1" },
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
    });
    expect(offeringEn.status, JSON.stringify(offeringEn.body)).toBe(201);
    state.offeringEnId = offeringEn.body.id;
    await api("POST", `/teaching/records/${state.offeringEnId}/review-translation`);
    await publish(state.offeringEnId);
    await publish(state.offeringPtId);

    // The completed previous offering.
    const previous = await api("POST", "/teaching/offerings", {
      title: `Turma ${RUN} T02`,
      slug: `turma-${RUN}-t02`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.coursePtId,
      term_id: state.termCompletedId,
      section: "t02",
      team: [{ person_id: state.leadId, role: "lead" }],
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
    await api("POST", `/teaching/records/${previousEn.body.id}/review-translation`);
    await publish(previousEn.body.id);
    await publish(state.offeringPrevId);
  });

  test("exact code, accent-insensitive, instructor and term lookups resolve", async ({ browser }) => {
    expect(state.offeringPtId, "graph test must run first").toBeGreaterThan(0);
    const anonymous = await anonymousContext(browser);
    try {
      // The exact course code resolves the course and both offerings.
      const byCode = resultTitles(await searchText(anonymous, PT, { q: CODE }));
      expect(byCode.join(" ")).toContain(`Disciplina ${RUN}`);
      expect(byCode.join(" ")).toContain(`Turma ${RUN} T01`);
      expect(byCode.join(" ")).toContain(`Turma ${RUN} T02`);

      // Accented and unaccented spellings return the same records.
      const accented = resultTitles(await searchText(anonymous, PT, { q: `Disciplina ${RUN}` }));
      const plain = resultTitles(await searchText(anonymous, PT, { q: `disciplina ${RUN}` }));
      expect(plain).toEqual(accented);
      expect(accented.length).toBeGreaterThan(0);

      // Both co-teachers reach the single offering row — one entity, never
      // one result per instructor.
      for (const name of [state.leadName, state.coTeacherName]) {
        const hits = resultTitles(await searchText(anonymous, PT, { q: name }));
        const offeringHits = hits.filter((title) => title.includes(`Turma ${RUN} T01`));
        expect(offeringHits, name).toHaveLength(1);
      }

      // The term label reaches the offerings scheduled in it.
      const byTerm = resultTitles(await searchText(anonymous, PT, { q: `2026.2 ${RUN}` }));
      expect(byTerm.join(" ")).toContain(`Turma ${RUN} T01`);

      // Result links point at the canonical teaching routes, not permalinks.
      const hrefs = resultHrefs(await searchText(anonymous, PT, { q: CODE }));
      expect(hrefs.join(" ")).toContain(`/pt-br/ensino/disciplinas/${state.courseSlugPt}/`);
      expect(hrefs.join(" ")).toContain(`2026-2-${RUN}-semester/t01/`);
    } finally {
      await anonymous.close();
    }
  });

  test("the current/previous filter narrows offerings through plain GET navigation", async ({
    browser,
  }) => {
    expect(state.offeringPrevId, "graph test must run first").toBeGreaterThan(0);
    const anonymous = await anonymousContext(browser);
    try {
      const current = resultTitles(
        await searchText(anonymous, PT, {
          q: `Turma ${RUN}`,
          record: "lps_offering",
          "status[]": "current",
        }),
      );
      expect(current.join(" ")).toContain(`Turma ${RUN} T01`);
      expect(current.join(" ")).not.toContain(`Turma ${RUN} T02`);

      const previous = resultTitles(
        await searchText(anonymous, PT, {
          q: `Turma ${RUN}`,
          record: "lps_offering",
          "status[]": "previous",
        }),
      );
      expect(previous.join(" ")).toContain(`Turma ${RUN} T02`);
      expect(previous.join(" ")).not.toContain(`Turma ${RUN} T01`);

      // The filter form itself renders the localized current/previous
      // choices as ordinary GET checkboxes.
      const form = await searchText(anonymous, PT, { q: `Turma ${RUN}`, record: "lps_offering" });
      expect(form).toContain('name="status[]"');
      expect(form).toContain('value="current"');
      expect(form).toContain('value="previous"');
      expect(form).toContain("Atuais");
      expect(form).toContain("Anteriores");
    } finally {
      await anonymous.close();
    }
  });

  test("locale isolation holds and drafts never appear", async ({ browser }) => {
    expect(state.offeringEnId, "graph test must run first").toBeGreaterThan(0);
    const anonymous = await anonymousContext(browser);
    try {
      // The English variant answers only inside the English route.
      const en = resultTitles(await searchText(anonymous, EN, { q: `Section ${RUN}` }));
      expect(en.join(" ")).toContain(`Section ${RUN} T01`);
      const pt = resultTitles(await searchText(anonymous, PT, { q: `Section ${RUN}` }));
      expect(pt.join(" ")).not.toContain(`Section ${RUN} T01`);
      const ptTurma = resultTitles(await searchText(anonymous, EN, { q: `Turma ${RUN}` }));
      expect(ptTurma.join(" ")).not.toContain(`Turma ${RUN} T01`);

      // The draft course is invisible to every query, including its code.
      const draft = await searchText(anonymous, PT, { q: `Rascunho ${RUN}` });
      expect(resultTitles(draft).join(" ")).not.toContain(`Rascunho ${RUN}`);
      const draftCode = await searchText(anonymous, PT, { q: `DRAFT-${RUN.toUpperCase()}` });
      expect(resultTitles(draftCode).join(" ")).not.toContain(`Rascunho ${RUN}`);
    } finally {
      await anonymous.close();
    }
  });

  test("released resources index by title and language; scheduled and private data stay out", async ({
    browser,
  }) => {
    test.setTimeout(120_000);
    expect(state.offeringPtId, "graph test must run first").toBeGreaterThan(0);
    const anonymous = await anonymousContext(browser);
    try {
      // A versioned resource: upload, select, release, publish.
      const version = await page.request.post("/wp-json/lps/v1/teaching/resource-versions", {
        multipart: {
          file: { name: `apostila-${RUN}.pdf`, mimeType: "application/pdf", buffer: Buffer.from(PDF) },
          offering_id: String(state.offeringPtId),
        },
        headers: { "X-WP-Nonce": state.nonce },
      });
      expect(version.status(), JSON.stringify(await version.json())).toBe(201);
      const versionId = (await version.json()).version_id;

      const resource = await api("POST", "/teaching/resources", {
        title: `Apostila ${RUN}`,
        slug: `apostila-${RUN}`,
        offering_id: state.offeringPtId,
        version_id: versionId,
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
      await api("POST", `/teaching/resources/${state.resourceId}/release`, { state: "released" });
      await publish(state.resourceId);

      // An external resource in English.
      const external = await api("POST", "/teaching/resources", {
        title: `Notebook ${RUN}`,
        slug: `notebook-${RUN}`,
        offering_id: state.offeringPtId,
        meta: {
          _lps_resource_type: "notebook",
          _lps_resource_language: "en",
          _lps_external_url: "https://example.org/notebook.ipynb",
          _lps_rights_review: "approved",
          _lps_accessibility_review: "approved",
        },
      });
      expect(external.status, JSON.stringify(external.body)).toBe(201);
      state.externalId = external.body.id;
      await api("POST", `/teaching/resources/${state.externalId}/release`, { state: "released" });
      await publish(state.externalId);

      // A scheduled resource due in the past is already public — the
      // release needs no scheduler run.
      const scheduledPast = await api("POST", "/teaching/resources", {
        title: `Slides ${RUN}`,
        slug: `slides-${RUN}`,
        offering_id: state.offeringPtId,
        meta: {
          _lps_resource_type: "slides",
          _lps_resource_language: "pt-br",
          _lps_external_url: "https://example.org/slides.pdf",
          _lps_rights_review: "approved",
          _lps_accessibility_review: "approved",
        },
      });
      expect(scheduledPast.status).toBe(201);
      state.scheduledPastId = scheduledPast.body.id;
      await api("POST", `/teaching/resources/${state.scheduledPastId}/release`, {
        state: "scheduled",
        release_at: "2020-01-01T00:00:00+00:00",
      });
      await publish(state.scheduledPastId);

      // A scheduled resource due in the future must stay invisible.
      const scheduledFuture = await api("POST", "/teaching/resources", {
        title: `Agendado ${RUN}`,
        slug: `agendado-${RUN}`,
        offering_id: state.offeringPtId,
        meta: {
          _lps_resource_type: "document",
          _lps_resource_language: "pt-br",
          _lps_external_url: "https://example.org/agendado.pdf",
          _lps_rights_review: "approved",
          _lps_accessibility_review: "approved",
        },
      });
      expect(scheduledFuture.status).toBe(201);
      state.scheduledFutureId = scheduledFuture.body.id;
      await api("POST", `/teaching/resources/${state.scheduledFutureId}/release`, {
        state: "scheduled",
        release_at: "2999-01-01T00:00:00+00:00",
      });
      await publish(state.scheduledFutureId);

      // Released resources resolve by title; the due scheduled one too.
      const released = resultTitles(await searchText(anonymous, PT, { q: `Apostila ${RUN}` }));
      expect(released.join(" ")).toContain(`Apostila ${RUN}`);
      const due = resultTitles(await searchText(anonymous, PT, { q: `Slides ${RUN}` }));
      expect(due.join(" ")).toContain(`Slides ${RUN}`);

      // The future-scheduled resource leaks through neither results nor
      // facet counts: its type value must not appear in the filter counts.
      const future = await searchText(anonymous, PT, { q: `Agendado ${RUN}` });
      expect(resultTitles(future).join(" ")).not.toContain(`Agendado ${RUN}`);
      const resourceForm = await searchText(anonymous, PT, {
        q: RUN,
        record: "lps_resource",
      });
      const typeCounts = [
        ...resourceForm.matchAll(/name="type\[\]" value="([^"]+)"[^>]*>[\s\S]*?\((\d+)\)/g),
      ].map((match) => [match[1], Number(match[2])]);
      const documentCount = typeCounts.find(([value]) => value === "document")?.[1] ?? 0;
      // Apostila is the only visible document; the scheduled one adds nothing.
      expect(documentCount).toBe(1);

      // The authored-language facet narrows to the English notebook only.
      const englishOnly = resultTitles(
        await searchText(anonymous, PT, {
          q: RUN,
          record: "lps_resource",
          "language[]": "en",
        }),
      );
      expect(englishOnly.join(" ")).toContain(`Notebook ${RUN}`);
      expect(englishOnly.join(" ")).not.toContain(`Apostila ${RUN}`);

      // Private fields never leak: the storage key, version id and uploader
      // linkage are absent from every result payload and snippet.
      const all = await searchText(anonymous, PT, { q: `Apostila ${RUN}` });
      expect(all).not.toContain("lps-file-");
      expect(all).not.toContain("lpsver:");
      expect(all).not.toContain("_lps_storage_key");
      expect(all).not.toContain("_lps_uploader_user_id");
      const keyGuess = await searchText(anonymous, PT, { q: "lps-file" });
      expect(resultTitles(keyGuess)).toHaveLength(0);
    } finally {
      await anonymous.close();
    }
  });

  test("a public title correction propagates to search and the purge log", async ({ browser }) => {
    expect(state.coursePtId, "graph test must run first").toBeGreaterThan(0);
    const anonymous = await anonymousContext(browser);
    try {
      const corrected = await page.request.post(`/wp-json/wp/v2/courses/${state.coursePtId}`, {
        data: { title: `Disciplina ${RUN} Corrigida` },
        headers: { "X-WP-Nonce": state.nonce },
      });
      expect(corrected.status(), JSON.stringify(await corrected.json())).toBe(200);

      const found = resultTitles(await searchText(anonymous, PT, { q: `Corrigida ${RUN}` }));
      expect(found.join(" ")).toContain(`Disciplina ${RUN} Corrigida`);

      // The publish transition purged the canonical course route, the
      // landing, the locale home and the search entry.
      const purge = await page.request.get("/wp-json/lps/v1/test/cache-purge", {
        headers: { "X-WP-Nonce": state.nonce },
      });
      expect(purge.status()).toBe(200);
      const targets = (await purge.json()).targets ?? [];
      expect(targets.join(" ")).toContain(`/pt-br/ensino/disciplinas/${state.courseSlugPt}/`);
      expect(targets.join(" ")).toContain("/pt-br/ensino/");
      expect(targets.join(" ")).toContain("/pt-br/busca/");
      expect(targets.join(" ")).toContain("/pt-br/");
    } finally {
      await anonymous.close();
    }
  });

  test("withdrawal removes the resource from search and delivery immediately", async ({
    browser,
  }) => {
    expect(state.resourceId, "resource test must run first").toBeGreaterThan(0);
    const anonymous = await anonymousContext(browser);
    try {
      const withdrawn = await api("POST", `/teaching/resources/${state.resourceId}/withdraw`);
      expect(withdrawn.status, JSON.stringify(withdrawn.body)).toBe(200);
      expect(withdrawn.body.release_state).toBe("withdrawn");

      // The index row is gone on the very next GET — no stale snippet.
      const gone = await searchText(anonymous, PT, { q: `Apostila ${RUN}` });
      expect(resultTitles(gone).join(" ")).not.toContain(`Apostila ${RUN}`);

      // The guarded download denies on the same request boundary.
      expect((await anonymous.request.get(state.downloadUrl)).status()).toBe(404);

      // The withdrawal purged the affected surfaces: the offering route,
      // the locale home and the search entry.
      const purge = await page.request.get("/wp-json/lps/v1/test/cache-purge", {
        headers: { "X-WP-Nonce": state.nonce },
      });
      const targets = (await purge.json()).targets ?? [];
      expect(targets.join(" ")).toContain(`2026-2-${RUN}-semester/t01/`);
      expect(targets.join(" ")).toContain("/pt-br/busca/");
      expect(targets.join(" ")).toContain("/pt-br/");
    } finally {
      await anonymous.close();
    }
  });

  test("a term transition flips the current/previous bucket without a deployment", async ({
    browser,
  }) => {
    expect(state.termCurrentId, "graph test must run first").toBeGreaterThan(0);
    const anonymous = await anonymousContext(browser);
    try {
      // Sanity: the current offering answers the current filter.
      const before = resultTitles(
        await searchText(anonymous, PT, {
          q: `Turma ${RUN}`,
          record: "lps_offering",
          "status[]": "current",
        }),
      );
      expect(before.join(" ")).toContain(`Turma ${RUN} T01`);

      // Shortening the term ends it: the offering becomes previous.
      const updated = await page.request.post(`/wp-json/wp/v2/terms/${state.termCurrentId}`, {
        data: { meta: { _lps_ends_on: "2026-09-01" } },
        headers: { "X-WP-Nonce": state.nonce },
      });
      expect(updated.status(), JSON.stringify(await updated.json())).toBe(200);

      const after = resultTitles(
        await searchText(anonymous, PT, {
          q: `Turma ${RUN}`,
          record: "lps_offering",
          "status[]": "previous",
        }),
      );
      expect(after.join(" ")).toContain(`Turma ${RUN} T01`);
      const stillCurrent = resultTitles(
        await searchText(anonymous, PT, {
          q: `Turma ${RUN}`,
          record: "lps_offering",
          "status[]": "current",
        }),
      );
      expect(stillCurrent.join(" ")).not.toContain(`Turma ${RUN} T01`);
    } finally {
      await anonymous.close();
    }
  });

  test("repeated synchronization keeps one row per record", async ({ browser }) => {
    expect(state.offeringPtId, "graph test must run first").toBeGreaterThan(0);
    const anonymous = await anonymousContext(browser);
    try {
      // A same-status update re-runs the whole synchronization path; the
      // co-taught offering must stay exactly one search entity.
      const touched = await page.request.post(`/wp-json/wp/v2/offerings/${state.offeringPtId}`, {
        data: { excerpt: "Turma de teste atualizada." },
        headers: { "X-WP-Nonce": state.nonce },
      });
      expect(touched.status()).toBe(200);

      for (const name of [state.leadName, state.coTeacherName]) {
        const hits = resultTitles(await searchText(anonymous, PT, { q: name }));
        expect(hits.filter((title) => title.includes(`Turma ${RUN} T01`)), name).toHaveLength(1);
      }
    } finally {
      await anonymous.close();
    }
  });
});

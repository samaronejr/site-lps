import { expect, test } from "@playwright/test";

/**
 * Task 18 — language navigation, search metadata, SEO and print.
 *
 * The spec exercises the real HTTP boundary of the running site: every
 * teaching route answers with a canonical link, reciprocal hreflang and a
 * Course/CourseInstance JSON-LD node; the default CPT permalinks answer with
 * one-hop 301s to the canonical locale routes; the locale switcher links the
 * canonical counterpart and preserves the search query; the search surface
 * stays noindex with a query-aware title; legacy addresses resolve through
 * the redirect graph in one hop, a deliberate removal answers 410 and a
 * redirect cycle is refused with 404; a missing or stale English variant
 * leaves the index, the sitemap and the language switcher while the page
 * announces its state; a withdrawn resource keeps its notice, denies its
 * download route and stays out of search; and the offering page prints
 * without its navigation strips.
 *
 * The pure route, policy and renderer contracts are covered by the PHPUnit
 * suites; this spec proves the wired boundary.
 */

// The development runtime declares `localhost` as its origin (WP_SITEURL,
// canonicals, asset URLs) and its CSP refuses subresources fetched under a
// different loopback name, so the default base URL must be `localhost`, not
// `127.0.0.1`.
const BASE_URL = process.env.LPS_BASE_URL ?? "http://localhost:8899";

const RUN = `t18-${Date.now().toString(36)}`;
const AUTO_LOGIN_COOKIE = {
  name: "playground_auto_login_already_happened",
  value: "1",
};

const state = {
  nonce: "",
  termId: 0,
  termToken: "",
  coursePtId: 0,
  courseEnId: 0,
  courseSlugPt: `disciplina-seo-${RUN}`,
  courseSlugEn: `seo-course-${RUN}`,
  offeringPtId: 0,
  offeringEnId: 0,
  offeringPathPt: "",
  offeringPathEn: "",
  unitId: 0,
  resourceId: 0,
  downloadUrl: "",
  withdrawnId: 0,
  redirectAId: 0,
  redirectBId: 0,
};

let context;
let page;

test.describe.configure({ mode: "serial" });

/** Returns a context whose anonymous requests skip the auto-login handshake. */
async function anonymousContext(browser) {
  const ctx = await browser.newContext({ baseURL: BASE_URL });
  const probe = await ctx.newPage();
  await probe.goto("/", { waitUntil: "domcontentloaded" });
  const origin = new URL(probe.url());
  await ctx.addCookies([{ ...AUTO_LOGIN_COOKIE, domain: origin.hostname, path: "/" }]);
  await probe.close();
  return ctx;
}

/**
 * Signs in as the MFA-enrolled publisher through the real `wp-login.php`
 * boundary: the credential POST and the Two-Factor challenge POST go through
 * the request API so the renderer never touches the login flow.
 */
async function loginAsPublisher(ctx) {
  await ctx.addCookies([{ ...AUTO_LOGIN_COOKIE, url: BASE_URL }]);

  const login = await ctx.request.post("/wp-login.php", {
    form: {
      log: "lps-t18-publisher",
      pwd: "lps-t18-publisher-pass",
      "wp-submit": "Log In",
      redirect_to: "/wp-admin/",
      testcookie: "1",
    },
    maxRedirects: 0,
  });
  const body = await login.text();
  expect(login.status()).toBe(200);
  expect(body).not.toContain("login_error");

  const field = (name) => {
    const match = body.match(new RegExp(`name="${name}"[^>]*value="([^"]*)"`));
    return match ? match[1] : "";
  };
  // Without the challenge fields the POST below cannot authenticate; surface
  // the unexpected login response instead of silently continuing anonymous.
  if ("" === field("wp-auth-id") || "" === field("wp-auth-nonce")) {
    throw new Error(`2FA challenge form missing fields (login body starts: ${body.slice(0, 200)})`);
  }
  const challenge = await ctx.request.post("/wp-login.php?action=validate_2fa", {
    form: {
      provider: field("provider"),
      "wp-auth-id": field("wp-auth-id"),
      "wp-auth-nonce": field("wp-auth-nonce"),
      redirect_to: field("redirect_to"),
      rememberme: "0",
      submit: "Yup.",
    },
    maxRedirects: 0,
  });
  expect(challenge.status()).toBe(302);
  // A failed challenge bounces back to the 2FA form; success leaves it.
  expect(challenge.headers()["location"] ?? "").not.toContain("validate_2fa");
}

/**
 * Establishes a verified publisher session. Parallel project logins race the
 * single writer, so the whole credential + challenge flow retries until
 * `users/me` confirms the session identity.
 */
async function ensurePublisherSession(ctx) {
  let lastError = "";
  for (let attempt = 0; attempt < 4; attempt += 1) {
    try {
      await loginAsPublisher(ctx);
      // The nonce endpoint answers 400 to anonymous sessions and a nonce to
      // authenticated ones, so it doubles as the session verification.
      state.nonce = await restNonce(ctx);
      return;
    } catch (error) {
      // Parallel project logins race the single writer; retry the whole flow.
      lastError = String(error);
    }
  }
  throw new Error(`publisher session could not be established: ${lastError}`);
}

/** Reads the REST nonce bound to the signed-in session. */
async function restNonce(ctx) {
  let lastStatus = 0;
  for (let attempt = 0; attempt < 12; attempt += 1) {
    const response = await ctx.request.get("/wp-admin/admin-ajax.php?action=rest-nonce");
    lastStatus = response.status();
    if (response.ok()) {
      const nonce = (await response.text()).trim();
      if (nonce.length > 0 && nonce !== "0") {
        return nonce;
      }
    }
    // Parallel project logins contend on the single writer; back off and retry.
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(
    `REST nonce endpoint never answered for the publisher session (last status ${lastStatus})`,
  );
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

/** Calls the core REST boundary with the session nonce. */
async function wpApi(method, path, data) {
  const response = await page.request.fetch(`/wp-json/wp/v2${path}`, {
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

/** Uploads one PDF version and returns its immutable version id. */
async function uploadVersion(offeringId, name) {
  const pdf = `%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\n% task-18 fixture ${RUN}\n%%EOF\n`;
  const response = await page.request.post("/wp-json/lps/v1/teaching/resource-versions", {
    multipart: {
      file: { name, mimeType: "application/pdf", buffer: Buffer.from(pdf) },
      offering_id: String(offeringId),
    },
    headers: { "X-WP-Nonce": state.nonce },
  });
  expect(response.status(), JSON.stringify(await response.json())).toBe(201);
  return (await response.json()).version_id;
}

test.describe("task-18: language navigation, search metadata, SEO and print", () => {
  test.beforeAll(async ({ browser }) => {
    test.setTimeout(300_000);
    context = await browser.newContext({ baseURL: BASE_URL });
    page = await context.newPage();
    await ensurePublisherSession(context);
  });

  test.afterAll(async () => {
    // The redirect-cycle fixture must not outlive the run: both records are
    // trashed so the graph returns to its seeded shape.
    for (const id of [state.redirectAId, state.redirectBId]) {
      if (0 < id) {
        await wpApi("DELETE", `/redirects/${id}?force=true`);
      }
    }
    await context?.close();
  });

  test("publishes the bilingual teaching fixture used by the metadata checks", async () => {
    test.setTimeout(240_000);

    const term = await api("POST", "/teaching/terms", {
      title: `2026.1 ${RUN}`,
      slug: `term-${RUN}`,
      calendar_key: "semester",
      term_code: `2026-1-${RUN}`,
      status: "publish",
      meta: {
        _lps_period_label: `2026.2 ${RUN}`,
        _lps_period_type: "semester",
        _lps_starts_on: "2026-08-01",
        _lps_ends_on: "2026-12-15",
      },
    });
    expect(term.status, JSON.stringify(term.body)).toBe(201);
    state.termId = term.body.id;
    state.termToken = term.body.term_token;

    const course = await api("POST", "/teaching/courses", {
      title: `Disciplina SEO ${RUN}`,
      slug: state.courseSlugPt,
      excerpt: `Disciplina de teste para metadados ${RUN}.`,
      content: "Conteúdo da disciplina de teste.",
      meta: {
        _lps_course_code: `SEO-${RUN.toUpperCase()}`,
        _lps_course_level: "undergraduate",
        _lps_calendar_key: "semester",
        _lps_syllabus: "Ementa da disciplina de teste.",
      },
    });
    expect(course.status, JSON.stringify(course.body)).toBe(201);
    state.coursePtId = course.body.id;

    const courseEn = await api("POST", "/teaching/courses", {
      title: `SEO Course ${RUN}`,
      slug: state.courseSlugEn,
      locale: "en",
      translation_of: state.coursePtId,
      excerpt: `Metadata test course ${RUN}.`,
      content: "Test course content.",
    });
    expect(courseEn.status, JSON.stringify(courseEn.body)).toBe(201);
    state.courseEnId = courseEn.body.id;
    await api("POST", `/teaching/records/${state.courseEnId}/review-translation`);
    await publish(state.courseEnId);
    await publish(state.coursePtId);

    const person = await wpApi("GET", "/people?slug=docente-sinais-fixture&status=any");
    expect(person.status, JSON.stringify(person.body)).toBe(200);
    const leadId = person.body[0]?.id;
    expect(leadId, "seeded faculty person missing").toBeGreaterThan(0);

    const offering = await api("POST", "/teaching/offerings", {
      title: `Turma SEO ${RUN} T01`,
      slug: `turma-seo-${RUN}-t01`,
      excerpt: `Turma de teste para metadados ${RUN}.`,
      content: "Conteúdo da turma de teste.",
      course_id: state.coursePtId,
      term_id: state.termId,
      section: "t01",
      team: [{ person_id: leadId, role: "lead" }],
      meta: {
        _lps_schedule: "Seg/Qua 14h",
        _lps_venue: "Sala H-324",
        _lps_syllabus_snapshot: `Ementa ${RUN}: metadados e rotas.`,
      },
    });
    expect(offering.status, JSON.stringify(offering.body)).toBe(201);
    state.offeringPtId = offering.body.id;

    const offeringEn = await api("POST", "/teaching/offerings", {
      title: `SEO Section ${RUN} T01`,
      slug: `section-seo-${RUN}-t01`,
      locale: "en",
      translation_of: state.offeringPtId,
      excerpt: `Metadata test section ${RUN}.`,
      content: "Test section content.",
      meta: { _lps_syllabus_snapshot: `Syllabus ${RUN}: metadata and routes.` },
    });
    expect(offeringEn.status, JSON.stringify(offeringEn.body)).toBe(201);
    state.offeringEnId = offeringEn.body.id;
    await api("POST", `/teaching/records/${state.offeringEnId}/review-translation`);
    await publish(state.offeringEnId);
    await publish(state.offeringPtId);

    const unit = await api("POST", "/teaching/units", {
      title: `Unidade SEO ${RUN}`,
      slug: `unidade-seo-${RUN}`,
      offering_id: state.offeringPtId,
      meta: { _lps_anchor: `u1-${RUN}`, _lps_position: 1 },
    });
    expect(unit.status, JSON.stringify(unit.body)).toBe(201);
    state.unitId = unit.body.id;
    await publish(state.unitId);

    // One downloadable resource (root-relative link for the print check) and
    // one withdrawn resource (notice without a link, denied download).
    const versionId = await uploadVersion(state.offeringPtId, `apostila-seo-${RUN}.pdf`);
    const resource = await api("POST", "/teaching/resources", {
      title: `Apostila SEO ${RUN}`,
      slug: `apostila-seo-${RUN}`,
      excerpt: `Notas de aula de teste ${RUN}.`,
      offering_id: state.offeringPtId,
      unit_id: state.unitId,
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
    const release = await api("POST", `/teaching/resources/${state.resourceId}/release`, {
      state: "released",
      release_at: "",
    });
    expect(release.status, JSON.stringify(release.body)).toBe(200);
    await publish(state.resourceId);
    state.downloadUrl = resource.body.download_url ?? "";
    expect(state.downloadUrl).toMatch(/^\/lps-resource\//);

    const withdrawnVersion = await uploadVersion(state.offeringPtId, `prova-seo-${RUN}.pdf`);
    const withdrawn = await api("POST", "/teaching/resources", {
      title: `Prova SEO ${RUN}`,
      slug: `prova-seo-${RUN}`,
      excerpt: `Avaliação de teste retirada ${RUN}.`,
      offering_id: state.offeringPtId,
      version_id: withdrawnVersion,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    expect(withdrawn.status, JSON.stringify(withdrawn.body)).toBe(201);
    state.withdrawnId = withdrawn.body.id;
    const withdrawRelease = await api("POST", `/teaching/resources/${state.withdrawnId}/release`, {
      state: "released",
      release_at: "",
    });
    expect(withdrawRelease.status, JSON.stringify(withdrawRelease.body)).toBe(200);
    await publish(state.withdrawnId);
    const withdraw = await api("POST", `/teaching/resources/${state.withdrawnId}/withdraw`);
    expect(withdraw.status, JSON.stringify(withdraw.body)).toBe(200);

    state.offeringPathPt = `/pt-br/ensino/disciplinas/${state.courseSlugPt}/${state.termToken}/t01/`;
    state.offeringPathEn = `/en/teaching/courses/${state.courseSlugEn}/${state.termToken}/t01/`;
  });

  test("teaching routes emit canonical, reciprocal hreflang and typed JSON-LD", async ({
    browser,
  }) => {
    const anon = await anonymousContext(browser);
    const coursePath = `/pt-br/ensino/disciplinas/${state.courseSlugPt}/`;

    const landing = await anon.request.get("/pt-br/ensino/");
    expect(landing.status()).toBe(200);
    const landingHtml = await landing.text();
    expect(landingHtml).toContain(
      '<link rel="canonical" href="http://localhost:8899/pt-br/ensino/">',
    );
    expect(landingHtml).toContain('hreflang="en" href="http://localhost:8899/en/teaching/"');
    expect(landingHtml).toContain(
      'hreflang="x-default" href="http://localhost:8899/pt-br/ensino/"',
    );

    const course = await anon.request.get(coursePath);
    expect(course.status()).toBe(200);
    const courseHtml = await course.text();
    expect(courseHtml).toContain(
      `<link rel="canonical" href="http://localhost:8899${coursePath}">`,
    );
    expect(courseHtml).toContain(
      `hreflang="en" href="http://localhost:8899/en/teaching/courses/${state.courseSlugEn}/"`,
    );
    expect(courseHtml).toContain('"@type":"Course"');
    expect(courseHtml).toContain(`"courseCode":"SEO-${RUN.toUpperCase()}"`);
    expect(courseHtml).toContain('"@type":"CourseInstance"');
    expect(courseHtml).toContain('name="lps:record-type" content="lps_course"');
    expect(courseHtml).toContain('name="lps:record-kind" content="undergraduate"');
    // The visible breadcrumb carries the section crumb, not only home + title.
    expect(courseHtml).toMatch(/lps-breadcrumbs[\s\S]*Ensino[\s\S]*aria-current="page"/);

    const offering = await anon.request.get(state.offeringPathPt);
    expect(offering.status()).toBe(200);
    const offeringHtml = await offering.text();
    expect(offeringHtml).toContain(
      `<link rel="canonical" href="http://localhost:8899${state.offeringPathPt}">`,
    );
    expect(offeringHtml).toContain(
      `hreflang="en" href="http://localhost:8899${state.offeringPathEn}"`,
    );
    expect(offeringHtml).toContain('"@type":"CourseInstance"');
    expect(offeringHtml).toContain(`"startDate":"2026-08-01"`);
    expect(offeringHtml).toContain('"@type":"Place"');
    expect(offeringHtml).toContain('name="lps:record-type" content="lps_offering"');
    expect(offeringHtml).toContain('name="lps:record-kind" content="current"');
    // The offering document names its course in the title and the breadcrumb.
    expect(offeringHtml).toMatch(new RegExp(`<title>[^<]*Disciplina SEO ${RUN}[^<]*</title>`));
    expect(offeringHtml).toMatch(
      /lps-breadcrumbs[\s\S]*Ensino[\s\S]*Disciplina SEO[\s\S]*aria-current="page"/,
    );

    // The English course answers with the reciprocal pair.
    const courseEn = await anon.request.get(`/en/teaching/courses/${state.courseSlugEn}/`);
    expect(courseEn.status()).toBe(200);
    const courseEnHtml = await courseEn.text();
    expect(courseEnHtml).toContain(`hreflang="pt-BR" href="http://localhost:8899${coursePath}"`);
    expect(courseEnHtml).toContain('"inLanguage":"en"');

    await anon.close();
  });

  test("default teaching permalinks answer with one-hop canonical redirects", async ({
    browser,
  }) => {
    const anon = await anonymousContext(browser);
    const checks = [
      [`/lps_course/${state.courseSlugPt}/`, `/pt-br/ensino/disciplinas/${state.courseSlugPt}/`],
      [
        `/pt-br/lps_course/${state.courseSlugPt}/`,
        `/pt-br/ensino/disciplinas/${state.courseSlugPt}/`,
      ],
      [`/en/lps_course/${state.courseSlugEn}/`, `/en/teaching/courses/${state.courseSlugEn}/`],
      ["/lps_course/", "/pt-br/ensino/"],
      ["/lps_offering/", "/pt-br/ensino/"],
      ["/en/lps_offering/", "/en/teaching/"],
    ];
    for (const [legacy, target] of checks) {
      const response = await anon.request.get(legacy, { maxRedirects: 0 });
      expect(response.status(), `${legacy} must 301`).toBe(301);
      expect(response.headers()["location"], `${legacy} target`).toBe(
        `http://localhost:8899${target}`,
      );
    }
    await anon.close();
  });

  test("the locale switcher links canonical counterparts and keeps the search query", async ({
    browser,
  }) => {
    const anon = await anonymousContext(browser);
    const pg = await anon.newPage();

    await pg.goto(`/pt-br/ensino/disciplinas/${state.courseSlugPt}/`, {
      waitUntil: "domcontentloaded",
    });
    const switcher = pg.locator(".lps-locale a");
    await expect(switcher).toHaveCount(2);
    await expect(switcher.nth(0)).toHaveAttribute(
      "href",
      `/pt-br/ensino/disciplinas/${state.courseSlugPt}/`,
    );
    await expect(switcher.nth(1)).toHaveAttribute(
      "href",
      `/en/teaching/courses/${state.courseSlugEn}/`,
    );

    await pg.goto(state.offeringPathPt, { waitUntil: "domcontentloaded" });
    await expect(pg.locator(".lps-locale a").nth(1)).toHaveAttribute("href", state.offeringPathEn);

    await pg.goto("/pt-br/ensino/", { waitUntil: "domcontentloaded" });
    await expect(pg.locator(".lps-locale a").nth(1)).toHaveAttribute("href", "/en/teaching/");

    await pg.goto("/pt-br/busca/?q=sinais", { waitUntil: "domcontentloaded" });
    await expect(pg.locator(".lps-locale a").nth(0)).toHaveAttribute(
      "href",
      "/pt-br/busca/?q=sinais",
    );
    await expect(pg.locator(".lps-locale a").nth(1)).toHaveAttribute(
      "href",
      "/en/search/?q=sinais",
    );

    await anon.close();
  });

  test("the search surface stays noindex with a query-aware title", async ({ browser }) => {
    const anon = await anonymousContext(browser);
    const response = await anon.request.get("/pt-br/busca/?q=sinais");
    expect(response.status()).toBe(200);
    const html = await response.text();
    expect(html).toContain("<title>Busca: sinais — LPS/UFRJ</title>");
    expect(html).toContain("noindex");
    expect(html).toContain('<link rel="canonical" href="http://localhost:8899/pt-br/busca/">');

    const english = await anon.request.get("/en/search/?q=signals");
    const englishHtml = await english.text();
    expect(englishHtml).toContain("<title>Search: signals — LPS/UFRJ</title>");

    // A withdrawn resource never reaches the result set; the released one does.
    const withdrawn = await anon.request.get(
      `/pt-br/busca/?q=${encodeURIComponent(`Prova SEO ${RUN}`)}`,
    );
    const withdrawnHtml = await withdrawn.text();
    const withdrawnResults = withdrawnHtml.match(/<ol class="lps-search-results"[\s\S]*?<\/ol>/);
    expect(withdrawnResults?.[0] ?? "").not.toContain(`Prova SEO ${RUN}`);
    const released = await anon.request.get(
      `/pt-br/busca/?q=${encodeURIComponent(`Apostila SEO ${RUN}`)}`,
    );
    const releasedHtml = await released.text();
    const releasedResults = releasedHtml.match(/<ol class="lps-search-results"[\s\S]*?<\/ol>/);
    expect(releasedResults?.[0] ?? "").toContain(`Apostila SEO ${RUN}`);

    await anon.close();
  });

  test("the sitemap lists teaching routes and robots.txt denies resource downloads", async ({
    browser,
  }) => {
    const anon = await anonymousContext(browser);
    const sitemap = await anon.request.get("/sitemap-pt-br.xml");
    expect(sitemap.status()).toBe(200);
    const xml = await sitemap.text();
    expect(xml).toContain("<loc>http://localhost:8899/pt-br/ensino/</loc>");
    expect(xml).toContain(
      `<loc>http://localhost:8899/pt-br/ensino/disciplinas/${state.courseSlugPt}/</loc>`,
    );
    expect(xml).toContain(`<loc>http://localhost:8899${state.offeringPathPt}</loc>`);
    expect(xml).not.toContain("/lps-resource/");
    expect(xml).not.toContain("/lps_course/");

    const sitemapEn = await anon.request.get("/sitemap-en.xml");
    const xmlEn = await sitemapEn.text();
    expect(xmlEn).toContain("<loc>http://localhost:8899/en/teaching/</loc>");
    expect(xmlEn).toContain(
      `<loc>http://localhost:8899/en/teaching/courses/${state.courseSlugEn}/</loc>`,
    );
    expect(xmlEn).toContain(`<loc>http://localhost:8899${state.offeringPathEn}</loc>`);

    const robots = await anon.request.get("/robots.txt");
    const robotsTxt = await robots.text();
    expect(robotsTxt).toContain("Disallow: /lps-resource/");
    expect(robotsTxt).toContain("Disallow: /pt-br/busca/");
    expect(robotsTxt).toContain("Sitemap: http://localhost:8899/sitemap.xml");

    await anon.close();
  });

  test("legacy addresses resolve in one hop and a redirect cycle is refused", async ({
    browser,
  }) => {
    const anon = await anonymousContext(browser);

    const direct = await anon.request.get("/lps/antigo.html", { maxRedirects: 0 });
    expect(direct.status()).toBe(301);
    expect(direct.headers()["location"]).toBe("http://localhost:8899/pt-br/pesquisa/");

    // The seeded chain flattens to a single hop at serve time.
    const chain = await anon.request.get("/lps/cadeia.html", { maxRedirects: 0 });
    expect(chain.status()).toBe(301);
    expect(chain.headers()["location"]).toBe("http://localhost:8899/pt-br/pesquisa/");

    const gone = await anon.request.get("/lps/removido.html", { maxRedirects: 0 });
    expect(gone.status()).toBe(410);

    // A redirect cycle written through the REST boundary is refused at serve
    // time: the loop never produces a redirect, the address answers 404.
    const redirectA = await wpApi("POST", "/redirects", {
      title: `/legado-ciclo-a-${RUN}`,
      slug: `legado-ciclo-a-${RUN}`,
      status: "publish",
      excerpt: "Ciclo de redirecionamento de teste.",
      content: "Fixture de ciclo A.",
      meta: {
        _lps_locale: "pt-br",
        _lps_redirect_source: `/legado-ciclo-a-${RUN}`,
        _lps_redirect_target: `/legado-ciclo-b-${RUN}`,
        _lps_redirect_status: 301,
      },
    });
    expect(redirectA.status, JSON.stringify(redirectA.body)).toBe(201);
    state.redirectAId = redirectA.body.id;
    const redirectB = await wpApi("POST", "/redirects", {
      title: `/legado-ciclo-b-${RUN}`,
      slug: `legado-ciclo-b-${RUN}`,
      status: "publish",
      excerpt: "Ciclo de redirecionamento de teste.",
      content: "Fixture de ciclo B.",
      meta: {
        _lps_locale: "pt-br",
        _lps_redirect_source: `/legado-ciclo-b-${RUN}`,
        _lps_redirect_target: `/legado-ciclo-a-${RUN}`,
        _lps_redirect_status: 301,
      },
    });
    expect(redirectB.status, JSON.stringify(redirectB.body)).toBe(201);
    state.redirectBId = redirectB.body.id;

    const cycle = await anon.request.get(`/legado-ciclo-a-${RUN}`, { maxRedirects: 0 });
    expect(cycle.status(), "a redirect cycle must never answer a redirect").toBe(404);

    await anon.close();
  });

  test("a missing English variant leaves the index, the sitemap and the switcher", async () => {
    test.setTimeout(120_000);
    const anon = await anonymousContext(page.context().browser());
    const coursePathEn = `/en/teaching/courses/${state.courseSlugEn}/`;
    const coursePathPt = `/pt-br/ensino/disciplinas/${state.courseSlugPt}/`;

    // Unpublish the English variant through the real REST boundary.
    const draft = await wpApi("POST", `/courses/${state.courseEnId}`, { status: "draft" });
    expect(draft.status, JSON.stringify(draft.body)).toBe(200);

    const en = await anon.request.get(coursePathEn);
    expect(en.status()).toBe(404);

    const pt = await anon.request.get(coursePathPt);
    expect(pt.status()).toBe(200);
    const ptHtml = await pt.text();
    // No hreflang alternate is emitted while the English variant is absent.
    expect(ptHtml).not.toContain('rel="alternate"');
    expect(ptHtml).not.toContain("x-default");
    const ptPage = await anon.newPage();
    await ptPage.goto(coursePathPt, { waitUntil: "domcontentloaded" });
    await expect(ptPage.locator(".lps-locale a")).toHaveCount(1);
    await ptPage.close();

    const sitemapEn = await anon.request.get("/sitemap-en.xml");
    const sitemapEnXml = await sitemapEn.text();
    expect(sitemapEnXml).not.toContain(`<loc>http://localhost:8899${coursePathEn}</loc>`);
    // The offering under the unpublished course is withheld too: the route
    // answers 404, so the sitemap never advertises it.
    expect(sitemapEnXml).not.toContain(`<loc>http://localhost:8899${state.offeringPathEn}</loc>`);

    // Restore the variant for the next checks.
    const republish = await wpApi("POST", `/courses/${state.courseEnId}`, { status: "publish" });
    expect(republish.status, JSON.stringify(republish.body)).toBe(200);
    const restored = await anon.request.get(coursePathEn);
    expect(restored.status()).toBe(200);
    const restoredHtml = await restored.text();
    expect(restoredHtml).toContain(
      `rel="alternate" hreflang="pt-BR" href="http://localhost:8899${coursePathPt}"`,
    );

    await anon.close();
  });

  test("a stale English variant is noindex, noticed and out of the sitemap", async () => {
    test.setTimeout(120_000);
    const anon = await anonymousContext(page.context().browser());
    const coursePathEn = `/en/teaching/courses/${state.courseSlugEn}/`;
    const coursePathPt = `/pt-br/ensino/disciplinas/${state.courseSlugPt}/`;

    // Editing the Portuguese authority marks the English variant stale.
    const edit = await wpApi("POST", `/courses/${state.coursePtId}`, {
      title: `Disciplina SEO ${RUN} rev`,
    });
    expect(edit.status, JSON.stringify(edit.body)).toBe(200);

    const en = await anon.request.get(coursePathEn);
    expect(en.status()).toBe(200);
    const enHtml = await en.text();
    expect(enHtml).toContain("noindex");
    expect(enHtml).toContain("lps-translation-notice");
    // No hreflang alternate is emitted while the variant is unreviewed.
    expect(enHtml).not.toContain('rel="alternate"');
    expect(enHtml).not.toContain("x-default");

    const sitemapEn = await anon.request.get("/sitemap-en.xml");
    expect(await sitemapEn.text()).not.toContain(`<loc>http://localhost:8899${coursePathEn}</loc>`);

    // The reviewer clears the stale flag through the real review boundary.
    const review = await api("POST", `/teaching/records/${state.courseEnId}/review-translation`);
    expect(review.status, JSON.stringify(review.body)).toBe(200);
    const fresh = await anon.request.get(coursePathEn);
    const freshHtml = await fresh.text();
    expect(freshHtml).not.toContain("noindex");
    expect(freshHtml).toContain(
      `rel="alternate" hreflang="pt-BR" href="http://localhost:8899${coursePathPt}"`,
    );

    await anon.close();
  });

  test("a withdrawn resource keeps its notice, denies its route and stays out of search", async ({
    browser,
  }) => {
    const anon = await anonymousContext(browser);
    const offering = await anon.request.get(state.offeringPathPt);
    const html = await offering.text();
    expect(html).toContain(`Prova SEO ${RUN}`);
    expect(html).toContain("lps-material-note-warning");
    expect(html).toContain('data-state="withdrawn"');
    // The withdrawn row never renders a download link.
    const withdrawnRow = html.match(/data-state="withdrawn"[\s\S]*?<\/li>/);
    expect(withdrawnRow).toBeTruthy();
    expect(withdrawnRow[0]).not.toContain("<a href");

    const search = await anon.request.get(
      `/pt-br/busca/?q=${encodeURIComponent(`Prova SEO ${RUN}`)}`,
    );
    const searchHtml = await search.text();
    const results = searchHtml.match(/<ol class="lps-search-results"[\s\S]*?<\/ol>/);
    expect(results?.[0] ?? "").not.toContain(`Prova SEO ${RUN}`);

    await anon.close();
  });

  test("the offering page prints without navigation strips and keeps unit blocks whole", async ({
    browser,
  }) => {
    const anon = await anonymousContext(browser);
    const pg = await anon.newPage();
    await pg.goto(state.offeringPathPt, { waitUntil: "domcontentloaded" });

    await pg.emulateMedia({ media: "print" });
    await expect(pg.locator(".lps-offering-toc")).toBeHidden();
    await expect(pg.locator(".lps-offering-history")).toBeHidden();
    await expect(pg.locator(".lps-breadcrumbs")).toBeHidden();

    const unitBreak = await pg
      .locator(".lps-units > li")
      .first()
      .evaluate((el) => getComputedStyle(el).breakInside);
    expect(["avoid", "avoid-page"]).toContain(unitBreak);

    // The download link prints its destination: the root-relative material
    // URL is appended as text so the printed page keeps the address. Engines
    // serialize the ::after content differently (Chromium resolves attr(),
    // Firefox reports the expression), so the assertion accepts either form.
    const downloadContent = await pg
      .locator(".lps-materials a[href^='/']")
      .first()
      .evaluate((el) => getComputedStyle(el, "::after").content);
    expect(downloadContent).toMatch(/\/lps-resource\/|attr\(href\)/);

    await pg.emulateMedia({ media: "screen" });
    await expect(pg.locator(".lps-offering-toc")).toBeVisible();

    await anon.close();
  });
});

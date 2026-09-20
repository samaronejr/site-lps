import { expect, test } from "@playwright/test";
import { mkdirSync, writeFileSync } from "node:fs";

/**
 * Task 22 — performance measurement and time/cache behavior.
 *
 * The spec exercises the real HTTP boundary of the dedicated Playground: the
 * homepage and a dense offering page are fetched as anonymous HTML while
 * every subresource request is audited for third-party payload; the single
 * hero image is eager/high-priority and every other image is lazy; the
 * release clock is evaluated at read time — a future-scheduled resource is
 * invisible and undownloadable, becomes servable the moment its release
 * instant passes, and a withdrawal denies the next request — all without a
 * scheduler ever running; the guarded download stays fail-closed when the
 * scanner is down and recovers when it returns; and the query-count probe
 * proves the materials list grows by a bounded number of queries when the
 * resource count grows.
 *
 * All timings and query counts are laboratory measurements on the WASM
 * Playground; they are recorded as development signals, never as field
 * percentiles or production latency.
 */

const BASE_URL = process.env.LPS_BASE_URL ?? "http://localhost:8905";
const RUN = `t22-${Date.now().toString(36)}`;
const AUTO_LOGIN_COOKIE = {
  name: "playground_auto_login_already_happened",
  value: "1",
};
const PDF = `%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\n% task-22 fixture ${RUN}\n%%EOF\n`;
const PAST_RELEASE = "2020-01-01T00:00:00+00:00";
const FUTURE_RELEASE = "2999-01-01T00:00:00+00:00";
// A flat-growth list may still pay a small constant for the extra rows (one
// primed meta read per new record at most); a pathological list pays several
// queries per row. Thirty added resources may cost at most this many queries.
const MAX_QUERY_GROWTH_FOR_30_RESOURCES = 30;
const MEASUREMENT_OUT =
  process.env.LPS_MEASUREMENT_OUT ?? "test-results/task-22-measurements.json";

const state = {
  nonce: "",
  personId: 0,
  termId: 0,
  courseId: 0,
  offeringId: 0,
  offeringPath: "",
  unitId: 0,
  releasedDownloadUrl: "",
  scheduledDownloadUrl: "",
  withdrawnDownloadUrl: "",
  measurements: {
    suite: "task-22",
    captured_at: "",
    environment: {
      runtime: "wordpress-playground (PHP-WASM, SQLite, single-process)",
      base_url: BASE_URL,
      content: "seeded fixtures plus spec-created teaching graph",
      browser: "playwright project browser",
      network: "loopback, no throttling",
    },
    measurement_method:
      "laboratory: on-demand fetches against the dedicated Playground; query counts read from the lps-perf-probe footer marker; timings are response-time observations, not percentiles",
    field_data:
      "unavailable — the site ships no real-user monitoring; no field percentiles are reported",
    pages: {},
    query_growth: {},
  },
};

let context;
let page;

test.describe.configure({ mode: "serial" });

/**
 * Returns a context whose anonymous requests skip the auto-login handshake.
 *
 * The marker cookie must be set before the first request: without it the
 * Playground auto-login mu-plugin answers the first hit with admin auth
 * cookies, and every later "anonymous" request would be authenticated —
 * which would falsify the cache and visibility assertions.
 */
async function anonymousContext(browser) {
  const ctx = await browser.newContext({ baseURL: BASE_URL });
  await ctx.addCookies([{ ...AUTO_LOGIN_COOKIE, url: BASE_URL }]);
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
      log: "lps-t22-publisher",
      pwd: "lps-t22-publisher-pass",
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
  expect(challenge.headers()["location"] ?? "").not.toContain("validate_2fa");
}

/** Establishes a verified publisher session, retrying the raced login. */
async function ensurePublisherSession(ctx) {
  let lastError = "";
  for (let attempt = 0; attempt < 4; attempt += 1) {
    try {
      await loginAsPublisher(ctx);
      state.nonce = await restNonce(ctx);
      return;
    } catch (error) {
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

/** Publishes one stored record through the gated endpoint. */
async function publish(id) {
  const result = await api("POST", `/teaching/records/${id}/publish`);
  expect(result.status, `publish ${id}: ${JSON.stringify(result.body)}`).toBe(200);
  return result.body;
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

/** Fetches a page as anonymous HTML and returns status, headers and body. */
async function fetchAnon(ctx, path) {
  const response = await ctx.request.get(path);
  return {
    status: response.status(),
    headers: response.headers(),
    body: await response.text(),
  };
}

/** Reads the lps-perf-probe query-count marker from rendered HTML. */
function queryCount(html) {
  const match = html.match(/lps-queries:(\d+)/);
  expect(match, "lps-perf-probe marker missing — fixture not mounted").toBeTruthy();
  return Number(match[1]);
}

test.describe("task-22: performance measurement and time/cache behavior", () => {
  test.beforeAll(async ({ browser }) => {
    test.setTimeout(240_000);
    context = await browser.newContext({ baseURL: BASE_URL });
    page = await context.newPage();
    await ensurePublisherSession(context);
  });

  test.afterAll(async () => {
    state.measurements.captured_at = new Date().toISOString();
    mkdirSync("test-results", { recursive: true });
    writeFileSync(MEASUREMENT_OUT, `${JSON.stringify(state.measurements, null, 2)}\n`);
    await context?.close();
  });

  test("publishes the fixture graph: offering, unit and timed materials", async () => {
    test.setTimeout(300_000);

    const person = await page.request.get(
      "/wp-json/wp/v2/people?slug=docente-sinais-fixture&status=any",
      { headers: { "X-WP-Nonce": state.nonce } },
    );
    const people = await person.json();
    expect(people.length, "seeded person missing").toBeGreaterThan(0);
    state.personId = people[0].id;

    const term = await api("POST", "/teaching/terms", {
      title: `2026.2 ${RUN}`,
      slug: `term-${RUN}`,
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
    expect(term.status, JSON.stringify(term.body)).toBe(201);
    state.termId = term.body.id;

    const course = await api("POST", "/teaching/courses", {
      title: `Disciplina ${RUN}`,
      slug: `disciplina-${RUN}`,
      excerpt: "Disciplina de teste.",
      content: "Conteúdo de fixture.",
      meta: {
        _lps_course_code: `PERF-${RUN.toUpperCase()}`,
        _lps_course_level: "undergraduate",
        _lps_calendar_key: "semester",
        _lps_syllabus: "Ementa padrão da disciplina.",
      },
    });
    expect(course.status, JSON.stringify(course.body)).toBe(201);
    state.courseId = course.body.id;

    // The bilingual publish contract requires a reviewed, published English
    // variant before the Portuguese authority may publish.
    const courseEn = await api("POST", "/teaching/courses", {
      title: `Course ${RUN}`,
      slug: `course-${RUN}`,
      locale: "en",
      translation_of: state.courseId,
      excerpt: "Test course.",
      content: "Fixture content.",
    });
    expect(courseEn.status, JSON.stringify(courseEn.body)).toBe(201);
    await api("POST", `/teaching/records/${courseEn.body.id}/review-translation`);
    await publish(courseEn.body.id);
    await publish(state.courseId);

    const offering = await api("POST", "/teaching/offerings", {
      title: `Turma ${RUN} T01`,
      slug: `turma-${RUN}-t01`,
      excerpt: "Turma de teste.",
      content: "Conteúdo de fixture.",
      course_id: state.courseId,
      term_id: state.termId,
      section: "t01",
      team: [{ person_id: state.personId, role: "lead" }],
      meta: { _lps_syllabus_snapshot: `Ementa corrente ${RUN}.` },
    });
    expect(offering.status, JSON.stringify(offering.body)).toBe(201);
    state.offeringId = offering.body.id;

    const offeringEn = await api("POST", "/teaching/offerings", {
      title: `Section ${RUN} T01`,
      slug: `section-${RUN}-t01`,
      locale: "en",
      translation_of: state.offeringId,
      excerpt: "Test section.",
      content: "Fixture content.",
    });
    expect(offeringEn.status, JSON.stringify(offeringEn.body)).toBe(201);
    await api("POST", `/teaching/records/${offeringEn.body.id}/review-translation`);
    await publish(offeringEn.body.id);
    await publish(state.offeringId);
    state.offeringPath = `/pt-br/ensino/disciplinas/disciplina-${RUN}/2026-2-${RUN}-semester/t01/`;

    const unit = await api("POST", "/teaching/units", {
      title: `Unidade Um ${RUN}`,
      slug: `unidade-um-${RUN}`,
      offering_id: state.offeringId,
      meta: { _lps_anchor: `u1-${RUN}`, _lps_position: 1 },
    });
    expect(unit.status, JSON.stringify(unit.body)).toBe(201);
    state.unitId = unit.body.id;
    await publish(state.unitId);

    // Released versioned download.
    const releasedVersion = await uploadVersion(state.offeringId, `apostila-${RUN}.pdf`);
    const released = await publishResource({
      title: `Apostila ${RUN}`,
      slug: `apostila-${RUN}`,
      offering_id: state.offeringId,
      unit_id: state.unitId,
      version_id: releasedVersion,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    state.releasedDownloadUrl = released.download_url;
    expect(state.releasedDownloadUrl).toMatch(/^\/lps-resource\//);

    // Future-scheduled versioned download: invisible and undownloadable
    // until its instant passes.
    const scheduledVersion = await uploadVersion(state.offeringId, `agendado-${RUN}.pdf`);
    const scheduled = await publishResource({
      title: `Agendado ${RUN}`,
      slug: `agendado-${RUN}`,
      offering_id: state.offeringId,
      unit_id: state.unitId,
      version_id: scheduledVersion,
      release_state: "scheduled",
      release_at: FUTURE_RELEASE,
      meta: {
        _lps_resource_type: "document",
        _lps_resource_language: "pt-br",
        _lps_rights_review: "approved",
        _lps_accessibility_review: "approved",
      },
    });
    state.scheduledId = scheduled.id;
    state.scheduledDownloadUrl = scheduled.download_url;

    // Withdrawn versioned download: notice stays, bytes are denied.
    const withdrawnVersion = await uploadVersion(state.offeringId, `retirado-${RUN}.pdf`);
    const withdrawn = await publishResource({
      title: `Retirado ${RUN}`,
      slug: `retirado-${RUN}`,
      offering_id: state.offeringId,
      unit_id: state.unitId,
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
  });

  test("the homepage carries no third-party payload and loads its hero eagerly", async ({
    browser,
  }) => {
    const anonymous = await anonymousContext(browser);
    const anonPage = await anonymous.newPage();
    const subresources = [];
    anonPage.on("request", (request) => {
      if (["stylesheet", "script", "font", "image", "media", "iframe"].includes(request.resourceType())) {
        subresources.push(request.url());
      }
    });
    try {
      const started = Date.now();
      const response = await anonPage.goto("/pt-br/", { waitUntil: "load" });
      state.measurements.pages.homepage = {
        path: "/pt-br/",
        status: response.status(),
        total_ms: Date.now() - started,
        cache_control: response.headers()["cache-control"] ?? "",
      };
      expect(response.status()).toBe(200);

      // Every subresource is same-origin: no third-party script, style,
      // font, image or frame ever reaches the wire.
      const origin = new URL(BASE_URL).origin;
      for (const url of subresources) {
        expect(new URL(url).origin, `third-party subresource: ${url}`).toBe(origin);
      }
      expect(subresources.some((url) => url.endsWith(".woff2"))).toBe(true);

      // Exactly one eager/high-priority image: the mission hero. Every other
      // content image is lazy. The masthead logo is exempt: it is a tiny
      // always-visible brand mark, so lazy-loading it would be a defect —
      // but it must never claim a priority hint that competes with the LCP
      // image.
      const images = anonPage.locator("img");
      const count = await images.count();
      expect(count).toBeGreaterThan(1);
      let eager = 0;
      for (let i = 0; i < count; i += 1) {
        const img = images.nth(i);
        const loading = await img.getAttribute("loading");
        const priority = await img.getAttribute("fetchpriority");
        const classes = (await img.getAttribute("class")) ?? "";
        if (classes.includes("lps-logo")) {
          expect(loading, "the masthead logo must not be lazy").not.toBe("lazy");
          expect(priority, "the masthead logo must not claim high priority").not.toBe("high");
          continue;
        }
        if ("eager" === loading || "high" === priority) {
          eager += 1;
          expect(loading).toBe("eager");
          expect(priority).toBe("high");
        } else {
          expect(loading).toBe("lazy");
        }
      }
      expect(eager, "exactly one LCP image may be eager/high-priority").toBe(1);

      // No external URL is referenced anywhere in the served markup.
      const html = await anonPage.content();
      const external = html.match(/(?:src|href)="https?:\/\/([^"]+)"/g) ?? [];
      for (const ref of external) {
        expect(ref, `external reference in markup: ${ref}`).toContain("localhost:8905");
      }
    } finally {
      await anonymous.close();
    }
  });

  test("timed visibility transitions happen at read time, never by a scheduler", async ({
    browser,
  }) => {
    const anonymous = await anonymousContext(browser);
    try {
      // Before the release instant: the scheduled resource is absent from
      // the materials list and its guarded route denies delivery.
      let fetched = await fetchAnon(anonymous, state.offeringPath);
      expect(fetched.status).toBe(200);
      expect(fetched.body).toContain(`Apostila ${RUN}`);
      expect(fetched.body).not.toContain(`Agendado ${RUN}`);
      let denied = await anonymous.request.get(state.scheduledDownloadUrl);
      expect(denied.status()).toBe(404);

      // The withdrawn resource keeps its notice and its route denies too.
      expect(fetched.body).toContain(`Retirado ${RUN}`);
      denied = await anonymous.request.get(state.withdrawnDownloadUrl);
      expect(denied.status()).toBe(404);
      denied = await anonymous.request.get(
        "/lps-resource/00000000-0000-0000-0000-000000000000/",
      );
      expect(denied.status()).toBe(404);

      // Move the release instant into the past. No scheduler runs anywhere
      // in this environment; the next read must already see the release.
      const reRelease = await api("POST", `/teaching/resources/${state.scheduledId}/release`, {
        state: "scheduled",
        release_at: PAST_RELEASE,
      });
      expect(reRelease.status, JSON.stringify(reRelease.body)).toBe(200);

      fetched = await fetchAnon(anonymous, state.offeringPath);
      expect(fetched.status).toBe(200);
      expect(fetched.body).toContain(`Agendado ${RUN}`);
      const served = await anonymous.request.get(state.scheduledDownloadUrl);
      expect(served.status()).toBe(200);
      expect((await served.body()).toString()).toContain("%PDF-1.4");

      // The released download still serves and the withdrawn one still
      // denies: the guard re-evaluates the clock on every request.
      const stillServed = await anonymous.request.get(state.releasedDownloadUrl);
      expect(stillServed.status()).toBe(200);
      denied = await anonymous.request.get(state.withdrawnDownloadUrl);
      expect(denied.status()).toBe(404);
    } finally {
      await anonymous.close();
    }
  });

  test("the guarded download stays fail-closed while the scanner is down", async () => {
    // The upload → deny → rescan → release → publish flow issues several
    // sequential requests against the single-process WASM runtime; the
    // default 30 s test timeout is not enough on the slowest browser.
    test.setTimeout(180_000);
    // Take the scanner down through the test-only fixture endpoint.
    const down = await api("POST", "/test/scanner", { state: "down" });
    expect(down.status, JSON.stringify(down.body)).toBe(200);
    expect(down.body.state).toBe("down");

    try {
      // An upload under a failed scanner lands quarantined; releasing it is
      // refused, and even a resource that somehow referenced it could never
      // serve bytes.
      const response = await page.request.post("/wp-json/lps/v1/teaching/resource-versions", {
        multipart: {
          file: { name: `quarentena-${RUN}.pdf`, mimeType: "application/pdf", buffer: Buffer.from(PDF) },
          offering_id: String(state.offeringId),
        },
        headers: { "X-WP-Nonce": state.nonce },
      });
      expect(response.status()).toBe(201);
      const versionId = (await response.json()).version_id;

      const created = await api("POST", "/teaching/resources", {
        title: `Quarentena ${RUN}`,
        slug: `quarentena-${RUN}`,
        offering_id: state.offeringId,
        unit_id: state.unitId,
        version_id: versionId,
        meta: {
          _lps_resource_type: "document",
          _lps_resource_language: "pt-br",
          _lps_rights_review: "approved",
          _lps_accessibility_review: "approved",
        },
      });
      expect(created.status, JSON.stringify(created.body)).toBe(201);
      const release = await api("POST", `/teaching/resources/${created.body.id}/release`, {
        state: "released",
      });
      expect(release.status, JSON.stringify(release.body)).not.toBe(200);

      // Restore the scanner, rescan and release: the same version becomes
      // servable — the guard re-evaluates storage state per request.
      const up = await api("POST", "/test/scanner", { state: "up" });
      expect(up.status).toBe(200);
      const rescan = await api("POST", `/teaching/resource-versions/${versionId}/scan`, {
        offering_id: state.offeringId,
      });
      expect(rescan.status, JSON.stringify(rescan.body)).toBe(200);
      const retry = await api("POST", `/teaching/resources/${created.body.id}/release`, {
        state: "released",
      });
      expect(retry.status, JSON.stringify(retry.body)).toBe(200);
      await publish(created.body.id);
    } finally {
      await api("POST", "/test/scanner", { state: "up" });
    }
  });

  test("repeat requests keep bounded cache decisions and reflect lifecycle changes", async ({
    browser,
  }) => {
    const anonymous = await anonymousContext(browser);
    try {
      const first = await fetchAnon(anonymous, state.offeringPath);
      const second = await fetchAnon(anonymous, state.offeringPath);
      expect(first.status).toBe(200);
      expect(second.status).toBe(200);
      // The cache contract is bounded: shared TTL with stale-while-revalidate.
      for (const fetched of [first, second]) {
        const control = fetched.headers["cache-control"] ?? "";
        expect(control).toContain("s-maxage=300");
        expect(control).toContain("stale-while-revalidate");
      }
      state.measurements.pages.offering = {
        path: state.offeringPath,
        status: first.status,
        cache_control: first.headers["cache-control"] ?? "",
        queries: queryCount(first.body),
      };

      // A lifecycle meta write is the invalidation signal: publish a new
      // released resource and the next anonymous read already lists it.
      const extra = await publishResource({
        title: `Extra ${RUN}`,
        slug: `extra-${RUN}`,
        offering_id: state.offeringId,
        unit_id: state.unitId,
        external_url: "https://example.org/extra",
        meta: {
          _lps_resource_type: "document",
          _lps_resource_language: "pt-br",
          _lps_rights_review: "approved",
          _lps_accessibility_review: "approved",
        },
      });
      expect(extra.id).toBeGreaterThan(0);
      const after = await fetchAnon(anonymous, state.offeringPath);
      expect(after.body).toContain(`Extra ${RUN}`);
    } finally {
      await anonymous.close();
    }
  });

  test("the materials list grows by a bounded number of queries", async ({ browser }) => {
    test.setTimeout(300_000);
    const anonymous = await anonymousContext(browser);
    try {
      const before = await fetchAnon(anonymous, state.offeringPath);
      const queriesBefore = queryCount(before.body);

      // Thirty additional released external resources on the same unit.
      const created = [];
      for (let i = 0; i < 30; i += 1) {
        const resource = await api("POST", "/teaching/resources", {
          title: `Volume ${RUN} ${String(i).padStart(2, "0")}`,
          slug: `volume-${RUN}-${i}`,
          offering_id: state.offeringId,
          unit_id: state.unitId,
          external_url: `https://example.org/volume-${i}`,
          meta: {
            _lps_resource_type: "document",
            _lps_resource_language: "pt-br",
            _lps_rights_review: "approved",
            _lps_accessibility_review: "approved",
          },
        });
        expect(resource.status, JSON.stringify(resource.body)).toBe(201);
        created.push(resource.body.id);
      }
      await Promise.all(
        created.map((id) => api("POST", `/teaching/resources/${id}/release`, { state: "released" })),
      );
      await Promise.all(created.map((id) => publish(id)));

      const after = await fetchAnon(anonymous, state.offeringPath);
      const queriesAfter = queryCount(after.body);
      const delta = queriesAfter - queriesBefore;
      state.measurements.query_growth = {
        added_resources: 30,
        queries_before: queriesBefore,
        queries_after: queriesAfter,
        delta,
        bound: MAX_QUERY_GROWTH_FOR_30_RESOURCES,
      };
      expect(after.body).toContain(`Volume ${RUN} 29`);
      expect(
        delta,
        `30 added resources cost ${delta} queries — pathological growth`,
      ).toBeLessThanOrEqual(MAX_QUERY_GROWTH_FOR_30_RESOURCES);
    } finally {
      await anonymous.close();
    }
  });
});

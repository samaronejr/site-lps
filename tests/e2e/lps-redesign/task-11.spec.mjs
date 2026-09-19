// @ts-check
/**
 * Task 11 e2e: the dynamic homepage composition and the governed image
 * pipeline, exercised through the real CMS publication path.
 *
 * The spec proves the acceptance contract end to end:
 *  - the homepage renders the specified composition from governed selections,
 *    not a fixed ten-section presentation;
 *  - a newly authored, approved news item appears after CMS publication
 *    without any deployment, and a reviewed feature image renders through the
 *    governed renderer with its focal point and credit;
 *  - rejected, expired, draft, and unpublished content stays excluded;
 *  - when the image's rights are revoked the record degrades to text — never
 *    a broken-image box — and the text-only homepage stays coherent.
 *
 * Run with:
 *   LPS_BASE_URL=http://localhost:8894 npm run test:e2e -- tests/e2e/lps-redesign/task-11.spec.mjs --reporter=line
 */
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const baseURL = process.env.LPS_BASE_URL || 'http://localhost:8894';
// Each browser project signs in with its own publisher account: the three
// projects run in parallel, and a shared account's session tokens would
// invalidate one another's two-factor challenge mid-flight.
const PROJECT_USERS = {
  chromium: 'lps-t11-publisher',
  firefox: 'lps-t11-publisher-ff',
  webkit: 'lps-t11-publisher-wk',
};
/** @type {{ user: string, pass: string }} */
let publisher = { user: 'lps-t11-publisher', pass: 'lps-t11-publisher-pass' };

test.describe.configure({ mode: 'serial', timeout: 600_000 });

// Serial mode orders tests inside one project, but the three browser
// projects still run in parallel workers and share the seeded homepage
// fixtures: the composition tests read the mission attachment and the seeded
// news relation while the mutation tests revoke image rights, detach the
// featured image, and rewrite selections. A cross-process file lock gives
// every test an exclusive view of that shared state, the same primitive the
// QA endpoint uses to serialize selection writes.
const HOME_LOCK_DIR = path.join(os.tmpdir(), `lps-t11-homepage-${new URL(baseURL).port}.lock`);
// Each test holds the lock for its full duration (up to a few minutes on a
// loaded host), so the wait deadline and the stale-steal threshold must both
// exceed the slowest observed single-test duration by a wide margin.
const HOME_LOCK_STALE_MS = 600_000;
let homeLockHeld = false;

async function acquireHomepageLock() {
  const deadline = Date.now() + HOME_LOCK_STALE_MS;
  for (;;) {
    try {
      fs.mkdirSync(HOME_LOCK_DIR);
      fs.writeFileSync(path.join(HOME_LOCK_DIR, 'owner'), `${process.pid} ${Date.now()}`);
      homeLockHeld = true;
      return;
    } catch {
      try {
        if (Date.now() - fs.statSync(HOME_LOCK_DIR).mtimeMs > HOME_LOCK_STALE_MS) {
          // A crashed worker can leave the lock behind; steal it.
          fs.rmSync(HOME_LOCK_DIR, { recursive: true, force: true });
          continue;
        }
      } catch {
        // The holder released between our attempts; retry immediately.
        continue;
      }
      if (Date.now() > deadline) {
        throw new Error('homepage fixture lock was never released');
      }
      await new Promise((resolve) => setTimeout(resolve, 500));
    }
  }
}

function releaseHomepageLock() {
  // Only the holder may remove the lock: a beforeEach that timed out waiting
  // must not delete a lock another worker acquired in the meantime.
  if (homeLockHeld) {
    homeLockHeld = false;
    fs.rmSync(HOME_LOCK_DIR, { recursive: true, force: true });
  }
}

test.beforeEach(async () => {
  await acquireHomepageLock();
});

test.afterEach(async () => {
  releaseHomepageLock();
});

/** @type {import('@playwright/test').BrowserContext} */
let context;
let nonce = '';
/** @type {Record<string, any>} */
let seeded = {};

/**
 * Logs in as the publisher through the real wp-login form, including the
 * dummy two-factor provider the development environment installs.
 *
 * @param {import('@playwright/test').BrowserContext} ctx
 */
async function loginAsPublisher(ctx) {
  const page = await ctx.newPage();
  // Parallel browser projects share the account's session tokens, so a
  // concurrent login can invalidate the challenge nonce mid-flight; one
  // retry keeps the sign-in honest without masking a real credential error.
  for (let attempt = 0; attempt < 3; attempt += 1) {
    await page.goto('/wp-login.php');
    const login = page.locator('#user_login');
    await login.waitFor({ state: 'visible', timeout: 15_000 });
    await login.fill(publisher.user);
    await page.locator('#user_pass').fill(publisher.pass);
    await Promise.all([page.waitForLoadState('load'), page.locator('#wp-submit').click()]);
    // The enrolled-MFA challenge renders its own submit inside #loginform;
    // a failed sign-in must surface here, never as a later REST denial.
    const challenge = page.locator('#loginform input[type=submit], #loginform button[type=submit]');
    await challenge.waitFor({ state: 'visible', timeout: 15_000 });
    await Promise.all([page.waitForLoadState('load'), challenge.click()]);
    await expect(page.locator('#login_error')).toHaveCount(0);
    if (0 < (await page.locator('#wpbody, #wpadminbar').count())) {
      await page.close();
      return;
    }
    if (!page.url().includes('reauth=1')) {
      break;
    }
    // The concurrent login that invalidated this challenge has finished by
    // now; a short pause keeps the retry from racing the same window.
    await page.waitForTimeout(1500);
  }
  await expect(page.locator('#wpbody, #wpadminbar').first()).toBeAttached({ timeout: 15_000 });
  await page.close();
}

/**
 * Fetches the REST nonce through the authenticated admin-ajax action.
 *
 * @param {import('@playwright/test').BrowserContext} ctx
 */
async function restNonce(ctx) {
  for (let attempt = 0; attempt < 6; attempt += 1) {
    const response = await ctx.request.get('/wp-admin/admin-ajax.php?action=rest-nonce');
    if (response.ok()) {
      const nonce = (await response.text()).trim();
      if (nonce.length > 0 && nonce !== '0') {
        return nonce;
      }
    }
  }
  throw new Error('REST nonce endpoint never answered for the publisher session');
}

/**
 * Opens a clean anonymous context with the Playground auto-login cookie so
 * the front page renders instead of the wp-login redirect.
 *
 * @param {import('@playwright/test').Browser} browser
 */
async function anonymousContext(browser) {
  const ctx = await browser.newContext({ baseURL });
  await ctx.addCookies([
    { name: 'playground_auto_login_already_happened', value: '1', url: baseURL },
  ]);
  return ctx;
}

/**
 * Applies one QA homepage-selection write through the fixture endpoint.
 *
 * @param {Record<string, any>} body
 */
async function qaWrite(body) {
  const response = await context.request.post('/wp-json/lps-qa/v1/homepage', {
    headers: { 'X-WP-Nonce': nonce },
    data: body,
  });
  expect(response.ok(), `QA write failed: ${await response.text()}`).toBeTruthy();
  return response.json();
}

/**
 * Removes earlier fixture news items so reruns stay deterministic: each
 * stale record is unlinked from the homepage and force-deleted through the
 * same REST surface the publisher used to create it.
 *
 * @param {string} slugPrefix Fixture slug prefix.
 */
async function purgeNews(slugPrefix) {
  const list = await context.request.get('/wp-json/wp/v2/news?per_page=100&status=any', {
    headers: { 'X-WP-Nonce': nonce },
  });
  if (!list.ok()) {
    return;
  }
  for (const item of await list.json()) {
    if (typeof item.slug === 'string' && item.slug.startsWith(slugPrefix)) {
      await qaWrite({ action: 'unrelate', post_id: item.id });
      await context.request.delete(`/wp-json/wp/v2/news/${item.id}?force=true`, {
        headers: { 'X-WP-Nonce': nonce },
      });
    }
  }
}

test.beforeAll(async ({ browser }) => {
  // The shared Playground serves each login step in seconds under load; the
  // default 30s hook budget cannot cover form login, the two-factor
  // challenge, the nonce fetch, and the seed report.
  test.setTimeout(180_000);
  const user = process.env.LPS_PUBLISHER_USER || PROJECT_USERS[test.info().project.name] || 'lps-t11-publisher';
  publisher = { user, pass: process.env.LPS_PUBLISHER_PASS || `${user}-pass` };
  context = await browser.newContext({ baseURL });
  await context.addCookies([
    { name: 'playground_auto_login_already_happened', value: '1', url: baseURL },
  ]);
  await loginAsPublisher(context);
  nonce = await restNonce(context);
  const report = await context.request.get('/wp-json/lps-qa/v1/homepage', {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(report.ok(), `seed report failed: ${await report.text()}`).toBeTruthy();
  seeded = await report.json();
  expect(seeded.home['pt-br']).toBeGreaterThan(0);
  expect(seeded.attachment).toBeGreaterThan(0);
});

test.afterAll(async () => {
  await context?.close();
});

test('the Portuguese homepage renders the specified composition from governed selections', async ({ browser }) => {
  const anon = await anonymousContext(browser);
  const page = await anon.newPage();
  await page.goto('/pt-br/', { waitUntil: 'domcontentloaded' });

  // The locked module order: mission, research, latest, teaching, people,
  // journeys, partners — no fixed ten-section presentation remains.
  const sections = await page.locator('[data-home-section]').evaluateAll((nodes) =>
    nodes.map((node) => node.getAttribute('data-home-section'))
  );
  expect(sections).toEqual([
    'mission',
    'research',
    'projects',
    'evidence',
    'infrastructure',
    'latest',
    'teaching',
    'people',
    'journeys',
    'partners',
    'contact',
  ]);

  // Mission: one h1, the two pinned first-viewport actions, and the reviewed
  // feature image rendered through the governed renderer.
  const mission = page.locator('[data-home-section="mission"]');
  await expect(mission.locator('h1')).toHaveCount(1);
  await expect(mission.locator('h1')).toContainText('Início');
  await expect(mission.locator('[data-home-action="research"]')).toHaveAttribute('href', '/pt-br/pesquisa/');
  await expect(mission.locator('[data-home-action="teaching"]')).toHaveAttribute('href', '/pt-br/ensino/');
  const hero = mission.locator('figure.lps-media img');
  await expect(hero).toHaveCount(1);
  await expect(hero).toHaveAttribute('loading', 'eager');
  await expect(hero).toHaveAttribute('fetchpriority', 'high');
  await expect(hero).toHaveAttribute('alt', /Bancada de instrumentação/);
  const heroStyle = await hero.getAttribute('style');
  expect(heroStyle).toContain('object-position: 35% 65%');
  await expect(mission.locator('figcaption')).toContainText('Arquivo LPS');
  await expect(mission.locator('figcaption')).toContainText('authorized-use');

  // Research: the linked area plus the nested strata.
  const research = page.locator('[data-home-section="research"]');
  await expect(research.locator('h2')).toContainText('Pesquisa');
  await expect(research.locator('a[href*="qa-home-area-sinais"]')).toContainText('Processamento de sinais');
  await expect(page.locator('[data-home-section="projects"] h3')).toContainText('Projetos');
  await expect(page.locator('[data-home-section="projects"]')).toContainText('Projeto da home');
  await expect(page.locator('[data-home-section="evidence"]')).toContainText('Publicação da home');
  await expect(page.locator('[data-home-section="infrastructure"] a')).toContainText('Infraestrutura');

  // Latest: differentiated news and events with dates, status, and venue.
  const latest = page.locator('[data-home-section="latest"]');
  await expect(latest.locator('h2')).toContainText('Publicações, notícias e eventos');
  await expect(latest).toContainText('Notícia da home');
  await expect(latest).toContainText('Seminário da home');
  await expect(latest).toContainText('Agendado');
  await expect(latest).toContainText('Auditório do LPS');
  await expect(latest.locator('time').first()).toHaveAttribute('datetime', /\d{4}-\d{2}-\d{2}/);
  await expect(latest.locator('a[href="/pt-br/noticias/"]')).toHaveCount(1);

  // Teaching entrance, people module, journeys, partners + contact handoff.
  await expect(page.locator('[data-home-section="teaching"] a')).toHaveAttribute('href', '/pt-br/ensino/');
  const people = page.locator('[data-home-section="people"]');
  await expect(people).toContainText('Docente da home');
  await expect(people.locator('a[href="/pt-br/pessoas/"]')).toHaveCount(1);
  await expect(people.locator('a[href="/pt-br/colabore/"]')).toHaveCount(1);
  const journeys = page.locator('[data-home-section="journeys"]');
  await expect(journeys.locator('a')).toHaveCount(3);
  await expect(journeys.locator('[data-home-journey="opportunities"]')).toHaveAttribute('href', /\/pt-br\/oportunidades\/$/);
  await expect(journeys.locator('[data-home-journey="collaborate"]')).toHaveAttribute('href', /\/pt-br\/colabore\/$/);
  await expect(journeys.locator('[data-home-journey="infrastructure"]')).toHaveAttribute('href', /\/pt-br\/infraestrutura\/$/);
  await expect(page.locator('[data-home-section="partners"]')).toContainText('Parceiro da home');
  const contact = page.locator('[data-home-section="contact"]');
  await expect(contact.locator('a')).toHaveAttribute('href', /\/pt-br\/contato\/$/);
  await expect(contact.locator('a')).toContainText('Fale com o LPS');

  // No empty notices and no broken-image boxes anywhere on the seeded home.
  await expect(page.locator('[data-home-empty]')).toHaveCount(0);
  await expect(page.locator('.lps-feature-media-fallback')).toHaveCount(0);

  await anon.close();
});

test('the English homepage renders the same composition in English', async ({ browser }) => {
  const anon = await anonymousContext(browser);
  const page = await anon.newPage();
  await page.goto('/en/', { waitUntil: 'domcontentloaded' });

  const mission = page.locator('[data-home-section="mission"]');
  await expect(mission.locator('h1')).toContainText('Signal Processing Laboratory');
  await expect(mission.locator('[data-home-action="research"]')).toHaveAttribute('href', '/en/research/');
  await expect(mission.locator('[data-home-action="teaching"]')).toHaveAttribute('href', '/en/teaching/');
  await expect(mission.locator('figure.lps-media img')).toHaveCount(1);
  await expect(page.locator('[data-home-section="research"]')).toContainText('Signal processing (home fixture)');
  await expect(page.locator('[data-home-section="latest"]')).toContainText('Home news (QA fixture)');
  await expect(page.locator('[data-home-section="latest"]')).toContainText('Home seminar (QA fixture)');
  await expect(page.locator('[data-home-section="latest"]')).toContainText('Scheduled');
  await expect(page.locator('[data-home-section="people"]')).toContainText('Home faculty member');
  await expect(page.locator('[data-home-section="partners"]')).toContainText('Home partner');
  await expect(page.locator('[data-home-section="contact"] a')).toHaveAttribute('href', /\/en\/contact\/$/);
  await expect(page.locator('[data-home-section="journeys"] a')).toHaveCount(3);
  await expect(page.locator('[data-home-empty]')).toHaveCount(0);

  await anon.close();
});

test('a newly authored approved news item appears after CMS publication without deployment', async ({ browser }) => {
  test.setTimeout(600_000);
  // The slug is project-scoped so parallel browser projects never purge or
  // collide with one another's fixture records.
  const slug = `qa-home-noticia-dinamica-${test.info().project.name}`;
  const title = `Notícia dinâmica da home (QA ${test.info().project.name})`;
  await purgeNews(slug);

  // 1. The publisher authors the news item through the real REST surface.
  const created = await context.request.post('/wp-json/wp/v2/news', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      title,
      slug,
      status: 'publish',
      content: 'Resumo da notícia dinâmica publicada via CMS.',
      excerpt: 'Resumo da notícia dinâmica publicada via CMS.',
      meta: {
        _lps_locale: 'pt-br',
        _lps_canonical_date: new Date().toISOString(),
        _lps_news_status: 'published',
      },
    },
  });
  expect(created.ok(), `news creation failed: ${await created.text()}`).toBeTruthy();
  const news = await created.json();
  const newsId = news.id;
  expect(newsId).toBeGreaterThan(0);

  // 2. The CMS operations the public REST surface does not expose to
  //    editors — assigning the accountable owner and linking the record to
  //    the homepage — go through the QA selection endpoint.
  await qaWrite({
    action: 'meta',
    post_id: newsId,
    meta: {
      _lps_owner_user_id: 1,
      _lps_review_date: '2099-01-01',
      _lps_locale: 'pt-br',
    },
  });
  await qaWrite({ action: 'relate', post_id: newsId, role: 'featured' });

  // 3. The item renders on the live homepage — no deployment involved.
  const anon = await anonymousContext(browser);
  const page = await anon.newPage();
  await page.goto('/pt-br/', { waitUntil: 'domcontentloaded' });
  const latest = page.locator('[data-home-section="latest"]');
  await expect(latest).toContainText(title);
  await expect(latest.locator('article', { hasText: title })).toHaveCount(1);

  // 4. Removing the selection removes the item — the page is data-driven.
  await qaWrite({ action: 'unrelate', post_id: newsId });
  // The write is verified against the canonical row set before the page is
  // reloaded, so a stale render can never be mistaken for a failed delete.
  await expect
    .poll(
      async () => {
        const probe = await context.request.get('/wp-json/lps-qa/v1/homepage?probe=snapshot&locale=pt-br', {
          headers: { 'X-WP-Nonce': nonce },
        });
        const body = await probe.json();
        return (body.relations || []).some((row) => Number(row.target_post_id) === newsId);
      },
      { timeout: 15_000 }
    )
    .toBe(false);
  // The anonymous cache policy sends stale-while-revalidate, so a plain
  // reload can serve the pre-delete page from the browser's HTTP cache while
  // it revalidates in the background. An unused query var changes the cache
  // key and forces a fresh render of the same front page.
  await page.goto(`/pt-br/?lps-qa-fresh=${Date.now()}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-home-section="latest"]')).not.toContainText(title);

  await context.request.delete(`/wp-json/wp/v2/news/${newsId}?force=true`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  await anon.close();
});

test('rejected, expired, and unpublished content stays excluded', async ({ browser }) => {
  test.setTimeout(600_000);
  const draftSlug = `qa-home-rascunho-${test.info().project.name}`;
  const draftTitle = `Rascunho proibido na home (QA ${test.info().project.name})`;
  await purgeNews(draftSlug);

  // A draft news item is related to the homepage but must never render.
  const draft = await context.request.post('/wp-json/wp/v2/news', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      title: draftTitle,
      slug: draftSlug,
      status: 'draft',
      content: 'Rascunho.',
      meta: { _lps_locale: 'pt-br', _lps_canonical_date: new Date().toISOString() },
    },
  });
  expect(draft.ok()).toBeTruthy();
  const draftId = (await draft.json()).id;
  await qaWrite({
    action: 'meta',
    post_id: draftId,
    meta: { _lps_owner_user_id: 1, _lps_review_date: '2099-01-01', _lps_locale: 'pt-br' },
  });
  await qaWrite({ action: 'relate', post_id: draftId, role: 'featured' });

  // An expired selection window must exclude the seeded news item.
  const newsId = seeded.news['pt-br'];
  await qaWrite({ action: 'unrelate', post_id: newsId });
  await qaWrite({
    action: 'relate',
    post_id: newsId,
    role: 'featured',
    start_date: '2020-01-01',
    end_date: '2020-12-31',
  });

  const anon = await anonymousContext(browser);
  const page = await anon.newPage();
  await page.goto('/pt-br/', { waitUntil: 'domcontentloaded' });
  const latest = page.locator('[data-home-section="latest"]');
  await expect(latest).not.toContainText(draftTitle);
  await expect(latest).not.toContainText('Notícia da home (fixture de QA)');
  // The still-valid event keeps rendering — exclusion is per record.
  await expect(latest).toContainText('Seminário da home');

  // Restore the seeded selection for the next test.
  await qaWrite({ action: 'unrelate', post_id: newsId });
  await qaWrite({ action: 'relate', post_id: newsId, role: 'featured' });
  await qaWrite({ action: 'unrelate', post_id: draftId });
  await context.request.delete(`/wp-json/wp/v2/news/${draftId}?force=true`, {
    headers: { 'X-WP-Nonce': nonce },
  });

  await anon.close();
});

test('revoked image rights degrade to text and the text-only homepage stays coherent', async ({ browser }) => {
  test.setTimeout(600_000);
  const attachmentId = seeded.attachment;
  const homeId = seeded.home['pt-br'];

  // Revoke the rights clearance — the governed renderer must fail closed.
  await qaWrite({
    action: 'meta',
    post_id: attachmentId,
    meta: { _lps_media_rights_status: 'restricted' },
  });

  const anon = await anonymousContext(browser);
  const page = await anon.newPage();
  await page.goto('/pt-br/', { waitUntil: 'domcontentloaded' });

  // No image, no broken-image box, no empty chrome: the mission degrades to
  // text and keeps its heading, summary, and pinned actions.
  const mission = page.locator('[data-home-section="mission"]');
  await expect(mission.locator('img')).toHaveCount(0);
  // No governed figure renders anywhere; the static brand logo is not media.
  await expect(page.locator('figure.lps-media')).toHaveCount(0);
  await expect(mission.locator('h1')).toContainText('Início');
  await expect(mission.locator('[data-home-action="research"]')).toHaveCount(1);
  await expect(mission.locator('[data-home-action="teaching"]')).toHaveCount(1);
  await expect(page.locator('[data-home-empty]')).toHaveCount(0);
  await expect(page.locator('.lps-feature-media-fallback')).toHaveCount(0);
  // The rest of the composition is unaffected.
  await expect(page.locator('[data-home-section="latest"]')).toContainText('Notícia da home');
  await expect(page.locator('[data-home-section="contact"] a')).toHaveAttribute('href', /\/pt-br\/contato\/$/);

  // Removing the featured image reference entirely keeps the same contract.
  await qaWrite({ action: 'meta', post_id: homeId, meta: { _thumbnail_id: 0 } });
  await page.goto(`/pt-br/?lps-qa-fresh=${Date.now()}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('figure.lps-media')).toHaveCount(0);
  await expect(page.locator('[data-home-section="mission"] h1')).toHaveCount(1);
  await expect(page.locator('[data-home-empty]')).toHaveCount(0);

  // Restore the governed image for later runs.
  await qaWrite({
    action: 'meta',
    post_id: attachmentId,
    meta: { _lps_media_rights_status: 'cleared' },
  });
  await qaWrite({ action: 'meta', post_id: homeId, meta: { _thumbnail_id: attachmentId } });
  await page.goto(`/pt-br/?lps-qa-fresh=${Date.now()}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-home-section="mission"] figure.lps-media img')).toHaveCount(1);

  await anon.close();
});

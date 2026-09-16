import { createHmac } from "node:crypto";
import { readFileSync } from "node:fs";
import { expect, test } from "@playwright/test";

const targetsPath =
  process.env.LPS_CORPUS_TARGETS ?? ".omo/evidence/task-22/live/spot-check-targets.json";
const evidenceDir = process.env.LPS_CORPUS_EVIDENCE_DIR ?? ".omo/evidence/task-22/browser";

/**
 * Disposable corpus targets are loaded at execution time so that
 * `playwright --list` never depends on a disposable live import. Each journey
 * calls `loadCorpusTargets()` first and fails loudly when the file is absent,
 * unreadable, malformed, or missing the required spot-check records.
 */
function loadCorpusTargets() {
  let raw;
  try {
    raw = readFileSync(targetsPath, "utf8");
  } catch (error) {
    throw new Error(
      `LPS_CORPUS_TARGETS unreadable at ${targetsPath}: ${error.message} (provision disposable corpus targets at execution time)`,
    );
  }
  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (error) {
    throw new Error(`LPS_CORPUS_TARGETS malformed JSON at ${targetsPath}: ${error.message}`);
  }
  const { targets, totp_secret: totpSecret, editor: sectionEditor } = parsed;
  if (!Array.isArray(targets) || targets.length === 0)
    throw new Error(`LPS_CORPUS_TARGETS at ${targetsPath} must contain a non-empty targets array`);
  if (!totpSecret || typeof totpSecret !== "string")
    throw new Error(`LPS_CORPUS_TARGETS at ${targetsPath} must contain a totp_secret string`);
  if (!sectionEditor?.login || !sectionEditor?.password)
    throw new Error(
      `LPS_CORPUS_TARGETS at ${targetsPath} must contain editor.login and editor.password`,
    );
  const spotChecks = ["record-001", "record-008", "record-006"].map((id) =>
    targets.find((entry) => entry.record === id),
  );
  const missing = ["record-001", "record-008", "record-006"].filter(
    (_, index) => !spotChecks[index],
  );
  if (missing.length > 0)
    throw new Error(
      `LPS_CORPUS_TARGETS at ${targetsPath} is missing spot-check records: ${missing.join(", ")}`,
    );
  return { targets, totpSecret, sectionEditor, spotChecks };
}

function totpAt(base32, at) {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  let bits = "";
  for (const char of base32.replace(/\s/g, "").toUpperCase()) {
    bits += alphabet.indexOf(char).toString(2).padStart(5, "0");
  }
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => Number.parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
  const digest = createHmac("sha1", key).update(counter).digest();
  const offset = digest[digest.length - 1] & 15;
  return ((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).toString().padStart(6, "0");
}

/**
 * Deterministic TOTP clock policy: never emit a code within the rollover
 * boundary, and never reuse a 30-second window. Two-Factor records the
 * timestamp of every code it accepts (`_two_factor_totp_last_successful_login`)
 * and rejects the same or an older window on the next attempt, so the serial
 * admin logins below must each draw a strictly newer window. Waits a computed
 * (not fixed) interval until at least 7 seconds remain in a fresh step,
 * bounded by `timeoutMs`.
 */
let lastTotpWindow = -1;
async function stableTotp(base32, { timeoutMs = 35_000 } = {}) {
  const started = Date.now();
  for (;;) {
    const now = Date.now();
    if (now - started > timeoutMs)
      throw new Error(`stableTotp: no safe 30-second TOTP window within ${timeoutMs}ms`);
    const step = Math.floor(now / 30_000);
    const msIntoStep = now % 30_000;
    if (step > lastTotpWindow && msIntoStep >= 2_000 && msIntoStep <= 23_000) {
      lastTotpWindow = step;
      return totpAt(base32, now);
    }
    const waitMs =
      msIntoStep < 2_000 ? 2_000 - msIntoStep + 250 : 30_000 - msIntoStep + 2_250;
    await new Promise((resolve) =>
      setTimeout(resolve, Math.min(waitMs, Math.max(0, timeoutMs - (Date.now() - started)))),
    );
  }
}

async function login(page, totpSecret) {
  await page.goto("/wp-admin/profile.php", { waitUntil: "domcontentloaded" });
  if (!/wp-login\.php/.test(page.url())) {
    return;
  }
  await page.locator("#user_login").fill("admin");
  await page.locator("#user_pass").fill("password");
  const passwordPost = page.waitForResponse(
    (response) => response.request().method() === "POST" && /wp-login\.php/.test(response.url()),
    { timeout: 60_000 },
  );
  await page.locator("#wp-submit").click();
  await passwordPost;
  await expect(page.locator("#authcode")).toBeVisible({ timeout: 30_000 });
  // Register the 2FA POST before filling so the bounded `stableTotp` code
  // cannot miss its validation window on a 30-second rollover.
  const authPost = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      /wp-login\.php.*action=validate_2fa/.test(response.url()),
    { timeout: 60_000 },
  );
  // Two-Factor auto-submits the challenge on the sixth digit and disables the
  // submit control, so the admin navigation is registered before the fill and
  // no click follows it.
  const submitted = page.waitForURL(/wp-admin/, { timeout: 60_000 });
  await page.locator("#authcode").fill(await stableTotp(totpSecret));
  await authPost;
  await submitted;
  await page.goto("/wp-admin/profile.php", { waitUntil: "domcontentloaded" });
  await expect(page.locator("#wpadminbar")).toBeVisible({ timeout: 30_000 });
}

async function loginAs(page, user, pass) {
  await page.context().clearCookies();
  await page.goto("/wp-login.php", { waitUntil: "domcontentloaded" });
  await page.locator("#user_login").fill(user);
  await page.locator("#user_pass").fill(pass);
  const post = page.waitForResponse(
    (response) => response.request().method() === "POST" && /wp-login\.php/.test(response.url()),
    { timeout: 60_000 },
  );
  await page.locator("#wp-submit").click();
  await post;
  await page.goto("/wp-admin/profile.php", { waitUntil: "domcontentloaded" });
  await expect(page.locator("#wpadminbar")).toBeVisible({ timeout: 30_000 });
}

async function editablePost(page, postType, id) {
  return await page.evaluate(
    async ([type, postId]) => {
      const nonce = await (await fetch("/wp-admin/admin-ajax.php?action=rest-nonce")).text();
      const base = { page: "pages", lps_person: "people", lps_opportunity: "opportunities" }[type];
      const response = await fetch(`/wp-json/wp/v2/${base}/${postId}?context=edit`, {
        headers: { "X-WP-Nonce": nonce },
      });
      const body = await response.json();
      return {
        status: response.status,
        title: body?.title?.raw ?? "",
        content: body?.content?.raw ?? "",
        postStatus: body?.status ?? "",
        meta: body?.meta ?? {},
      };
    },
    [postType, id],
  );
}

test.describe("@task-22 migrated launch corpus", () => {
  test.describe.configure({ mode: "serial", timeout: 120_000 });

  test("draft records are not publicly exposed in either locale", async ({ page }) => {
    const { spotChecks } = loadCorpusTargets();
    const observed = [];
    for (const target of spotChecks) {
      for (const [locale, slug] of [
        ["pt-br", target.pt_br_slug],
        ["en", target.en_slug],
      ]) {
        const response = await page.goto(`/${locale}/${slug}/`, { waitUntil: "domcontentloaded" });
        observed.push({ record: target.record, locale, slug, status: response.status() });
        expect(response.status(), `${target.record} ${locale} must stay unpublished`).toBe(404);
      }
    }
    await page.screenshot({ path: `${evidenceDir}/public-draft-not-exposed.png`, fullPage: true });
    expect(observed).toHaveLength(spotChecks.length * 2);
  });

  test("the Portuguese authority variants are present in the editor", async ({ page }) => {
    const { spotChecks, totpSecret } = loadCorpusTargets();
    await login(page, totpSecret);
    await page.goto("/wp-admin/edit.php?post_type=page&post_status=draft&lang=pt-br", {
      waitUntil: "domcontentloaded",
    });
    await expect(page.locator("#the-list")).toContainText("Laboratório de Processamento de Sinais");
    await expect(page.locator("#the-list")).toContainText("Sobre o LPS");
    await page.screenshot({ path: `${evidenceDir}/admin-pages-pt-br.png`, fullPage: true });

    const home = spotChecks[0];
    const stored = await editablePost(page, home.post_type, home.pt_br_post_id);
    expect(stored.status).toBe(200);
    expect(stored.title).toBe(home.pt_br_title);
    expect(stored.content).toContain("fundado em 1996");
    expect(stored.postStatus).toBe("draft");
    await page.goto(`/wp-admin/post.php?post=${home.pt_br_post_id}&action=edit`, {
      waitUntil: "domcontentloaded",
    });
    await page.screenshot({
      path: `${evidenceDir}/admin-editor-pt-br-record-001.png`,
      fullPage: true,
    });
  });

  test("the English variants are independent and present in the editor", async ({ page }) => {
    const { spotChecks, totpSecret } = loadCorpusTargets();
    await login(page, totpSecret);
    await page.goto("/wp-admin/edit.php?post_type=page&post_status=draft&lang=en", {
      waitUntil: "domcontentloaded",
    });
    await expect(page.locator("#the-list")).toContainText("Signal Processing Laboratory");
    await expect(page.locator("#the-list")).toContainText("About LPS");
    await page.screenshot({ path: `${evidenceDir}/admin-pages-en.png`, fullPage: true });

    const home = spotChecks[0];
    const stored = await editablePost(page, home.post_type, home.en_post_id);
    expect(stored.status).toBe(200);
    expect(stored.title).toBe(home.en_title);
    expect(stored.content).toContain("founded in 1996");
    expect(stored.content).not.toContain("fundado em 1996");
    expect(stored.postStatus).toBe("draft");
    expect(home.en_title).not.toBe(home.pt_br_title);
    await page.goto(`/wp-admin/post.php?post=${home.en_post_id}&action=edit`, {
      waitUntil: "domcontentloaded",
    });
    await page.screenshot({
      path: `${evidenceDir}/admin-editor-en-record-001.png`,
      fullPage: true,
    });
  });

  test("an administrator without a collection assignment cannot edit editorial records", async ({
    page,
  }) => {
    const { totpSecret } = loadCorpusTargets();
    await login(page, totpSecret);
    await page.goto("/wp-admin/edit.php?post_type=lps_person&post_status=draft&lang=pt-br", {
      waitUntil: "domcontentloaded",
    });
    await expect(page.locator("body")).toContainText("not allowed to edit posts in this post type");
    await page.screenshot({ path: `${evidenceDir}/admin-person-denied.png`, fullPage: true });
  });

  test("the assigned section editor sees both locale variants of person and opportunity records", async ({
    page,
  }) => {
    const { targets, sectionEditor } = loadCorpusTargets();
    await loginAs(page, sectionEditor.login, sectionEditor.password);
    const person = targets.find((entry) => entry.record === "record-008");
    const opportunity = targets.find((entry) => entry.record === "record-006");

    for (const [postType, file] of [
      ["lps_person", "editor-people"],
      ["lps_opportunity", "editor-opportunities"],
    ]) {
      for (const locale of ["pt-br", "en"]) {
        await page.goto(
          `/wp-admin/edit.php?post_type=${postType}&post_status=draft&lang=${locale}`,
          { waitUntil: "domcontentloaded" },
        );
        await expect(page.locator("#the-list tr").first()).toBeVisible();
        await page.screenshot({ path: `${evidenceDir}/${file}-${locale}.png`, fullPage: true });
      }
    }

    const personPt = await editablePost(page, "lps_person", person.pt_br_post_id);
    const personEn = await editablePost(page, "lps_person", person.en_post_id);
    expect(personPt.content).toContain("nasceu em 1944 no Rio de Janeiro");
    expect(personEn.content).toContain("was born in Rio de Janeiro in 1944");
    expect(personEn.content).not.toContain("nasceu em 1944");
    expect(personPt.postStatus).toBe("draft");
    expect(personEn.postStatus).toBe("draft");

    const opportunityPt = await editablePost(page, "lps_opportunity", opportunity.pt_br_post_id);
    const opportunityEn = await editablePost(page, "lps_opportunity", opportunity.en_post_id);
    expect(opportunityPt.title).toBe("Oportunidades de bolsa");
    expect(opportunityEn.title).toBe("Scholarship opportunities");
    expect(opportunityEn.content).toContain("no open call");
  });

  test("the translation freshness dashboard reports the unreviewed English variants", async ({
    page,
  }) => {
    const { totpSecret } = loadCorpusTargets();
    await login(page, totpSecret);
    await page.goto("/wp-admin/tools.php?page=lps-translation-freshness", {
      waitUntil: "domcontentloaded",
    });
    await expect(page.locator("h1")).toContainText("Translation freshness");
    await expect(page.locator("table")).toContainText("stale");
    await page.screenshot({
      path: `${evidenceDir}/admin-translation-freshness.png`,
      fullPage: true,
    });
  });
});

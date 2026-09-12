import { createHmac } from "node:crypto";
import { readFileSync } from "node:fs";
import { expect, test } from "@playwright/test";

const targetsPath =
  process.env.LPS_CORPUS_TARGETS ?? ".omo/evidence/task-22/live/spot-check-targets.json";
const evidenceDir = process.env.LPS_CORPUS_EVIDENCE_DIR ?? ".omo/evidence/task-22/browser";
const {
  targets,
  totp_secret: totpSecret,
  editor: sectionEditor,
} = JSON.parse(readFileSync(targetsPath, "utf8"));
const spotChecks = ["record-001", "record-008", "record-006"].map((id) =>
  targets.find((entry) => entry.record === id),
);

function totp(base32) {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  let bits = "";
  for (const char of base32.replace(/\s/g, "").toUpperCase()) {
    bits += alphabet.indexOf(char).toString(2).padStart(5, "0");
  }
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => Number.parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
  const digest = createHmac("sha1", key).update(counter).digest();
  const offset = digest[digest.length - 1] & 15;
  return ((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).toString().padStart(6, "0");
}

async function login(page) {
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
  await page.locator("#authcode").fill(totp(totpSecret));
  const authPost = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      /wp-login\.php.*action=validate_2fa/.test(response.url()),
    { timeout: 60_000 },
  );
  await page.locator("#submit").click();
  await authPost;
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
    await login(page);
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
    await login(page);
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
    await login(page);
    await page.goto("/wp-admin/edit.php?post_type=lps_person&post_status=draft&lang=pt-br", {
      waitUntil: "domcontentloaded",
    });
    await expect(page.locator("body")).toContainText("not allowed to edit posts in this post type");
    await page.screenshot({ path: `${evidenceDir}/admin-person-denied.png`, fullPage: true });
  });

  test("the assigned section editor sees both locale variants of person and opportunity records", async ({
    page,
  }) => {
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
    await login(page);
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

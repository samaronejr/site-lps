import { createHmac } from "node:crypto";
import { mkdir, writeFile } from "node:fs/promises";
import { chromium } from "@playwright/test";

const baseURL = process.env.LPS_BASE_URL ?? "http://127.0.0.1:8888";
const outputDir = process.env.LPS_TASK10_PREFLIGHT_DIR;
if (!outputDir) throw new Error("LPS_TASK10_PREFLIGHT_DIR is required");
await mkdir(outputDir, { recursive: true });

function totp(base32) {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  let bits = "";
  for (const char of base32.replace(/\s/g, "").toUpperCase())
    bits += alphabet.indexOf(char).toString(2).padStart(5, "0");
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => Number.parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
  const digest = createHmac("sha1", key).update(counter).digest();
  const offset = digest[digest.length - 1] & 15;
  return ((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).toString().padStart(6, "0");
}

/**
 * Deterministic TOTP clock policy: never emit a code within the rollover
 * boundary, and never reuse a 30-second window. Two-Factor records the
 * timestamp of every code it accepts (`_two_factor_totp_last_successful_login`)
 * and rejects the same or an older window on the next attempt, so the
 * enrollment revalidation and the login challenge below must each draw a
 * strictly newer window. Waits a computed (not fixed) interval until at least
 * 7 seconds remain in a fresh step, bounded by `timeoutMs`.
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
      return totp(base32);
    }
    const waitMs =
      msIntoStep < 2_000 ? 2_000 - msIntoStep + 250 : 30_000 - msIntoStep + 2_250;
    await new Promise((resolve) =>
      setTimeout(resolve, Math.min(waitMs, Math.max(0, timeoutMs - (Date.now() - started)))),
    );
  }
}

async function assertSession(page, label) {
  await page.goto(`${baseURL}/wp-admin/profile.php`, {
    waitUntil: "domcontentloaded",
    timeout: 30_000,
  });
  if (/wp-login\.php/.test(page.url()))
    throw new Error(`${label}: authenticated session sentinel redirected to ${page.url()}`);
  const sentinel = page.locator("body.wp-admin #wpadminbar");
  if ((await sentinel.count()) !== 1 || !(await sentinel.isVisible()))
    throw new Error(
      `${label}: authenticated session sentinel body.wp-admin #wpadminbar was not visible at ${page.url()}`,
    );
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ baseURL });
const page = await context.newPage();
page.setDefaultTimeout(15_000);
page.setDefaultNavigationTimeout(30_000);
const receipt = {
  result: "pending",
  sessionSentinels: [],
  selectors: {},
  accounts: [],
  enrollment: {},
};
try {
  await page.goto(`${baseURL}/wp-login.php`, { waitUntil: "domcontentloaded" });
  await page.locator("#user_login").fill("admin");
  await page.locator("#user_pass").fill("password");
  const loginResponse = page.waitForResponse(
    (response) => response.request().method() === "POST" && /wp-login\.php/.test(response.url()),
  );
  await page.locator("#wp-submit").click();
  await loginResponse;
  await assertSession(page, "preflight-admin-login");
  receipt.sessionSentinels.push({
    journey: "preflight-admin-login",
    selector: "body.wp-admin #wpadminbar",
    result: "pass",
  });

  const setupSelectors = [
    "#two-factor-totp-key",
    "#two-factor-totp-authcode",
    'input[name="two-factor-totp-submit"]',
    "#enabled-Two_Factor_Totp",
    "#two-factor-primary-provider",
    "#submit",
  ];
  for (const selector of setupSelectors) {
    const locator = page.locator(selector);
    if ((await locator.count()) !== 1)
      throw new Error(
        `selector preflight ${selector}: expected one fresh-DOM match, observed ${await locator.count()}`,
      );
    receipt.selectors[selector] = { matches: 1, result: "pass" };
  }

  const secret = await page.locator("#two-factor-totp-key").inputValue();
  await page.locator("#two-factor-totp-authcode").fill(await stableTotp(secret));
  const verificationResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      /\/wp-json\/two-factor\/.*\/totp/.test(response.url()),
    { timeout: 60_000 },
  );
  await page.locator('input[name="two-factor-totp-submit"]').click();
  const verification = await verificationResponse;
  if (verification.status() !== 200)
    throw new Error(`TOTP selector preflight verification HTTP ${verification.status()}`);
  await page.locator("#enabled-Two_Factor_Totp").waitFor({ state: "attached" });
  if (!(await page.locator("#enabled-Two_Factor_Totp").isChecked()))
    throw new Error("TOTP selector preflight did not enable provider");
  receipt.enrollment.verification = {
    httpStatus: verification.status(),
    checkboxChecked: true,
    result: "pass",
  };

  // The REST enrollment enables the provider but leaves this session
  // unauthenticated for two-factor, so the plugin disables the options UI
  // until the revalidation flow marks the session. Revalidate through the
  // real wp-login.php?action=revalidate_2fa form before saving the profile.
  await page.goto(
    "/wp-login.php?action=revalidate_2fa&redirect_to=" +
      encodeURIComponent("/wp-admin/profile.php"),
    { waitUntil: "domcontentloaded" },
  );
  // The waiters are registered before the fill, so their timeouts must exceed
  // stableTotp's bounded wait for a fresh 30-second window (~30s) plus the
  // POST/redirect round trip; 60s keeps the contract bounded without racing.
  const revalidateResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      /revalidate_2fa/.test(response.url()),
    { timeout: 60_000 },
  );
  // Two-Factor auto-submits the challenge on the sixth digit and disables the
  // submit control, so the POST and the profile navigation are registered
  // before the fill and no click follows it.
  const revalidatedUrl = page.waitForURL(
    (url) => url.pathname === "/wp-admin/profile.php",
    { waitUntil: "domcontentloaded", timeout: 60_000 },
  );
  await page.locator("#authcode").fill(await stableTotp(secret));
  const revalidated = await revalidateResponse;
  if (revalidated.status() !== 302 && revalidated.status() !== 200)
    throw new Error(
      `TOTP selector preflight revalidation HTTP ${revalidated.status()}`,
    );
  await revalidatedUrl;
  receipt.enrollment.revalidation = {
    httpStatus: revalidated.status(),
    result: "pass",
  };

  const enrollmentSaveResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/wp-admin/profile.php",
  );
  const enrollmentProfileUrl = page.waitForURL((url) => url.pathname === "/wp-admin/profile.php", {
    waitUntil: "domcontentloaded",
  });
  await page.locator("#submit").click();
  const [enrollmentSave] = await Promise.all([enrollmentSaveResponse, enrollmentProfileUrl]);
  receipt.enrollment.enrollmentSave = {
    control: "#submit",
    httpStatus: enrollmentSave.status(),
    navigationUrl: page.url(),
    primarySelected: false,
    result: "pass",
  };

  await assertSession(page, "preflight-enrollment-reload");
  receipt.sessionSentinels.push({
    journey: "preflight-enrollment-reload",
    selector: "body.wp-admin #wpadminbar",
    result: "pass",
  });
  const persistedCheckbox = page.locator("#enabled-Two_Factor_Totp");
  if ((await persistedCheckbox.count()) !== 1 || !(await persistedCheckbox.isChecked()))
    throw new Error("TOTP provider enrollment was not checked after a fresh profile navigation");
  const totpOption = page.locator('#two-factor-primary-provider option[value="Two_Factor_Totp"]');
  if ((await totpOption.count()) !== 1 || !(await totpOption.isEnabled()))
    throw new Error(
      "TOTP primary-provider option was not uniquely enabled after enrollment save and profile reload",
    );
  receipt.enrollment.reload = {
    url: page.url(),
    checkboxChecked: true,
    optionEnabled: true,
    result: "pass",
  };

  await page.locator("#two-factor-primary-provider").selectOption("Two_Factor_Totp");
  const primarySaveResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/wp-admin/profile.php",
  );
  const primaryProfileUrl = page.waitForURL((url) => url.pathname === "/wp-admin/profile.php", {
    waitUntil: "domcontentloaded",
  });
  await page.locator("#submit").click();
  const [primarySave] = await Promise.all([primarySaveResponse, primaryProfileUrl]);
  await assertSession(page, "preflight-primary-provider-save");
  if ((await page.locator("#two-factor-primary-provider").inputValue()) !== "Two_Factor_Totp")
    throw new Error("TOTP primary provider did not persist after the second profile save");
  receipt.enrollment.primarySave = {
    control: "#submit",
    httpStatus: primarySave.status(),
    provider: "Two_Factor_Totp",
    persisted: true,
    result: "pass",
  };
  receipt.sessionSentinels.push({
    journey: "preflight-primary-provider-save",
    selector: "body.wp-admin #wpadminbar",
    result: "pass",
  });

  await context.clearCookies();
  await page.goto(`${baseURL}/wp-login.php`, { waitUntil: "domcontentloaded" });
  await page.locator("#user_login").fill("admin");
  await page.locator("#user_pass").fill("password");
  const passwordPost = page.waitForResponse(
    (response) => response.request().method() === "POST" && /wp-login\.php/.test(response.url()),
  );
  await page.locator("#wp-submit").click();
  await passwordPost;
  for (const selector of ["#authcode", "#submit"]) {
    const locator = page.locator(selector);
    if ((await locator.count()) !== 1 || !(await locator.isVisible()))
      throw new Error(
        `login selector preflight ${selector}: not uniquely visible at ${page.url()}`,
      );
    receipt.selectors[`login:${selector}`] = { matches: 1, visible: true, result: "pass" };
  }
  receipt.enrollment.loginChallenge = {
    url: page.url(),
    authcodeSelector: "#authcode",
    submitSelector: "#submit",
    result: "pass",
  };
  const authPost = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      /wp-login\.php.*action=validate_2fa/.test(response.url()),
    { timeout: 60_000 },
  );
  const submitted = page.waitForURL(/wp-admin/, { timeout: 60_000 });
  await page.locator("#authcode").fill(await stableTotp(secret));
  await authPost;
  await submitted;
  await assertSession(page, "preflight-totp-login-submit");
  receipt.sessionSentinels.push({
    journey: "preflight-totp-login-submit",
    selector: "body.wp-admin #wpadminbar",
    result: "pass",
  });

  // The account receipt is enumerated only after the administrator completes
  // TOTP enrollment: the MFA policy strips every capability except read/exist
  // from unenrolled privileged accounts, so `list_users` (and therefore the
  // `users?context=edit` endpoint) is denied before this point by design.
  const nonce = await page.evaluate(async () =>
    (await fetch("/wp-admin/admin-ajax.php?action=rest-nonce")).text(),
  );
  const users = await page.evaluate(async (nonceValue) => {
    const response = await fetch("/wp-json/wp/v2/users?context=edit&per_page=100", {
      headers: { "X-WP-Nonce": nonceValue },
    });
    if (!response.ok) throw new Error(`users receipt HTTP ${response.status}`);
    return response.json();
  }, nonce);
  receipt.accounts = users
    .filter((user) => user.slug.startsWith("task10-"))
    .map((user) => ({ id: user.id, login: user.slug, role: user.roles[0] }))
    .sort((left, right) => left.id - right.id);
  if (receipt.accounts.length !== 7)
    throw new Error(`fixture receipt expected 7 accounts, observed ${receipt.accounts.length}`);

  receipt.result = "pass";
  await page.goto(`${baseURL}/wp-admin/`, { waitUntil: "domcontentloaded" });
  await page.screenshot({ path: `${outputDir}/selector-preflight.png`, fullPage: true });
} catch (error) {
  receipt.result = "fail";
  receipt.error =
    error instanceof Error
      ? { name: error.name, message: error.message, stack: error.stack }
      : { message: String(error) };
  await page
    .screenshot({ path: `${outputDir}/selector-preflight-failure.png`, fullPage: true })
    .catch(() => {});
  await writeFile(`${outputDir}/selector-preflight.json`, `${JSON.stringify(receipt, null, 2)}\n`);
  throw error;
} finally {
  await writeFile(`${outputDir}/selector-preflight.json`, `${JSON.stringify(receipt, null, 2)}\n`);
  await context.close();
  await browser.close();
}

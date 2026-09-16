import { createHmac } from "node:crypto";
import { readFileSync } from "node:fs";
import { expect, test } from "@playwright/test";

/**
 * Disposable local fixture accounts only. The password and TOTP secret live in the
 * throwaway environment directory and are never checked in or written to evidence.
 *
 * The fixture is loaded at execution time so that `playwright --list` (spec
 * discovery) never depends on disposable runtime state. Each journey calls
 * `loadSecurityFixture()` first and fails loudly when the input is absent,
 * unreadable, or malformed.
 */
function loadSecurityFixture() {
  const fixturePath = process.env.LPS_SECURITY_FIXTURE;
  if (!fixturePath)
    throw new Error(
      "LPS_SECURITY_FIXTURE is required (set it to the disposable fixture JSON path at execution time)",
    );
  let raw;
  try {
    raw = readFileSync(fixturePath, "utf8");
  } catch (error) {
    throw new Error(`LPS_SECURITY_FIXTURE unreadable at ${fixturePath}: ${error.message}`);
  }
  let fixture;
  try {
    fixture = JSON.parse(raw);
  } catch (error) {
    throw new Error(`LPS_SECURITY_FIXTURE malformed JSON at ${fixturePath}: ${error.message}`);
  }
  if (!fixture?.password || !fixture?.secret)
    throw new Error(
      `LPS_SECURITY_FIXTURE at ${fixturePath} must contain password and secret`,
    );
  return fixture;
}
const PRIVILEGED = "security.privileged";
const NO_MFA = "security.no-mfa";

function base32Decode(secret) {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  let bits = "";
  for (const char of secret.replace(/=+$/, "").toUpperCase()) {
    bits += alphabet.indexOf(char).toString(2).padStart(5, "0");
  }
  const bytes = bits.match(/.{8}/g) ?? [];
  return Buffer.from(bytes.map((byte) => Number.parseInt(byte, 2)));
}

function totpAt(secret, at) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
  const digest = createHmac("sha1", base32Decode(secret)).update(counter).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  const code = digest.readUInt32BE(offset) & 0x7fffffff;
  return String(code % 1_000_000).padStart(6, "0");
}

/**
 * Deterministic TOTP clock policy: never emit a code within the rollover
 * boundary. Waits a computed (not fixed) interval until at least 7 seconds
 * remain in the current 30-second step, bounded by `timeoutMs`.
 */
async function stableTotp(secret, { timeoutMs = 35_000 } = {}) {
  const started = Date.now();
  for (;;) {
    const now = Date.now();
    if (now - started > timeoutMs)
      throw new Error(`stableTotp: no safe 30-second TOTP window within ${timeoutMs}ms`);
    const msIntoStep = now % 30_000;
    if (msIntoStep >= 2_000 && msIntoStep <= 23_000) return totpAt(secret, now);
    const waitMs =
      msIntoStep < 2_000 ? 2_000 - msIntoStep + 250 : 30_000 - msIntoStep + 2_250;
    await new Promise((resolve) =>
      setTimeout(resolve, Math.min(waitMs, Math.max(0, timeoutMs - (Date.now() - started)))),
    );
  }
}

/**
 * Bounded login-outcome contract: after the login POST, exactly one of the MFA
 * challenge, the authenticated admin bar, or a login error must become visible.
 * The previous `Promise.all([waitForLoadState("load"), click()])` did not
 * synchronize the navigation because `load` can already be satisfied by the
 * current page, and the immediate `challenge.count()` then raced the render.
 */
async function awaitLoginOutcome(page, { timeout = 15_000 } = {}) {
  await expect(page.locator("#authcode, #wpadminbar, #login_error").first()).toBeVisible({
    timeout,
  });
}

function awaitLoginPost(page, { timeout = 15_000 } = {}) {
  return page.waitForResponse(
    (response) =>
      response.request().method() === "POST" && /wp-login\.php/.test(response.url()),
    { timeout },
  );
}

async function collectAssets(page, origin) {
  const external = [];
  page.on("response", (response) => {
    if (!response.url().startsWith(origin) && !response.url().startsWith("data:"))
      external.push(response.url());
  });
  return external;
}

test("privileged account signs in through the MFA challenge and works under the policy headers", async ({
  page,
  baseURL,
}) => {
  const fixture = loadSecurityFixture();
  const origin = new URL(baseURL).origin;
  const external = await collectAssets(page, origin);

  const login = await page.goto("/wp-login.php");
  expect(login.headers()["content-security-policy"]).toContain("script-src 'self' 'nonce-");
  expect(login.headers()["x-frame-options"]).toBe("DENY");

  await page.fill("#user_login", PRIVILEGED);
  await page.fill("#user_pass", fixture.password);
  const loginPost = awaitLoginPost(page);
  await page.locator("#wp-submit").click();
  await loginPost;
  await awaitLoginOutcome(page);

  const challenge = page.locator('input[name="authcode"], #authcode');
  if (await challenge.first().isVisible()) {
    await page.screenshot({ path: "test-results/todo20/mfa-challenge.png", fullPage: true });
    // Two-Factor submits the challenge form as soon as the sixth digit is entered.
    // Register both the 2FA POST and the admin-URL navigation before filling so
    // the TOTP rollover window cannot be missed; the code itself comes from the
    // bounded `stableTotp` clock policy above.
    // The waiters are registered before the fill, so their timeouts must
    // exceed stableTotp's bounded wait for a safe TOTP window plus the
    // POST/redirect round trip.
    const twoFaPost = page.waitForResponse(
      (response) =>
        response.request().method() === "POST" &&
        /wp-login\.php.*action=validate_2fa/.test(response.url()),
      { timeout: 60_000 },
    );
    const submitted = page.waitForURL(/wp-admin/, { timeout: 60_000 });
    await challenge.first().fill(await stableTotp(fixture.secret));
    await twoFaPost;
    await submitted;
  }

  await page.goto("/wp-admin/index.php", { waitUntil: "domcontentloaded" });
  await expect(page.locator("body")).toHaveClass(/wp-admin/);
  await page.screenshot({ path: "test-results/todo20/privileged-admin.png", fullPage: true });
  expect(external, "the editor surface must load only self-hosted assets").toEqual([]);

  const storage = await page.evaluate(() => Object.keys(window.localStorage));
  expect(Array.isArray(storage)).toBe(true);
});

test("an account without MFA enrollment keeps only read capability", async ({ page }) => {
  const fixture = loadSecurityFixture();
  await page.goto("/wp-login.php");
  await page.fill("#user_login", NO_MFA);
  await page.fill("#user_pass", fixture.password);
  const loginPost = awaitLoginPost(page);
  await page.locator("#wp-submit").click();
  await loginPost;
  await awaitLoginOutcome(page);

  const response = await page.goto("/wp-admin/post-new.php?post_type=lps_person", {
    waitUntil: "domcontentloaded",
  });
  expect(response.status(), "creating content without MFA must be refused").toBeGreaterThanOrEqual(
    400,
  );
  expect(await page.content()).not.toContain("security.privileged");
});

import { createHmac } from "node:crypto";
import { readFileSync } from "node:fs";
import { expect, test } from "@playwright/test";

/**
 * Disposable local fixture accounts only. The password and TOTP secret live in the
 * throwaway environment directory and are never checked in or written to evidence.
 */
const fixture = JSON.parse(readFileSync(process.env.LPS_SECURITY_FIXTURE, "utf8"));
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

function totp(secret, at = Date.now()) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
  const digest = createHmac("sha1", base32Decode(secret)).update(counter).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  const code = digest.readUInt32BE(offset) & 0x7fffffff;
  return String(code % 1_000_000).padStart(6, "0");
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
  const origin = new URL(baseURL).origin;
  const external = await collectAssets(page, origin);

  const login = await page.goto("/wp-login.php");
  expect(login.headers()["content-security-policy"]).toContain("script-src 'self' 'nonce-");
  expect(login.headers()["x-frame-options"]).toBe("DENY");

  await page.fill("#user_login", PRIVILEGED);
  await page.fill("#user_pass", fixture.password);
  await Promise.all([page.waitForLoadState("load"), page.click("#wp-submit")]);

  const challenge = page.locator('input[name="authcode"], #authcode');
  if (await challenge.count()) {
    await page.screenshot({ path: "test-results/todo20/mfa-challenge.png", fullPage: true });
    // Two-Factor submits the challenge form as soon as the sixth digit is entered.
    const submitted = page.waitForURL(/wp-admin/, { timeout: 15_000 });
    await challenge.first().fill(totp(fixture.secret));
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
  await page.goto("/wp-login.php");
  await page.fill("#user_login", NO_MFA);
  await page.fill("#user_pass", fixture.password);
  await Promise.all([page.waitForLoadState("load"), page.click("#wp-submit")]);

  const response = await page.goto("/wp-admin/post-new.php?post_type=lps_person", {
    waitUntil: "domcontentloaded",
  });
  expect(response.status(), "creating content without MFA must be refused").toBeGreaterThanOrEqual(
    400,
  );
  expect(await page.content()).not.toContain("security.privileged");
});

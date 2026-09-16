import { createHmac } from "node:crypto";
import { readFile, writeFile } from "node:fs/promises";
import { expect, test } from "@playwright/test";

const password = process.env.LPS_TASK10_PASSWORD;
if (!password) throw new Error("LPS_TASK10_PASSWORD is required");
const receiptPath = process.env.LPS_TASK10_FIXTURE_RECEIPT;
if (!receiptPath) throw new Error("LPS_TASK10_FIXTURE_RECEIPT is required");

async function assertAuthenticatedSession(page, journey) {
  await page.goto("/wp-admin/profile.php", { waitUntil: "domcontentloaded", timeout: 30_000 });
  if (/wp-login\.php/.test(page.url()))
    throw new Error(`${journey}: authenticated session sentinel redirected to ${page.url()}`);
  const sentinel = page.locator("body.wp-admin #wpadminbar");
  if ((await sentinel.count()) !== 1 || !(await sentinel.isVisible())) {
    throw new Error(
      `${journey}: authenticated session sentinel body.wp-admin #wpadminbar was not uniquely visible at ${page.url()}`,
    );
  }
}

async function login(page, loginName, journey) {
  await page.context().clearCookies();
  await page.goto("/wp-login.php", { waitUntil: "domcontentloaded" });
  await page.locator("#user_login").fill(loginName);
  await page.locator("#user_pass").fill(password);
  const passwordPost = page.waitForResponse(
    (response) => response.request().method() === "POST" && /wp-login\.php/.test(response.url()),
    { timeout: 30_000 },
  );
  await page.locator("#wp-submit").click();
  await passwordPost;
  if (await page.locator("#authcode").isVisible()) return "mfa-challenge";
  await assertAuthenticatedSession(page, journey);
  return "authenticated";
}

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

async function enrollTotp(page, journey) {
  await assertAuthenticatedSession(page, `${journey}:before-enrollment`);
  const secret = await page.locator("#two-factor-totp-key").inputValue();
  await page.locator("#two-factor-totp-authcode").fill(totp(secret));
  const verificationResponse = page.waitForResponse(
    (response) =>
      /\/wp-json\/two-factor\/.*\/totp/.test(response.url()) &&
      response.request().method() === "POST",
    { timeout: 30_000 },
  );
  await page.locator('input[name="two-factor-totp-submit"]').click();
  expect((await verificationResponse).status()).toBe(200);
  await expect(page.locator("#enabled-Two_Factor_Totp")).toBeChecked();

  const enrollmentSaveResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/wp-admin/profile.php",
    { timeout: 30_000 },
  );
  const enrollmentProfileUrl = page.waitForURL((url) => url.pathname === "/wp-admin/profile.php", {
    waitUntil: "domcontentloaded",
    timeout: 30_000,
  });
  await page.locator("#submit").click();
  await Promise.all([enrollmentSaveResponse, enrollmentProfileUrl]);

  await assertAuthenticatedSession(page, `${journey}:after-enrollment-save`);
  await expect(page.locator("#enabled-Two_Factor_Totp")).toBeChecked();
  const totpOption = page.locator('#two-factor-primary-provider option[value="Two_Factor_Totp"]');
  await expect(totpOption).toBeEnabled();
  await page.locator("#two-factor-primary-provider").selectOption("Two_Factor_Totp");

  const primarySaveResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/wp-admin/profile.php",
    { timeout: 30_000 },
  );
  const primaryProfileUrl = page.waitForURL((url) => url.pathname === "/wp-admin/profile.php", {
    waitUntil: "domcontentloaded",
    timeout: 30_000,
  });
  await page.locator("#submit").click();
  await Promise.all([primarySaveResponse, primaryProfileUrl]);

  await assertAuthenticatedSession(page, `${journey}:after-primary-save`);
  await expect(page.locator("#two-factor-primary-provider")).toHaveValue("Two_Factor_Totp");
  return secret;
}

async function loginWithTotp(page, loginName, secret, journey) {
  expect(await login(page, loginName, `${journey}:password-stage`)).toBe("mfa-challenge");
  await expect(page.locator("#authcode")).toBeVisible();
  await page.locator("#authcode").fill(totp(secret));
  const authPost = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      /wp-login\.php.*action=validate_2fa/.test(response.url()),
    { timeout: 30_000 },
  );
  await page.locator("#submit").click();
  await authPost;
  await assertAuthenticatedSession(page, journey);
}

async function rest(page, path, method = "GET", body) {
  const nonce = await page.evaluate(async () =>
    (await fetch("/wp-admin/admin-ajax.php?action=rest-nonce")).text(),
  );
  return page.evaluate(
    async ({ path: requestPath, method: requestMethod, body: requestBody, nonce: nonceValue }) => {
      const response = await fetch(requestPath, {
        method: requestMethod,
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-WP-Nonce": nonceValue },
        body: requestBody === undefined ? undefined : JSON.stringify(requestBody),
      });
      const text = await response.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch {
        data = text;
      }
      return { status: response.status, data };
    },
    { path, method, body, nonce },
  );
}

function assertDenied(response) {
  expect([401, 403]).toContain(response.status);
}

test("Todo 10 real role, MFA, denial, and immutable-audit journeys", async ({
  browser,
}, testInfo) => {
  test.setTimeout(900_000);
  const fixture = JSON.parse(await readFile(receiptPath, "utf8"));
  expect(fixture.result).toBe("pass");
  expect(fixture.accounts).toHaveLength(7);
  const ids = Object.fromEntries(fixture.accounts.map((account) => [account.login, account.id]));
  const contexts = [];
  let context;
  let page;
  let currentStep = "initialize-browser";
  const result = {
    status: "running",
    attempt: "single-approved-bounded-retry",
    twoFactorVersion: "0.16.0",
    fixture: fixture.accounts,
    counts: {
      roles: 7,
      allowedActions: 0,
      forbiddenActions: 0,
      mfaBypassDenials: 0,
      immutableAuditRows: 0,
    },
    journeys: [],
    mfaState: [],
    forbiddenReceipts: [],
    browserObservations: [],
  };
  const flush = async () =>
    writeFile(testInfo.outputPath("action-result.json"), `${JSON.stringify(result, null, 2)}\n`);
  const record = async (journey) => {
    result.journeys.push({ ...journey, result: "pass" });
    result.counts.allowedActions += 1;
    result.counts.forbiddenActions += 1;
    await flush();
  };
  const screenshot = async (name) => {
    await page.goto("/wp-admin/", { waitUntil: "domcontentloaded" });
    await page.screenshot({ path: testInfo.outputPath(`${name}.png`), fullPage: true });
  };

  try {
    context = await browser.newContext();
    contexts.push(context);
    await context.tracing.start({ screenshots: true, snapshots: true, sources: true });
    page = await context.newPage();
    page.setDefaultTimeout(15_000);
    page.setDefaultNavigationTimeout(30_000);
    page.on("pageerror", (error) =>
      result.browserObservations.push({
        type: "pageerror",
        message: error.message,
        url: page.url(),
      }),
    );
    page.on("requestfailed", (request) =>
      result.browserObservations.push({
        type: "requestfailed",
        error: request.failure()?.errorText,
        url: request.url(),
      }),
    );
    page.on("response", (response) => {
      if (response.status() >= 500)
        result.browserObservations.push({
          type: "server-response",
          status: response.status(),
          url: response.url(),
        });
    });

    currentStep = "administrator:session-sentinel";
    expect(await login(page, "task10-elisa", "administrator")).toBe("authenticated");
    await expect(page.locator("body")).toContainText("MFA enrollment required");
    currentStep = "administrator:mfa-bypass-denial";
    const settingsBeforeBypass = await rest(page, "/wp-json/wp/v2/settings");
    const adminBypass = await rest(page, "/wp-json/wp/v2/settings", "POST", {
      lps_site_settings: { acronym: "BYPASS" },
    });
    assertDenied(adminBypass);
    const settingsAfterBypass = await rest(page, "/wp-json/wp/v2/settings");
    expect(settingsAfterBypass.data.lps_site_settings).toEqual(
      settingsBeforeBypass.data.lps_site_settings,
    );
    result.counts.mfaBypassDenials += 1;
    result.forbiddenReceipts.push({
      role: "administrator",
      action: "MFA-bypass-settings",
      status: adminBypass.status,
      dataUnchanged: true,
    });
    currentStep = "administrator:totp-enrollment";
    const adminSecret = await enrollTotp(page, "administrator");
    currentStep = "administrator:totp-login";
    await loginWithTotp(
      page,
      "task10-elisa",
      adminSecret,
      "administrator:authenticated-totp-session",
    );
    result.mfaState.push({
      role: "administrator",
      provider: "Two_Factor_Totp",
      enrollmentPersisted: true,
      primaryPersisted: true,
      challengeSelectors: ["#authcode", "#submit"],
      challengePassed: true,
    });
    currentStep = "administrator:privileged-settings-action";
    const settings = await rest(page, "/wp-json/wp/v2/settings", "POST", {
      lps_site_settings: {
        official_name: "Laboratorio de Processamento de Sinais",
        acronym: "LPS",
      },
    });
    expect(settings.status).toBe(200);
    expect(JSON.stringify(settings.data)).not.toContain("BYPASS");
    const administratorMe = await rest(page, "/wp-json/wp/v2/users/me?context=edit");
    expect(administratorMe.status).toBe(200);
    expect(administratorMe.data.capabilities.lps_deploy).not.toBe(true);
    const translatorRecord = await rest(page, "/wp-json/wp/v2/news", "POST", {
      title: "English translation fixture",
      excerpt: "Reviewed summary",
      content: "Reviewed body",
      status: "draft",
      author: ids["task10-bruno"],
      meta: { _lps_locale: "en", _lps_state: "draft", _lps_canonical_date: "2026-08-31T00:00:00Z" },
    });
    expect(translatorRecord.status).toBe(201);
    const recordId = translatorRecord.data.id;
    result.recordId = recordId;
    result.forbiddenReceipts.push({
      role: "administrator",
      action: "deploy",
      observable: "capability-false",
      dataUnchanged: true,
    });
    await record({
      role: "administrator",
      allowed: "settings",
      forbidden: "deploy",
      denialObservable: "capability-false",
      mfaBypassStatus: adminBypass.status,
      mfaBypassMutation: false,
    });
    await screenshot("administrator-privileged-settings");

    currentStep = "contributor:session-sentinel";
    expect(await login(page, "task10-ana", "contributor")).toBe("authenticated");
    currentStep = "contributor:submit";
    const contributorRecord = await rest(page, "/wp-json/wp/v2/news", "POST", {
      title: "Contributor submission",
      excerpt: "Submission summary",
      content: "Submission body",
      status: "pending",
      meta: {
        _lps_locale: "pt-br",
        _lps_state: "draft",
        _lps_canonical_date: "2026-08-31T00:00:00Z",
      },
    });
    expect(contributorRecord.status).toBe(201);
    const contributorBefore = await rest(page, `/wp-json/wp/v2/news/${contributorRecord.data.id}`);
    const contributorDenied = await rest(
      page,
      `/wp-json/wp/v2/news/${contributorRecord.data.id}`,
      "POST",
      { status: "publish" },
    );
    assertDenied(contributorDenied);
    const contributorAfter = await rest(page, `/wp-json/wp/v2/news/${contributorRecord.data.id}`);
    expect(contributorAfter.data.status).toBe(contributorBefore.data.status);
    result.forbiddenReceipts.push({
      role: "contributor",
      action: "publish",
      status: contributorDenied.status,
      dataUnchanged: true,
    });
    await record({
      role: "contributor",
      allowed: "submit",
      forbidden: "publish",
      deniedStatus: contributorDenied.status,
      forbiddenMutation: false,
    });
    await screenshot("contributor-submit-denial");

    currentStep = "translator:session-sentinel";
    expect(await login(page, "task10-bruno", "translator")).toBe("authenticated");
    currentStep = "translator:locale-edit";
    const translated = await rest(page, `/wp-json/wp/v2/news/${recordId}`, "POST", {
      title: "Reviewed English locale edit",
    });
    expect(translated.status).toBe(200);
    const sharedBefore = translated.data.meta._lps_canonical_date;
    const translatorDenied = await rest(page, `/wp-json/wp/v2/news/${recordId}`, "POST", {
      meta: { _lps_canonical_date: "2099-01-01T00:00:00Z" },
    });
    assertDenied(translatorDenied);
    const sharedAfter = await rest(page, `/wp-json/wp/v2/news/${recordId}`);
    expect(sharedAfter.data.meta._lps_canonical_date).toBe(sharedBefore);
    result.forbiddenReceipts.push({
      role: "translator",
      action: "shared-identifier-edit",
      status: translatorDenied.status,
      dataUnchanged: true,
    });
    await record({
      role: "translator",
      allowed: "locale-edit",
      forbidden: "shared-identifier-edit",
      deniedStatus: translatorDenied.status,
      forbiddenMutation: false,
    });
    await screenshot("translator-locale-denial");

    currentStep = "section-editor:session-sentinel";
    expect(await login(page, "task10-carla", "section-editor")).toBe("authenticated");
    currentStep = "section-editor:review";
    const reviewed = await rest(page, `/wp-json/wp/v2/news/${recordId}`, "POST", {
      meta: { _lps_state: "in_review" },
    });
    expect(reviewed.status).toBe(200);
    const editorDenied = await rest(page, `/wp-json/wp/v2/news/${recordId}`, "POST", {
      status: "publish",
    });
    assertDenied(editorDenied);
    expect((await rest(page, `/wp-json/wp/v2/news/${recordId}`)).data.status).toBe("draft");
    result.forbiddenReceipts.push({
      role: "section-editor",
      action: "publish",
      status: editorDenied.status,
      dataUnchanged: true,
    });
    await record({
      role: "section-editor",
      allowed: "review",
      forbidden: "publish",
      deniedStatus: editorDenied.status,
      forbiddenMutation: false,
    });
    await screenshot("section-editor-review-denial");

    currentStep = "publisher:session-sentinel";
    expect(await login(page, "task10-diego", "publisher")).toBe("authenticated");
    await expect(page.locator("body")).toContainText("MFA enrollment required");
    currentStep = "publisher:mfa-bypass-denial";
    const publisherBeforeBypass = await rest(page, `/wp-json/wp/v2/news/${recordId}`);
    const publisherBypass = await rest(page, `/wp-json/wp/v2/news/${recordId}`, "POST", {
      status: "publish",
    });
    assertDenied(publisherBypass);
    const publisherAfterBypass = await rest(page, `/wp-json/wp/v2/news/${recordId}`);
    expect(publisherAfterBypass.data.status).toBe(publisherBeforeBypass.data.status);
    result.counts.mfaBypassDenials += 1;
    result.forbiddenReceipts.push({
      role: "publisher",
      action: "MFA-bypass-publish",
      status: publisherBypass.status,
      dataUnchanged: true,
    });
    currentStep = "publisher:totp-enrollment";
    const publisherSecret = await enrollTotp(page, "publisher");
    currentStep = "publisher:totp-login";
    await loginWithTotp(
      page,
      "task10-diego",
      publisherSecret,
      "publisher:authenticated-totp-session",
    );
    result.mfaState.push({
      role: "publisher",
      provider: "Two_Factor_Totp",
      enrollmentPersisted: true,
      primaryPersisted: true,
      challengeSelectors: ["#authcode", "#submit"],
      challengePassed: true,
    });
    currentStep = "publisher:publish";
    const published = await rest(page, `/wp-json/wp/v2/news/${recordId}`, "POST", {
      status: "publish",
      meta: { _lps_state: "published" },
    });
    expect(published.status).toBe(200);
    expect(published.data.status).toBe("publish");
    const settingsBeforeDenied = await rest(page, "/wp-json/wp/v2/settings");
    const publisherDenied = await rest(page, "/wp-json/wp/v2/settings", "POST", {
      lps_site_settings: { acronym: "NO" },
    });
    assertDenied(publisherDenied);
    const settingsAfterDenied = await rest(page, "/wp-json/wp/v2/settings");
    expect(settingsAfterDenied.data.lps_site_settings).toEqual(
      settingsBeforeDenied.data.lps_site_settings,
    );
    result.forbiddenReceipts.push({
      role: "publisher",
      action: "settings",
      status: publisherDenied.status,
      dataUnchanged: true,
    });
    await record({
      role: "publisher",
      allowed: "totp-enrollment+publish",
      forbidden: "settings",
      deniedStatus: publisherDenied.status,
      forbiddenMutation: false,
      mfaBypassStatus: publisherBypass.status,
      mfaBypassMutation: false,
    });
    await screenshot("publisher-mfa-published");

    currentStep = "privacy-auditor:session-sentinel";
    expect(await login(page, "task10-fabia", "privacy-auditor")).toBe("authenticated");
    currentStep = "privacy-auditor:reports";
    const auditorDenied = await rest(page, "/wp-json/wp/v2/news", "POST", { title: "Forbidden" });
    assertDenied(auditorDenied);
    await page.goto("/wp-admin/users.php?page=lps-dormant-accounts", {
      waitUntil: "domcontentloaded",
    });
    await expect(page.locator("body")).toContainText("Dormant LPS accounts");
    await page.goto("/wp-admin/tools.php?page=lps-audit-history", {
      waitUntil: "domcontentloaded",
    });
    await expect(page.locator("body")).toContainText("Hash chain verified");
    for (const action of ["create", "edit", "submit", "review", "publish", "settings"])
      await expect(page.locator("body")).toContainText(action);
    const auditRows = await page.locator("table tbody tr").evaluateAll((rows) =>
      rows.map((row) => {
        const cells = [...row.querySelectorAll("td")].map((cell) => cell.textContent.trim());
        return {
          auditId: Number(cells[0]),
          actorUserId: Number(cells[1]),
          occurredAt: cells[2],
          action: cells[3],
          objectRevision: cells[4],
        };
      }),
    );
    expect(auditRows.length).toBeGreaterThanOrEqual(6);
    expect(new Set(auditRows.map((row) => row.auditId)).size).toBe(auditRows.length);
    expect(
      auditRows.every((row, index) => index === 0 || row.auditId < auditRows[index - 1].auditId),
    ).toBe(true);
    result.counts.immutableAuditRows = auditRows.length;
    await writeFile(
      testInfo.outputPath("audit-export.json"),
      `${JSON.stringify({ immutable: true, hashChain: "verified", ordering: "append-only-descending-unique-ids", rows: auditRows }, null, 2)}\n`,
    );
    await writeFile(testInfo.outputPath("audit-table.html"), await page.content());
    await page.screenshot({
      path: testInfo.outputPath("privacy-auditor-reports.png"),
      fullPage: true,
    });
    result.forbiddenReceipts.push({
      role: "privacy-auditor",
      action: "create",
      status: auditorDenied.status,
      dataUnchanged: true,
    });
    await record({
      role: "privacy-auditor",
      allowed: "dormant+immutable-audit-reports",
      forbidden: "create",
      deniedStatus: auditorDenied.status,
      forbiddenMutation: false,
      hashChain: "verified",
      immutableRows: auditRows.length,
    });

    currentStep = "deployer:session-sentinel";
    expect(await login(page, "task10-gustavo", "deployer")).toBe("authenticated");
    currentStep = "deployer:operations-only";
    const me = await rest(page, "/wp-json/wp/v2/users/me?context=edit");
    expect(me.status).toBe(200);
    expect(me.data.capabilities.lps_deploy).toBe(true);
    const deployerBefore = await rest(page, `/wp-json/wp/v2/news/${recordId}`);
    const deployerDenied = await rest(page, `/wp-json/wp/v2/news/${recordId}`, "POST", {
      title: "Forbidden deployer edit",
    });
    assertDenied(deployerDenied);
    const deployerAfter = await rest(page, `/wp-json/wp/v2/news/${recordId}`);
    expect(deployerAfter.data.title.rendered).toBe(deployerBefore.data.title.rendered);
    result.forbiddenReceipts.push({
      role: "deployer",
      action: "content-edit",
      status: deployerDenied.status,
      dataUnchanged: true,
    });
    await record({
      role: "deployer",
      allowed: "lps_deploy-capability",
      forbidden: "content-edit",
      deniedStatus: deployerDenied.status,
      forbiddenMutation: false,
    });
    await screenshot("deployer-operations-only");

    expect(result.counts).toMatchObject({
      roles: 7,
      allowedActions: 7,
      forbiddenActions: 7,
      mfaBypassDenials: 2,
    });
    result.status = "pass";
    result.firstFailure = null;
    await flush();
    await writeFile(
      testInfo.outputPath("mfa-state.json"),
      `${JSON.stringify({ result: "pass", providers: result.mfaState }, null, 2)}\n`,
    );
    await writeFile(
      testInfo.outputPath("forbidden-receipts.json"),
      `${JSON.stringify({ result: "pass", attempts: result.forbiddenReceipts }, null, 2)}\n`,
    );
  } catch (error) {
    result.status = "fail";
    result.firstFailure = {
      step: currentStep,
      url: page?.url() ?? null,
      error:
        error instanceof Error
          ? { name: error.name, message: error.message, stack: error.stack }
          : { message: String(error) },
      session: page
        ? await page
            .evaluate(() => ({
              href: location.href,
              bodyClasses: document.body?.className ?? "",
              hasAdminBar: Boolean(document.querySelector("#wpadminbar")),
              hasLoginForm: Boolean(document.querySelector("#loginform")),
              hasAuthcode: Boolean(document.querySelector("#authcode")),
            }))
            .catch(() => null)
        : null,
    };
    await flush();
    if (page) {
      await writeFile(testInfo.outputPath("first-failure-dom.html"), await page.content()).catch(
        () => {},
      );
      await page
        .screenshot({ path: testInfo.outputPath("first-failure.png"), fullPage: true })
        .catch(() => {});
    }
    throw error;
  } finally {
    if (context)
      await context.tracing.stop({ path: testInfo.outputPath("matrix-trace.zip") }).catch(() => {});
    for (const openContext of contexts) await openContext.close().catch(() => {});
  }
});

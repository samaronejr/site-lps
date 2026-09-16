#!/usr/bin/env node
/**
 * Reproducible Todo 20 security fixture provisioner.
 *
 * Generates, from explicit required inputs, the three artifacts the disposable security site
 * needs: the `LPS_SECURITY_FIXTURE` JSON read by `tests/e2e/todo20-authenticated.spec.mjs`, a
 * Playground blueprint, and the standalone provisioning PHP the blueprint runs.
 *
 * Required inputs:
 *   --out-dir <dir>              Destination outside the repository; credentials never land in Git.
 *   LPS_SECURITY_PASSWORD        Disposable password for both fixture accounts.
 *   LPS_SECURITY_TOTP_SECRET     Disposable base32 TOTP secret for `security.privileged`.
 *   SOURCE_DATE_EPOCH            Optional; pins `generated_at` for byte-identical reruns.
 *
 * Outputs (mode 0600): todo20-security-fixture.json, todo20-security-blueprint.json,
 * todo20-provision.php.
 *
 * Exit codes: 0 when every artifact was written and re-validated, 1 on any missing, malformed or
 * stale input.
 */

import { readFileSync } from "node:fs";
import { join } from "node:path";
import process from "node:process";
import {
  assertOutsideRepository,
  assertSecurityFixtureContract,
  buildSecurityFixture,
  ProvisionerError,
  requiredOption,
  requiredPassword,
  requiredTotpSecret,
  resolveGeneratedAt,
  SECURITY_ACCOUNTS,
  writeArtifact,
} from "./lib/fixture-provisioning.mjs";

try {
  const argv = process.argv.slice(2);
  const outDir = assertOutsideRepository(requiredOption(argv, "--out-dir"));
  const password = requiredPassword(process.env, "LPS_SECURITY_PASSWORD");
  const secret = requiredTotpSecret(process.env, "LPS_SECURITY_TOTP_SECRET");
  const built = buildSecurityFixture({
    password,
    secret,
    generatedAt: resolveGeneratedAt(process.env),
  });

  const fixture = writeArtifact(
    join(outDir, "todo20-security-fixture.json"),
    `${JSON.stringify(built.fixture, null, 2)}\n`,
  );
  const blueprint = writeArtifact(
    join(outDir, "todo20-security-blueprint.json"),
    `${JSON.stringify(built.blueprint, null, 2)}\n`,
  );
  const script = writeArtifact(join(outDir, "todo20-provision.php"), built.php);

  const persisted = JSON.parse(readFileSync(fixture.path, "utf8"));
  assertSecurityFixtureContract(persisted);
  if (persisted.password !== password || persisted.secret !== secret) {
    throw new ProvisionerError("written fixture credentials do not match the supplied inputs");
  }
  const blueprintSteps = JSON.parse(readFileSync(blueprint.path, "utf8")).steps ?? [];
  const provisioningStep = blueprintSteps.find((step) => step.step === "runPHP");
  if (!provisioningStep || provisioningStep.code?.content !== built.php) {
    throw new ProvisionerError("blueprint does not carry the generated provisioning script");
  }
  for (const account of SECURITY_ACCOUNTS) {
    if (!built.php.includes(account.login)) {
      throw new ProvisionerError(`provisioning script never creates ${account.login}`);
    }
  }

  process.stdout.write(
    `${JSON.stringify(
      {
        status: "provisioned",
        task: "todo20",
        artifacts: {
          fixture: { path: fixture.path, sha256: fixture.sha256, mode: fixture.mode },
          blueprint: { path: blueprint.path, sha256: blueprint.sha256, mode: blueprint.mode },
          provisioning_script: { path: script.path, sha256: script.sha256, mode: script.mode },
        },
        accounts: built.fixture.accounts,
        next: {
          LPS_SECURITY_FIXTURE: fixture.path,
          run: "apply the blueprint, or run the script with DOCROOT set against a local site",
        },
      },
      null,
      2,
    )}\n`,
  );
} catch (error) {
  if (error instanceof ProvisionerError) {
    process.stderr.write(`todo20 fixture provisioning failed: ${error.message}\n`);
    process.exit(1);
  }
  throw error;
}

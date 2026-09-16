#!/usr/bin/env node
/**
 * Reproducible Todo 22 corpus fixture provisioner.
 *
 * Rebuilds the launch corpus from `content/corpus`, refuses to continue when the tracked import
 * package is stale, assigns every record a declared post ID, and generates the artifacts the
 * disposable corpus site needs: the `LPS_CORPUS_TARGETS` JSON read by
 * `tests/e2e/todo22-corpus.spec.mjs`, a Playground blueprint that writes the import package into
 * the site, and the standalone provisioning PHP that imports it.
 *
 * The provisioning PHP enforces the declared post IDs: it seeds each record at its declared ID and
 * fails loudly when WordPress assigns a different one, so no historical database ID is reused.
 *
 * Required inputs:
 *   --out-dir <dir>              Destination outside the repository; credentials never land in Git.
 *   LPS_CORPUS_ADMIN_TOTP_SECRET Disposable base32 TOTP secret enrolled for the site administrator.
 *   LPS_CORPUS_EDITOR_PASSWORD   Disposable password for the section-editor account.
 *
 * Optional inputs:
 *   --corpus-dir <dir>           Default content/corpus.
 *   --inventory-dir <dir>        Default content/inventory.
 *   --package <file>             Default content/import/launch-corpus.json.
 *   --pairs <file>               Default content/import/locale-pairs.json.
 *   --post-id-base <integer>     Default 4200; first declared post ID.
 *   --editor-login <login>       Default task22-helena; individual, attributable, disposable.
 *   SOURCE_DATE_EPOCH            Pins `generated_at` for byte-identical reruns.
 *
 * Outputs (mode 0600): todo22-spot-check-targets.json, todo22-corpus-blueprint.json,
 * todo22-provision.php, launch-corpus.json.
 *
 * Exit codes: 0 when every artifact was written and re-validated, 1 on any missing, malformed or
 * stale input.
 */

import { readFileSync } from "node:fs";
import { join } from "node:path";
import process from "node:process";
import {
  assertCorpusTargetsContract,
  assertOutsideRepository,
  buildCorpusFixture,
  CORPUS_SPOT_CHECK_RECORDS,
  DEFAULT_CORPUS_EDITOR_LOGIN,
  DEFAULT_POST_ID_BASE,
  option,
  ProvisionerError,
  requiredOption,
  requiredPassword,
  requiredTotpSecret,
  resolveGeneratedAt,
  writeArtifact,
} from "./lib/fixture-provisioning.mjs";

try {
  const argv = process.argv.slice(2);
  const outDir = assertOutsideRepository(requiredOption(argv, "--out-dir"));
  const editorLogin = option(argv, "--editor-login", DEFAULT_CORPUS_EDITOR_LOGIN);
  if (!/^[a-z][a-z0-9._-]{2,59}$/.test(editorLogin)) {
    throw new ProvisionerError("--editor-login must be a lowercase individual account name");
  }
  const built = await buildCorpusFixture({
    corpusDir: option(argv, "--corpus-dir", "content/corpus"),
    inventoryDir: option(argv, "--inventory-dir", "content/inventory"),
    packagePath: option(argv, "--package", "content/import/launch-corpus.json"),
    pairsPath: option(argv, "--pairs", "content/import/locale-pairs.json"),
    postIdBase: Number(option(argv, "--post-id-base", String(DEFAULT_POST_ID_BASE))),
    editorLogin,
    editorPassword: requiredPassword(process.env, "LPS_CORPUS_EDITOR_PASSWORD"),
    adminTotpSecret: requiredTotpSecret(process.env, "LPS_CORPUS_ADMIN_TOTP_SECRET"),
    generatedAt: resolveGeneratedAt(process.env),
  });

  const targets = writeArtifact(
    join(outDir, "todo22-spot-check-targets.json"),
    `${JSON.stringify(built.targets, null, 2)}\n`,
  );
  const blueprint = writeArtifact(
    join(outDir, "todo22-corpus-blueprint.json"),
    `${JSON.stringify(built.blueprint, null, 2)}\n`,
  );
  const script = writeArtifact(join(outDir, "todo22-provision.php"), built.php);
  const importPackage = writeArtifact(join(outDir, "launch-corpus.json"), built.packageText);

  const persisted = JSON.parse(readFileSync(targets.path, "utf8"));
  assertCorpusTargetsContract(persisted);
  if (importPackage.sha256 !== built.packageSha256) {
    throw new ProvisionerError("written import package does not match the digest pinned in PHP");
  }
  const blueprintSteps = JSON.parse(readFileSync(blueprint.path, "utf8")).steps ?? [];
  const provisioningStep = blueprintSteps.find((step) => step.step === "runPHP");
  const packageStep = blueprintSteps.find((step) => step.step === "writeFile");
  if (!provisioningStep || provisioningStep.code?.content !== built.php) {
    throw new ProvisionerError("blueprint does not carry the generated provisioning script");
  }
  if (!packageStep || packageStep.data !== built.packageText) {
    throw new ProvisionerError("blueprint does not carry the rebuilt import package");
  }
  for (const target of persisted.targets) {
    if (!built.php.includes(target.pt_br_record_id) || !built.php.includes(target.en_record_id)) {
      throw new ProvisionerError(`provisioning script never imports ${target.record}`);
    }
  }

  process.stdout.write(
    `${JSON.stringify(
      {
        status: "provisioned",
        task: "todo22",
        artifacts: {
          targets: { path: targets.path, sha256: targets.sha256, mode: targets.mode },
          blueprint: { path: blueprint.path, sha256: blueprint.sha256, mode: blueprint.mode },
          provisioning_script: { path: script.path, sha256: script.sha256, mode: script.mode },
          import_package: {
            path: importPackage.path,
            sha256: importPackage.sha256,
            mode: importPackage.mode,
          },
        },
        corpus: {
          records: persisted.targets.length * 2,
          locale_pairs: persisted.targets.length,
          spot_checks: CORPUS_SPOT_CHECK_RECORDS,
          post_id_range: [
            persisted.targets[0].pt_br_post_id,
            persisted.targets[persisted.targets.length - 1].en_post_id,
          ],
        },
        accounts: [
          { login: "admin", role: "administrator", mfa: "enrolled", collections: [] },
          {
            login: persisted.editor.login,
            role: persisted.editor.role,
            mfa: "absent",
            collections: persisted.editor.collections,
          },
        ],
        next: {
          LPS_CORPUS_TARGETS: targets.path,
          run: "apply the blueprint, or run the script with DOCROOT set against a local site",
        },
      },
      null,
      2,
    )}\n`,
  );
} catch (error) {
  if (error instanceof ProvisionerError) {
    process.stderr.write(`todo22 fixture provisioning failed: ${error.message}\n`);
    process.exit(1);
  }
  throw error;
}

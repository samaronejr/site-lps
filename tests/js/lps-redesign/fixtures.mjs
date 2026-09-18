import { mkdir, readFile, rm, writeFile } from "node:fs/promises";
import path from "node:path";

/**
 * Attempt-namespaced fixture helpers for the lps-redesign plan.
 *
 * Every fixture is synthetic data. Materialized copies live under
 * `test-results/lps-redesign/<attempt-id>/`, a gitignored scratch root, so a
 * run can always be cleaned up without touching repository or site content.
 */

const ATTEMPT_PATTERN = /^[a-z0-9][a-z0-9-]{0,62}$/;
const FIXTURE_ROOT = path.join("test-results", "lps-redesign");
const FIXTURE_DIR = path.join("tests", "fixtures", "lps-redesign");

export function attemptId(env = process.env) {
  const id = env.LPS_ATTEMPT_ID ?? "attempt-1";
  if (!ATTEMPT_PATTERN.test(id)) {
    throw new Error(`LPS_ATTEMPT_ID "${id}" must match ${ATTEMPT_PATTERN}`);
  }
  return id;
}

export function fixtureNamespace(env = process.env) {
  return path.join(FIXTURE_ROOT, attemptId(env));
}

export async function loadFixture(name) {
  const file = path.join(FIXTURE_DIR, `${name}.json`);
  const fixture = JSON.parse(await readFile(file, "utf8"));
  if (fixture.schemaVersion !== 1) {
    throw new Error(`${file}: schemaVersion must equal 1`);
  }
  if (fixture.synthetic !== true) {
    throw new Error(`${file}: fixture must declare synthetic: true`);
  }
  return fixture;
}

export function fixtureSlug(id, env = process.env) {
  return `lps-redesign-${attemptId(env)}-${id}`;
}

/**
 * Writes a namespaced, attempt-scoped copy of a fixture record and returns the
 * materialized path plus the record. The copy is scratch state, never content.
 */
export async function materializeFixture(name, recordId, env = process.env) {
  const fixture = await loadFixture(name);
  const collection = ["people", "accounts", "calendars", "terms", "offerings"].find((key) =>
    (fixture[key] ?? []).some((entry) => entry.id === recordId),
  );
  if (!collection) {
    throw new Error(`${name}.json has no record with id "${recordId}"`);
  }
  const record = fixture[collection].find((entry) => entry.id === recordId);
  const dir = fixtureNamespace(env);
  await mkdir(dir, { recursive: true });
  const file = path.join(dir, `${name}-${recordId}.json`);
  const payload = {
    attemptId: attemptId(env),
    fixture: fixture.fixture,
    record,
  };
  await writeFile(file, `${JSON.stringify(payload, null, 2)}\n`);
  return { file, record, payload };
}

/** Removes the whole attempt namespace. Safe: the root is scratch-only. */
export async function cleanupFixtures(env = process.env) {
  await rm(fixtureNamespace(env), { recursive: true, force: true });
}

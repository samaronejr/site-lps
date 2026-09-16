import { mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import {
  assertCorpusTargetsContract,
  assertOutsideRepository,
  assertSecurityFixtureContract,
  buildCorpusFixture,
  buildSecurityFixture,
  CORPUS_SPOT_CHECK_RECORDS,
  DEFAULT_POST_ID_BASE,
  ProvisionerError,
  REPOSITORY_ROOT,
  requiredOption,
  requiredPassword,
  requiredTotpSecret,
  SECURITY_ACCOUNTS,
  writeArtifact,
} from "../../scripts/lib/fixture-provisioning.mjs";

const { registered } = vi.hoisted(() => ({ registered: [] }));

vi.mock("@playwright/test", () => {
  const noop = () => {};
  const chainable = new Proxy(() => chainable, { get: () => chainable });
  const test = (title, fn) => {
    registered.push({ title, fn });
  };
  test.describe = (_title, fn) => fn();
  test.describe.configure = noop;
  test.beforeEach = noop;
  test.afterEach = noop;
  const expectStub = () => chainable;
  expectStub.poll = () => chainable;
  expectStub.soft = expectStub;
  return { test, expect: expectStub };
});

const PASSWORD = "Disposable-Fixture-9fQz";
const SECRET = "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP";
const GENERATED_AT = "2026-09-12T00:00:00.000Z";
const CORPUS_INPUTS = {
  corpusDir: "content/corpus",
  inventoryDir: "content/inventory",
  packagePath: "content/import/launch-corpus.json",
  pairsPath: "content/import/locale-pairs.json",
  postIdBase: DEFAULT_POST_ID_BASE,
  editorLogin: "task22-helena",
  editorPassword: PASSWORD,
  adminTotpSecret: SECRET,
  generatedAt: GENERATED_AT,
};

let workspace;

beforeAll(() => {
  workspace = mkdtempSync(join(tmpdir(), "lps-fixture-provisioning-"));
});

afterAll(() => {
  rmSync(workspace, { recursive: true, force: true });
});

describe("required input validation", () => {
  it("names the missing environment variable", () => {
    expect(() => requiredPassword({}, "LPS_SECURITY_PASSWORD")).toThrow(
      /LPS_SECURITY_PASSWORD is required/,
    );
    expect(() => requiredTotpSecret({}, "LPS_CORPUS_ADMIN_TOTP_SECRET")).toThrow(
      /LPS_CORPUS_ADMIN_TOTP_SECRET is required/,
    );
  });

  it("rejects empty, short and placeholder credentials", () => {
    expect(() => requiredPassword({ P: "   " }, "P")).toThrow(ProvisionerError);
    expect(() => requiredPassword({ P: "short" }, "P")).toThrow(/at least 12 characters/);
    expect(() => requiredPassword({ P: "changeme" }, "P")).toThrow(/placeholder material/);
    expect(() => requiredPassword({ P: "aaaaaaaaaaaaaaaa" }, "P")).toThrow(/placeholder material/);
  });

  it("rejects secrets Two-Factor could never store", () => {
    expect(() => requiredTotpSecret({ S: "not base32!" }, "S")).toThrow(/must be base32/);
    expect(() => requiredTotpSecret({ S: "JBSWY3DP" }, "S")).toThrow(/32-128 base32 characters/);
    expect(() => requiredTotpSecret({ S: "JBSWY3DPEHPK3PXPJBSWY3DP" }, "S")).toThrow(
      /32-128 base32 characters/,
    );
    expect(requiredTotpSecret({ S: `${SECRET}==` }, "S")).toBe(SECRET);
  });

  it("requires an explicit output directory outside the repository", () => {
    expect(() => requiredOption([], "--out-dir")).toThrow(/--out-dir <path> is required/);
    expect(() => requiredOption(["--out-dir", "--other"], "--out-dir")).toThrow(/is required/);
    expect(() => assertOutsideRepository(join(REPOSITORY_ROOT, "tmp-fixture"))).toThrow(
      /refusing to write fixture credentials inside the repository/,
    );
    expect(assertOutsideRepository(workspace)).toBe(workspace);
  });
});

describe("todo20 security fixture", () => {
  it("satisfies the journey contract and provisions both disposable accounts", () => {
    const built = buildSecurityFixture({
      password: PASSWORD,
      secret: SECRET,
      generatedAt: GENERATED_AT,
    });
    expect(() => assertSecurityFixtureContract(built.fixture)).not.toThrow();
    expect(built.fixture.password).toBe(PASSWORD);
    expect(built.fixture.secret).toBe(SECRET);
    for (const account of SECURITY_ACCOUNTS) {
      expect(built.php).toContain(account.login);
      expect(built.php).toContain(account.role);
    }
    expect(built.php).toContain("wp_get_environment_type()");
    expect(built.php).toContain("_task20_fixture_receipt");
    const runPhp = built.blueprint.steps.find((step) => step.step === "runPHP");
    expect(runPhp.code.content).toBe(built.php);
  });

  it("rejects a fixture whose accounts drift from the journey", () => {
    expect(() => assertSecurityFixtureContract({ schema_version: 1 })).toThrow(/password/);
    expect(() =>
      assertSecurityFixtureContract({
        schema_version: 1,
        password: PASSWORD,
        secret: SECRET,
        accounts: [{ login: "security.privileged", role: "lps_publisher", mfa: "enrolled" }],
      }),
    ).toThrow(/must use role lps_administrator/);
  });
});

describe("todo22 corpus fixture", () => {
  let built;

  beforeAll(async () => {
    built = await buildCorpusFixture(CORPUS_INPUTS);
  });

  it("derives every target from the versioned corpus", () => {
    expect(() => assertCorpusTargetsContract(built.targets)).not.toThrow();
    expect(built.targets.targets).toHaveLength(12);
    for (const record of CORPUS_SPOT_CHECK_RECORDS) {
      expect(built.targets.targets.some((target) => target.record === record)).toBe(true);
    }
    const home = built.targets.targets.find((target) => target.record === "record-001");
    expect(home.post_type).toBe("page");
    expect(home.pt_br_title).not.toBe(home.en_title);
  });

  it("declares deterministic post IDs the provisioning script enforces", () => {
    const ids = built.targets.targets.flatMap((target) => [
      target.pt_br_post_id,
      target.en_post_id,
    ]);
    expect(ids).toEqual(Array.from({ length: 24 }, (_, index) => DEFAULT_POST_ID_BASE + index));
    expect(new Set(ids).size).toBe(ids.length);
    expect(built.php).toContain("but WordPress assigned");
    expect(built.php).toContain("import package digest mismatch");
    expect(built.php).toContain("_task22_corpus_receipt");
  });

  it("reproduces byte-identical artifacts from identical inputs", async () => {
    const again = await buildCorpusFixture(CORPUS_INPUTS);
    expect(JSON.stringify(again.targets)).toBe(JSON.stringify(built.targets));
    expect(again.packageSha256).toBe(built.packageSha256);
    expect(again.php).toBe(built.php);
  });

  it("fails loudly on a stale import package", async () => {
    const stalePath = join(workspace, "stale-launch-corpus.json");
    const tracked = JSON.parse(
      readFileSync(join(REPOSITORY_ROOT, "content/import/launch-corpus.json"), "utf8"),
    );
    tracked.records[0].title = "Stale historical title";
    writeFileSync(stalePath, `${JSON.stringify(tracked, null, 2)}\n`);
    await expect(buildCorpusFixture({ ...CORPUS_INPUTS, packagePath: stalePath })).rejects.toThrow(
      /is stale against content\/corpus/,
    );
  });

  it("fails loudly on a missing or empty import package", async () => {
    const emptyPath = join(workspace, "empty-launch-corpus.json");
    writeFileSync(emptyPath, "");
    await expect(
      buildCorpusFixture({ ...CORPUS_INPUTS, packagePath: join(workspace, "absent.json") }),
    ).rejects.toThrow(/import package is required but could not be read/);
    await expect(buildCorpusFixture({ ...CORPUS_INPUTS, packagePath: emptyPath })).rejects.toThrow(
      /import package is empty/,
    );
  });
});

describe("artifacts written for the journeys", () => {
  it("writes owner-only files the real spec loaders accept", async () => {
    const security = buildSecurityFixture({
      password: PASSWORD,
      secret: SECRET,
      generatedAt: GENERATED_AT,
    });
    const corpus = await buildCorpusFixture(CORPUS_INPUTS);
    const securityPath = join(workspace, "todo20-security-fixture.json");
    const corpusPath = join(workspace, "todo22-spot-check-targets.json");
    writeArtifact(securityPath, `${JSON.stringify(security.fixture, null, 2)}\n`);
    writeArtifact(corpusPath, `${JSON.stringify(corpus.targets, null, 2)}\n`);
    expect((statSync(securityPath).mode & 0o777).toString(8)).toBe("600");
    expect((statSync(corpusPath).mode & 0o777).toString(8)).toBe("600");

    process.env.LPS_SECURITY_FIXTURE = securityPath;
    process.env.LPS_CORPUS_TARGETS = corpusPath;
    registered.length = 0;
    await import("../e2e/todo20-authenticated.spec.mjs");
    await import("../e2e/todo22-corpus.spec.mjs");
    expect(registered.length).toBeGreaterThan(0);

    // Each journey loads its fixture before it touches the browser. Running the bodies against a
    // page that refuses every call proves the loaders accepted the generated inputs: the only
    // failure that may surface is the sentinel, never a fixture-contract error.
    const sentinel = "page-unavailable-sentinel";
    const page = new Proxy(
      {},
      {
        get: () => () => {
          throw new Error(sentinel);
        },
      },
    );
    for (const entry of registered) {
      const failure = await entry
        .fn({ page, baseURL: "http://127.0.0.1:8888" })
        .then(() => "")
        .catch((error) => error.message);
      expect(failure, entry.title).toContain(sentinel);
    }
  });
});

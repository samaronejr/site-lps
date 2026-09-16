/**
 * LPS staging runtime orchestrator.
 *
 * Provisions pinned releases, deploys them behind the staging edge, runs
 * migrations and the corpus import, manages cron/monitoring/maintenance, and
 * verifies the served surface. Every command's real exit code lands in a
 * receipt under <root>/receipts/.
 *
 * The staging root (LPS_STAGING_ROOT, default .omo/staging) holds:
 *   releases/<id>/wordpress   release trees (code only; no mutable state)
 *   current                   symlink to the live release tree
 *   previous                  symlink to the last live release tree
 *   ops/db                    SQLite database (shared across releases)
 *   ops/uploads               uploads (shared across releases)
 *   ops/cache                 page cache, object cache, purge queue/receipts
 *   ops/logs                  structured logs (access, app, php-error, mail, cron, monitor, alerts)
 *   ops/secrets/secrets.env   least-privilege secrets (mode 0600)
 *   ops/run                   pid files, maintenance/fault flags
 *   receipts/                 command receipts
 *
 * Usage: node scripts/deploy/staging.mjs <command> [--root=<dir>] [--release=<id>]
 */

import { spawn, spawnSync } from "node:child_process";
import { createHash, randomBytes } from "node:crypto";
import {
  appendFileSync,
  chmodSync,
  closeSync,
  cpSync,
  existsSync,
  mkdirSync,
  openSync,
  readFileSync as readCert,
  readdirSync,
  readFileSync,
  readlinkSync,
  readSync,
  rmSync,
  statSync,
  symlinkSync,
  unlinkSync,
  writeFileSync,
} from "node:fs";
import { request as httpRequest } from "node:http";
import { request as httpsRequest } from "node:https";
import path from "node:path";

const REPO = path.resolve(path.dirname(new URL(import.meta.url).pathname), "../..");
const CLI = `${REPO}/node_modules/@wp-playground/cli/cli.js`;
const EDGE = `${REPO}/scripts/deploy/edge.mjs`;
const SELF = `${REPO}/scripts/deploy/staging.mjs`;

function arg(name, fallback = null) {
  const prefix = `--${name}=`;
  const inline = process.argv.find((a) => a.startsWith(prefix));
  if (inline !== undefined) return inline.slice(prefix.length);
  const index = process.argv.indexOf(`--${name}`);
  return index === -1 ? fallback : process.argv[index + 1];
}
const ROOT = path.resolve(arg("root", process.env.LPS_STAGING_ROOT ?? `${REPO}/.omo/staging`));
const OPS = `${ROOT}/ops`;
const RELEASES = `${ROOT}/releases`;
const RECEIPTS = `${ROOT}/receipts`;
const HTTPS_PORT = Number(arg("https-port", process.env.LPS_EDGE_HTTPS_PORT ?? 8443));
const HTTP_PORT = Number(arg("http-port", process.env.LPS_EDGE_HTTP_PORT ?? 8080));
const ORIGIN_PORT = Number(arg("origin-port", process.env.LPS_ORIGIN_PORT ?? 8927));
const SITE_URL = arg("site-url", process.env.LPS_SITE_URL ?? `https://127.0.0.1:${HTTPS_PORT}`);
const ORIGIN_URL = `http://127.0.0.1:${ORIGIN_PORT}`;
const WP_CLI_SHA256 = "ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c";
const WP_CLI_VERSION = "2.12.0";

const PINNED = {
  wordpress: {
    dir: `${REPO}/.cache/wordpress`,
    versionFile: "wp-includes/version.php",
    expect: "6.9.7",
  },
  polylang: {
    dir: `${REPO}/.cache/wp-plugins/polylang`,
    versionFile: "polylang.php",
    expect: "3.8.7",
  },
  "two-factor": {
    dir: `${REPO}/.cache/wp-plugins/two-factor`,
    versionFile: "two-factor.php",
    expect: "0.16.0",
  },
};

for (const dir of [
  OPS,
  RELEASES,
  RECEIPTS,
  `${OPS}/db`,
  `${OPS}/uploads`,
  `${OPS}/languages`,
  `${OPS}/cache`,
  `${OPS}/logs`,
  `${OPS}/run`,
  `${OPS}/secrets`,
  `${OPS}/reports`,
  `${ROOT}/bin`,
]) {
  mkdirSync(dir, { recursive: true });
}

function receipt(name, data) {
  const file = `${RECEIPTS}/${name}.json`;
  writeFileSync(file, `${JSON.stringify(data, null, 2)}\n`);
  return file;
}

function redact(text) {
  const secrets = parseSecrets();
  let out = text;
  for (const value of Object.values(secrets)) {
    if (value && value.length > 8) out = out.split(value).join("[redacted]");
  }
  return out;
}

function run(command, args, options = {}) {
  const started = Date.now();
  const result = spawnSync(command, args, {
    encoding: "utf8",
    timeout: options.timeoutMs ?? 600_000,
    env: { ...process.env, ...(options.env ?? {}) },
  });
  const ms = Date.now() - started;
  const code =
    result.status === null
      ? result.signal
        ? `signal:${result.signal}`
        : "spawn-error"
      : result.status;
  return {
    command: redact(`${command} ${args.join(" ")}`),
    exit: code,
    ms,
    stdout: redact(result.stdout ?? ""),
    stderr: redact(result.stderr ?? ""),
  };
}

function sha256(file) {
  return createHash("sha256").update(readFileSync(file)).digest("hex");
}

function treeHash(dir) {
  const files = [];
  const walk = (d) => {
    for (const entry of readdirSync(d, { withFileTypes: true })) {
      const full = `${d}/${entry.name}`;
      if (entry.isDirectory()) walk(full);
      else if (entry.isFile()) files.push(full);
    }
  };
  walk(dir);
  files.sort();
  const hash = createHash("sha256");
  for (const file of files) {
    hash.update(file.slice(dir.length + 1));
    hash.update("\0");
    hash.update(readFileSync(file));
    hash.update("\0");
  }
  return { hash: hash.digest("hex"), files: files.length };
}

// --- secrets ---------------------------------------------------------------

const SECRETS_FILE = `${OPS}/secrets/secrets.env`;
const SECRET_KEYS = [
  "LPS_PURGE_TOKEN",
  "LPS_ALERT_SINK",
  "AUTH_KEY",
  "SECURE_AUTH_KEY",
  "LOGGED_IN_KEY",
  "NONCE_KEY",
  "AUTH_SALT",
  "SECURE_AUTH_SALT",
  "LOGGED_IN_SALT",
  "NONCE_SALT",
];

function parseSecrets() {
  const secrets = {};
  if (!existsSync(SECRETS_FILE)) return secrets;
  for (const line of readFileSync(SECRETS_FILE, "utf8").split("\n")) {
    const match = /^([A-Z_]+)=(.*)$/.exec(line.trim());
    if (match) secrets[match[1]] = match[2];
  }
  return secrets;
}

function secretsInit() {
  if (existsSync(SECRETS_FILE)) {
    return { created: false, file: SECRETS_FILE };
  }
  const token = randomBytes(24).toString("hex");
  const salt = () =>
    randomBytes(32)
      .toString("base64")
      .replace(/[+/=]/g, (c) => ({ "+": "x", "/": "y", "=": "" })[c]);
  const lines = [
    "# LPS staging secrets — generated by staging.mjs secrets-init. Mode 0600.",
    `LPS_PURGE_TOKEN=${token}`,
    `LPS_ALERT_SINK=${OPS}/logs/alerts.jsonl`,
    `LPS_ADMIN_PASSWORD=${randomBytes(18).toString("base64").replace(/[+/=]/g, "x")}`,
    `AUTH_KEY=${salt()}`,
    `SECURE_AUTH_KEY=${salt()}`,
    `LOGGED_IN_KEY=${salt()}`,
    `NONCE_KEY=${salt()}`,
    `AUTH_SALT=${salt()}`,
    `SECURE_AUTH_SALT=${salt()}`,
    `LOGGED_IN_SALT=${salt()}`,
    `NONCE_SALT=${salt()}`,
    "",
  ];
  writeFileSync(SECRETS_FILE, lines.join("\n"), { mode: 0o600 });
  chmodSync(SECRETS_FILE, 0o600);
  return { created: true, file: SECRETS_FILE };
}

function secretsCheck() {
  const problems = [];
  if (!existsSync(SECRETS_FILE)) {
    problems.push(`missing ${SECRETS_FILE} (run secrets-init or install the operator file)`);
    return { ok: false, problems };
  }
  const mode = statSync(SECRETS_FILE).mode & 0o777;
  if (mode !== 0o600) problems.push(`secrets.env mode ${mode.toString(8)} must be 600`);
  const secrets = parseSecrets();
  for (const key of SECRET_KEYS) {
    if (!secrets[key]) problems.push(`secrets.env key ${key} is missing or empty`);
  }
  if (secrets.LPS_ALERT_SINK) {
    const dir = path.dirname(secrets.LPS_ALERT_SINK);
    if (!existsSync(dir)) problems.push(`LPS_ALERT_SINK directory ${dir} does not exist`);
  }
  return { ok: problems.length === 0, problems };
}

// --- runtime launchers ------------------------------------------------------

function runtimeDefines(provisioning = false, releaseId = null) {
  const secrets = parseSecrets();
  // Provisioning commands run before the plugin set is active, so the
  // production gate (which wp_dies on init until provisioning_ready()) would
  // deadlock the install. They declare "staging"; every runtime and
  // post-provisioning invocation declares "production" and must pass the gate.
  const defines = [
    ["--define", "WP_ENVIRONMENT_TYPE", provisioning ? "staging" : "production"],
    ["--define-bool", "WP_DEBUG", "false"],
    ["--define-bool", "WP_DEBUG_DISPLAY", "false"],
    ["--define-bool", "DISALLOW_FILE_EDIT", "true"],
    ["--define-bool", "DISALLOW_FILE_MODS", "true"],
    ["--define-bool", "LPS_EDGE_SECURITY_VERIFIED", "true"],
    ["--define-bool", "DISABLE_WP_CRON", "true"],
    ["--define", "LPS_OPS_DIR", "/lps-ops"],
    ["--define", "LPS_OPS_CACHE_DIR", "/lps-cache"],
    // The runtime predefines WP_DEBUG_LOG=true, which points early-boot
    // errors at wp-content/debug.log inside the web root. A string value
    // redirects that sink into the ops volume, keeping every log outside
    // the web root as the ops policy requires.
    ["--define", "WP_DEBUG_LOG", "/lps-ops/logs/php-error-early.log"],
  ];
  // The health endpoint reports this so a deploy can prove the origin that
  // answered is the release that was just flipped to, not a stale listener.
  if (releaseId) defines.push(["--define", "LPS_RELEASE_ID", releaseId]);
  for (const key of SECRET_KEYS) {
    if (key.startsWith("AUTH_") || key.endsWith("_KEY") || key.endsWith("_SALT")) {
      if (secrets[key]) defines.push(["--define", key, secrets[key]]);
    }
  }
  return defines.flat();
}

function releaseDir(id) {
  return `${RELEASES}/${id}/wordpress`;
}

function currentRelease() {
  try {
    return readlinkSync(`${ROOT}/current`);
  } catch {
    return null;
  }
}

/** Release id ("r6") for a release docroot path ("…/releases/r6/wordpress"). */
function releaseIdOf(siteDir) {
  return siteDir ? path.basename(path.dirname(siteDir)) : null;
}

function mounts(siteDir) {
  // Everything mounts before install: the shared database, uploads and
  // languages directories must exist before the installer writes, and the
  // ops volumes must be visible to the boot probe.
  return [
    ["--mount-dir-before-install", siteDir, "/wordpress"],
    ["--mount-dir-before-install", `${OPS}/db`, "/wordpress/wp-content/database"],
    ["--mount-dir-before-install", `${OPS}/uploads`, "/wordpress/wp-content/uploads"],
    ["--mount-dir-before-install", `${OPS}/languages`, "/wordpress/wp-content/languages"],
    ["--mount-dir-before-install", OPS, "/lps-ops"],
    ["--mount-dir-before-install", `${OPS}/cache`, "/lps-cache"],
    ["--mount-dir-before-install", `${OPS}/secrets`, "/lps-secrets"],
    ["--mount-dir-before-install", `${ROOT}/bin`, "/lps-bin"],
    ["--mount-dir-before-install", `${REPO}/content/import`, "/lps-import"],
    ["--mount-dir-before-install", `${REPO}/content/inventory`, "/lps-inventory"],
    ["--mount-dir-before-install", `${OPS}/reports`, "/lps-report"],
    ["--mount-dir-before-install", `${OPS}/probes`, "/lps-probes"],
  ].flat();
}

function wpCli(wpArgs, options = {}) {
  const siteDir = options.release ? releaseDir(options.release) : currentRelease();
  if (!siteDir || !existsSync(siteDir)) {
    return { command: "wp", exit: 2, ms: 0, stdout: "", stderr: `no release mounted (${siteDir})` };
  }
  const args = [
    CLI,
    "php",
    `--site-url=${SITE_URL}`,
    "--wordpress-install-mode=install-from-existing-files-if-needed",
    ...mounts(siteDir),
    "--php=8.3",
    ...runtimeDefines(options.provisioning === true, options.release ?? releaseIdOf(siteDir)),
    "--",
    "/lps-bin/wp.php",
    ...wpArgs,
  ];
  return run(process.execPath, args, options);
}

function phpScript(script, options = {}) {
  const siteDir = options.release ? releaseDir(options.release) : currentRelease();
  const args = [
    CLI,
    "php",
    `--site-url=${SITE_URL}`,
    "--wordpress-install-mode=install-from-existing-files-if-needed",
    ...mounts(siteDir),
    "--php=8.3",
    ...runtimeDefines(options.provisioning === true, options.release ?? releaseIdOf(siteDir)),
    "--",
    script,
  ];
  return run(process.execPath, args, options);
}

// --- provision --------------------------------------------------------------

function provision(id) {
  const steps = [];
  const fail = (why) => {
    receipt(`provision-${id}`, { release: id, ok: false, reason: why, steps });
    return { ok: false, reason: why, steps };
  };

  for (const [name, pin] of Object.entries(PINNED)) {
    const versionFile = `${pin.dir}/${pin.versionFile}`;
    if (!existsSync(versionFile)) return fail(`pinned ${name} missing at ${pin.dir}`);
    const content = readFileSync(versionFile, "utf8");
    if (!content.includes(pin.expect)) return fail(`pinned ${name} is not ${pin.expect}`);
    steps.push({ check: `pin-${name}`, expect: pin.expect, ok: true });
  }

  const manifest = JSON.parse(readFileSync(`${REPO}/dist/manifest.json`, "utf8"));
  const manifestEntries = {
    "dist/lps-content-model": "lps-content-model.php",
    "dist/lps-theme": "theme.json",
  };
  for (const [artifact, expected] of Object.entries(manifest)) {
    const entry = `${REPO}/${artifact}${manifestEntries[artifact] ? `/${manifestEntries[artifact]}` : ""}`;
    const actual = sha256(entry);
    if (actual !== expected)
      return fail(`dist artifact ${artifact} drifted: ${actual} != ${expected}`);
    steps.push({ check: `dist-${artifact}`, ok: true });
  }

  const phar = `${REPO}/.cache/wp-cli.phar`;
  if (!existsSync(phar))
    return fail(`wp-cli phar missing at ${phar} (expected WP-CLI ${WP_CLI_VERSION})`);
  const pharHash = sha256(phar);
  if (pharHash !== WP_CLI_SHA256)
    return fail(`wp-cli phar sha256 ${pharHash} != pinned ${WP_CLI_SHA256}`);
  steps.push({ check: "wp-cli-phar", sha256: pharHash, ok: true });

  const dest = releaseDir(id);
  rmSync(dest, { recursive: true, force: true });
  mkdirSync(path.dirname(dest), { recursive: true });
  cpSync(PINNED.wordpress.dir, dest, { recursive: true });
  steps.push({ check: "copy-wordpress", ok: true });

  // Least surface: bundled plugins/themes are not part of the release.
  for (const extra of ["akismet", "hello.php"]) {
    rmSync(`${dest}/wp-content/plugins/${extra}`, { recursive: true, force: true });
  }
  for (const theme of readdirSync(`${dest}/wp-content/themes`)) {
    if (theme !== "index.php")
      rmSync(`${dest}/wp-content/themes/${theme}`, { recursive: true, force: true });
  }

  cpSync(`${REPO}/dist/lps-content-model`, `${dest}/wp-content/plugins/lps-content-model`, {
    recursive: true,
  });
  cpSync(PINNED.polylang.dir, `${dest}/wp-content/plugins/polylang`, { recursive: true });
  cpSync(PINNED["two-factor"].dir, `${dest}/wp-content/plugins/two-factor`, { recursive: true });
  cpSync(`${REPO}/dist/lps-theme`, `${dest}/wp-content/themes/lps-theme`, { recursive: true });
  mkdirSync(`${dest}/wp-content/mu-plugins`, { recursive: true });
  cpSync(
    `${REPO}/dist/mu-plugins/lps-security.php`,
    `${dest}/wp-content/mu-plugins/lps-security.php`,
  );
  cpSync(`${REPO}/scripts/deploy/release/lps-ops.php`, `${dest}/wp-content/mu-plugins/lps-ops.php`);
  cpSync(`${REPO}/scripts/deploy/release/object-cache.php`, `${dest}/wp-content/object-cache.php`);
  cpSync(`${REPO}/scripts/deploy/release/wp-config.php`, `${dest}/wp-config.php`);
  cpSync(phar, `${ROOT}/bin/wp-cli.phar`);
  // Runtime state never lives inside a release: the database, uploads,
  // languages and logs are mounted shared volumes.
  rmSync(`${dest}/wp-content/database`, { recursive: true, force: true });
  rmSync(`${dest}/wp-content/debug.log`, { force: true });
  steps.push({ check: "copy-artifacts", ok: true });

  // Deployed bytes must equal the pinned artifacts exactly (no content drift).
  const drift = [];
  const compare = (a, b, label) => {
    const ha = treeHash(a);
    const hb = treeHash(b);
    if (ha.hash !== hb.hash) drift.push(`${label}: ${ha.hash} != ${hb.hash}`);
  };
  compare(
    `${REPO}/dist/lps-content-model`,
    `${dest}/wp-content/plugins/lps-content-model`,
    "plugin",
  );
  compare(`${REPO}/dist/lps-theme`, `${dest}/wp-content/themes/lps-theme`, "theme");
  compare(PINNED.polylang.dir, `${dest}/wp-content/plugins/polylang`, "polylang");
  compare(PINNED["two-factor"].dir, `${dest}/wp-content/plugins/two-factor`, "two-factor");
  if (
    sha256(`${REPO}/dist/mu-plugins/lps-security.php`) !==
    sha256(`${dest}/wp-content/mu-plugins/lps-security.php`)
  )
    drift.push("mu-plugin lps-security.php");
  if (drift.length > 0) return fail(`content drift: ${drift.join("; ")}`);
  steps.push({ check: "no-drift", ok: true });

  const tree = treeHash(dest);
  const meta = {
    release: id,
    created: new Date().toISOString(),
    wordpress: PINNED.wordpress.expect,
    polylang: PINNED.polylang.expect,
    twoFactor: PINNED["two-factor"].expect,
    wpCli: WP_CLI_VERSION,
    dist: manifest,
    tree,
  };
  writeFileSync(`${RELEASES}/${id}/release.json`, `${JSON.stringify(meta, null, 2)}\n`);
  receipt(`provision-${id}`, { release: id, ok: true, steps, tree });
  return { ok: true, steps, tree };
}

// --- boot check (pre-flip gate) ----------------------------------------------

const POLYLANG_SETUP = `
if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) { echo 'NO_PLL'; return; }
$languages = array(
  array( 'name' => 'Português', 'slug' => 'pt-br', 'locale' => 'pt_BR', 'term_group' => 0 ),
  array( 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'term_group' => 1 ),
);
foreach ( $languages as $language ) {
  if ( ! PLL()->model->get_language( $language['slug'] ) ) {
    PLL()->model->add_language( $language );
  }
}
$stored = get_option( 'polylang' );
if ( is_array( $stored ) ) {
  $stored['force_lang'] = 1; $stored['hide_default'] = 0; $stored['rewrite'] = 1;
  $stored['default_lang'] = 'pt-br'; $stored['browser'] = 0; $stored['redirect_lang'] = 1;
  update_option( 'polylang', $stored );
}
flush_rewrite_rules( false );
echo 'LANG_OK ' . wp_json_encode( pll_languages_list() );
`;

const ADMIN_PASSWORD = `
$env = @file_get_contents( '/lps-secrets/secrets.env' );
if ( false === $env ) { echo 'NO_SECRETS'; exit( 1 ); }
if ( ! preg_match( '/^LPS_ADMIN_PASSWORD=(.+)$/m', $env, $m ) ) { echo 'NO_PASSWORD_KEY'; exit( 1 ); }
$password = trim( $m[1] );
$user = get_user_by( 'login', 'admin' );
if ( ! $user ) { echo 'NO_ADMIN'; exit( 1 ); }
wp_set_password( $password, $user->ID );
echo 'ADMIN_PASSWORD_SET user=' . $user->ID;
`;

function bootCheck(id) {
  const steps = [];
  const record = (name, result, extra = {}) => {
    steps.push({
      name,
      command: result.command,
      exit: result.exit,
      ms: result.ms,
      stdout: result.stdout.slice(-2000),
      stderr: result.stderr.slice(-2000),
      ...extra,
    });
    return result.exit === 0;
  };
  const fail = (why) => {
    receipt(`boot-check-${id}`, { release: id, ok: false, reason: why, steps });
    return { ok: false, reason: why, steps };
  };

  const probe = phpScript("/lps-bin/wp-probe.php", { release: id, provisioning: true });
  if (!record("boot-probe", probe)) return fail("boot probe failed");
  if (!probe.stdout.includes("WP_LOADED")) return fail("WordPress did not load");
  if (!probe.stdout.includes("object_cache=persistent"))
    return fail("object cache drop-in not loaded");

  if (
    !record(
      "plugin-activate",
      wpCli(["plugin", "activate", "lps-content-model", "polylang", "two-factor"], {
        release: id,
        provisioning: true,
      }),
    )
  )
    return fail("plugin activation failed");
  if (
    !record(
      "theme-activate",
      wpCli(["theme", "activate", "lps-theme"], { release: id, provisioning: true }),
    )
  )
    return fail("theme activation failed");
  if (
    !record(
      "options",
      wpCli(["option", "update", "permalink_structure", "/%postname%/"], {
        release: id,
        provisioning: true,
      }),
    )
  )
    return fail("permalink option failed");
  record(
    "blogname",
    wpCli(["option", "update", "blogname", "LPS Staging"], { release: id, provisioning: true }),
  );
  record(
    "blogdescription",
    wpCli(["option", "update", "blogdescription", "Production-equivalent staging runtime"], {
      release: id,
      provisioning: true,
    }),
  );

  const langs = wpCli(["eval", POLYLANG_SETUP], { release: id, provisioning: true });
  if (!record("polylang-languages", langs)) return fail("Polylang language provisioning failed");
  if (!langs.stdout.includes("LANG_OK")) return fail("Polylang languages not confirmed");

  // The production gate must now pass: plugins active, versions pinned, every
  // required constant injected. This probe runs with the full production
  // constant set and dies on init when anything is missing.
  const gate = phpScript("/lps-bin/wp-probe.php", { release: id });
  if (!record("production-gate-probe", gate))
    return fail("production gate rejected the provisioned release");
  if (!gate.stdout.includes("env=production")) return fail("WP_ENVIRONMENT_TYPE is not production");
  if (!gate.stdout.includes("theme=lps-theme"))
    return fail("lps-theme not active under production");
  if (!gate.stdout.includes("debug=off")) return fail("WP_DEBUG is not off under production");

  const migrate = wpCli(
    ["eval", "echo wp_json_encode(\\LPS\\ContentModel\\Migrations::apply());"],
    { release: id },
  );
  if (!record("migrations-apply", migrate)) return fail("schema migration failed");
  const report = wpCli(
    ["eval", "echo wp_json_encode(\\LPS\\ContentModel\\Migrations::report());"],
    { release: id },
  );
  if (!record("migrations-report", report)) return fail("schema report failed");
  try {
    const parsed = JSON.parse(report.stdout.trim().split("\n").pop());
    if (parsed.version !== "1.0.0") return fail(`schema version ${parsed.version}`);
  } catch {
    return fail("schema report unreadable");
  }

  const packagePath = arg("package", "/lps-import/launch-corpus.json");
  const apply = wpCli(
    ["lps", "import", "apply", `--input=${packagePath}`, `--report-dir=/lps-report/import-${id}`],
    { release: id, timeoutMs: 300_000 },
  );
  if (!record("import-apply", apply)) return fail("corpus import failed");
  const verify = wpCli(
    ["lps", "import", "verify", `--input=${packagePath}`, "--inventory=/lps-inventory"],
    { release: id },
  );
  if (!record("import-verify", verify)) return fail("import verify failed");
  if (!verify.stdout.includes('"verified"') && !verify.stdout.includes("verified"))
    return fail("import not verified");
  const redirects = wpCli(["lps", "redirects", "verify"], { release: id });
  if (!record("redirects-verify", redirects)) return fail("redirect verify failed");

  // Documented release step: an import never publishes; the two reviewed
  // redirect records are published so the redirect graph goes live.
  const publish = wpCli(
    [
      "eval",
      "$ids = get_posts(array('post_type'=>'lps_redirect','post_status'=>'draft','numberposts'=>-1,'fields'=>'ids')); foreach($ids as $pid){ wp_update_post(array('ID'=>$pid,'post_status'=>'publish')); } echo 'PUBLISHED '.count($ids);",
    ],
    { release: id },
  );
  if (!record("redirects-publish", publish)) return fail("redirect publish failed");

  const password = wpCli(["eval", ADMIN_PASSWORD], { release: id });
  if (!record("admin-password", password)) return fail("admin password provisioning failed");
  if (!password.stdout.includes("ADMIN_PASSWORD_SET")) return fail("admin password not set");

  record(
    "rewrite-flush",
    wpCli(["eval", "flush_rewrite_rules(false); echo 'flushed';"], { release: id }),
  );
  record("cron-list", wpCli(["cron", "event", "list", "--format=json"], { release: id }));

  receipt(`boot-check-${id}`, { release: id, ok: true, steps });
  return { ok: true, steps };
}

// --- servers -----------------------------------------------------------------

function pidAlive(pid) {
  try {
    process.kill(pid, 0);
    return true;
  } catch {
    return false;
  }
}

function readPid(name) {
  try {
    return Number(readFileSync(`${OPS}/run/${name}.pid`, "utf8"));
  } catch {
    return null;
  }
}

/**
 * Waits for `needle` to appear in `file` at or after `offset` bytes. The
 * offset matters: these logs are append-only across many boots, so a naive
 * includes() matches a stale line from a previous process and reports a dead
 * server as ready.
 */
async function waitFor(file, needle, timeoutMs, offset = 0) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const fd = openSync(file, "r");
      try {
        const size = statSync(file).size - offset;
        if (size > 0) {
          const buf = Buffer.alloc(size);
          readSync(fd, buf, 0, size, offset);
          if (buf.toString("utf8").includes(needle)) return true;
        }
      } finally {
        closeSync(fd);
      }
    } catch {
      // not yet
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  return false;
}

function fileSize(file) {
  try {
    return statSync(file).size;
  } catch {
    return 0;
  }
}

/** PIDs of processes listening on a TCP port, parsed from `ss -tlnp`. */
function portPids(port) {
  const out = spawnSync("ss", ["-tlnp"], { encoding: "utf8" });
  if (out.status !== 0) return [];
  const pids = new Set();
  for (const line of out.stdout.split("\n")) {
    if (!line.includes(`:${port} `)) continue;
    for (const match of line.matchAll(/pid=(\d+)/g)) pids.add(Number(match[1]));
  }
  return [...pids];
}

async function sleepMs(ms) {
  await new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * (Re)starts the origin and proves it is serving the current release.
 *
 * The previous implementation matched a stale "Ready!" line from an earlier
 * boot, so a spawn that died EADDRINUSE still reported ok and the pid file
 * ended up holding the dead child's pid while the real listener ran
 * untracked. This version stops whatever owns the port first, waits for a
 * Ready! line written after this spawn started, then requires the spawned
 * pid to be the live :ORIGIN_PORT listener and the release health endpoint
 * to report the release that was just flipped to.
 */
async function serveOrigin() {
  const siteDir = currentRelease();
  if (!siteDir) return { ok: false, reason: "no current release" };
  const releaseId = releaseIdOf(siteDir);
  await stopOne("origin");
  const logFile = `${OPS}/logs/origin.log`;
  const out = appendFileSync.bind(null, logFile);
  out(`\n# serve ${new Date().toISOString()} site=${siteDir}\n`);
  const args = [
    CLI,
    "server",
    `--site-url=${SITE_URL}`,
    `--port=${ORIGIN_PORT}`,
    "--workers=2",
    "--php=8.3",
    "--wordpress-install-mode=do-not-attempt-installing",
    ...mounts(siteDir),
    ...runtimeDefines(false, releaseId),
  ];
  const logOffset = fileSize(logFile);
  const child = spawn(process.execPath, args, {
    detached: true,
    stdio: ["ignore", "pipe", "pipe"],
  });
  child.stdout.on("data", (chunk) => out(chunk));
  child.stderr.on("data", (chunk) => out(chunk));
  child.unref();
  const ready = await waitFor(logFile, "Ready!", 240_000, logOffset);
  if (!ready) {
    try {
      process.kill(child.pid, "SIGTERM");
    } catch {}
    return { ok: false, reason: "origin did not report Ready! within 240s" };
  }
  // The spawned process must be the live listener on the origin port; a
  // Ready! line alone does not prove the socket was bound.
  const listeners = portPids(ORIGIN_PORT);
  if (!pidAlive(child.pid) || !listeners.includes(child.pid)) {
    try {
      process.kill(child.pid, "SIGTERM");
    } catch {}
    return {
      ok: false,
      reason: `origin child ${child.pid} is not the :${ORIGIN_PORT} listener (${listeners.join(",") || "none"})`,
    };
  }
  writeFileSync(`${OPS}/run/origin.pid`, String(child.pid));
  // The release health endpoint must answer and report this release; that is
  // the proof the new origin mounted the release that was just flipped to.
  let health = null;
  const deadline = Date.now() + 120_000;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(`${ORIGIN_URL}/wp-json/lps-ops/v1/health`, {
        signal: AbortSignal.timeout(15_000),
      });
      if (res.status === 200) {
        health = await res.json();
        if (health?.release === releaseId) break;
      }
    } catch {}
    await sleepMs(1000);
  }
  if (health?.release !== releaseId) {
    return {
      ok: false,
      reason: `origin health reports release=${health?.release ?? "unreachable"}, expected ${releaseId}`,
      pid: child.pid,
    };
  }
  // One-time Playground bootstrap redirect: warm it so the edge never sees it.
  try {
    await fetch(`${ORIGIN_URL}/`, { redirect: "manual", signal: AbortSignal.timeout(30_000) });
  } catch {}
  return { ok: true, pid: child.pid, release: releaseId };
}

async function serveEdge() {
  await stopOne("edge");
  const logFile = `${OPS}/logs/edge.log`;
  const out = appendFileSync.bind(null, logFile);
  out(`\n# serve ${new Date().toISOString()}\n`);
  const secrets = parseSecrets();
  const child = spawn(process.execPath, [EDGE], {
    detached: true,
    stdio: ["ignore", "pipe", "pipe"],
    env: {
      ...process.env,
      LPS_EDGE_ORIGIN: ORIGIN_URL,
      LPS_EDGE_HTTPS_PORT: String(HTTPS_PORT),
      LPS_EDGE_HTTP_PORT: String(HTTP_PORT),
      LPS_EDGE_TLS_CERT: `${OPS}/tls/edge-cert.pem`,
      LPS_EDGE_TLS_KEY: `${OPS}/tls/edge-key.pem`,
      LPS_EDGE_ROOT: ROOT,
      LPS_PURGE_TOKEN: secrets.LPS_PURGE_TOKEN ?? "",
    },
  });
  child.stdout.on("data", (chunk) => out(chunk));
  child.stderr.on("data", (chunk) => out(chunk));
  child.unref();
  const ready = await waitFor(logFile, "edge https ready", 30_000, fileSize(logFile));
  if (!ready) {
    try {
      process.kill(child.pid, "SIGTERM");
    } catch {}
    return { ok: false, reason: "edge did not report ready within 30s" };
  }
  if (!pidAlive(child.pid) || !portPids(HTTPS_PORT).includes(child.pid)) {
    try {
      process.kill(child.pid, "SIGTERM");
    } catch {}
    return { ok: false, reason: `edge child ${child.pid} is not the :${HTTPS_PORT} listener` };
  }
  writeFileSync(`${OPS}/run/edge.pid`, String(child.pid));
  return { ok: true, pid: child.pid };
}

const PORT_OF = { origin: () => ORIGIN_PORT, edge: () => HTTPS_PORT };
const CMDLINE_MATCH = { origin: "cli.js", edge: "edge.mjs" };

/**
 * Stops a managed process. The pid file is a hint, not the truth: it can hold
 * a dead spawn's pid while the real listener runs untracked. The tracked pid
 * is only signalled when its cmdline still matches the expected process, and
 * whatever still owns the service port is signalled too, so the port is
 * guaranteed free before a restart.
 */
async function stopOne(name) {
  const port = PORT_OF[name]?.();
  const stopped = [];
  const pid = readPid(name);
  if (pid && pidAlive(pid)) {
    let cmdline = "";
    try {
      cmdline = readFileSync(`/proc/${pid}/cmdline`, "utf8");
    } catch {}
    if (cmdline.includes(CMDLINE_MATCH[name])) {
      try {
        process.kill(pid, "SIGTERM");
        stopped.push(pid);
      } catch {}
    }
  }
  for (const listenerPid of port ? portPids(port) : []) {
    if (listenerPid === pid) continue;
    try {
      process.kill(listenerPid, "SIGTERM");
      stopped.push(listenerPid);
    } catch {}
  }
  const deadline = Date.now() + 15_000;
  while (Date.now() < deadline) {
    const alive = stopped.some((p) => pidAlive(p));
    const bound = port ? portPids(port).length > 0 : false;
    if (!alive && !bound) break;
    await sleepMs(300);
  }
  rmSync(`${OPS}/run/${name}.pid`, { force: true });
  const portFree = port ? portPids(port).length === 0 : true;
  return { name, stopped: portFree && stopped.every((p) => !pidAlive(p)), pids: stopped };
}

function ensureTls() {
  mkdirSync(`${OPS}/tls`, { recursive: true });
  const cert = `${OPS}/tls/edge-cert.pem`;
  const key = `${OPS}/tls/edge-key.pem`;
  if (existsSync(cert) && existsSync(key)) return { created: false, cert };
  const result = run("openssl", [
    "req",
    "-x509",
    "-newkey",
    "rsa:2048",
    "-nodes",
    "-keyout",
    key,
    "-out",
    cert,
    "-days",
    "365",
    "-subj",
    "/CN=127.0.0.1",
    "-addext",
    "subjectAltName=IP:127.0.0.1,DNS:localhost",
  ]);
  chmodSync(key, 0o600);
  return { created: result.exit === 0, cert, exit: result.exit, stderr: result.stderr.slice(-500) };
}

// --- HTTP helpers (TLS pinned to the deployed certificate) --------------------

function edgeFetch(pathname, options = {}) {
  return new Promise((resolve, reject) => {
    const cert = readCert(`${OPS}/tls/edge-cert.pem`);
    const req = httpsRequest(
      {
        host: "127.0.0.1",
        port: HTTPS_PORT,
        path: pathname,
        method: options.method ?? "GET",
        ca: cert,
        headers: options.headers ?? {},
        timeout: options.timeoutMs ?? 30_000,
        rejectUnauthorized: true,
      },
      (res) => {
        const chunks = [];
        res.on("data", (chunk) => chunks.push(chunk));
        res.on("end", () =>
          resolve({
            status: res.statusCode,
            headers: res.headers,
            body: Buffer.concat(chunks).toString("utf8"),
          }),
        );
      },
    );
    req.on("timeout", () => req.destroy(new Error("timeout")));
    req.on("error", reject);
    if (options.body) req.write(options.body);
    req.end();
  });
}

function httpFetch(port, pathname) {
  return new Promise((resolve, reject) => {
    const req = httpRequest(
      { host: "127.0.0.1", port, path: pathname, method: "GET", timeout: 15_000 },
      (res) => {
        res.resume();
        res.on("end", () => resolve({ status: res.statusCode, headers: res.headers }));
      },
    );
    req.on("timeout", () => req.destroy(new Error("timeout")));
    req.on("error", reject);
    req.end();
  });
}

// --- maintenance / fault ------------------------------------------------------

function maintenance(state) {
  const file = `${OPS}/run/maintenance`;
  if (state === "on") writeFileSync(file, new Date().toISOString());
  else if (state === "off") rmSync(file, { force: true });
  return existsSync(file);
}

function fault(state) {
  const file = `${OPS}/run/fault`;
  if (state === "on") writeFileSync(file, new Date().toISOString());
  else if (state === "off") rmSync(file, { force: true });
  return existsSync(file);
}

// --- monitor ------------------------------------------------------------------

async function monitor() {
  const secrets = parseSecrets();
  const sink = secrets.LPS_ALERT_SINK;
  const checksFile = `${OPS}/logs/monitor-checks.jsonl`;
  const cursorFile = `${OPS}/run/monitor.cursor`;
  const results = { ts: new Date().toISOString(), checks: [], alerts: [] };

  if (!sink) {
    results.alerts.push({ kind: "config", detail: "LPS_ALERT_SINK unset" });
    appendFileSync(checksFile, `${JSON.stringify(results)}\n`);
    return { ok: false, exit: 2, results };
  }

  let health = null;
  try {
    const res = await edgeFetch("/lps-ops/health");
    health = { status: res.status, body: JSON.parse(res.body) };
  } catch (error) {
    health = { status: 0, error: String(error) };
  }
  const maintenanceMode = health?.body?.maintenance === true;
  const healthOk = health?.status === 200 && (health?.body?.status === "ok" || maintenanceMode);
  results.checks.push({
    name: "health",
    ok: healthOk,
    status: health?.status,
    maintenance: maintenanceMode,
  });

  let page = null;
  try {
    const res = await edgeFetch("/pt-br/");
    page = { status: res.status };
  } catch (error) {
    page = { status: 0, error: String(error) };
  }
  const pageOk = page?.status === 200 || (maintenanceMode && page?.status === 503);
  results.checks.push({ name: "public-page", ok: pageOk, status: page?.status });

  // PHP error log scan: new lines since the cursor become error alerts.
  const errorLog = `${OPS}/logs/php-error.log`;
  let cursor = 0;
  try {
    cursor = Number(readFileSync(cursorFile, "utf8")) || 0;
  } catch {}
  let newErrors = [];
  try {
    const size = statSync(errorLog).size;
    if (size < cursor) cursor = 0;
    if (size > cursor) {
      const fresh = readFileSync(errorLog, "utf8").slice(cursor);
      newErrors = fresh.split("\n").filter((line) => line.trim());
      cursor = size;
    }
  } catch {}
  writeFileSync(cursorFile, String(cursor));
  results.checks.push({ name: "php-errors", ok: newErrors.length === 0, count: newErrors.length });

  // Cron freshness: the system cron must have run the wrapper recently.
  const cronLog = `${OPS}/logs/cron.log`;
  const maxAgeSec = Number(process.env.LPS_CRON_MAX_AGE_SEC ?? 180);
  let cronAge = null;
  try {
    const last = readFileSync(cronLog, "utf8").trim().split("\n").filter(Boolean).pop();
    const parsed = JSON.parse(last);
    cronAge = (Date.now() - new Date(parsed.ts).getTime()) / 1000;
  } catch {}
  results.checks.push({
    name: "cron-freshness",
    ok: cronAge !== null && cronAge <= maxAgeSec,
    age_seconds: cronAge === null ? "no-cron-log" : Math.round(cronAge),
    max_age_seconds: maxAgeSec,
  });

  const failed = results.checks.filter((check) => !check.ok);
  if (failed.length > 0) {
    for (const check of failed) {
      const alert = {
        ts: new Date().toISOString(),
        kind: check.name === "php-errors" ? "error" : "uptime",
        check: check.name,
        detail: check,
        errors: check.name === "php-errors" ? newErrors.slice(0, 10) : undefined,
      };
      results.alerts.push(alert);
      try {
        appendFileSync(sink, `${JSON.stringify(alert)}\n`);
      } catch (error) {
        results.alerts.push({ kind: "config", detail: `alert sink unwritable: ${error}` });
        appendFileSync(checksFile, `${JSON.stringify(results)}\n`);
        return { ok: false, exit: 2, results };
      }
    }
  }
  appendFileSync(checksFile, `${JSON.stringify(results)}\n`);
  return { ok: failed.length === 0, exit: failed.length === 0 ? 0 : 1, results };
}

// --- cron ----------------------------------------------------------------------

function cronRun() {
  const result = wpCli(["cron", "event", "run", "--due-now"]);
  const entry = {
    ts: new Date().toISOString(),
    command: "wp cron event run --due-now",
    exit: result.exit,
    ms: result.ms,
    stdout_tail: result.stdout.slice(-500),
    stderr_tail: result.stderr.slice(-500),
  };
  appendFileSync(`${OPS}/logs/cron.log`, `${JSON.stringify(entry)}\n`);
  return result.exit === 0 ? 0 : 1;
}

const CRON_BEGIN = "# LPS-STAGING-CRON-BEGIN";
const CRON_END = "# LPS-STAGING-CRON-END";

function cronInstall() {
  const existing = spawnSync("crontab", ["-l"], { encoding: "utf8" });
  const current = existing.status === 0 ? existing.stdout : "";
  const lines = current.split("\n").filter(() => {
    return true;
  });
  const kept = [];
  let skipping = false;
  for (const line of lines) {
    if (line.trim() === CRON_BEGIN) skipping = true;
    if (!skipping) kept.push(line);
    if (line.trim() === CRON_END) skipping = false;
  }
  const block = [
    CRON_BEGIN,
    `* * * * * ${process.execPath} ${SELF} cron run --root=${ROOT} >> ${OPS}/logs/cron.log 2>&1`,
    `* * * * * ${process.execPath} ${SELF} monitor --root=${ROOT} >> ${OPS}/logs/monitor.log 2>&1`,
    CRON_END,
  ];
  const next = `${[...kept.join("\n").replace(/\n+$/, "").split("\n").filter(Boolean), ...block].join("\n")}\n`;
  // Install via stdin: this cron build truncates a filename argument at the
  // first non-alphanumeric character, so a path under .omo/ can never be
  // passed as an argument.
  const install = spawnSync("crontab", ["-"], { encoding: "utf8", input: next });
  return { ok: install.status === 0, exit: install.status, stderr: install.stderr };
}

function cronRemove() {
  const existing = spawnSync("crontab", ["-l"], { encoding: "utf8" });
  if (existing.status !== 0) return { ok: true, note: "no crontab" };
  const kept = [];
  let skipping = false;
  for (const line of existing.stdout.split("\n")) {
    if (line.trim() === CRON_BEGIN) skipping = true;
    if (!skipping) kept.push(line);
    if (line.trim() === CRON_END) skipping = false;
  }
  const install = spawnSync("crontab", ["-"], { encoding: "utf8", input: kept.join("\n") });
  return { ok: install.status === 0, exit: install.status };
}

// --- verify battery -------------------------------------------------------------

async function verifyBattery() {
  const secrets = parseSecrets();
  const checks = [];
  const check = (name, ok, detail = "") => {
    checks.push({ name, ok, detail });
    return ok;
  };

  // TLS + health
  try {
    const health = await edgeFetch("/lps-ops/health");
    const body = JSON.parse(health.body);
    check(
      "tls-health",
      health.status === 200 && body.status === "ok",
      `status=${health.status} body.status=${body.status}`,
    );
    check("health-origin", body.origin?.status === 200, `origin=${body.origin?.status}`);
    // The origin must be serving the release `current` points at; a stale
    // listener would report a different (or empty) release id.
    const expected = releaseIdOf(currentRelease());
    check(
      "origin-release",
      body.origin?.body?.release === expected && body.release === expected,
      `origin=${body.origin?.body?.release} edge=${body.release} expected=${expected}`,
    );
  } catch (error) {
    check("tls-health", false, String(error));
  }

  // HTTP -> HTTPS redirect
  try {
    const res = await httpFetch(HTTP_PORT, "/pt-br/");
    check(
      "http-redirect",
      res.status === 301 && String(res.headers.location ?? "").startsWith("https://"),
      `status=${res.status} location=${res.headers.location}`,
    );
  } catch (error) {
    check("http-redirect", false, String(error));
  }

  // Security headers on a public page
  try {
    const res = await edgeFetch("/pt-br/");
    const h = res.headers;
    check(
      "security-headers",
      res.status === 200 &&
        !!h["content-security-policy"] &&
        h["x-content-type-options"] === "nosniff" &&
        h["x-frame-options"] === "DENY" &&
        h["referrer-policy"] === "no-referrer" &&
        !!h["permissions-policy"] &&
        !!h["strict-transport-security"],
      `status=${res.status} csp=${(h["content-security-policy"] ?? "").slice(0, 60)}`,
    );
  } catch (error) {
    check("security-headers", false, String(error));
  }

  // Full-page cache: purge, MISS, HIT
  try {
    await edgeFetch("/lps-ops/purge", {
      method: "POST",
      headers: {
        authorization: `Bearer ${secrets.LPS_PURGE_TOKEN}`,
        "content-type": "application/json",
      },
      body: JSON.stringify({ all: true }),
    });
    const first = await edgeFetch("/pt-br/");
    const second = await edgeFetch("/pt-br/");
    check(
      "page-cache",
      first.headers["x-lps-edge-cache"] === "MISS" && second.headers["x-lps-edge-cache"] === "HIT",
      `first=${first.headers["x-lps-edge-cache"]} second=${second.headers["x-lps-edge-cache"]}`,
    );
    const q1 = await edgeFetch("/pt-br/busca/?q=lps");
    const q2 = await edgeFetch("/pt-br/busca/?q=lps");
    check(
      "query-cache",
      q1.headers["x-lps-edge-cache"] === "MISS" && q2.headers["x-lps-edge-cache"] === "HIT",
      `q1=${q1.headers["x-lps-edge-cache"]} q2=${q2.headers["x-lps-edge-cache"]}`,
    );
    const u1 = await edgeFetch("/pt-br/?utm_source=probe");
    const u2 = await edgeFetch("/pt-br/?utm_source=probe");
    check(
      "unapproved-query-bypass",
      u1.headers["x-lps-edge-cache"] === "BYPASS" && u2.headers["x-lps-edge-cache"] === "BYPASS",
      `u1=${u1.headers["x-lps-edge-cache"]} u2=${u2.headers["x-lps-edge-cache"]}`,
    );
    const admin = await edgeFetch("/wp-admin/");
    check(
      "private-path-bypass",
      admin.headers["x-lps-edge-cache"] === "BYPASS",
      `${admin.headers["x-lps-edge-cache"]} status=${admin.status}`,
    );
  } catch (error) {
    check("page-cache", false, String(error));
  }

  // Purge API auth + targeted purge
  try {
    const denied = await edgeFetch("/lps-ops/purge", { method: "POST", body: "{}" });
    const allowed = await edgeFetch("/lps-ops/purge", {
      method: "POST",
      headers: {
        authorization: `Bearer ${secrets.LPS_PURGE_TOKEN}`,
        "content-type": "application/json",
      },
      body: JSON.stringify({ urls: [`${SITE_URL}/pt-br/`] }),
    });
    const after = await edgeFetch("/pt-br/");
    check(
      "purge-api",
      denied.status === 403 &&
        allowed.status === 200 &&
        after.headers["x-lps-edge-cache"] === "MISS",
      `denied=${denied.status} allowed=${allowed.status} after=${after.headers["x-lps-edge-cache"]}`,
    );
  } catch (error) {
    check("purge-api", false, String(error));
  }

  // Publish-driven purge through the queue
  try {
    await edgeFetch("/pt-br/");
    const wp = wpCli([
      "eval",
      "$p = get_posts(array('post_status'=>'any','numberposts'=>1,'post_type'=>'page')); if(!$p){echo 'NO_POST';exit(1);} \\LPS\\Theme\\Delivery::purge_on_transition('publish','draft',$p[0]); echo 'PURGED';",
    ]);
    const deadline = Date.now() + 15_000;
    let receiptFound = false;
    while (Date.now() < deadline) {
      try {
        const receipts = readFileSync(`${OPS}/cache/purge-receipts.jsonl`, "utf8");
        if (receipts.includes('"queue"')) {
          receiptFound = true;
          break;
        }
      } catch {}
      await new Promise((resolve) => setTimeout(resolve, 400));
    }
    let after;
    try {
      after = await edgeFetch("/pt-br/");
    } catch {
      await new Promise((resolve) => setTimeout(resolve, 1500));
      after = await edgeFetch("/pt-br/");
    }
    check(
      "purge-queue",
      wp.exit === 0 &&
        wp.stdout.includes("PURGED") &&
        receiptFound &&
        after.headers["x-lps-edge-cache"] === "MISS",
      `wp=${wp.exit} receipt=${receiptFound} after=${after.headers["x-lps-edge-cache"]}`,
    );
  } catch (error) {
    check("purge-queue", false, String(error));
  }

  // Static denials
  for (const [pathname, expect] of [
    ["/.env", 403],
    ["/xmlrpc.php", 403],
    ["/wp-json/wp/v2/users", 403],
    ["/wp-content/uploads/evil.php", 403],
    ["/readme.html", 403],
  ]) {
    try {
      const res = await edgeFetch(pathname);
      check(`deny${pathname}`, res.status === expect, `status=${res.status}`);
    } catch (error) {
      check(`deny${pathname}`, false, String(error));
    }
  }

  // Rate limit on the credential surface (burst 20 at 5/min)
  try {
    let saw429 = false;
    for (let i = 0; i < 26; i++) {
      const res = await edgeFetch("/wp-login.php");
      if (res.status === 429) {
        saw429 = true;
        break;
      }
    }
    check("login-throttle", saw429, "429 observed within 26 requests");
  } catch (error) {
    check("login-throttle", false, String(error));
  }

  // Redirect graph + SEO surfaces
  try {
    const gone = await edgeFetch("/publications/publications.html");
    const moved = await edgeFetch("/projetos/research.html");
    const target = await edgeFetch("/pt-br/projetos/");
    const sitemap = await edgeFetch("/sitemap.xml");
    const robots = await edgeFetch("/robots.txt");
    check(
      "redirect-graph",
      gone.status === 410 &&
        moved.status === 301 &&
        target.status === 200 &&
        sitemap.status === 200 &&
        robots.status === 200,
      `gone=${gone.status} moved=${moved.status} target=${target.status} sitemap=${sitemap.status} robots=${robots.status}`,
    );
  } catch (error) {
    check("redirect-graph", false, String(error));
  }

  // Maintenance window
  try {
    maintenance("on");
    const during = await edgeFetch("/pt-br/");
    const healthDuring = await edgeFetch("/lps-ops/health");
    const healthBody = JSON.parse(healthDuring.body);
    maintenance("off");
    const after = await edgeFetch("/pt-br/");
    check(
      "maintenance",
      during.status === 503 &&
        !!during.headers["retry-after"] &&
        healthDuring.status === 200 &&
        healthBody.maintenance === true &&
        after.status === 200,
      `during=${during.status} retry=${during.headers["retry-after"]} health=${healthBody.status} after=${after.status}`,
    );
  } catch (error) {
    maintenance("off");
    check("maintenance", false, String(error));
  }

  // Mail capture + mailto links
  try {
    const wp = wpCli([
      "eval",
      "wp_mail('ops@lps-staging.invalid','staging probe','body'); echo 'MAILED';",
    ]);
    await new Promise((resolve) => setTimeout(resolve, 300));
    const outbox = existsSync(`${OPS}/logs/mail-outbox.jsonl`)
      ? readFileSync(`${OPS}/logs/mail-outbox.jsonl`, "utf8")
      : "";
    check(
      "mail-capture",
      wp.exit === 0 && outbox.includes("staging probe"),
      `wp=${wp.exit} outbox=${outbox.includes("staging probe")}`,
    );
    let mailto = 0;
    for (const page of ["/pt-br/", "/en/", "/pt-br/projetos/"]) {
      const res = await edgeFetch(page);
      mailto += (res.body.match(/mailto:/g) ?? []).length;
    }
    checks.push({
      name: "mailto-links",
      ok: true,
      detail: `${mailto} mailto links on sampled pages (informational)`,
    });
  } catch (error) {
    check("mail-capture", false, String(error));
  }

  // Persistent object cache across processes
  try {
    const set = wpCli(["eval", "wp_cache_set('lps_t27_probe','persisted','lps'); echo 'SET';"]);
    const get = wpCli(["eval", "echo wp_cache_get('lps_t27_probe','lps');"]);
    check(
      "object-cache",
      set.exit === 0 && get.stdout.includes("persisted"),
      `set=${set.exit} get=${get.stdout.trim().slice(-80)}`,
    );
  } catch (error) {
    check("object-cache", false, String(error));
  }

  // Cron: run the heartbeat event through the cron wrapper and confirm the
  // option timestamp the hook writes.
  try {
    const schedule = wpCli([
      "eval",
      "wp_schedule_single_event(time()-10,'lps_ops_heartbeat'); echo 'SCHEDULED';",
    ]);
    const runExit = cronRun();
    const fired = wpCli(["eval", "echo get_option('lps_ops_heartbeat','none');"]);
    check(
      "cron",
      schedule.exit === 0 && runExit === 0 && !fired.stdout.includes("none"),
      `schedule=${schedule.exit} run=${runExit} fired=${fired.stdout.trim().slice(-40)}`,
    );
  } catch (error) {
    check("cron", false, String(error));
  }

  // Alert routing: uptime alert via fault injection, error alert via PHP error.
  try {
    const alertsFile = secrets.LPS_ALERT_SINK;
    fault("on");
    const down = await monitor();
    fault("off");
    wpCli(["eval", "trigger_error('lps_t27_probe_error', E_USER_WARNING); echo 'TRIGGERED';"]);
    await monitor();
    const after = existsSync(alertsFile) ? readFileSync(alertsFile, "utf8") : "";
    const uptimeAlert = after.includes('"uptime"');
    const errorAlert = after.includes("lps_t27_probe_error");
    const recovered = await monitor();
    check(
      "alert-routing",
      down.exit === 1 && uptimeAlert && errorAlert && recovered.exit === 0,
      `down=${down.exit} uptime=${uptimeAlert} error=${errorAlert} recovered=${recovered.exit}`,
    );
  } catch (error) {
    fault("off");
    check("alert-routing", false, String(error));
  }

  // Structured logs exist and the access log never records query strings.
  try {
    const access = readFileSync(`${OPS}/logs/access.jsonl`, "utf8").trim().split("\n").slice(-50);
    let leaks = 0;
    for (const line of access) {
      try {
        const parsed = JSON.parse(line);
        if (String(parsed.path).includes("?")) leaks += 1;
      } catch {
        leaks += 1;
      }
    }
    const app = existsSync(`${OPS}/logs/app.jsonl`)
      ? readFileSync(`${OPS}/logs/app.jsonl`, "utf8")
      : "";
    check(
      "structured-logs",
      access.length > 0 && leaks === 0 && app.includes("mail_captured"),
      `access_lines=${access.length} query_leaks=${leaks} app_log=${app.includes("mail_captured")}`,
    );
  } catch (error) {
    check("structured-logs", false, String(error));
  }

  // Secrets scan: token and salts must not appear in the release tree, logs,
  // or receipts; secrets.env stays mode 600 outside the web root.
  try {
    const problems = [];
    const secretsValues = Object.values(secrets).filter((value) => value.length > 8);
    const scanDirs = [`${ROOT}/releases`, `${OPS}/logs`, RECEIPTS];
    for (const dir of scanDirs) {
      const walk = (d) => {
        for (const entry of readdirSync(d, { withFileTypes: true })) {
          const full = `${d}/${entry.name}`;
          if (entry.isDirectory()) walk(full);
          else if (entry.isFile() && statSync(full).size < 2_000_000) {
            const content = readFileSync(full, "utf8");
            for (const value of secretsValues) {
              if (content.includes(value)) problems.push(`${full} contains a secret value`);
            }
          }
        }
      };
      walk(dir);
    }
    if (existsSync(`${currentRelease()}/../secrets.env`))
      problems.push("secrets.env inside release");
    const mode = statSync(SECRETS_FILE).mode & 0o777;
    if (mode !== 0o600) problems.push(`secrets.env mode ${mode.toString(8)}`);
    check("secrets-scan", problems.length === 0, problems.join("; ") || "clean");
  } catch (error) {
    check("secrets-scan", false, String(error));
  }

  // Content drift: deployed plugin/theme bytes still equal dist/.
  try {
    const site = currentRelease();
    const drift = [];
    for (const [label, a, b] of [
      ["plugin", `${REPO}/dist/lps-content-model`, `${site}/wp-content/plugins/lps-content-model`],
      ["theme", `${REPO}/dist/lps-theme`, `${site}/wp-content/themes/lps-theme`],
    ]) {
      if (treeHash(a).hash !== treeHash(b).hash) drift.push(label);
    }
    check("no-drift", drift.length === 0, drift.join(",") || "deployed bytes == dist");
  } catch (error) {
    check("no-drift", false, String(error));
  }

  const failed = checks.filter((item) => !item.ok);
  const report = {
    ts: new Date().toISOString(),
    ok: failed.length === 0,
    checks,
    failed: failed.map((f) => f.name),
  };
  receipt("verify", report);
  return report;
}

// --- deploy / rollback ----------------------------------------------------------

async function deploy(id) {
  const steps = [];
  const secrets = secretsCheck();
  steps.push({ name: "secrets-check", ok: secrets.ok, problems: secrets.problems });
  if (!secrets.ok) {
    receipt(`deploy-${id}`, { release: id, ok: false, stage: "secrets-check", steps });
    return { ok: false, stage: "secrets-check", steps };
  }

  if (!existsSync(releaseDir(id))) {
    const prov = provision(id);
    steps.push({ name: "provision", ok: prov.ok, reason: prov.reason });
    if (!prov.ok) {
      receipt(`deploy-${id}`, { release: id, ok: false, stage: "provision", steps });
      return { ok: false, stage: "provision", steps };
    }
  } else {
    steps.push({ name: "provision", ok: true, note: "release exists" });
  }

  const boot = bootCheck(id);
  steps.push({ name: "boot-check", ok: boot.ok, reason: boot.reason });
  if (!boot.ok) {
    receipt(`deploy-${id}`, { release: id, ok: false, stage: "boot-check", steps });
    return { ok: false, stage: "boot-check", steps };
  }

  const previous = currentRelease();
  const wasServing = readPid("origin") !== null;
  if (wasServing) maintenance("on");

  // Flip: previous keeps pointing at the last live release for rollback.
  if (previous) {
    try {
      unlinkSync(`${ROOT}/previous`);
    } catch {}
    symlinkSync(previous, `${ROOT}/previous`);
  }
  try {
    unlinkSync(`${ROOT}/current`);
  } catch {}
  symlinkSync(releaseDir(id), `${ROOT}/current`);
  steps.push({ name: "flip", ok: true, from: previous, to: releaseDir(id) });

  if (wasServing) {
    await stopOne("origin");
  }
  const origin = await serveOrigin();
  steps.push({ name: "serve-origin", ok: origin.ok, reason: origin.reason });
  if (!origin.ok) {
    if (previous) {
      unlinkSync(`${ROOT}/current`);
      symlinkSync(previous, `${ROOT}/current`);
      await serveOrigin();
    }
    maintenance("off");
    receipt(`deploy-${id}`, { release: id, ok: false, stage: "serve-origin", steps });
    return { ok: false, stage: "serve-origin", steps };
  }

  let edge = { ok: true };
  if (readPid("edge") === null) {
    ensureTls();
    edge = await serveEdge();
  }
  steps.push({ name: "serve-edge", ok: edge.ok, reason: edge.reason });
  if (!edge.ok) {
    maintenance("off");
    receipt(`deploy-${id}`, { release: id, ok: false, stage: "serve-edge", steps });
    return { ok: false, stage: "serve-edge", steps };
  }

  // A release flip invalidates every cached page.
  const secrets2 = parseSecrets();
  try {
    await edgeFetch("/lps-ops/purge", {
      method: "POST",
      headers: {
        authorization: `Bearer ${secrets2.LPS_PURGE_TOKEN}`,
        "content-type": "application/json",
      },
      body: JSON.stringify({ all: true }),
    });
  } catch {}
  steps.push({ name: "cache-flush", ok: true });

  // The maintenance window covers the flip and the origin restart only;
  // verification runs against the live surface.
  maintenance("off");

  const report = await verifyBattery();
  steps.push({ name: "verify", ok: report.ok, failed: report.failed });
  if (!report.ok) {
    if (previous) {
      unlinkSync(`${ROOT}/current`);
      symlinkSync(previous, `${ROOT}/current`);
      await stopOne("origin");
      await serveOrigin();
      try {
        await edgeFetch("/lps-ops/purge", {
          method: "POST",
          headers: {
            authorization: `Bearer ${secrets2.LPS_PURGE_TOKEN}`,
            "content-type": "application/json",
          },
          body: JSON.stringify({ all: true }),
        });
      } catch {}
      steps.push({ name: "auto-rollback", ok: true, to: previous });
    }
    maintenance("off");
    receipt(`deploy-${id}`, { release: id, ok: false, stage: "verify", steps });
    return { ok: false, stage: "verify", steps };
  }

  maintenance("off");
  receipt(`deploy-${id}`, { release: id, ok: true, steps });
  return { ok: true, steps };
}

async function rollback(toId) {
  const target = releaseDir(toId);
  const steps = [];
  if (!existsSync(target)) {
    receipt(`rollback-${toId}`, { ok: false, reason: `release ${toId} does not exist` });
    return { ok: false, reason: `release ${toId} does not exist` };
  }
  const previous = currentRelease();
  maintenance("on");
  try {
    unlinkSync(`${ROOT}/previous`);
  } catch {}
  if (previous) symlinkSync(previous, `${ROOT}/previous`);
  try {
    unlinkSync(`${ROOT}/current`);
  } catch {}
  symlinkSync(target, `${ROOT}/current`);
  steps.push({ name: "flip", from: previous, to: target });
  await stopOne("origin");
  const origin = await serveOrigin();
  steps.push({ name: "serve-origin", ok: origin.ok });
  const secrets2 = parseSecrets();
  try {
    await edgeFetch("/lps-ops/purge", {
      method: "POST",
      headers: {
        authorization: `Bearer ${secrets2.LPS_PURGE_TOKEN}`,
        "content-type": "application/json",
      },
      body: JSON.stringify({ all: true }),
    });
  } catch {}
  // The maintenance window covers the flip and origin restart only; the
  // smoke check must see the live surface.
  maintenance("off");
  const health = await edgeFetch("/lps-ops/health").catch(() => null);
  const page = await edgeFetch("/pt-br/").catch(() => null);
  const ok = origin.ok && health?.status === 200 && page?.status === 200;
  steps.push({ name: "smoke", ok, health: health?.status, page: page?.status });
  receipt(`rollback-${toId}`, { ok, steps });
  return { ok, steps };
}

// --- CLI ------------------------------------------------------------------------

const command = process.argv[2];
const releaseId = arg("release", `r${Date.now().toString(36)}`);

async function main() {
  switch (command) {
    case "secrets-init": {
      const result = secretsInit();
      console.log(JSON.stringify(result));
      return 0;
    }
    case "secrets-check": {
      const result = secretsCheck();
      console.log(JSON.stringify(result));
      return result.ok ? 0 : 1;
    }
    case "provision": {
      const result = provision(releaseId);
      console.log(JSON.stringify({ ok: result.ok, reason: result.reason ?? null }));
      return result.ok ? 0 : 1;
    }
    case "boot-check": {
      const result = bootCheck(releaseId);
      console.log(JSON.stringify({ ok: result.ok, reason: result.reason ?? null }));
      return result.ok ? 0 : 1;
    }
    case "deploy": {
      const result = await deploy(releaseId);
      console.log(JSON.stringify({ ok: result.ok, stage: result.stage ?? "done" }));
      return result.ok ? 0 : 1;
    }
    case "rollback": {
      const to = arg("to", "");
      if (!to) {
        console.log(JSON.stringify({ ok: false, reason: "--to=<release> required" }));
        return 2;
      }
      const result = await rollback(to);
      console.log(JSON.stringify({ ok: result.ok }));
      return result.ok ? 0 : 1;
    }
    case "serve": {
      ensureTls();
      const origin = await serveOrigin();
      const edge = origin.ok ? await serveEdge() : { ok: false, reason: "origin failed" };
      console.log(JSON.stringify({ origin, edge }));
      return origin.ok && edge.ok ? 0 : 1;
    }
    case "stop": {
      const edge = await stopOne("edge");
      const origin = await stopOne("origin");
      console.log(JSON.stringify({ edge, origin }));
      return 0;
    }
    case "status": {
      const health = await edgeFetch("/lps-ops/health").catch((error) => ({
        error: String(error),
      }));
      console.log(
        JSON.stringify({
          root: ROOT,
          current: currentRelease(),
          previous: (() => {
            try {
              return readlinkSync(`${ROOT}/previous`);
            } catch {
              return null;
            }
          })(),
          originPid: readPid("origin"),
          edgePid: readPid("edge"),
          originListeners: portPids(ORIGIN_PORT),
          edgeListeners: portPids(HTTPS_PORT),
          release: releaseIdOf(currentRelease()),
          maintenance: existsSync(`${OPS}/run/maintenance`),
          fault: existsSync(`${OPS}/run/fault`),
          health,
        }),
      );
      return 0;
    }
    case "wp": {
      const wpArgs = process.argv
        .slice(3)
        .filter((a) => !a.startsWith("--root=") && !a.startsWith("--release="));
      const result = wpCli(wpArgs, { release: arg("release") ?? undefined });
      process.stdout.write(result.stdout);
      process.stderr.write(result.stderr);
      return typeof result.exit === "number" ? result.exit : 1;
    }
    case "cron": {
      const sub = process.argv[3];
      if (sub === "run") return cronRun();
      if (sub === "install") {
        const result = cronInstall();
        console.log(JSON.stringify(result));
        return result.ok ? 0 : 1;
      }
      if (sub === "remove") {
        const result = cronRemove();
        console.log(JSON.stringify(result));
        return result.ok ? 0 : 1;
      }
      if (sub === "status") {
        const existing = spawnSync("crontab", ["-l"], { encoding: "utf8" });
        const installed = existing.status === 0 && existing.stdout.includes(CRON_BEGIN);
        console.log(JSON.stringify({ installed, crontab: existing.stdout }));
        return installed ? 0 : 1;
      }
      console.log(JSON.stringify({ error: "cron run|install|remove|status" }));
      return 2;
    }
    case "monitor": {
      const result = await monitor();
      console.log(
        JSON.stringify({
          ok: result.ok,
          checks: result.results.checks.length,
          alerts: result.results.alerts.length,
        }),
      );
      return result.exit;
    }
    case "maintenance": {
      const state = process.argv[3];
      const active = maintenance(state);
      console.log(JSON.stringify({ maintenance: active }));
      return 0;
    }
    case "fault": {
      const state = process.argv[3];
      const active = fault(state);
      console.log(JSON.stringify({ fault: active }));
      return 0;
    }
    case "verify": {
      const report = await verifyBattery();
      console.log(JSON.stringify({ ok: report.ok, failed: report.failed }));
      return report.ok ? 0 : 1;
    }
    default:
      console.log(
        "usage: staging.mjs secrets-init|secrets-check|provision|boot-check|deploy|rollback|serve|stop|status|wp|cron|monitor|maintenance|fault|verify",
      );
      return 2;
  }
}

process.exit(await main());

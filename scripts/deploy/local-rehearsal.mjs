#!/usr/bin/env node
/**
 * Local deploy / backup / restore / rollback rehearsal driver.
 *
 * Subcommands:
 *   run    Execute the full rehearsal against a disposable local Playground
 *          environment and write a JSON report with real exit codes.
 *   stop   Stop a rehearsal server previously started by `run`.
 *
 * `run` is mutating, so it refuses to start unless --confirm-local-rehearsal
 * is passed, --env-dir resolves inside the system temp directory, and --port
 * is a loopback port. It never touches a remote host, DNS, or production
 * credentials: those prerequisites are recorded as BLOCKED in the report and
 * in docs/operations/release-runbook-index.md, not faked.
 *
 * What `run` rehearses end-to-end on the local target:
 *   prepare   provision a persistent docroot (WordPress core, platform
 *             plugins, fixture seeds/media) under <env-dir>/wordpress
 *   release   build a versioned release directory under <env-dir>/releases
 *             (a real git repository, so rollback is a real `git revert`)
 *   deploy    rsync the release into the docroot and record the deployed
 *             file manifest (artifact-based deploy per the runbook)
 *   serve     boot wp-playground-cli on --port with the docroot mounted
 *             before install (persistent SQLite database)
 *   verify    HTTP journeys: REST index, locale root, release manifest,
 *             seeded offering
 *   backup    consistent SQLite snapshot (VACUUM INTO), uploads tar archive,
 *             config snapshot, per-file SHA-256 manifest, content manifest
 *             (offering relationships read through the probe endpoint)
 *   deploy-r2 commit and deploy a second release carrying a marker mu-plugin
 *   mutate    real REST writes (marker post, offering meta edit) plus a
 *             deleted upload file
 *   restore   stop, restore database + uploads from the backup, restart,
 *             verify content manifest, file hashes and SQLite integrity
 *   rollback  `git revert` the R2 commit, redeploy, verify the prior served
 *             version and that additive plugin schema survived
 */

import { spawn, spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  cpSync,
  existsSync,
  mkdirSync,
  openSync,
  readdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { createRequire } from "node:module";
import { tmpdir } from "node:os";
import { dirname, join, resolve, sep } from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";

const REPO_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const CONFIRMATION_FLAG = "confirm-local-rehearsal";
const SQLITE_DB_RELATIVE = "wp-content/database/.ht.sqlite";
const DEPLOYED_MANIFEST = ".lps-deployed-files";
const RELEASE_MARKER_MU = "lps-t23-release-marker.php";
const PROBE_MU = "lps-t23-rehearsal-probe.php";
const PROBE_NAMESPACE = "lps-t23/v1";
const RELEASE_HEADER = "x-lps-rehearsal-release";

/** Fixture mu-plugins copied into the rehearsal docroot (local seeding only). */
const SEED_MU_PLUGINS = [
  "lps-development-bootstrap.php",
  "lps-people-seed.php",
  "lps-discovery-seed.php",
  "lps-search-seed.php",
  "lps-trust-seed.php",
  "lps-seo-seed.php",
  "lps-a11y-seed.php",
  "lps-teaching-seed.php",
  "lps-homepage-seed.php",
  "lps-teaching-storage.php",
];

/** Platform plugins provisioned into the docroot (not release artifacts). */
const PLATFORM_PLUGINS = ["polylang", "two-factor"];

/**
 * Plugin-owned tables that must survive an additive-schema rollback.
 *
 * These are the exact names `Migrations::table_names()` and
 * `Audit::schema_sql()` create; `Migrations::rollback_sql()` would drop them,
 * which is the destructive downgrade this rehearsal must prove it does not run.
 */
const ADDITIVE_TABLE_MARKERS = [
  "lps_relationships",
  "lps_authorships",
  "lps_publication_dois",
  "lps_audit_log",
];

/**
 * Parses `command --key=value --flag` arguments.
 *
 * @param {string[]} argv Raw arguments without the node executable.
 * @returns {{command: string, flags: Record<string, string|boolean>}}
 */
export function parseCliArgs(argv) {
  const flags = {};
  let command = "";
  for (const argument of argv) {
    if (!argument.startsWith("--")) {
      if (command === "") command = argument;
      continue;
    }
    const [key, ...rest] = argument.slice(2).split("=");
    flags[key] = rest.length === 0 ? true : rest.join("=");
  }
  return { command, flags };
}

/**
 * Decides whether the local rehearsal may run.
 *
 * The rehearsal is local-only by construction: the env dir must live under
 * the system temp directory and the port must be a non-privileged loopback
 * port. There is no code path that accepts a remote host.
 *
 * @param {{flags: Record<string, string|boolean>, envDir: string, port: number}} input Execution context.
 * @returns {{allowed: boolean, reason: string}}
 */
export function assertLocalRehearsalAllowed(input) {
  const confirmed = input?.flags?.[CONFIRMATION_FLAG] === true;
  if (!confirmed) {
    return {
      allowed: false,
      reason: `Pass --${CONFIRMATION_FLAG} to acknowledge that the rehearsal mutates the local target directory.`,
    };
  }
  const envDir = resolve(String(input?.envDir ?? ""));
  const tempRoot = resolve(tmpdir());
  if (envDir === tempRoot || !envDir.startsWith(`${tempRoot}${sep}`)) {
    return {
      allowed: false,
      reason: `--env-dir must resolve inside ${tempRoot}; got ${envDir}.`,
    };
  }
  const port = Number(input?.port);
  if (!Number.isInteger(port) || port < 1024 || port > 65535) {
    return {
      allowed: false,
      reason: `--port must be an integer between 1024 and 65535; got ${input?.port}.`,
    };
  }
  return { allowed: true, reason: "" };
}

/**
 * Maps a rehearsal report to its exit code.
 *
 * @param {object} report Report produced by runRehearsal.
 * @returns {number} Process exit code.
 */
export function rehearsalExitCode(report) {
  return report?.status === "pass" ? 0 : 1;
}

function sha256File(path) {
  return createHash("sha256").update(readFileSync(path)).digest("hex");
}

function sha256Text(value) {
  return createHash("sha256").update(value, "utf8").digest("hex");
}

function walkFiles(root) {
  const out = [];
  if (!existsSync(root)) return out;
  const stack = [root];
  while (stack.length > 0) {
    const dir = stack.pop();
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = join(dir, entry.name);
      if (entry.isDirectory()) stack.push(full);
      else if (entry.isFile()) out.push(full);
    }
  }
  return out.sort();
}

function run(cmd, args, options = {}) {
  const result = spawnSync(cmd, args, { encoding: "utf8", ...options });
  return {
    cmd: [cmd, ...args].join(" "),
    exit: result.status ?? 1,
    stdout: (result.stdout ?? "").trim(),
    stderr: (result.stderr ?? "").trim(),
    error: result.error ? String(result.error) : null,
  };
}

async function http(url, options = {}) {
  const response = await fetch(url, { redirect: "manual", ...options });
  const body = await response.text();
  return {
    status: response.status,
    headers: response.headers,
    body,
    setCookies: response.headers.getSetCookie?.() ?? [],
  };
}

async function waitForServer(baseUrl, timeoutMs = 120_000) {
  const deadline = Date.now() + timeoutMs;
  let lastError = "no attempt";
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${baseUrl}/wp-json/`, {
        headers: { cookie: "playground_auto_login_already_happened=1" },
      });
      if (response.status === 200) return { ready: true };
      lastError = `status ${response.status}`;
    } catch (error) {
      lastError = String(error);
    }
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 500));
  }
  return { ready: false, error: lastError };
}

const EXPECTED_SEED_VERSIONS = {
  people: "10",
  teaching: "2",
  homepage: "5",
  discovery: "2",
  search: "17",
  trust: "7",
  a11y: "4",
};

/**
 * Polls the readiness endpoint until every fixture seed reports complete.
 *
 * Each poll is a real request that lets the people-seed queue drain one step;
 * the loop is sequential so seed writes never overlap.
 */
async function waitForSeeds(baseUrl, timeoutMs = 240_000) {
  const deadline = Date.now() + timeoutMs;
  let requests = 0;
  let versions = {};
  let lastError = "no attempt";
  while (Date.now() < deadline) {
    requests += 1;
    try {
      const response = await http(`${baseUrl}/wp-json/${PROBE_NAMESPACE}/readiness`, {
        headers: { cookie: "playground_auto_login_already_happened=1" },
      });
      if (response.status === 200) {
        const body = JSON.parse(response.body);
        versions = body.seed_versions ?? {};
        const queueEmpty = (body.queue_remaining ?? 1) === 0;
        const versionsDone = Object.entries(EXPECTED_SEED_VERSIONS).every(
          ([key, expected]) => String(versions[key] ?? "") === expected,
        );
        if (queueEmpty && versionsDone) return { done: true, requests, versions };
        lastError = `queue=${body.queue_remaining} versions=${JSON.stringify(versions)}`;
      } else {
        lastError = `status ${response.status}`;
      }
    } catch (error) {
      lastError = String(error);
    }
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 300));
  }
  return { done: false, requests, versions, error: lastError };
}

async function waitForExit(pid, timeoutMs = 15_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      process.kill(pid, 0);
    } catch {
      return true;
    }
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 200));
  }
  return false;
}

/**
 * Waits until nothing answers on the port, so a just-stopped server cannot
 * collide with the next bind.
 */
async function waitForPortFree(port, timeoutMs = 20_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      await fetch(`http://localhost:${port}/`, {
        signal: AbortSignal.timeout(1_000),
      });
    } catch {
      return true;
    }
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 250));
  }
  return false;
}

function git(repoDir, args) {
  return run("git", ["-C", repoDir, ...args]);
}

function blueprintFor(port) {
  return {
    $schema: "https://playground.wordpress.net/blueprint-schema.json",
    landingPage: "/pt-br/",
    preferredVersions: { php: "8.3", wp: "latest" },
    steps: [
      { step: "login", username: "admin", password: "password" },
      {
        step: "activatePlugin",
        pluginPath: "/wordpress/wp-content/plugins/lps-content-model",
      },
      { step: "activatePlugin", pluginPath: "/wordpress/wp-content/plugins/polylang" },
      { step: "activatePlugin", pluginPath: "/wordpress/wp-content/plugins/two-factor" },
      { step: "activateTheme", themeFolderName: "lps-theme" },
      {
        step: "runPHP",
        code:
          "<?php require '/wordpress/wp-load.php'; " +
          // The content model strips privileged caps from MFA-unenrolled
          // privileged roles; both rehearsal users enroll in the dummy
          // provider so the rehearsal exercises the real authenticated
          // boundary, including the Two-Factor challenge.
          "if ( ! username_exists( 'lps-t23-operator' ) ) { " +
          "$id = wp_create_user( 'lps-t23-operator', 'lps-t23-operator-pass', 'lps-t23-operator@example.org' ); " +
          "if ( ! is_wp_error( $id ) ) { ( new WP_User( $id ) )->set_role( 'administrator' ); " +
          "update_user_meta( $id, '_two_factor_enabled_providers', array( 'Two_Factor_Dummy' ) ); " +
          "update_user_meta( $id, '_two_factor_provider', 'Two_Factor_Dummy' ); } } " +
          // Offering writes are denied to plain administrators; the publisher
          // role is the least-privilege role the content model authorizes.
          "if ( ! username_exists( 'lps-t23-publisher' ) ) { " +
          "$pid = wp_create_user( 'lps-t23-publisher', 'lps-t23-publisher-pass', 'lps-t23-publisher@example.org' ); " +
          "if ( ! is_wp_error( $pid ) ) { ( new WP_User( $pid ) )->set_role( 'lps_publisher' ); " +
          "update_user_meta( $pid, '_two_factor_enabled_providers', array( 'Two_Factor_Dummy' ) ); " +
          "update_user_meta( $pid, '_two_factor_provider', 'Two_Factor_Dummy' ); } }",
      },
      {
        step: "defineWpConfigConsts",
        consts: {
          FS_METHOD: "direct",
          WP_DEBUG: true,
          WP_DEBUG_LOG: true,
          WP_ENVIRONMENT_TYPE: "staging",
          WP_TESTS_TITLE: "LPS t23 rehearsal",
          WP_SITEURL: `http://localhost:${port}`,
          WP_HOME: `http://localhost:${port}`,
          WP_MEMORY_LIMIT: "512M",
          WP_MAX_MEMORY_LIMIT: "512M",
        },
      },
    ],
  };
}

function probeMuPlugin() {
  return `<?php
/**
 * Plugin Name: LPS t23 rehearsal probe (local rehearsal fixture only)
 * Description: Preloads the content model before seed fixtures run and exposes
 *              a read-only manifest endpoint used to verify restore integrity.
 *              Never shipped: this file lives only in the disposable env dir.
 *
 * @package LPS\\Evidence
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\\\LPS\\\\ContentModel\\\\Translations' ) ) {
	$lps_plugin_entry = WP_CONTENT_DIR . '/plugins/lps-content-model/lps-content-model.php';
	if ( is_readable( $lps_plugin_entry ) ) {
		require_once $lps_plugin_entry;
	}
}

add_action(
	'init',
	static function (): void {
		if ( class_exists( '\\\\LPS\\\\ContentModel\\\\Plugin' ) ) {
			\\LPS\\ContentModel\\Plugin::migrate();
		}
	},
	0
);

add_action(
	'rest_api_init',
	static function (): void {
		// Unauthenticated readiness probe: reports only seed-queue depth and
		// seed version options of this disposable fixture environment, so the
		// driver can wait for seeding to finish before mutating anything.
		register_rest_route(
			'${PROBE_NAMESPACE}',
			'/readiness',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function (): array {
					$queue = get_option( 'lps_people_seed_queue', array() );
					return array(
						'queue_remaining' => is_array( $queue ) ? count( $queue ) : 0,
						'seed_versions'   => array(
							'people'    => get_option( 'lps_people_seed_version', '' ),
							'teaching'  => get_option( 'lps_teaching_seed_version', '' ),
							'homepage'  => get_option( 'lps_home_seed_version', '' ),
							'discovery' => get_option( 'lps_discovery_seed_version', '' ),
							'search'    => get_option( 'lps_search_seed_version', '' ),
							'trust'     => get_option( 'lps_trust_seed_version', '' ),
							'a11y'      => get_option( 'lps_a11y_seed_version', '' ),
							'seo'       => get_option( 'lps_seo_seed_version', '' ),
						),
					);
				},
			)
		);
		register_rest_route(
			'${PROBE_NAMESPACE}',
			'/manifest',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function (): array {
					$offerings = array();
					foreach ( get_posts( array( 'post_type' => 'lps_offering', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'ID', 'order' => 'ASC' ) ) as $post ) {
						$team = array();
						if ( class_exists( '\\\\LPS\\\\ContentModel\\\\Relationships' ) ) {
							foreach ( \\LPS\\ContentModel\\Relationships::for_source( $post->ID, 'teaching_team' ) as $member ) {
								$team[] = array(
									'target_post_id' => (int) ( $member['target_post_id'] ?? 0 ),
									'role'           => (string) ( $member['relationship_role'] ?? '' ),
								);
							}
						}
						$course_id = 0;
						$term_id   = 0;
						if ( class_exists( '\\\\LPS\\\\ContentModel\\\\Relationships' ) ) {
							$course_rows = \\LPS\\ContentModel\\Relationships::for_source( $post->ID, 'offering_course' );
							$term_rows   = \\LPS\\ContentModel\\Relationships::for_source( $post->ID, 'offering_term' );
							$course_id   = (int) ( $course_rows[0]['target_post_id'] ?? 0 );
							$term_id     = (int) ( $term_rows[0]['target_post_id'] ?? 0 );
						}
						$offerings[] = array(
							'id'        => $post->ID,
							'slug'      => $post->post_name,
							'status'    => $post->post_status,
							'course_id' => $course_id,
							'term_id'   => $term_id,
							'venue'     => (string) get_post_meta( $post->ID, '_lps_venue', true ),
							'team'      => $team,
						);
					}
					global $wpdb;
					$tables = array();
					foreach ( array( 'lps_relationships', 'lps_authorships', 'lps_publication_dois', 'lps_audit_log' ) as $marker ) {
						$wpdb->last_error = '';
						$wpdb->suppress_errors( true );
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rehearsal probe reads table existence only.
						$wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . $marker );
						$wpdb->suppress_errors( false );
						$tables[ $marker ] = '' === $wpdb->last_error;
					}
					return array(
						'offerings'   => $offerings,
						'tables'      => $tables,
						'post_counts' => array(
							'lps_offering' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'lps_offering' ) ),
							'lps_course'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'lps_course' ) ),
							'lps_person'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'lps_person' ) ),
						),
					);
				},
			)
		);
	}
);
`;
}

function releaseMarkerMuPlugin(releaseId) {
  return `<?php
/**
 * Plugin Name: LPS t23 release marker (${releaseId})
 * Description: Sends the deployed release id as a response header so the
 *              rehearsal can verify which release is serving. Release artifact.
 */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action(
	'send_headers',
	static function (): void {
		header( 'X-LPS-Rehearsal-Release: ${releaseId}' );
	}
);
// REST responses bypass send_headers; mark them through the dispatch filter.
add_filter(
	'rest_post_dispatch',
	static function ( $response ) {
		if ( $response instanceof \\WP_REST_Response ) {
			$response->header( 'X-LPS-Rehearsal-Release', '${releaseId}' );
		}
		return $response;
	}
);
`;
}

function copyDir(source, destination) {
  mkdirSync(dirname(destination), { recursive: true });
  cpSync(source, destination, { recursive: true });
}

function findWordPressCore() {
  const candidates = [
    join(REPO_ROOT, ".cache/wordpress"),
    join(REPO_ROOT, "../site-lps/.cache/wordpress"),
  ];
  for (const candidate of candidates) {
    if (existsSync(join(candidate, "wp-load.php"))) return candidate;
  }
  return null;
}

function findPlatformPlugin(slug) {
  const candidates = [
    join(REPO_ROOT, ".cache/wp-plugins", slug),
    join(REPO_ROOT, "../site-lps/.cache/wp-plugins", slug),
  ];
  for (const candidate of candidates) {
    if (existsSync(candidate)) return candidate;
  }
  return null;
}

function recordStep(report, id, detail) {
  report.steps.push({ id, at: new Date().toISOString(), ...detail });
  const step = report.steps.at(-1);
  if (step.exit !== undefined && step.exit !== 0) {
    report.status = "fail";
  }
  return step;
}

/**
 * Deploys one release directory into the docroot.
 *
 * The previous deploy's file manifest is read first: files that the new
 * release no longer ships are removed, then the release tree is rsynced in.
 * That is the artifact-based rollback contract — no destructive downgrade of
 * the database, only the deployed file set changes. rsync never runs with
 * --delete at docroot level: the docroot also holds WordPress core, uploads
 * and the database, which are not release artifacts.
 */
function deployRelease(envDir, releaseDir) {
  const docroot = join(envDir, "wordpress");
  const manifestPath = join(envDir, DEPLOYED_MANIFEST);
  const previous = existsSync(manifestPath)
    ? readFileSync(manifestPath, "utf8").split("\n").filter(Boolean)
    : [];
  const removed = [];
  for (const relative of previous) {
    const target = join(docroot, relative);
    if (existsSync(target) && !existsSync(join(releaseDir, relative))) {
      rmSync(target);
      removed.push(relative);
    }
  }
  const sync = run("rsync", ["-a", `${releaseDir}/`, `${docroot}/`]);
  if (sync.exit !== 0) return { ...sync, removed };
  const files = walkFiles(releaseDir).map((file) => file.slice(releaseDir.length + 1));
  writeFileSync(manifestPath, `${files.join("\n")}\n`, "utf8");
  return { ...sync, removed, deployedFiles: files.length };
}

function serverPidFile(envDir) {
  return join(envDir, "server.pid");
}

async function stopServer(envDir) {
  const pidFile = serverPidFile(envDir);
  if (!existsSync(pidFile)) return { stopped: false, reason: "no pid file" };
  const pid = Number(readFileSync(pidFile, "utf8").trim());
  try {
    process.kill(-pid, "SIGTERM");
  } catch {
    try {
      process.kill(pid, "SIGTERM");
    } catch {
      rmSync(pidFile, { force: true });
      return { stopped: false, reason: "process not running" };
    }
  }
  const exited = await waitForExit(pid);
  if (!exited) {
    try {
      process.kill(-pid, "SIGKILL");
    } catch {
      try {
        process.kill(pid, "SIGKILL");
      } catch {
        // already gone
      }
    }
    await waitForExit(pid, 5_000);
  }
  rmSync(pidFile, { force: true });
  return { stopped: true, pid };
}

/**
 * Boots the Playground server and waits for readiness, retrying once when the
 * process exits during boot (e.g. the port was still draining from a previous
 * server on the same port).
 */
async function startServerAndWait(envDir, port, baseUrl) {
  for (let attempt = 1; attempt <= 2; attempt += 1) {
    const server = startServer(envDir, port);
    const ready = await waitForServer(baseUrl);
    if (ready.ready) return { server, ready, attempts: attempt };
    await stopServer(envDir);
    await waitForPortFree(port);
  }
  return {
    server: startServer(envDir, port),
    ready: { ready: false, error: "boot retry exhausted" },
    attempts: 2,
  };
}

function startServer(envDir, port) {
  const require = createRequire(import.meta.url);
  const cliPackageJson = require.resolve("@wp-playground/cli/package.json");
  const cliEntry = join(dirname(cliPackageJson), "wp-playground.js");
  const logStream = join(envDir, "server.log");
  const fd = openSync(logStream, "a");
  const child = spawn(
    process.execPath,
    [
      cliEntry,
      "server",
      "--port",
      String(port),
      "--site-url",
      `http://localhost:${port}`,
      "--php",
      "8.3",
      // A single worker serializes requests: the fixture seeds write on every
      // init until their version options land, and concurrent workers race
      // those writes on SQLite. One worker keeps the rehearsal deterministic.
      "--workers",
      "1",
      "--blueprint",
      join(envDir, "blueprint.json"),
      "--login",
      "--mount-dir-before-install",
      join(envDir, "wordpress"),
      "/wordpress",
      "--wordpress-install-mode",
      "install-from-existing-files-if-needed",
    ],
    { detached: true, stdio: ["ignore", fd, fd] },
  );
  child.unref();
  writeFileSync(serverPidFile(envDir), `${child.pid}\n`, "utf8");
  return { pid: child.pid, log: logStream };
}

async function loginAs(baseUrl, user, password) {
  // WordPress refuses credential logins that do not echo the test cookie it
  // sets on the login form, so fetch the form first and carry its cookies.
  const form = await http(`${baseUrl}/wp-login.php`, {
    headers: { cookie: "playground_auto_login_already_happened=1" },
  });
  const formCookies = form.setCookies
    .map((cookie) => cookie.split(";")[0])
    .filter((cookie) => cookie.includes("="))
    .join("; ");
  const response = await http(`${baseUrl}/wp-login.php`, {
    method: "POST",
    headers: {
      "content-type": "application/x-www-form-urlencoded",
      cookie: `playground_auto_login_already_happened=1; ${formCookies}`,
    },
    body: new URLSearchParams({
      log: user,
      pwd: password,
      "wp-submit": "Log In",
      redirect_to: "/wp-admin/",
      testcookie: "1",
    }).toString(),
  });
  const collect = (res) =>
    res.setCookies
      .map((cookie) => cookie.split(";")[0])
      .filter((cookie) => cookie.includes("="))
      .join("; ");
  let cookies = [formCookies, collect(response)].filter(Boolean).join("; ");
  let status = response.status;

  // The operator is enrolled in the dummy Two-Factor provider, so a correct
  // password answers with the challenge form (200), not the final redirect.
  if (status === 200 && response.body.includes("wp-auth-nonce")) {
    const field = (name) => {
      const match = response.body.match(new RegExp(`name="${name}"[^>]*value="([^"]*)"`));
      return match ? match[1] : "";
    };
    const challenge = await http(`${baseUrl}/wp-login.php?action=validate_2fa`, {
      method: "POST",
      headers: {
        "content-type": "application/x-www-form-urlencoded",
        cookie: `playground_auto_login_already_happened=1; ${cookies}`,
      },
      body: new URLSearchParams({
        provider: field("provider"),
        "wp-auth-id": field("wp-auth-id"),
        "wp-auth-nonce": field("wp-auth-nonce"),
        redirect_to: field("redirect_to") || "/wp-admin/",
        rememberme: "0",
        submit: "Yup.",
      }).toString(),
    });
    status = challenge.status;
    cookies = [cookies, collect(challenge)].filter(Boolean).join("; ");
  }
  return { status, cookies };
}

async function restNonce(baseUrl, cookies) {
  const response = await http(`${baseUrl}/wp-admin/admin-ajax.php?action=rest-nonce`, {
    headers: { cookie: `playground_auto_login_already_happened=1; ${cookies}` },
  });
  return response.body.trim();
}

async function fetchManifest(baseUrl, cookies, nonce) {
  const response = await http(`${baseUrl}/wp-json/${PROBE_NAMESPACE}/manifest`, {
    headers: {
      cookie: `playground_auto_login_already_happened=1; ${cookies}`,
      "x-wp-nonce": nonce,
    },
  });
  if (response.status !== 200) {
    return { status: response.status, manifest: null };
  }
  return { status: response.status, manifest: JSON.parse(response.body) };
}

function normalizeManifest(manifest) {
  return {
    offerings: (manifest?.offerings ?? []).map((offering) => ({
      slug: offering.slug,
      status: offering.status,
      venue: offering.venue,
      course_id: offering.course_id,
      term_id: offering.term_id,
      team: (offering.team ?? []).map((member) => `${member.role}:${member.target_post_id}`).sort(),
    })),
    post_counts: manifest?.post_counts ?? {},
  };
}

function backupEnvironment(envDir, backupDir, contentManifest) {
  mkdirSync(backupDir, { recursive: true });
  const docroot = join(envDir, "wordpress");
  const dbSource = join(docroot, SQLITE_DB_RELATIVE);
  const dbTarget = join(backupDir, "database.sqlite");
  rmSync(dbTarget, { force: true });
  const vacuum = run(
    process.execPath,
    [
      "-e",
      "const {DatabaseSync}=require('node:sqlite');" +
        "const db=new DatabaseSync(process.env.LPS_DB_SOURCE);" +
        "const q=process.env.LPS_DB_TARGET.replace(/'/g,\"''\");" +
        'db.exec("VACUUM INTO \'" + q + "\'");' +
        "db.close();",
    ],
    { env: { ...process.env, LPS_DB_SOURCE: dbSource, LPS_DB_TARGET: dbTarget } },
  );
  if (vacuum.exit !== 0) return { exit: vacuum.exit, error: vacuum.stderr };

  const filesTar = join(backupDir, "files.tar");
  const tar = run("tar", ["-cf", filesTar, "-C", join(docroot, "wp-content"), "uploads"]);
  if (tar.exit !== 0) return { exit: tar.exit, error: tar.stderr };

  const uploads = {};
  for (const file of walkFiles(join(docroot, "wp-content/uploads"))) {
    uploads[file.slice(docroot.length + 1)] = sha256File(file);
  }
  const config = {
    "wp-config.php": existsSync(join(docroot, "wp-config.php"))
      ? sha256File(join(docroot, "wp-config.php"))
      : null,
    "release-manifest.json": existsSync(join(docroot, "release-manifest.json"))
      ? sha256File(join(docroot, "release-manifest.json"))
      : null,
  };
  const manifest = {
    createdAt: new Date().toISOString(),
    database: { path: "database.sqlite", sha256: sha256File(dbTarget) },
    files: { path: "files.tar", sha256: sha256File(filesTar), entries: uploads },
    config,
    content: {
      digest: sha256Text(JSON.stringify(normalizeManifest(contentManifest))),
      manifest: normalizeManifest(contentManifest),
    },
  };
  writeFileSync(join(backupDir, "manifest.json"), `${JSON.stringify(manifest, null, 2)}\n`);
  return { exit: 0, manifest };
}

function restoreEnvironment(envDir, backupDir) {
  const docroot = join(envDir, "wordpress");
  const dbTarget = join(docroot, SQLITE_DB_RELATIVE);
  const dbSource = join(backupDir, "database.sqlite");
  cpSync(dbSource, dbTarget);
  for (const suffix of ["-wal", "-shm", "-journal"]) {
    rmSync(`${dbTarget}${suffix}`, { force: true });
  }
  const uploadsDir = join(docroot, "wp-content/uploads");
  rmSync(uploadsDir, { recursive: true, force: true });
  mkdirSync(uploadsDir, { recursive: true });
  return run("tar", ["-xf", join(backupDir, "files.tar"), "-C", join(docroot, "wp-content")]);
}

function sqliteIntegrity(dbPath) {
  return run(
    process.execPath,
    [
      "-e",
      "const {DatabaseSync}=require('node:sqlite');" +
        "const db=new DatabaseSync(process.env.LPS_DB_PATH,{readOnly:true});" +
        "const rows=db.prepare('PRAGMA integrity_check').all();" +
        "db.close();process.stdout.write(JSON.stringify(rows));",
    ],
    { env: { ...process.env, LPS_DB_PATH: dbPath } },
  );
}

async function runRehearsal(flags) {
  const envDir = resolve(String(flags["env-dir"]));
  const port = Number(flags.port);
  const distDir = resolve(String(flags.dist ?? join(REPO_ROOT, "dist")));
  const reportPath = String(flags.report ?? join(envDir, "rehearsal-report.json"));
  const baseUrl = `http://localhost:${port}`;
  const report = {
    suite: "task-23 local staging rehearsal",
    classification:
      "local rehearsal on a disposable WordPress Playground target; not a hosted staging verification",
    startedAt: new Date().toISOString(),
    envDir,
    port,
    baseUrl,
    status: "pass",
    steps: [],
    blockers: [
      "staging-host: no institution-managed staging host is provisioned; hosted deploy/restore checks stay BLOCKED",
      "dns-control: no DNS/TLS zone access; cutover apply/rollback stay BLOCKED",
      "encryption-recipient: no institutional age recipient key; encrypted backup artifact stays BLOCKED",
      "rpo-rto-approval: no owner-approved recovery objectives; scored rollback stays BLOCKED",
      "owner-assignees: deployer/administrator/publisher roles have no named assignees",
    ],
  };

  const finish = (code) => {
    report.finishedAt = new Date().toISOString();
    mkdirSync(dirname(reportPath), { recursive: true });
    writeFileSync(reportPath, `${JSON.stringify(report, null, 2)}\n`, "utf8");
    return code;
  };

  // -- preflight -----------------------------------------------------------
  const permission = assertLocalRehearsalAllowed({ flags, envDir, port });
  if (!permission.allowed) {
    process.stderr.write(`${permission.reason}\n`);
    recordStep(report, "preflight", { exit: 2, error: permission.reason });
    return finish(2);
  }
  const coreDir = findWordPressCore();
  const missingPlugins = PLATFORM_PLUGINS.filter((slug) => findPlatformPlugin(slug) === null);
  const distArtifacts = ["lps-content-model", "lps-theme", "mu-plugins"].map((name) =>
    join(distDir, name),
  );
  const missingDist = distArtifacts.filter((path) => !existsSync(path));
  if (coreDir === null || missingPlugins.length > 0 || missingDist.length > 0) {
    recordStep(report, "preflight", {
      exit: 1,
      error: "missing prerequisites",
      coreDir,
      missingPlugins,
      missingDist,
    });
    return finish(1);
  }
  recordStep(report, "preflight", { exit: 0, coreDir, distDir });

  // -- prepare -------------------------------------------------------------
  if (existsSync(envDir)) {
    await stopServer(envDir);
    await waitForPortFree(port);
    rmSync(envDir, { recursive: true, force: true });
  }
  const docroot = join(envDir, "wordpress");
  mkdirSync(docroot, { recursive: true });
  copyDir(coreDir, docroot);
  for (const slug of PLATFORM_PLUGINS) {
    copyDir(findPlatformPlugin(slug), join(docroot, "wp-content/plugins", slug));
  }
  for (const seed of SEED_MU_PLUGINS) {
    copyDir(
      join(REPO_ROOT, "tests/fixtures/wp", seed),
      join(docroot, "wp-content/mu-plugins", seed),
    );
  }
  copyDir(
    join(REPO_ROOT, "wp-content/mu-plugins/lps-security.php"),
    join(docroot, "wp-content/mu-plugins/lps-security.php"),
  );
  writeFileSync(join(docroot, "wp-content/mu-plugins", PROBE_MU), probeMuPlugin(), "utf8");
  copyDir(
    join(REPO_ROOT, "tests/fixtures/media/assets"),
    join(docroot, "wp-content/uploads/lps-qa-media"),
  );
  writeFileSync(join(envDir, "blueprint.json"), `${JSON.stringify(blueprintFor(port), null, 2)}\n`);
  recordStep(report, "prepare", { exit: 0, docroot });

  // -- release R1 ----------------------------------------------------------
  const releasesDir = join(envDir, "releases");
  mkdirSync(releasesDir, { recursive: true });
  git(releasesDir, ["init", "-q"]);
  git(releasesDir, ["config", "user.email", "rehearsal@localhost"]);
  git(releasesDir, ["config", "user.name", "lps-t23 rehearsal"]);
  const r1Dir = join(releasesDir, "r1");
  copyDir(join(distDir, "lps-content-model"), join(r1Dir, "wp-content/plugins/lps-content-model"));
  copyDir(join(distDir, "lps-theme"), join(r1Dir, "wp-content/themes/lps-theme"));
  copyDir(join(distDir, "mu-plugins"), join(r1Dir, "wp-content/mu-plugins"));
  const r1Manifest = {
    releaseId: "r1",
    builtFrom: git(REPO_ROOT, ["rev-parse", "HEAD"]).stdout,
    builtAt: new Date().toISOString(),
  };
  writeFileSync(join(r1Dir, "release-manifest.json"), `${JSON.stringify(r1Manifest, null, 2)}\n`);
  writeFileSync(join(releasesDir, "CURRENT"), "r1\n", "utf8");
  git(releasesDir, ["add", "-A"]);
  const r1Commit = git(releasesDir, ["commit", "-q", "-m", "release r1"]);
  const r1Rev = git(releasesDir, ["rev-parse", "HEAD"]).stdout;
  recordStep(report, "release-r1", {
    exit: r1Commit.exit,
    commit: r1Rev,
    stderr: r1Commit.stderr || undefined,
  });
  if (r1Commit.exit !== 0) return finish(1);

  // -- deploy R1 + serve ---------------------------------------------------
  const deployR1 = deployRelease(envDir, r1Dir);
  recordStep(report, "deploy-r1", {
    exit: deployR1.exit,
    deployedFiles: deployR1.deployedFiles,
    stderr: deployR1.stderr || undefined,
  });
  if (deployR1.exit !== 0) return finish(1);

  const { server, ready } = await startServerAndWait(envDir, port, baseUrl);
  recordStep(report, "serve", { exit: ready.ready ? 0 : 1, pid: server.pid, error: ready.error });
  if (!ready.ready) return finish(1);

  // Warm-up: the fixture seeds drain a queue one step per request. Poll the
  // readiness endpoint sequentially (each poll is itself a request that
  // advances the queue) until seeding is complete, so later mutations never
  // collide with in-flight seed writes.
  const warmup = await waitForSeeds(baseUrl);
  recordStep(report, "warmup", {
    exit: warmup.done ? 0 : 1,
    requests: warmup.requests,
    seedVersions: warmup.versions,
    error: warmup.error,
  });
  if (!warmup.done) return finish(1);

  const login = await loginAs(baseUrl, "lps-t23-operator", "lps-t23-operator-pass");
  const nonce = await restNonce(baseUrl, login.cookies);
  recordStep(report, "login", {
    exit: login.status === 302 && nonce.length > 0 ? 0 : 1,
    loginStatus: login.status,
    nonceObtained: nonce.length > 0,
  });
  if (login.status !== 302 || nonce.length === 0) return finish(1);
  const authHeaders = {
    cookie: `playground_auto_login_already_happened=1; ${login.cookies}`,
    "x-wp-nonce": nonce,
  };

  // -- verify R1 journeys ---------------------------------------------------
  const journeys = {};
  for (const [name, path] of Object.entries({
    restIndex: "/wp-json/",
    localeRoot: "/pt-br/",
    releaseManifest: "/release-manifest.json",
    offerings: "/wp-json/wp/v2/offerings?per_page=100",
  })) {
    const response = await http(`${baseUrl}${path}`, {
      headers: { cookie: "playground_auto_login_already_happened=1" },
    });
    journeys[name] = { status: response.status };
  }
  const manifestBody = await http(`${baseUrl}/release-manifest.json`, {
    headers: { cookie: "playground_auto_login_already_happened=1" },
  });
  const servedRelease =
    manifestBody.status === 200 ? JSON.parse(manifestBody.body).releaseId : null;
  const offeringsBody = await http(`${baseUrl}/wp-json/wp/v2/offerings?per_page=100`, {
    headers: { cookie: "playground_auto_login_already_happened=1" },
  });
  const offeringCount = offeringsBody.status === 200 ? JSON.parse(offeringsBody.body).length : 0;
  const verifyR1 =
    journeys.restIndex.status === 200 &&
    journeys.localeRoot.status === 200 &&
    servedRelease === "r1" &&
    offeringCount > 0;
  recordStep(report, "verify-r1", {
    exit: verifyR1 ? 0 : 1,
    journeys,
    servedRelease,
    offeringCount,
  });
  if (!verifyR1) return finish(1);

  // -- backup ---------------------------------------------------------------
  const preBackup = await fetchManifest(baseUrl, login.cookies, nonce);
  if (preBackup.status !== 200) {
    recordStep(report, "backup", { exit: 1, error: "content manifest fetch failed" });
    return finish(1);
  }
  const backupDir = join(envDir, "backups/b1");
  const backup = backupEnvironment(envDir, backupDir, preBackup.manifest);
  recordStep(report, "backup", {
    exit: backup.exit,
    backupDir,
    manifestDigest: backup.manifest?.content?.digest,
    fileCount: Object.keys(backup.manifest?.files?.entries ?? {}).length,
    error: backup.error,
  });
  if (backup.exit !== 0) return finish(1);

  // -- release R2 + deploy --------------------------------------------------
  const r2Dir = join(releasesDir, "r2");
  copyDir(r1Dir, r2Dir);
  writeFileSync(
    join(r2Dir, "wp-content/mu-plugins", RELEASE_MARKER_MU),
    releaseMarkerMuPlugin("r2"),
    "utf8",
  );
  writeFileSync(
    join(r2Dir, "release-manifest.json"),
    `${JSON.stringify({ ...r1Manifest, releaseId: "r2" }, null, 2)}\n`,
  );
  rmSync(join(releasesDir, "r1"), { recursive: true, force: true });
  writeFileSync(join(releasesDir, "CURRENT"), "r2\n", "utf8");
  git(releasesDir, ["add", "-A"]);
  const r2Commit = git(releasesDir, ["commit", "-q", "-m", "release r2: add rehearsal marker"]);
  const r2Rev = git(releasesDir, ["rev-parse", "HEAD"]).stdout;
  const deployR2 = deployRelease(envDir, r2Dir);
  const r2Header = await http(`${baseUrl}/wp-json/`, {
    headers: { cookie: "playground_auto_login_already_happened=1" },
  });
  const r2Served = r2Header.headers.get(RELEASE_HEADER) === "r2";
  recordStep(report, "deploy-r2", {
    exit: r2Commit.exit === 0 && deployR2.exit === 0 && r2Served ? 0 : 1,
    commit: r2Rev,
    removed: deployR2.removed,
    servedHeader: r2Header.headers.get(RELEASE_HEADER),
  });
  if (r2Commit.exit !== 0 || deployR2.exit !== 0 || !r2Served) return finish(1);

  // -- mutate content + files ----------------------------------------------
  const markerSlug = `lps-t23-marker-${Date.now().toString(36)}`;
  const created = await http(`${baseUrl}/wp-json/wp/v2/posts`, {
    method: "POST",
    headers: { ...authHeaders, "content-type": "application/json" },
    body: JSON.stringify({
      title: "t23 rehearsal marker",
      slug: markerSlug,
      status: "publish",
      content: "post-backup mutation",
    }),
  });
  const createdPost = created.status === 201 ? JSON.parse(created.body) : null;
  // Shared fields are owned by the Portuguese authority record; editing the
  // English variant is correctly rejected, so the mutation targets the pt-BR
  // authority offering by slug.
  const authority = JSON.parse(offeringsBody.body).find(
    (offering) => offering.slug === "sinais-e-sistemas-2026-2-t01",
  );
  // Offering edits require the publisher role; the operator (administrator)
  // is intentionally not authorized for record writes.
  const publisherLogin = await loginAs(baseUrl, "lps-t23-publisher", "lps-t23-publisher-pass");
  const publisherNonce = await restNonce(baseUrl, publisherLogin.cookies);
  const publisherHeaders = {
    cookie: `playground_auto_login_already_happened=1; ${publisherLogin.cookies}`,
    "x-wp-nonce": publisherNonce,
    "content-type": "application/json",
  };
  const edited = await http(`${baseUrl}/wp-json/wp/v2/offerings/${authority.id}`, {
    method: "POST",
    headers: publisherHeaders,
    body: JSON.stringify({ meta: { _lps_venue: "Sala 999 (post-backup edit)" } }),
  });
  const deletedFile = join(docroot, "wp-content/uploads/lps-qa-media/qa-demo.webm");
  const deletedExisted = existsSync(deletedFile);
  rmSync(deletedFile, { force: true });
  const mutateOk =
    createdPost !== null && edited.status === 200 && deletedExisted && !existsSync(deletedFile);
  recordStep(report, "mutate", {
    exit: mutateOk ? 0 : 1,
    createdPostId: createdPost?.id ?? null,
    offeringEdited: edited.status === 200,
    offeringEditStatus: edited.status,
    offeringEditBody: edited.status === 200 ? undefined : edited.body.slice(0, 300),
    fileDeleted: deletedExisted && !existsSync(deletedFile),
  });
  if (!mutateOk) return finish(1);

  // -- restore --------------------------------------------------------------
  await stopServer(envDir);
  await waitForPortFree(port);
  const restored = restoreEnvironment(envDir, backupDir);
  const integrity = sqliteIntegrity(join(docroot, SQLITE_DB_RELATIVE));
  const integrityRows = integrity.exit === 0 ? JSON.parse(integrity.stdout) : [];
  const integrityOk = integrityRows.some((row) => row.integrity_check === "ok");
  const { ready: readyAfterRestore } = await startServerAndWait(envDir, port, baseUrl);
  const loginAfter = await loginAs(baseUrl, "lps-t23-operator", "lps-t23-operator-pass");
  const nonceAfter = await restNonce(baseUrl, loginAfter.cookies);
  const postRestore = await fetchManifest(baseUrl, loginAfter.cookies, nonceAfter);
  const restoredFile = join(docroot, "wp-content/uploads/lps-qa-media/qa-demo.webm");
  const fileRestored =
    existsSync(restoredFile) &&
    sha256File(restoredFile) ===
      backup.manifest.files.entries["wp-content/uploads/lps-qa-media/qa-demo.webm"];
  const contentMatches =
    postRestore.status === 200 &&
    sha256Text(JSON.stringify(normalizeManifest(postRestore.manifest))) ===
      backup.manifest.content.digest;
  const markerGone = await http(`${baseUrl}/wp-json/wp/v2/posts?slug=${markerSlug}`, {
    headers: { cookie: "playground_auto_login_already_happened=1" },
  });
  const markerAbsent = markerGone.status === 200 && JSON.parse(markerGone.body).length === 0;
  const restoreOk =
    restored.exit === 0 &&
    integrityOk &&
    readyAfterRestore.ready &&
    postRestore.status === 200 &&
    contentMatches &&
    fileRestored &&
    markerAbsent;
  recordStep(report, "restore", {
    exit: restoreOk ? 0 : 1,
    untarExit: restored.exit,
    sqliteIntegrity: integrityOk,
    contentMatches,
    fileRestored,
    markerAbsent,
    error: restored.stderr || readyAfterRestore.error,
  });
  if (!restoreOk) return finish(1);

  // -- rollback via git revert ----------------------------------------------
  const revert = git(releasesDir, ["revert", "--no-edit", r2Rev]);
  const revertRev = git(releasesDir, ["rev-parse", "HEAD"]).stdout;
  const currentAfterRevert = readFileSync(join(releasesDir, "CURRENT"), "utf8").trim();
  const rollbackDeploy = deployRelease(envDir, join(releasesDir, currentAfterRevert));
  const afterRollback = await http(`${baseUrl}/wp-json/`, {
    headers: { cookie: "playground_auto_login_already_happened=1" },
  });
  const r1HeaderBack = afterRollback.headers.get(RELEASE_HEADER) === null;
  const manifestAfterRollback = await http(`${baseUrl}/release-manifest.json`, {
    headers: { cookie: "playground_auto_login_already_happened=1" },
  });
  const servedAfterRollback =
    manifestAfterRollback.status === 200 ? JSON.parse(manifestAfterRollback.body).releaseId : null;
  const markerFileGone = !existsSync(join(docroot, "wp-content/mu-plugins", RELEASE_MARKER_MU));
  const manifestForTables = await fetchManifest(baseUrl, loginAfter.cookies, nonceAfter);
  const tablesSurvived =
    manifestForTables.status === 200 &&
    ADDITIVE_TABLE_MARKERS.every((name) => manifestForTables.manifest.tables?.[name] === true);
  const gitLog = git(releasesDir, ["log", "--oneline"]).stdout;
  const rollbackOk =
    revert.exit === 0 &&
    currentAfterRevert === "r1" &&
    rollbackDeploy.exit === 0 &&
    r1HeaderBack &&
    servedAfterRollback === "r1" &&
    markerFileGone &&
    tablesSurvived;
  recordStep(report, "rollback", {
    exit: rollbackOk ? 0 : 1,
    revertExit: revert.exit,
    revertCommit: revertRev,
    current: currentAfterRevert,
    removed: rollbackDeploy.removed,
    servedRelease: servedAfterRollback,
    markerFileGone,
    additiveTablesSurvived: tablesSurvived,
    history: gitLog,
  });
  if (!rollbackOk) return finish(1);

  return finish(0);
}

async function commandStop(flags) {
  const envDir = resolve(String(flags["env-dir"] ?? ""));
  const tempRoot = resolve(tmpdir());
  if (!envDir.startsWith(`${tempRoot}${sep}`)) {
    process.stderr.write(`--env-dir must resolve inside ${tempRoot}.\n`);
    return 2;
  }
  const result = await stopServer(envDir);
  process.stdout.write(`${JSON.stringify(result)}\n`);
  return result.stopped ? 0 : 1;
}

async function main(argv) {
  const { command, flags } = parseCliArgs(argv);
  switch (command) {
    case "run":
      return await runRehearsal(flags);
    case "stop":
      return await commandStop(flags);
    default:
      process.stderr.write(
        "usage: local-rehearsal.mjs <run|stop> --env-dir=<path> --port=<port> [--dist=<dir>] [--report=<path>] [--confirm-local-rehearsal]\n",
      );
      return 2;
  }
}

if (process.argv[1]?.endsWith("local-rehearsal.mjs")) {
  main(process.argv.slice(2))
    .then((code) => {
      process.exitCode = code;
    })
    .catch((error) => {
      process.stderr.write(`${error?.stack ?? error}\n`);
      process.exitCode = 1;
    });
}

/**
 * Reproducible disposable-site fixture provisioning for the Todo 20 and Todo 22 journeys.
 *
 * The Todo 20 security journey (`tests/e2e/todo20-authenticated.spec.mjs`) reads
 * `LPS_SECURITY_FIXTURE`, a JSON document that must expose `password` and `secret` for the
 * disposable `security.privileged` and `security.no-mfa` accounts. The Todo 22 corpus journey
 * (`tests/e2e/todo22-corpus.spec.mjs`) reads `LPS_CORPUS_TARGETS`, a JSON document that must
 * expose `targets`, `totp_secret` and `editor`.
 *
 * Both fixtures are generated here from explicit, versioned inputs:
 *   - credentials arrive as required environment variables and are validated, never committed;
 *   - corpus material is rebuilt from `content/corpus` and compared with the tracked import
 *     package, so a stale package fails instead of silently provisioning old content;
 *   - post IDs are declared by the provisioner and enforced inside the provisioning PHP, so no
 *     historical database ID is ever reused or invented.
 *
 * Every artifact is written outside the repository with mode 0600, and the provisioning PHP
 * refuses to run outside a local WordPress environment.
 */

import { createHash } from "node:crypto";
import { chmodSync, mkdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { dirname, resolve, sep } from "node:path";
import { fileURLToPath } from "node:url";
import { buildImportPackage, localePairs, readCorpus, validateCorpus } from "./content-corpus.mjs";

/** Repository root derived from this module, never from the current working directory. */
export const REPOSITORY_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");

/** Logins consumed verbatim by `tests/e2e/todo20-authenticated.spec.mjs`. */
export const SECURITY_ACCOUNTS = [
  { login: "security.privileged", role: "lps_administrator", mfa: true },
  { login: "security.no-mfa", role: "lps_publisher", mfa: false },
];

/** Records spot-checked by `tests/e2e/todo22-corpus.spec.mjs`. */
export const CORPUS_SPOT_CHECK_RECORDS = ["record-001", "record-008", "record-006"];

/** Post types the corpus journey resolves through the REST API. */
export const CORPUS_POST_TYPES = new Set(["page", "lps_person", "lps_opportunity"]);

/** Collections the Todo 22 section editor owns. */
export const CORPUS_EDITOR_COLLECTIONS = ["person", "opportunity"];

/** Role slug of the Todo 22 section editor; it must not require MFA to sign in. */
export const CORPUS_EDITOR_ROLE = "lps_section_editor";

/** Default individual, attributable login for the disposable section editor. */
export const DEFAULT_CORPUS_EDITOR_LOGIN = "task22-helena";

/** Default first declared post ID; high enough to clear WordPress default content. */
export const DEFAULT_POST_ID_BASE = 4200;

/** Site path the corpus blueprint writes the import package to. */
export const CORPUS_PACKAGE_SITE_PATH = "/wordpress/wp-content/uploads/lps-task22";

const MINIMUM_PASSWORD_LENGTH = 12;
// Two-Factor pads a decoded TOTP key shorter than 20 bytes by repeating it
// (pad_secret), so a secret under 32 base32 characters produces a different
// HMAC key than the RFC 6238 code a real authenticator emits. 32 characters
// decode to exactly the 160-bit key the plugin's own generate_key() creates.
const MINIMUM_SECRET_LENGTH = 32;
const MAXIMUM_SECRET_LENGTH = 128;
const BASE32_SECRET = /^[A-Z2-7]+$/;
const PLACEHOLDER_VALUES = new Set([
  "admin",
  "change-me",
  "changeme",
  "example",
  "fixme",
  "password",
  "placeholder",
  "sample",
  "secret",
  "tbd",
  "test",
  "todo",
]);

/** Raised for every missing, malformed or stale provisioning input. */
export class ProvisionerError extends Error {
  /**
   * @param {string} message Operator-facing failure reason.
   */
  constructor(message) {
    super(message);
    this.name = "ProvisionerError";
  }
}

/**
 * Returns the SHA-256 of a UTF-8 string.
 *
 * @param {string} text Content.
 * @returns {string} Lowercase hexadecimal digest.
 */
export function sha256(text) {
  return createHash("sha256").update(text, "utf8").digest("hex");
}

/**
 * Rejects obvious placeholder or single-character filler material.
 *
 * @param {string} value Candidate secret material.
 * @returns {boolean} True when the value is placeholder material.
 */
function isPlaceholder(value) {
  const normalized = value.trim().toLowerCase();
  return PLACEHOLDER_VALUES.has(normalized) || /^(.)\1*$/.test(normalized);
}

/**
 * Reads a required environment variable.
 *
 * @param {Record<string, string | undefined>} env Environment.
 * @param {string} name Variable name.
 * @returns {string} Raw value.
 */
export function requiredEnv(env, name) {
  const value = env[name];
  if (typeof value !== "string" || value.trim() === "") {
    throw new ProvisionerError(`${name} is required and must not be empty`);
  }
  return value;
}

/**
 * Reads a required disposable password.
 *
 * @param {Record<string, string | undefined>} env Environment.
 * @param {string} name Variable name.
 * @returns {string} Password.
 */
export function requiredPassword(env, name) {
  const value = requiredEnv(env, name);
  if (value !== value.trim()) {
    throw new ProvisionerError(`${name} must not begin or end with whitespace`);
  }
  if (isPlaceholder(value)) {
    throw new ProvisionerError(`${name} is placeholder material; supply a disposable secret`);
  }
  if (value.length < MINIMUM_PASSWORD_LENGTH) {
    throw new ProvisionerError(`${name} must be at least ${MINIMUM_PASSWORD_LENGTH} characters`);
  }
  return value;
}

/**
 * Reads a required base32 TOTP secret.
 *
 * @param {Record<string, string | undefined>} env Environment.
 * @param {string} name Variable name.
 * @returns {string} Normalized secret.
 */
export function requiredTotpSecret(env, name) {
  const value = requiredEnv(env, name).trim().replace(/=+$/, "");
  if (!BASE32_SECRET.test(value)) {
    throw new ProvisionerError(`${name} must be base32 (A-Z and 2-7) as stored by Two-Factor`);
  }
  if (value.length < MINIMUM_SECRET_LENGTH || value.length > MAXIMUM_SECRET_LENGTH) {
    throw new ProvisionerError(
      `${name} must be ${MINIMUM_SECRET_LENGTH}-${MAXIMUM_SECRET_LENGTH} base32 characters`,
    );
  }
  if (isPlaceholder(value)) {
    throw new ProvisionerError(`${name} is placeholder material; supply a disposable secret`);
  }
  return value;
}

/**
 * Returns the value of a required `--flag value` option.
 *
 * @param {string[]} argv Argument vector without the node/script entries.
 * @param {string} flag Flag name including the leading dashes.
 * @returns {string} Option value.
 */
export function requiredOption(argv, flag) {
  const index = argv.indexOf(flag);
  const value = index === -1 ? undefined : argv[index + 1];
  if (typeof value !== "string" || value.trim() === "" || value.startsWith("--")) {
    throw new ProvisionerError(`${flag} <path> is required`);
  }
  return value;
}

/**
 * Returns the value of an optional `--flag value` option.
 *
 * @param {string[]} argv Argument vector without the node/script entries.
 * @param {string} flag Flag name including the leading dashes.
 * @param {string} fallback Default value.
 * @returns {string} Option value.
 */
export function option(argv, flag, fallback) {
  const index = argv.indexOf(flag);
  const value = index === -1 ? undefined : argv[index + 1];
  if (index !== -1 && (typeof value !== "string" || value.startsWith("--"))) {
    throw new ProvisionerError(`${flag} requires a value`);
  }
  return typeof value === "string" ? value : fallback;
}

/**
 * Refuses output destinations inside the repository so credentials can never be committed.
 *
 * @param {string} path Candidate output path.
 * @returns {string} Absolute path.
 */
export function assertOutsideRepository(path) {
  const absolute = resolve(path);
  if (absolute === REPOSITORY_ROOT || absolute.startsWith(`${REPOSITORY_ROOT}${sep}`)) {
    throw new ProvisionerError(
      `refusing to write fixture credentials inside the repository: ${absolute}`,
    );
  }
  return absolute;
}

/**
 * Reads a required input file.
 *
 * @param {string} path Absolute or repository-relative path.
 * @param {string} label Operator-facing label.
 * @returns {string} File content.
 */
export function readRequiredFile(path, label) {
  const absolute = resolve(REPOSITORY_ROOT, path);
  let content = "";
  try {
    content = readFileSync(absolute, "utf8");
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);
    throw new ProvisionerError(`${label} is required but could not be read: ${reason}`);
  }
  if (content.trim() === "") {
    throw new ProvisionerError(`${label} is empty: ${absolute}`);
  }
  return content;
}

/**
 * Parses a required JSON input file.
 *
 * @param {string} path Absolute or repository-relative path.
 * @param {string} label Operator-facing label.
 * @returns {unknown} Parsed document.
 */
function readRequiredJson(path, label) {
  const text = readRequiredFile(path, label);
  try {
    return JSON.parse(text);
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);
    throw new ProvisionerError(`${label} is not valid JSON: ${reason}`);
  }
}

/**
 * Writes one artifact with owner-only permissions and verifies the result.
 *
 * @param {string} path Output path outside the repository.
 * @param {string} content File content.
 * @returns {{path: string, sha256: string, mode: string}} Written artifact receipt.
 */
export function writeArtifact(path, content) {
  const absolute = assertOutsideRepository(path);
  mkdirSync(dirname(absolute), { recursive: true, mode: 0o700 });
  writeFileSync(absolute, content, { mode: 0o600 });
  chmodSync(absolute, 0o600);
  const written = readFileSync(absolute, "utf8");
  if (written !== content) {
    throw new ProvisionerError(`artifact readback mismatch: ${absolute}`);
  }
  const mode = (statSync(absolute).mode & 0o777).toString(8).padStart(3, "0");
  if (mode !== "600") {
    throw new ProvisionerError(`artifact ${absolute} must be mode 600, observed ${mode}`);
  }
  return { path: absolute, sha256: sha256(written), mode };
}

/**
 * Returns the generation timestamp, honouring `SOURCE_DATE_EPOCH` for byte-identical reruns.
 *
 * @param {Record<string, string | undefined>} env Environment.
 * @returns {string} ISO-8601 timestamp.
 */
export function resolveGeneratedAt(env) {
  const epoch = env.SOURCE_DATE_EPOCH;
  if (typeof epoch !== "string" || epoch.trim() === "") {
    return new Date().toISOString();
  }
  if (!/^\d+$/.test(epoch.trim())) {
    throw new ProvisionerError("SOURCE_DATE_EPOCH must be whole seconds since the Unix epoch");
  }
  return new Date(Number(epoch.trim()) * 1000).toISOString();
}

/**
 * Escapes one PHP single-quoted string literal; byte-transparent for UTF-8 input.
 *
 * @param {string} value Raw value.
 * @returns {string} PHP literal.
 */
export function phpString(value) {
  return `'${String(value).replace(/\\/g, "\\\\").replace(/'/g, "\\'")}'`;
}

/**
 * Renders a JSON-like value as a PHP literal.
 *
 * @param {unknown} value Value.
 * @returns {string} PHP literal.
 */
export function phpValue(value) {
  if (typeof value === "boolean") {
    return value ? "true" : "false";
  }
  if (typeof value === "number") {
    if (!Number.isInteger(value)) {
      throw new ProvisionerError(`only integers may be embedded in provisioning PHP: ${value}`);
    }
    return String(value);
  }
  if (Array.isArray(value)) {
    return `array(${value.map((entry) => phpValue(entry)).join(", ")})`;
  }
  if (value !== null && typeof value === "object") {
    const entries = Object.entries(value).map(
      ([key, entry]) => `${phpString(key)} => ${phpValue(entry)}`,
    );
    return `array(${entries.join(", ")})`;
  }
  if (value === null) {
    return "null";
  }
  return phpString(String(value));
}

/**
 * Renders a multi-line PHP array literal.
 *
 * @param {Array<[string, unknown]>} entries Key/value pairs.
 * @param {string} indent Leading indent for each entry.
 * @returns {string} PHP literal.
 */
function phpMap(entries, indent = "    ") {
  const rendered = entries.map(
    ([key, value]) => `${indent}${phpString(key)} => ${phpValue(value)}`,
  );
  return `array(\n${rendered.join(",\n")},\n)`;
}

/**
 * Returns the plugin directories the disposable site must activate, from the tracked wp-env
 * configuration rather than a copied list.
 *
 * @returns {string[]} Site-absolute plugin paths.
 */
export function activatedPluginPaths() {
  const config = readRequiredJson(".wp-env.json", ".wp-env.json");
  const plugins = /** @type {{plugins?: unknown}} */ (config).plugins;
  if (!Array.isArray(plugins) || plugins.length === 0) {
    throw new ProvisionerError(".wp-env.json must declare the plugins the fixtures activate");
  }
  return plugins.map((entry) => {
    if (typeof entry !== "string" || entry.trim() === "") {
      throw new ProvisionerError(".wp-env.json contains an empty plugin entry");
    }
    const slug = entry.replace(/\/+$/, "").split("/").pop() ?? "";
    if (slug === "") {
      throw new ProvisionerError(`.wp-env.json plugin entry has no directory name: ${entry}`);
    }
    return `/wordpress/wp-content/plugins/${slug}`;
  });
}

/**
 * Returns the shared preamble every provisioning script runs before it writes anything.
 *
 * @param {string} task Task label used in failure messages.
 * @returns {string} PHP source.
 */
function phpPreamble(task) {
  return `<?php
/**
 * Generated by scripts/${task}-fixture.mjs. Disposable local fixtures only.
 */

declare(strict_types=1);

$docroot = getenv('DOCROOT') ?: '/wordpress';
if (!defined('ABSPATH')) {
    require_once $docroot . '/wp-load.php';
}
if ('local' !== wp_get_environment_type()) {
    throw new RuntimeException(
        '${task}: refusing to provision outside a local environment; observed ' . wp_get_environment_type()
    );
}
if (!class_exists('\\\\LPS\\\\ContentModel\\\\Roles')) {
    throw new RuntimeException('${task}: the lps-content-model plugin must be active');
}
if (!defined('TWO_FACTOR_VERSION') || \\LPS\\ContentModel\\MFA::REQUIRED_PLUGIN_VERSION !== TWO_FACTOR_VERSION) {
    throw new RuntimeException(
        '${task}: Two-Factor ' . \\LPS\\ContentModel\\MFA::REQUIRED_PLUGIN_VERSION . ' must be active'
    );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

/**
 * Resolves a Two-Factor meta key from the active plugin, falling back to its documented key.
 */
$lps_meta_key = static function (string $class, array $constants, string $documented): string {
    foreach ($constants as $constant) {
        if (defined($class . '::' . $constant)) {
            $value = constant($class . '::' . $constant);
            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }
    }
    return $documented;
};
$totp_meta = $lps_meta_key('Two_Factor_Totp', array('SECRET_META_KEY', 'USER_META_KEY'), '_two_factor_totp_key');
$enabled_meta = $lps_meta_key('Two_Factor_Core', array('ENABLED_PROVIDERS_USER_META_KEY'), '_two_factor_enabled_providers');
$provider_meta = $lps_meta_key('Two_Factor_Core', array('PROVIDER_USER_META_KEY'), '_two_factor_provider');
$last_login_meta = $lps_meta_key('Two_Factor_Totp', array('LAST_SUCCESSFUL_LOGIN_META_KEY'), '_two_factor_totp_last_successful_login');

/**
 * Enrolls one account in TOTP and proves the enrollment through the plugin contract.
 * Re-enrollment replaces the secret, so the consumed-window marker from any earlier
 * enrollment is cleared too: Two-Factor rejects a code whose window is not newer
 * than the recorded one, and a stale marker would deny the first login under the
 * new secret when it lands in the same 30-second step.
 */
$lps_enroll_totp = static function (int $user_id, string $secret) use ($totp_meta, $enabled_meta, $provider_meta, $last_login_meta): void {
    update_user_meta($user_id, $totp_meta, $secret);
    update_user_meta($user_id, $enabled_meta, array('Two_Factor_Totp'));
    update_user_meta($user_id, $provider_meta, 'Two_Factor_Totp');
    delete_user_meta($user_id, $last_login_meta);
    if (!\\LPS\\ContentModel\\MFA::is_enrolled($user_id)) {
        throw new RuntimeException('user ' . $user_id . ': TOTP enrollment did not take effect');
    }
};

/**
 * Removes every TOTP enrollment artifact and proves the account stays unenrolled.
 */
$lps_clear_totp = static function (int $user_id) use ($totp_meta, $enabled_meta, $provider_meta, $last_login_meta): void {
    delete_user_meta($user_id, $totp_meta);
    delete_user_meta($user_id, $enabled_meta);
    delete_user_meta($user_id, $provider_meta);
    delete_user_meta($user_id, $last_login_meta);
    if (\\LPS\\ContentModel\\MFA::is_enrolled($user_id)) {
        throw new RuntimeException('user ' . $user_id . ': account must remain unenrolled');
    }
};

/**
 * Recreates one disposable account and returns its ID.
 */
$lps_recreate_account = static function (string $login, string $role, string $password): int {
    $existing = get_user_by('login', $login);
    if ($existing instanceof WP_User) {
        wp_delete_user($existing->ID);
    }
    $created = wp_insert_user(array(
        'user_login' => $login,
        'user_pass' => $password,
        'user_email' => str_replace(array('.', '_'), '-', $login) . '@example.test',
        'display_name' => $login,
        'role' => $role,
    ));
    if (is_wp_error($created)) {
        throw new RuntimeException($login . ': ' . $created->get_error_message());
    }
    return (int) $created;
};
`;
}

/**
 * Builds the Todo 20 provisioning PHP.
 *
 * @param {{password: string, secret: string}} inputs Validated credentials.
 * @returns {string} PHP source.
 */
function securityProvisioningPhp(inputs) {
  const accounts = SECURITY_ACCOUNTS.map((account) => ({
    login: account.login,
    role: account.role,
    mfa: account.mfa,
  }));
  return `${phpPreamble("todo20")}
$password = ${phpString(inputs.password)};
$secret = ${phpString(inputs.secret)};
$accounts = ${phpValue(accounts)};
$collections = \\LPS\\ContentModel\\Roles::collections();
$receipt = array();
foreach ($accounts as $account) {
    $user_id = $lps_recreate_account($account['login'], $account['role'], $password);
    update_user_meta($user_id, \\LPS\\ContentModel\\Roles::COLLECTIONS_META, $collections);
    if ($account['mfa']) {
        $lps_enroll_totp($user_id, $secret);
    } else {
        $lps_clear_totp($user_id);
    }
    $receipt[] = array(
        'id' => $user_id,
        'login' => $account['login'],
        'role' => $account['role'],
        'mfa' => (bool) $account['mfa'],
    );
}
if (count($receipt) !== count($accounts)) {
    throw new RuntimeException('todo20: expected ' . count($accounts) . ' accounts, provisioned ' . count($receipt));
}
update_option('_task20_fixture_receipt', $receipt, false);
echo json_encode(array('status' => 'provisioned', 'task' => 'todo20', 'accounts' => $receipt));
`;
}

/**
 * Builds the Todo 22 provisioning PHP.
 *
 * @param {object} inputs Validated inputs.
 * @param {string} inputs.packageSitePath Site path of the written import package.
 * @param {string} inputs.packageSha256 Expected package digest.
 * @param {Array<[string, number]>} inputs.declaredIds Record ID to post ID assignments.
 * @param {Array<{record: string, ptBr: string, en: string}>} inputs.pairs Locale pairs.
 * @param {string} inputs.adminTotpSecret Administrator TOTP secret.
 * @param {string} inputs.editorLogin Section editor login.
 * @param {string} inputs.editorPassword Section editor password.
 * @returns {string} PHP source.
 */
function corpusProvisioningPhp(inputs) {
  const pairs = inputs.pairs.map((pair) => [pair.record, pair.ptBr, pair.en]);
  return `${phpPreamble("todo22")}
if (!function_exists('PLL') || !isset(PLL()->model)) {
    throw new RuntimeException('todo22: the pinned Polylang plugin must be active');
}
foreach (array('pt-br', 'en') as $language) {
    if (!PLL()->model->get_language($language)) {
        throw new RuntimeException('todo22: Polylang language ' . $language . ' is not configured');
    }
}

$package_path = getenv('LPS_TASK22_PACKAGE') ?: ${phpString(`${inputs.packageSitePath}/launch-corpus.json`)};
if (!is_file($package_path)) {
    throw new RuntimeException('todo22: import package missing at ' . $package_path);
}
$observed_hash = hash_file('sha256', $package_path);
if (!is_string($observed_hash) || !hash_equals(${phpString(inputs.packageSha256)}, $observed_hash)) {
    throw new RuntimeException(
        'todo22: import package digest mismatch; expected ${inputs.packageSha256}, observed ' . (string) $observed_hash
    );
}
$package = json_decode((string) file_get_contents($package_path), true);
if (!is_array($package) || !isset($package['records']) || !is_array($package['records'])) {
    throw new RuntimeException('todo22: import package has no records');
}
$assets_dir = $docroot . '/wp-content/uploads/lps-task22/assets';
wp_mkdir_p($assets_dir);

$declared = ${phpMap(inputs.declaredIds)};
$pairs = ${phpValue(pairs)};

foreach ($package['records'] as $record) {
    $record_id = (string) ($record['record_id'] ?? '');
    if (!isset($declared[$record_id])) {
        throw new RuntimeException('todo22: record ' . $record_id . ' has no declared post ID');
    }
    $target_id = (int) $declared[$record_id];
    $current_id = \\LPS\\ContentModel\\ImportRepository::post_id($record_id);
    if (0 < $current_id && $current_id !== $target_id) {
        wp_delete_post($current_id, true);
        $current_id = 0;
    }
    if (0 === $current_id) {
        $occupant = get_post($target_id);
        if ($occupant instanceof WP_Post) {
            wp_delete_post($target_id, true);
        }
        $seeded = wp_insert_post(
            array(
                'import_id' => $target_id,
                'post_type' => (string) ($record['type'] ?? ''),
                'post_status' => 'draft',
                'post_title' => (string) ($record['title'] ?? ''),
                'post_name' => (string) ($record['slug'] ?? ''),
                'post_content' => (string) ($record['content'] ?? ''),
                'post_excerpt' => (string) ($record['excerpt'] ?? ''),
                'meta_input' => array('_lps_record_id' => $record_id),
            ),
            true
        );
        if (is_wp_error($seeded)) {
            throw new RuntimeException($record_id . ': ' . $seeded->get_error_message());
        }
        if ((int) $seeded !== $target_id) {
            throw new RuntimeException(
                'todo22: record ' . $record_id . ' requested post ID ' . $target_id . ' but WordPress assigned ' . (int) $seeded
            );
        }
    }
}

$import = \\LPS\\ContentModel\\Importer::apply($package, $assets_dir);
if ($import instanceof WP_Error) {
    throw new RuntimeException('todo22: import failed with ' . $import->get_error_code() . ' ' . $import->get_error_message());
}
if ('applied' !== ($import['status'] ?? '')) {
    throw new RuntimeException('todo22: import status ' . (string) ($import['status'] ?? 'unknown'));
}

$records = array();
foreach ($pairs as $pair) {
    list($record, $pt_record_id, $en_record_id) = $pair;
    $pt_id = \\LPS\\ContentModel\\ImportRepository::post_id($pt_record_id);
    $en_id = \\LPS\\ContentModel\\ImportRepository::post_id($en_record_id);
    if ($pt_id !== (int) $declared[$pt_record_id] || $en_id !== (int) $declared[$en_record_id]) {
        throw new RuntimeException(
            'todo22: ' . $record . ' resolved to ' . $pt_id . '/' . $en_id . ' instead of the declared ' . (int) $declared[$pt_record_id] . '/' . (int) $declared[$en_record_id]
        );
    }
    $associated = \\LPS\\ContentModel\\Translations::associate($pt_id, $en_id);
    if ($associated instanceof WP_Error) {
        throw new RuntimeException('todo22: ' . $record . ': ' . $associated->get_error_message());
    }
    $records[] = array('record' => $record, 'pt_br_post_id' => $pt_id, 'en_post_id' => $en_id);
}
if (count($records) !== count($pairs)) {
    throw new RuntimeException('todo22: expected ' . count($pairs) . ' locale pairs, provisioned ' . count($records));
}

$administrator = get_user_by('login', 'admin');
if (!$administrator instanceof WP_User) {
    throw new RuntimeException('todo22: the disposable site has no admin account to enroll');
}
$lps_enroll_totp((int) $administrator->ID, ${phpString(inputs.adminTotpSecret)});
delete_user_meta((int) $administrator->ID, \\LPS\\ContentModel\\Roles::COLLECTIONS_META);

$editor_id = $lps_recreate_account(
    ${phpString(inputs.editorLogin)},
    ${phpString(CORPUS_EDITOR_ROLE)},
    ${phpString(inputs.editorPassword)}
);
update_user_meta($editor_id, \\LPS\\ContentModel\\Roles::COLLECTIONS_META, ${phpValue(CORPUS_EDITOR_COLLECTIONS)});
$lps_clear_totp($editor_id);

update_option(
    '_task22_corpus_receipt',
    array(
        'records' => $records,
        'editor' => array('id' => $editor_id, 'login' => ${phpString(inputs.editorLogin)}, 'role' => ${phpString(CORPUS_EDITOR_ROLE)}),
        'administrator' => array('id' => (int) $administrator->ID, 'mfa' => true),
        'package_sha256' => ${phpString(inputs.packageSha256)},
    ),
    false
);
echo json_encode(array('status' => 'provisioned', 'task' => 'todo22', 'records' => count($records), 'editor' => ${phpString(inputs.editorLogin)}));
`;
}

/**
 * Builds a Playground blueprint around one provisioning script.
 *
 * @param {object} options Blueprint options.
 * @param {string} options.filename Provisioning script filename.
 * @param {string} options.php Provisioning PHP source.
 * @param {Array<Record<string, unknown>>} [options.extraSteps] Steps inserted before the script.
 * @returns {Record<string, unknown>} Blueprint document.
 */
function blueprintFor(options) {
  return {
    $schema: "https://playground.wordpress.net/blueprint-schema.json",
    landingPage: "/wp-admin/",
    preferredVersions: { php: "8.3", wp: "latest" },
    steps: [
      ...activatedPluginPaths().map((pluginPath) => ({ step: "activatePlugin", pluginPath })),
      {
        step: "defineWpConfigConsts",
        consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_ENVIRONMENT_TYPE: "local" },
      },
      ...(options.extraSteps ?? []),
      { step: "runPHP", code: { filename: options.filename, content: options.php } },
    ],
  };
}

/**
 * Builds the Todo 20 security fixture, blueprint and provisioning script.
 *
 * @param {object} inputs Validated inputs.
 * @param {string} inputs.password Disposable password shared by both accounts.
 * @param {string} inputs.secret Disposable TOTP secret of the privileged account.
 * @param {string} inputs.generatedAt ISO-8601 generation timestamp.
 * @returns {{fixture: object, blueprint: object, php: string}} Artifacts.
 */
export function buildSecurityFixture(inputs) {
  const php = securityProvisioningPhp(inputs);
  const fixture = {
    schema_version: 1,
    task: "todo20",
    generated_at: inputs.generatedAt,
    consumer: "tests/e2e/todo20-authenticated.spec.mjs",
    accounts: SECURITY_ACCOUNTS.map((account) => ({
      login: account.login,
      role: account.role,
      mfa: account.mfa ? "enrolled" : "absent",
    })),
    password: inputs.password,
    secret: inputs.secret,
  };
  assertSecurityFixtureContract(fixture);
  return {
    fixture,
    blueprint: blueprintFor({ filename: "todo20-provision.php", php }),
    php,
  };
}

/**
 * Fails unless a security fixture satisfies every field the Todo 20 journey reads.
 *
 * @param {unknown} value Parsed fixture.
 * @returns {void}
 */
export function assertSecurityFixtureContract(value) {
  const fixture = /** @type {Record<string, unknown>} */ (value);
  if (!fixture || typeof fixture !== "object") {
    throw new ProvisionerError("todo20 fixture must be a JSON object");
  }
  if (fixture.schema_version !== 1) {
    throw new ProvisionerError("todo20 fixture schema_version must be 1");
  }
  if (typeof fixture.password !== "string" || fixture.password.length < MINIMUM_PASSWORD_LENGTH) {
    throw new ProvisionerError("todo20 fixture password is missing or too short");
  }
  if (typeof fixture.secret !== "string" || !BASE32_SECRET.test(fixture.secret)) {
    throw new ProvisionerError("todo20 fixture secret is missing or not base32");
  }
  const accounts = Array.isArray(fixture.accounts) ? fixture.accounts : [];
  for (const expected of SECURITY_ACCOUNTS) {
    const found = accounts.find(
      (account) => /** @type {Record<string, unknown>} */ (account)?.login === expected.login,
    );
    if (!found) {
      throw new ProvisionerError(`todo20 fixture is missing the ${expected.login} account`);
    }
    const account = /** @type {Record<string, unknown>} */ (found);
    if (account.role !== expected.role) {
      throw new ProvisionerError(
        `todo20 fixture account ${expected.login} must use role ${expected.role}`,
      );
    }
    if (account.mfa !== (expected.mfa ? "enrolled" : "absent")) {
      throw new ProvisionerError(
        `todo20 fixture account ${expected.login} has the wrong MFA disposition`,
      );
    }
  }
}

/**
 * Rebuilds the import package from the tracked corpus and fails when the tracked package is stale.
 *
 * @param {object} options Source locations.
 * @param {string} options.corpusDir Corpus directory.
 * @param {string} options.inventoryDir Inventory directory.
 * @param {string} options.packagePath Tracked import package.
 * @param {string} options.pairsPath Tracked locale pairs.
 * @returns {Promise<{package: Record<string, any>, pairs: Array<Record<string, string>>}>} Sources.
 */
async function readVersionedCorpus(options) {
  // The corpus loader keys evidence by its repository-relative path, so the rebuild runs from the
  // repository root regardless of where the operator invoked the provisioner.
  const invokedFrom = process.cwd();
  let corpus;
  try {
    process.chdir(REPOSITORY_ROOT);
    corpus = await readCorpus({
      corpusDir: options.corpusDir,
      inventoryDir: options.inventoryDir,
    });
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);
    throw new ProvisionerError(`corpus sources could not be read: ${reason}`);
  } finally {
    process.chdir(invokedFrom);
  }
  const report = validateCorpus(corpus);
  if (report.status !== "passed") {
    const counts = new Map();
    for (const issue of report.errors ?? []) {
      const code = issue.code ?? "unknown";
      counts.set(code, (counts.get(code) ?? 0) + 1);
    }
    const codes = [...counts]
      .map(([code, count]) => `${code} x${count}`)
      .sort()
      .join(", ");
    throw new ProvisionerError(`corpus validation failed before provisioning: ${codes}`);
  }
  const rebuilt = buildImportPackage(corpus);
  const rebuiltPairs = localePairs(corpus);
  const tracked = /** @type {Record<string, any>} */ (
    readRequiredJson(options.packagePath, "import package")
  );
  const trackedPairs = /** @type {Record<string, any>} */ (
    readRequiredJson(options.pairsPath, "locale pairs")
  );
  if (JSON.stringify(tracked) !== JSON.stringify(rebuilt)) {
    const trackedRecords = Array.isArray(tracked.records) ? tracked.records : [];
    const divergent = rebuilt.records.find(
      (record, index) => JSON.stringify(record) !== JSON.stringify(trackedRecords[index]),
    );
    const detail = divergent ? ` first divergence at ${divergent.record_id};` : "";
    throw new ProvisionerError(
      `${options.packagePath} is stale against ${options.corpusDir};${detail}` +
        " rebuild it with node scripts/build-import-package.mjs",
    );
  }
  if (JSON.stringify(trackedPairs.pairs) !== JSON.stringify(rebuiltPairs)) {
    throw new ProvisionerError(
      `${options.pairsPath} is stale against ${options.corpusDir}; ` +
        "rebuild it with node scripts/build-import-package.mjs",
    );
  }
  return { package: tracked, pairs: rebuiltPairs };
}

/**
 * Builds the Todo 22 corpus targets, blueprint and provisioning script.
 *
 * @param {object} inputs Validated inputs.
 * @param {string} inputs.corpusDir Corpus directory.
 * @param {string} inputs.inventoryDir Inventory directory.
 * @param {string} inputs.packagePath Tracked import package.
 * @param {string} inputs.pairsPath Tracked locale pairs.
 * @param {number} inputs.postIdBase First declared post ID.
 * @param {string} inputs.editorLogin Section editor login.
 * @param {string} inputs.editorPassword Section editor password.
 * @param {string} inputs.adminTotpSecret Administrator TOTP secret.
 * @param {string} inputs.generatedAt ISO-8601 generation timestamp.
 * @returns {Promise<{targets: object, blueprint: object, php: string, packageText: string,
 *   packageSha256: string}>} Artifacts.
 */
export async function buildCorpusFixture(inputs) {
  if (!Number.isInteger(inputs.postIdBase) || inputs.postIdBase < 100) {
    throw new ProvisionerError("--post-id-base must be a whole number of at least 100");
  }
  const sources = await readVersionedCorpus(inputs);
  const records = new Map(
    (sources.package.records ?? []).map((record) => [String(record.record_id), record]),
  );
  const declaredIds = /** @type {Array<[string, number]>} */ ([]);
  const pairs = /** @type {Array<{record: string, ptBr: string, en: string}>} */ ([]);
  const targets = sources.pairs.map((pair, index) => {
    const record = String(pair.record);
    const ptBrRecordId = String(pair["pt-br"] ?? "");
    const enRecordId = String(pair.en ?? "");
    const ptBr = records.get(ptBrRecordId);
    const en = records.get(enRecordId);
    if (!ptBr || !en) {
      throw new ProvisionerError(`${record}: locale pair is absent from the import package`);
    }
    if (ptBr.type !== en.type || !CORPUS_POST_TYPES.has(String(ptBr.type))) {
      throw new ProvisionerError(`${record}: unsupported or mismatched post type ${ptBr.type}`);
    }
    if (ptBr.state !== "draft" || en.state !== "draft") {
      throw new ProvisionerError(`${record}: both locale variants must stay draft`);
    }
    if (!ptBr.slug || !en.slug || !ptBr.title || !en.title) {
      throw new ProvisionerError(`${record}: slug and title are required in both locales`);
    }
    // Translated pages must carry independent titles; personal names are legitimately identical.
    if (ptBr.type === "page" && ptBr.title === en.title) {
      throw new ProvisionerError(`${record}: page variants must not share one title`);
    }
    const ptBrPostId = inputs.postIdBase + index * 2;
    const enPostId = ptBrPostId + 1;
    declaredIds.push([ptBrRecordId, ptBrPostId], [enRecordId, enPostId]);
    pairs.push({ record, ptBr: ptBrRecordId, en: enRecordId });
    return {
      record,
      post_type: String(ptBr.type),
      pt_br_post_id: ptBrPostId,
      en_post_id: enPostId,
      pt_br_slug: String(ptBr.slug),
      en_slug: String(en.slug),
      pt_br_title: String(ptBr.title),
      en_title: String(en.title),
      pt_br_record_id: ptBrRecordId,
      en_record_id: enRecordId,
    };
  });
  if (declaredIds.length !== records.size) {
    throw new ProvisionerError(
      `every package record needs a declared post ID: ${records.size} records, ` +
        `${declaredIds.length} assignments`,
    );
  }
  const packageText = `${JSON.stringify(sources.package, null, 2)}\n`;
  const packageSha256 = sha256(packageText);
  const php = corpusProvisioningPhp({
    packageSitePath: CORPUS_PACKAGE_SITE_PATH,
    packageSha256,
    declaredIds,
    pairs,
    adminTotpSecret: inputs.adminTotpSecret,
    editorLogin: inputs.editorLogin,
    editorPassword: inputs.editorPassword,
  });
  const document = {
    schema_version: 1,
    task: "todo22",
    generated_at: inputs.generatedAt,
    consumer: "tests/e2e/todo22-corpus.spec.mjs",
    source: {
      corpus_dir: inputs.corpusDir,
      inventory_dir: inputs.inventoryDir,
      package: inputs.packagePath,
      package_sha256: packageSha256,
      pairs: inputs.pairsPath,
      post_id_base: inputs.postIdBase,
    },
    targets,
    totp_secret: inputs.adminTotpSecret,
    editor: {
      login: inputs.editorLogin,
      password: inputs.editorPassword,
      role: CORPUS_EDITOR_ROLE,
      collections: CORPUS_EDITOR_COLLECTIONS,
    },
  };
  assertCorpusTargetsContract(document);
  return {
    targets: document,
    blueprint: blueprintFor({
      filename: "todo22-provision.php",
      php,
      extraSteps: [
        { step: "mkdir", path: CORPUS_PACKAGE_SITE_PATH },
        {
          step: "writeFile",
          path: `${CORPUS_PACKAGE_SITE_PATH}/launch-corpus.json`,
          data: packageText,
        },
      ],
    }),
    php,
    packageText,
    packageSha256,
  };
}

/**
 * Fails unless a corpus targets document satisfies every field the Todo 22 journey reads.
 *
 * @param {unknown} value Parsed targets document.
 * @returns {void}
 */
export function assertCorpusTargetsContract(value) {
  const document = /** @type {Record<string, any>} */ (value);
  if (!document || typeof document !== "object") {
    throw new ProvisionerError("todo22 targets must be a JSON object");
  }
  if (document.schema_version !== 1) {
    throw new ProvisionerError("todo22 targets schema_version must be 1");
  }
  if (typeof document.totp_secret !== "string" || !BASE32_SECRET.test(document.totp_secret)) {
    throw new ProvisionerError("todo22 targets totp_secret is missing or not base32");
  }
  const editor = document.editor;
  if (!editor || typeof editor.login !== "string" || editor.login.trim() === "") {
    throw new ProvisionerError("todo22 targets editor.login is required");
  }
  if (typeof editor.password !== "string" || editor.password.length < MINIMUM_PASSWORD_LENGTH) {
    throw new ProvisionerError("todo22 targets editor.password is missing or too short");
  }
  if (editor.role !== CORPUS_EDITOR_ROLE) {
    throw new ProvisionerError(`todo22 targets editor.role must be ${CORPUS_EDITOR_ROLE}`);
  }
  const targets = Array.isArray(document.targets) ? document.targets : [];
  if (targets.length === 0) {
    throw new ProvisionerError("todo22 targets must not be empty");
  }
  const seen = new Set();
  for (const target of targets) {
    for (const field of ["pt_br_post_id", "en_post_id"]) {
      const id = target[field];
      if (!Number.isInteger(id) || id <= 0) {
        throw new ProvisionerError(`${target.record}: ${field} must be a positive integer`);
      }
      if (seen.has(id)) {
        throw new ProvisionerError(`${target.record}: post ID ${id} is declared twice`);
      }
      seen.add(id);
    }
    for (const field of ["pt_br_slug", "en_slug", "pt_br_title", "en_title"]) {
      if (typeof target[field] !== "string" || target[field].trim() === "") {
        throw new ProvisionerError(`${target.record}: ${field} is required`);
      }
    }
    if (!CORPUS_POST_TYPES.has(String(target.post_type))) {
      throw new ProvisionerError(`${target.record}: unsupported post_type ${target.post_type}`);
    }
  }
  for (const record of CORPUS_SPOT_CHECK_RECORDS) {
    if (!targets.some((target) => target.record === record)) {
      throw new ProvisionerError(`todo22 targets must include the spot-checked ${record}`);
    }
  }
}

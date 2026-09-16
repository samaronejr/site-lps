import { createHash } from "node:crypto";
import { cp, mkdir, readdir, readFile, rm, stat, writeFile } from "node:fs/promises";
import path from "node:path";
import { backup, DatabaseSync } from "node:sqlite";
import { fileURLToPath } from "node:url";
/** Durable inputs resolve against the worktree, never against the caller's directory. */
export const repoRoot = fileURLToPath(new URL("../../../", import.meta.url));
export const root = path.resolve(
  process.env.LPS_TASK24_ROOT ?? path.join(repoRoot, ".omo/evidence/task-24k"),
);
export const historical =
  process.env.LPS_TASK24_HISTORY ?? "/home/samarone/Documents/site-lps/.omo/evidence/task-24";
export const archiveIdentityPath = path.join(
  repoRoot,
  "tests/fixtures/task24/archive-identity.json",
);
export const sha = (bytes) => createHash("sha256").update(bytes).digest("hex");

/** Provisioning failure. Always fatal: never downgraded to a warning or a skipped step. */
export class ProvisionError extends Error {
  constructor(code, detail) {
    super(`${code}: ${detail}`);
    this.name = "ProvisionError";
    this.code = code;
  }
}

/**
 * Archive paths the provisioner refuses to import. `database` is re-created from a
 * consistent SQLite backup, `mu-plugins`, `themes` and `uploads/lps-qa-media` are
 * replaced by durable in-repository inputs, and the rest is historical noise.
 */
export const ARCHIVE_EXCLUDED = [
  "wp-content/upgrade",
  "wp-content/cache",
  "wp-content/database",
  "wp-content/themes",
  "wp-content/mu-plugins",
  "wp-content/uploads/lps-qa-media",
];
export const acceptArchivePath = (relative) =>
  !ARCHIVE_EXCLUDED.some((name) => relative === name || relative.startsWith(`${name}/`)) &&
  !relative.endsWith(".log");

export async function fileHashes(dir, prefix = "", accept = () => true) {
  const files = [];
  for (const item of (await readdir(dir, { withFileTypes: true })).sort((a, b) =>
    a.name.localeCompare(b.name),
  )) {
    const name = path.join(prefix, item.name),
      file = path.join(dir, item.name);
    if (!accept(name)) continue;
    if (item.isDirectory()) files.push(...(await fileHashes(file, name, accept)));
    else if (item.isFile()) files.push({ file: name, sha256: sha(await readFile(file)) });
    else
      throw new ProvisionError("unsupported-input-entry", `${file} is neither file nor directory`);
  }
  return files;
}
/** Order-sensitive rollup of a hashed tree; one byte anywhere changes the identity. */
export const rollup = (files) => sha(files.map((f) => `${f.file}:${f.sha256}\n`).join(""));

const present = async (target) =>
  await stat(target).then(
    () => true,
    () => false,
  );

/** Loads the checked-in expected identity of every provisioning input. */
export async function loadArchiveManifest() {
  let text;
  try {
    text = await readFile(archiveIdentityPath, "utf8");
  } catch (error) {
    throw new ProvisionError(
      "missing-archive-manifest",
      `${archiveIdentityPath} is required and could not be read (${error.code})`,
    );
  }
  const manifest = JSON.parse(text);
  if (manifest.schemaVersion !== 1)
    throw new ProvisionError("invalid-archive-manifest", "schemaVersion must be 1");
  for (const key of ["archive", "durable", "administrator"])
    if (!manifest[key]) throw new ProvisionError("invalid-archive-manifest", `missing ${key}`);
  if (!manifest.durable.muPlugins?.length)
    throw new ProvisionError("invalid-archive-manifest", "durable.muPlugins must not be empty");
  return { manifest, manifestHash: sha(text) };
}

/**
 * Fails loudly when an input is absent or is not the expected archive. Every hash is
 * an identity of content the provisioner reads; no account or credential material is
 * read, recorded or printed.
 */
export async function verifyArchive(manifest, history = historical) {
  const wordpress = path.join(history, manifest.archive.wordpress.path);
  const database = path.join(history, manifest.archive.database.path);
  const fixed = path.join(history, manifest.archive.historicalFixedDatabase.path);
  const media = path.resolve(repoRoot, manifest.durable.media.path);
  const missing = [];
  for (const target of [wordpress, database, fixed, media])
    if (!(await present(target))) missing.push(target);
  if (missing.length)
    throw new ProvisionError(
      "missing-provisioning-input",
      `required input(s) absent under ${history}:\n  ${missing.join("\n  ")}`,
    );
  const tree = await fileHashes(wordpress, "", acceptArchivePath);
  const mediaFiles = await fileHashes(media);
  const observed = {
    wordpressFileCount: tree.length,
    wordpressTreeHash: rollup(tree),
    database: sha(await readFile(database)),
    historicalFixedDatabase: sha(await readFile(fixed)),
    mediaFileCount: mediaFiles.length,
    mediaTreeHash: rollup(mediaFiles),
  };
  const expected = {
    wordpressFileCount: manifest.archive.wordpress.fileCount,
    wordpressTreeHash: manifest.archive.wordpress.treeHash,
    database: manifest.archive.database.sha256,
    historicalFixedDatabase: manifest.archive.historicalFixedDatabase.sha256,
    mediaFileCount: manifest.durable.media.fileCount,
    mediaTreeHash: manifest.durable.media.treeHash,
  };
  const mismatches = Object.keys(expected)
    .filter((key) => observed[key] !== expected[key])
    .map((key) => `  ${key}: expected ${expected[key]}, got ${observed[key]}`);
  if (mismatches.length)
    throw new ProvisionError(
      "archive-identity-mismatch",
      `inputs under ${history} do not match ${archiveIdentityPath}:\n${mismatches.join("\n")}`,
    );
  return { ...observed, mediaFiles, verifiedAt: new Date().toISOString() };
}

/**
 * Resolves every declared durable mu-plugin before anything is copied. An absent or
 * empty source is always fatal: the archive is never a fallback. Entries that declare a
 * sha256 are frozen QA recoveries and are pinned to it; entries without one are live
 * repository fixtures owned by their own lane, so their bytes are recorded in the run
 * identity instead of being pinned twice against version control.
 */
export async function verifyDurableMuPlugins(manifest) {
  const resolved = [];
  for (const plugin of manifest.durable.muPlugins) {
    const source = path.resolve(repoRoot, plugin.source);
    let bytes;
    try {
      bytes = await readFile(source);
    } catch (error) {
      throw new ProvisionError(
        "missing-durable-mu-plugin",
        `${plugin.target} requires ${source} (${error.code}); the archive is not a fallback`,
      );
    }
    if (bytes.length === 0)
      throw new ProvisionError("empty-durable-mu-plugin", `${source} provides no fixture bytes`);
    const sha256 = sha(bytes);
    if (plugin.sha256 && sha256 !== plugin.sha256)
      throw new ProvisionError(
        "durable-mu-plugin-mismatch",
        `${source}: expected ${plugin.sha256}, got ${sha256}`,
      );
    resolved.push({
      target: plugin.target,
      source: plugin.source,
      sha256,
      pinned: Boolean(plugin.sha256),
      absolute: source,
    });
  }
  return resolved;
}

/**
 * The fixture administrator is a required input, not an assumption. Only the numeric ID
 * and the granted role are read; logins, e-mail addresses and password hashes are never
 * queried or recorded.
 */
export function verifyAdministrator(databasePath, expected) {
  if (!Number.isInteger(expected?.id) || expected.id < 1 || !expected?.role)
    throw new ProvisionError(
      "invalid-administrator-expectation",
      "administrator.id must be a positive integer and administrator.role must be set",
    );
  let db;
  try {
    db = new DatabaseSync(databasePath, { readOnly: true });
  } catch (error) {
    throw new ProvisionError(
      "unreadable-fixture-database",
      `${databasePath} could not be opened (${error.message})`,
    );
  }
  try {
    const user = db.prepare("SELECT ID FROM wp_users WHERE ID = ?").get(expected.id);
    if (!user)
      throw new ProvisionError(
        "missing-fixture-administrator",
        `${databasePath} has no wp_users row with ID ${expected.id}; provision the required administrator before running the fixture migration`,
      );
    const capabilities = db
      .prepare("SELECT meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key = ?")
      .get(expected.id, "wp_capabilities");
    if (!String(capabilities?.meta_value ?? "").includes(`"${expected.role}";b:1`))
      throw new ProvisionError(
        "missing-administrator-capability",
        `wp_usermeta wp_capabilities for user ${expected.id} does not grant ${expected.role}`,
      );
    return { id: expected.id, role: expected.role, verified: true };
  } finally {
    db.close();
  }
}

/** Guards a phase that reuses an earlier provisioning run against stale or edited state. */
export async function assertProvisioned(target = root) {
  const manifestPath = `${target}/manifests/identity.json`;
  let identity;
  try {
    identity = JSON.parse(await readFile(manifestPath, "utf8"));
  } catch (error) {
    throw new ProvisionError(
      "missing-provisioned-fixture",
      `${manifestPath} could not be read (${error.code}); run the provisioner first`,
    );
  }
  if (!identity.muPlugins?.length || !identity.administrator)
    throw new ProvisionError(
      "stale-fixture-identity",
      `${manifestPath} predates durable input provisioning; re-run the provisioner`,
    );
  for (const plugin of identity.muPlugins) {
    const file = `${target}/runtime/wordpress/wp-content/mu-plugins/${plugin.target}`;
    let bytes;
    try {
      bytes = await readFile(file);
    } catch (error) {
      throw new ProvisionError("missing-provisioned-mu-plugin", `${file} (${error.code})`);
    }
    if (sha(bytes) !== plugin.sha256)
      throw new ProvisionError(
        "provisioned-mu-plugin-mismatch",
        `${file} changed after provisioning`,
      );
  }
  verifyAdministrator(
    `${target}/runtime/wordpress/wp-content/database/.ht.sqlite`,
    identity.administrator,
  );
  return identity;
}

export async function prepare() {
  const { manifest, manifestHash } = await loadArchiveManifest();
  const archive = await verifyArchive(manifest);
  const muPlugins = await verifyDurableMuPlugins(manifest);
  await rm(`${root}/runtime`, { recursive: true, force: true });
  await mkdir(`${root}/runtime`, { recursive: true });
  await mkdir(`${root}/tmp`, { recursive: true });
  await mkdir(`${root}/manifests`, { recursive: true });
  await cp(new URL("./entry.mjs", import.meta.url), `${root}/qa.mjs`);
  const old = path.join(historical, manifest.archive.wordpress.path);
  await cp(old, `${root}/runtime/wordpress`, {
    recursive: true,
    filter: (src) => acceptArchivePath(path.relative(old, src)),
  });
  await mkdir(`${root}/runtime/wordpress/wp-content/database`, { recursive: true });
  const src = new DatabaseSync(path.join(historical, manifest.archive.database.path), {
    readOnly: true,
  });
  await backup(src, `${root}/runtime/wordpress/wp-content/database/.ht.sqlite`);
  src.close();
  const db = new DatabaseSync(`${root}/runtime/wordpress/wp-content/database/.ht.sqlite`);
  // Ports have equal byte length, including inside PHP serialized strings.
  db.exec("BEGIN");
  for (const { name } of db
    .prepare("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'wp_%'")
    .all()) {
    const quote = (n) => `"${n.replaceAll('"', '""')}"`;
    for (const col of db.prepare(`PRAGMA table_info(${quote(name)})`).all()) {
      for (const port of ["8894", "8895", "8896"])
        db.exec(
          `UPDATE ${quote(name)} SET ${quote(col.name)}=replace(${quote(col.name)},'127.0.0.1:${port}','127.0.0.1:8903') WHERE typeof(${quote(col.name)})='text' AND instr(${quote(col.name)},'127.0.0.1:${port}')>0`,
        );
    }
  }
  db.exec("COMMIT");
  db.close();
  const administrator = verifyAdministrator(
    `${root}/runtime/wordpress/wp-content/database/.ht.sqlite`,
    manifest.administrator,
  );
  for (const [source, target] of [
    ["wp-content/themes/lps-theme", "lps-theme"],
    ["wp-content/plugins/lps-content-model", "lps-content-model"],
  ])
    await cp(path.join(repoRoot, source), `${root}/runtime/${target}`, { recursive: true });
  await cp(
    path.resolve(repoRoot, manifest.durable.media.path),
    `${root}/runtime/wordpress/wp-content/uploads/lps-qa-media`,
    { recursive: true },
  );
  const muPluginDir = `${root}/runtime/wordpress/wp-content/mu-plugins`;
  await mkdir(muPluginDir, { recursive: true });
  for (const plugin of muPlugins) await cp(plugin.absolute, `${muPluginDir}/${plugin.target}`);
  for (const plugin of muPlugins) {
    const installed = sha(await readFile(`${muPluginDir}/${plugin.target}`));
    if (installed !== plugin.sha256)
      throw new ProvisionError(
        "provisioned-mu-plugin-mismatch",
        `${muPluginDir}/${plugin.target}: expected ${plugin.sha256}, got ${installed}`,
      );
  }
  await cp(
    path.join(repoRoot, "scripts/qa/task24/inspect.php"),
    `${muPluginDir}/lps-zzz-task24k.php`,
  );
  const provisionedTree = rollup(
    await fileHashes(`${root}/runtime/wordpress`, "", acceptArchivePath),
  );
  if (provisionedTree !== archive.wordpressTreeHash)
    throw new ProvisionError(
      "incomplete-provisioned-tree",
      `${root}/runtime/wordpress does not reproduce the verified archive tree: expected ${archive.wordpressTreeHash}, got ${provisionedTree}`,
    );
  const sourceFiles = [
    ...(await fileHashes(`${root}/runtime/lps-theme`, "lps-theme")),
    ...(await fileHashes(`${root}/runtime/lps-content-model`, "lps-content-model")),
  ];
  const identity = {
    baseline: "73dac5ccff03d5da15f31637a94fb3a8f508c6c2",
    createdAt: new Date().toISOString(),
    sourceHash: sha(JSON.stringify(sourceFiles)),
    sourceFiles,
    baselineDatabase: archive.database,
    historicalFixedDatabase: archive.historicalFixedDatabase,
    media: await fileHashes(`${root}/runtime/wordpress/wp-content/uploads/lps-qa-media`),
    archive: {
      history: historical,
      manifest: path.relative(repoRoot, archiveIdentityPath),
      manifestHash,
      wordpressFileCount: archive.wordpressFileCount,
      wordpressTreeHash: archive.wordpressTreeHash,
      mediaFileCount: archive.mediaFileCount,
      mediaTreeHash: archive.mediaTreeHash,
      verifiedAt: archive.verifiedAt,
    },
    muPlugins: muPlugins.map(({ target, source, sha256, pinned }) => ({
      target,
      source,
      sha256,
      pinned,
    })),
    administrator,
  };
  await writeFile(`${root}/manifests/identity.json`, JSON.stringify(identity, null, 2));
  return identity;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const identity = await prepare();
    console.log(
      JSON.stringify(
        {
          provisioned: true,
          root,
          history: historical,
          archive: identity.archive,
          muPlugins: identity.muPlugins.map((plugin) => plugin.target),
          mediaFiles: identity.media.length,
          sourceFiles: identity.sourceFiles.length,
          sourceHash: identity.sourceHash,
          administrator: identity.administrator,
        },
        null,
        2,
      ),
    );
  } catch (error) {
    console.error(`PROVISION FAILED [${error.code ?? error.name}] ${error.message}`);
    process.exitCode = 1;
  }
}

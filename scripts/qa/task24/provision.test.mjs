// Provisioning-contract tests: every missing, mismatched or stale input must fail loudly.
// A provisioner that exits 0 has validated its inputs; these tests pin that promise.
import assert from "node:assert/strict";
import { mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import { DatabaseSync } from "node:sqlite";
import test, { after } from "node:test";
import {
  acceptArchivePath,
  archiveIdentityPath,
  assertProvisioned,
  fileHashes,
  loadArchiveManifest,
  repoRoot,
  rollup,
  sha,
  verifyAdministrator,
  verifyArchive,
  verifyDurableMuPlugins,
} from "./prepare.mjs";

const scratch = [];
after(async () => {
  for (const dir of scratch) await rm(dir, { recursive: true, force: true });
});
async function workspace(name) {
  const dir = await mkdtemp(path.join(tmpdir(), `lps-${name}-`));
  scratch.push(dir);
  return dir;
}

const ADMIN_CAPABILITIES = 'a:1:{s:13:"administrator";b:1;}';
function writeUsers(file, rows) {
  const db = new DatabaseSync(file);
  db.exec("CREATE TABLE wp_users (ID INTEGER PRIMARY KEY)");
  db.exec("CREATE TABLE wp_usermeta (user_id INTEGER, meta_key TEXT, meta_value TEXT)");
  for (const row of rows) {
    db.prepare("INSERT INTO wp_users (ID) VALUES (?)").run(row.id);
    db.prepare("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, ?, ?)").run(
      row.id,
      "wp_capabilities",
      row.capabilities,
    );
  }
  db.close();
}

/** Builds a synthetic archive plus durable inputs and the manifest that describes them. */
async function fixture(users = [{ id: 1, capabilities: ADMIN_CAPABILITIES }]) {
  const history = await workspace("archive");
  const wordpress = path.join(history, "wordpress");
  await mkdir(path.join(wordpress, "wp-content/plugins/example"), { recursive: true });
  await mkdir(path.join(wordpress, "wp-content/database"), { recursive: true });
  await writeFile(path.join(wordpress, "index.php"), "<?php // fixture front controller\n");
  await writeFile(path.join(wordpress, "wp-content/plugins/example/example.php"), "<?php // x\n");
  writeUsers(path.join(wordpress, "wp-content/database/.ht.sqlite"), users);
  await writeFile(path.join(history, "fixed.sqlite"), "historical provenance bytes\n");
  const durable = await workspace("durable");
  await mkdir(path.join(durable, "media"), { recursive: true });
  await writeFile(path.join(durable, "media", "asset.txt"), "media bytes\n");
  const muPlugin = path.join(durable, "lps-0-content-model-loader.php");
  await writeFile(muPlugin, "<?php // durable loader\n");
  const tree = await fileHashes(wordpress, "", acceptArchivePath);
  const media = await fileHashes(path.join(durable, "media"));
  const manifest = {
    schemaVersion: 1,
    archive: {
      wordpress: { path: "wordpress", fileCount: tree.length, treeHash: rollup(tree) },
      database: {
        path: "wordpress/wp-content/database/.ht.sqlite",
        sha256: sha(await readFile(path.join(wordpress, "wp-content/database/.ht.sqlite"))),
      },
      historicalFixedDatabase: {
        path: "fixed.sqlite",
        sha256: sha(await readFile(path.join(history, "fixed.sqlite"))),
      },
    },
    durable: {
      media: {
        path: path.join(durable, "media"),
        fileCount: media.length,
        treeHash: rollup(media),
      },
      muPlugins: [
        {
          target: "lps-0-content-model-loader.php",
          source: muPlugin,
          sha256: sha(await readFile(muPlugin)),
        },
      ],
    },
    administrator: { id: 1, role: "administrator" },
  };
  return { history, wordpress, durable, manifest };
}

const rejects = async (run, code) =>
  await assert.rejects(run, (error) => {
    assert.equal(error.code, code, `${error.code}: ${error.message}`);
    return true;
  });

test("matching inputs verify and report the observed identity", async () => {
  const { history, manifest } = await fixture();
  const observed = await verifyArchive(manifest, history);
  assert.equal(observed.wordpressTreeHash, manifest.archive.wordpress.treeHash);
  assert.equal(observed.wordpressFileCount, manifest.archive.wordpress.fileCount);
  assert.equal(observed.database, manifest.archive.database.sha256);
});

test("a missing archive fails loudly instead of provisioning", async () => {
  const { history, manifest } = await fixture();
  await rm(path.join(history, "wordpress"), { recursive: true, force: true });
  await rejects(verifyArchive(manifest, history), "missing-provisioning-input");
  await rejects(
    verifyArchive(manifest, path.join(history, "absent")),
    "missing-provisioning-input",
  );
});

test("one changed archive byte fails the identity check", async () => {
  const { history, wordpress, manifest } = await fixture();
  await writeFile(path.join(wordpress, "index.php"), "<?php // tampered front controller\n");
  await rejects(verifyArchive(manifest, history), "archive-identity-mismatch");
});

test("an extra unexpected archive file fails the identity check", async () => {
  const { history, wordpress, manifest } = await fixture();
  await writeFile(path.join(wordpress, "wp-content/plugins/example/extra.php"), "<?php // new\n");
  await rejects(verifyArchive(manifest, history), "archive-identity-mismatch");
});

test("a corrupt fixture database fails the identity check", async () => {
  const { history, wordpress, manifest } = await fixture();
  await writeFile(path.join(wordpress, "wp-content/database/.ht.sqlite"), "not a database");
  await rejects(verifyArchive(manifest, history), "archive-identity-mismatch");
});

test("excluded historical noise never changes the verified identity", async () => {
  const { history, wordpress, manifest } = await fixture();
  await writeFile(path.join(wordpress, "wp-content/debug.log"), "late log line\n");
  await mkdir(path.join(wordpress, "wp-content/mu-plugins"), { recursive: true });
  await writeFile(path.join(wordpress, "wp-content/mu-plugins/archived.php"), "<?php // stale\n");
  const observed = await verifyArchive(manifest, history);
  assert.equal(observed.wordpressTreeHash, manifest.archive.wordpress.treeHash);
});

test("a missing or altered pinned durable mu-plugin fails; the archive is no fallback", async () => {
  const { manifest } = await fixture();
  const absent = structuredClone(manifest);
  absent.durable.muPlugins[0].source = path.join(manifest.durable.muPlugins[0].source, "absent");
  await rejects(verifyDurableMuPlugins(absent), "missing-durable-mu-plugin");
  const altered = structuredClone(manifest);
  altered.durable.muPlugins[0].sha256 = "0".repeat(64);
  await rejects(verifyDurableMuPlugins(altered), "durable-mu-plugin-mismatch");
});

test("an unpinned durable mu-plugin is still required, and its bytes are recorded", async () => {
  const { manifest } = await fixture();
  const unpinned = structuredClone(manifest);
  unpinned.durable.muPlugins[0].sha256 = undefined;
  const source = unpinned.durable.muPlugins[0].source;
  await writeFile(source, "<?php // this lane owns and edits its own fixture\n");
  const [resolved] = await verifyDurableMuPlugins(unpinned);
  assert.equal(resolved.pinned, false);
  assert.equal(resolved.sha256, sha(await readFile(source)));
  await writeFile(source, "");
  await rejects(verifyDurableMuPlugins(unpinned), "empty-durable-mu-plugin");
  await rm(source);
  await rejects(verifyDurableMuPlugins(unpinned), "missing-durable-mu-plugin");
});

test("the fixture administrator is validated, not assumed", async () => {
  const { wordpress } = await fixture();
  const database = path.join(wordpress, "wp-content/database/.ht.sqlite");
  assert.deepEqual(verifyAdministrator(database, { id: 1, role: "administrator" }), {
    id: 1,
    role: "administrator",
    verified: true,
  });
  assert.throws(() => verifyAdministrator(database, { id: 7, role: "administrator" }), {
    code: "missing-fixture-administrator",
  });
  const editor = await workspace("editor");
  const editorDatabase = path.join(editor, "editor.sqlite");
  writeUsers(editorDatabase, [{ id: 1, capabilities: 'a:1:{s:6:"editor";b:1;}' }]);
  assert.throws(() => verifyAdministrator(editorDatabase, { id: 1, role: "administrator" }), {
    code: "missing-administrator-capability",
  });
  assert.throws(() => verifyAdministrator(database, { id: 0, role: "administrator" }), {
    code: "invalid-administrator-expectation",
  });
});

test("a phase reusing an earlier run rejects missing, stale or edited fixture state", async () => {
  const { durable, manifest } = await fixture();
  const target = await workspace("root");
  await rejects(assertProvisioned(target), "missing-provisioned-fixture");
  await mkdir(`${target}/manifests`, { recursive: true });
  await writeFile(`${target}/manifests/identity.json`, JSON.stringify({ sourceHash: "x" }));
  await rejects(assertProvisioned(target), "stale-fixture-identity");
  const plugin = manifest.durable.muPlugins[0];
  await writeFile(
    `${target}/manifests/identity.json`,
    JSON.stringify({
      muPlugins: [{ target: plugin.target, source: plugin.source, sha256: plugin.sha256 }],
      administrator: { id: 1, role: "administrator" },
    }),
  );
  await rejects(assertProvisioned(target), "missing-provisioned-mu-plugin");
  const muPluginDir = `${target}/runtime/wordpress/wp-content/mu-plugins`;
  await mkdir(muPluginDir, { recursive: true });
  await mkdir(`${target}/runtime/wordpress/wp-content/database`, { recursive: true });
  await writeFile(`${muPluginDir}/${plugin.target}`, "<?php // edited after provisioning\n");
  await rejects(assertProvisioned(target), "provisioned-mu-plugin-mismatch");
  await writeFile(
    `${muPluginDir}/${plugin.target}`,
    await readFile(path.join(durable, "lps-0-content-model-loader.php")),
  );
  writeUsers(`${target}/runtime/wordpress/wp-content/database/.ht.sqlite`, [
    { id: 1, capabilities: ADMIN_CAPABILITIES },
  ]);
  const identity = await assertProvisioned(target);
  assert.equal(identity.administrator.id, 1);
});

test("the checked-in manifest declares both essential mu-plugins and matches the repository", async () => {
  const { manifest } = await loadArchiveManifest();
  const declared = manifest.durable.muPlugins.map((plugin) => plugin.target);
  for (const essential of ["lps-0-content-model-loader.php", "lps-zz-t24-harness.php"]) {
    const entry = manifest.durable.muPlugins.find((plugin) => plugin.target === essential);
    assert.ok(entry, `${essential} must be a declared durable input`);
    assert.match(entry.sha256 ?? "", /^[a-f0-9]{64}$/, `${essential} must be pinned`);
  }
  const resolved = await verifyDurableMuPlugins(manifest);
  assert.equal(resolved.length, declared.length);
  for (const plugin of resolved)
    assert.ok(
      plugin.absolute.startsWith(repoRoot),
      `${plugin.target} must resolve inside the worktree`,
    );
  // Machine-consumed shape only: the manifest may describe credential policy in prose,
  // but must carry no credential field and no secret-shaped value.
  const keys = [];
  const values = [];
  const walk = (node) => {
    if (Array.isArray(node)) return node.forEach(walk);
    if (node && typeof node === "object")
      return Object.entries(node).forEach(([key, value]) => {
        keys.push(key);
        walk(value);
      });
    if (typeof node === "string") values.push(node);
  };
  walk(JSON.parse(await readFile(archiveIdentityPath, "utf8")));
  for (const forbidden of ["user_pass", "user_login", "user_email", "password", "secret", "token"])
    assert.ok(!keys.includes(forbidden), `manifest must not carry a ${forbidden} field`);
  for (const value of values)
    assert.ok(
      !/\$P\$|\$wp\$|\$2y\$|PRIVATE KEY/.test(value),
      "manifest must not embed credential material",
    );
});

import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import test from "node:test";
import { fileURLToPath } from "node:url";

const root = fileURLToPath(new URL("../", import.meta.url));
test("Composer lint resolves the active worktree vendor executable", () => {
  const result = spawnSync(`${root}tools/composer`, ["lint", "--version"], {
    cwd: root,
    encoding: "utf8",
    timeout: 120000,
  });
  assert.ifError(result.error);
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.match(result.stdout, /PHP_CodeSniffer version [0-9]/);
});

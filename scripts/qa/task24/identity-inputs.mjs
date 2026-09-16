// Read-only reporter for the observed identity of every provisioning input.
// It never writes tests/fixtures/task24/archive-identity.json: accepting a new input
// identity stays an explicit, reviewed edit of that checked-in expectation.
import { readFile } from "node:fs/promises";
import path from "node:path";
import {
  acceptArchivePath,
  fileHashes,
  historical,
  loadArchiveManifest,
  repoRoot,
  rollup,
  sha,
} from "./prepare.mjs";

const { manifest } = await loadArchiveManifest();
const wordpress = path.join(historical, manifest.archive.wordpress.path);
const tree = await fileHashes(wordpress, "", acceptArchivePath);
const media = await fileHashes(path.resolve(repoRoot, manifest.durable.media.path));
const muPlugins = [];
for (const plugin of manifest.durable.muPlugins)
  muPlugins.push({
    target: plugin.target,
    source: plugin.source,
    sha256: sha(await readFile(path.resolve(repoRoot, plugin.source))),
  });
console.log(
  JSON.stringify(
    {
      history: historical,
      archive: {
        wordpress: {
          path: manifest.archive.wordpress.path,
          fileCount: tree.length,
          treeHash: rollup(tree),
        },
        database: {
          path: manifest.archive.database.path,
          sha256: sha(await readFile(path.join(historical, manifest.archive.database.path))),
        },
        historicalFixedDatabase: {
          path: manifest.archive.historicalFixedDatabase.path,
          sha256: sha(
            await readFile(path.join(historical, manifest.archive.historicalFixedDatabase.path)),
          ),
        },
      },
      durable: {
        media: {
          path: manifest.durable.media.path,
          fileCount: media.length,
          treeHash: rollup(media),
        },
        muPlugins,
      },
    },
    null,
    2,
  ),
);

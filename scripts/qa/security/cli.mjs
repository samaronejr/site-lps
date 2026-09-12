import { writeFile } from "node:fs/promises";
import { passiveScan } from "./passive-scan.mjs";

const PUBLIC_PATHS = [
  "/pt-br/",
  "/en/",
  "/pt-br/privacidade/",
  "/en/privacy/",
  "/pt-br/busca/?q=lps",
  "/en/search/?q=lps",
  "/wp-json/",
  "/wp-json/wp/v2/people?per_page=5",
  "/this-path-does-not-exist",
];

const RESTRICTED_PATHS = ["/wp-login.php", "/wp-admin/", "/xmlrpc.php", "/.env", "/readme.html"];

function option(argv, name, fallback) {
  const index = argv.indexOf(name);
  return index === -1 ? fallback : argv[index + 1];
}

export async function runSecurityCli(argv) {
  const origin = option(argv, "--origin", process.env.LPS_BASE_URL ?? "http://127.0.0.1:8894");
  const report = await passiveScan({
    origin,
    targets: [...PUBLIC_PATHS, ...RESTRICTED_PATHS],
    publicPaths: PUBLIC_PATHS,
  });
  const output = `${JSON.stringify(report, null, 2)}\n`;
  const reportPath = option(argv, "--report");
  if (reportPath) await writeFile(reportPath, output);
  process.stdout.write(output);
  if (report.status !== "passed") process.exitCode = 1;
}

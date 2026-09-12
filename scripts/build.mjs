import { createHash } from "node:crypto";
import { cp, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import {
  collectThemeAssets,
  evaluateBudget,
  formatBudgetReport,
} from "./lib/performance-budget.mjs";

const artifacts = ["wp-content/plugins/lps-content-model", "wp-content/themes/lps-theme"];

await rm("dist", { force: true, recursive: true });
await mkdir("dist", { recursive: true });

const manifest = {};
for (const artifact of artifacts) {
  const destination = `dist/${artifact.split("/").at(-1)}`;
  await cp(artifact, destination, {
    recursive: true,
    filter: (source) => !/(?:\/tests(?:\/|$)|\/seed-debug\.txt$)/.test(source),
  });
  const entry = artifact.includes("plugins") ? "lps-content-model.php" : "theme.json";
  const bytes = await readFile(`${destination}/${entry}`);
  manifest[destination] = createHash("sha256").update(bytes).digest("hex");
}

await cp("wp-content/mu-plugins", "dist/mu-plugins", { recursive: true });
manifest["dist/mu-plugins/lps-security.php"] = createHash("sha256")
  .update(await readFile("dist/mu-plugins/lps-security.php"))
  .digest("hex");

await writeFile("dist/manifest.json", `${JSON.stringify(manifest, null, 2)}\n`);

const budgetReport = evaluateBudget(collectThemeAssets("dist/lps-theme"));
await writeFile("dist/performance-budget.json", `${JSON.stringify(budgetReport, null, 2)}\n`);
console.log(formatBudgetReport(budgetReport));
if (!budgetReport.pass) {
  console.error("Todo 21 compressed initial budgets exceeded:");
  for (const violation of budgetReport.violations) {
    console.error(
      `  ${violation.category}: ${violation.actual} B > ${violation.limit} B (${violation.assets.join(", ")})`,
    );
  }
  process.exitCode = 1;
}

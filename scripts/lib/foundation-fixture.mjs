import { readFile } from "node:fs/promises";

export async function readFoundationFixture(path) {
  const fixture = JSON.parse(await readFile(path, "utf8"));

  if (fixture.schemaVersion !== 1) {
    throw new Error("foundation fixture schemaVersion must equal 1");
  }
  if (fixture.plugin?.slug !== "lps-content-model") {
    throw new Error("foundation fixture must name the lps-content-model plugin");
  }
  if (fixture.theme?.slug !== "lps-theme") {
    throw new Error("foundation fixture must name the lps-theme theme");
  }
  if (JSON.stringify(fixture.locales) !== JSON.stringify(["pt-BR", "en"])) {
    throw new Error("foundation fixture locales must be deterministic: pt-BR, en");
  }

  return fixture;
}

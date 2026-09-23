import { mkdir, readdir, readFile, writeFile } from "node:fs/promises";
import { join } from "node:path";

export const IA_FIXTURE_PATH = "tests/fixtures/ia/routes.json";
export const TAXONOMY_DIRECTORY = "content/taxonomies";
export const IA_OUTPUT_DIRECTORY = ".omo/evidence/task-5/generated";

const LOCALES = ["pt-br", "en"];
const REQUIRED_NAVIGATION = [
  ["about", "Sobre", "About"],
  ["research", "Pesquisa", "Research"],
  ["people", "Pessoas", "People"],
  ["publications", "Publicações", "Publications"],
  ["infrastructure", "Infraestrutura", "Infrastructure"],
  ["opportunities", "Oportunidades", "Opportunities"],
  ["news", "Notícias", "News"],
];
const REQUIRED_FOOTER = [
  ["contact", "Contato", "Contact"],
  ["events", "Eventos", "Events"],
  ["privacy", "Privacidade", "Privacy"],
  ["accessibility", "Acessibilidade", "Accessibility"],
];
const REQUIRED_PAGE_KEYS = new Set([
  "home",
  "about",
  "research",
  "research-area-detail",
  "projects",
  "project-detail",
  "people",
  "person-detail",
  "publications",
  "publication-detail",
  "infrastructure",
  "opportunities",
  "opportunity-detail",
  "news",
  "news-detail",
  "events",
  "event-detail",
  "collaborate",
  "contact",
  "privacy",
  "accessibility",
  "teaching",
  "course-detail",
  "offering-detail",
]);
const REQUIRED_RESEARCH_AREAS = new Set([
  "instrumentation",
  "signal-processing",
  "computational-intelligence",
  "software-engineering",
]);
const REQUIRED_APPLICATION_DOMAINS = new Set([
  "electrical-nuclear-energy",
  "oil-and-gas",
  "high-energy-physics",
  "defense",
  "medicine",
  "veterinary-science",
  "data-quality",
]);
const FACET_SOURCES = new Set([
  "record-type",
  "research-area",
  "application-domain",
  "project-status",
  "publication-type",
  "publication-year",
  "person-role",
  "person-status",
  "opportunity-type",
]);
const STABLE_KEY = /^[a-z][a-z0-9-]*$/;

function issue(code, path, message) {
  return { code, path, message };
}

function splitTopLevel(value, delimiter = ",") {
  const values = [];
  let depth = 0;
  let quote = null;
  let start = 0;
  for (let index = 0; index < value.length; index += 1) {
    const character = value[index];
    if (quote) {
      if (character === quote && value[index - 1] !== "\\") quote = null;
    } else if (character === '"' || character === "'") {
      quote = character;
    } else if (character === "[" || character === "{") {
      depth += 1;
    } else if (character === "]" || character === "}") {
      depth -= 1;
    } else if (character === delimiter && depth === 0) {
      values.push(value.slice(start, index).trim());
      start = index + 1;
    }
  }
  values.push(value.slice(start).trim());
  return values.filter(Boolean);
}

function splitKeyValue(value) {
  const [key, ...rest] = splitTopLevel(value, ":");
  return [key?.trim(), rest.join(":").trim()];
}

function parseScalar(raw) {
  const value = raw.trim();
  if (value === "true") return true;
  if (value === "false") return false;
  if (/^\d+$/.test(value)) return Number(value);
  if (value.startsWith("[") && value.endsWith("]")) {
    return splitTopLevel(value.slice(1, -1)).map(parseScalar);
  }
  if (value.startsWith("{") && value.endsWith("}")) {
    return Object.fromEntries(
      splitTopLevel(value.slice(1, -1)).map((entry) => {
        const [key, nestedValue] = splitKeyValue(entry);
        return [key, parseScalar(nestedValue)];
      }),
    );
  }
  if (
    (value.startsWith('"') && value.endsWith('"')) ||
    (value.startsWith("'") && value.endsWith("'"))
  ) {
    return value.slice(1, -1);
  }
  return value;
}

export function parseContractYaml(source) {
  const lines = source
    .split(/\r?\n/)
    .map((raw) => ({ indent: raw.match(/^\s*/)[0].length, text: raw.trim() }))
    .filter((line) => line.text && !line.text.startsWith("#"));

  function parseNode(index, indent) {
    const isList = lines[index]?.indent === indent && lines[index].text.startsWith("- ");
    const result = isList ? [] : {};
    while (index < lines.length && lines[index].indent === indent) {
      const line = lines[index];
      if (isList) {
        if (!line.text.startsWith("- ")) break;
        const remainder = line.text.slice(2).trim();
        index += 1;
        if (!remainder) {
          const child = parseNode(index, lines[index]?.indent);
          result.push(child.value);
          index = child.index;
        } else {
          const [key, value] = splitKeyValue(remainder);
          const item = { [key]: parseScalar(value) };
          if (lines[index]?.indent > indent) {
            const child = parseNode(index, lines[index].indent);
            Object.assign(item, child.value);
            index = child.index;
          }
          result.push(item);
        }
      } else {
        if (line.text.startsWith("- ")) break;
        const [key, value] = splitKeyValue(line.text);
        index += 1;
        if (value) {
          result[key] = parseScalar(value);
        } else if (lines[index]?.indent > indent) {
          const child = parseNode(index, lines[index].indent);
          result[key] = child.value;
          index = child.index;
        } else {
          result[key] = null;
        }
      }
    }
    return { index, value: result };
  }

  return lines.length === 0 ? {} : parseNode(0, lines[0].indent).value;
}

async function readTaxonomies(directory) {
  const files = (await readdir(directory)).filter((name) => name.endsWith(".yaml")).sort();
  return Promise.all(
    files.map(async (name) => ({
      file: name,
      value: parseContractYaml(await readFile(join(directory, name), "utf8")),
    })),
  );
}

function validateNavigation(items, required, pages, path, issues) {
  const seen = new Set();
  for (const [key, pt, en] of required) {
    const item = items?.find((candidate) => candidate.key === key);
    if (!item || item.labels?.["pt-br"] !== pt || item.labels?.en !== en) {
      issues.push(
        issue("REQUIRED_NAVIGATION_MISSING", path, `Missing approved bilingual item: ${key}.`),
      );
    }
    if (!pages.has(key)) {
      issues.push(
        issue("NAVIGATION_DESTINATION_MISSING", path, `Navigation destination is missing: ${key}.`),
      );
    }
  }
  for (const item of items ?? []) {
    if (!item.key || seen.has(item.key)) {
      issues.push(
        issue("NAVIGATION_KEY_INVALID", path, "Navigation keys must be unique and non-empty."),
      );
    }
    seen.add(item.key);
  }
}

function validateTerms(taxonomies, issues) {
  const declared = new Map(taxonomies.map((taxonomy) => [taxonomy.id, taxonomy]));
  const termsByTaxonomy = new Map();
  for (const id of ["research-area", "application-domain"]) {
    const taxonomy = declared.get(id);
    if (!taxonomy) {
      issues.push(issue("TAXONOMY_MISSING", "taxonomies", `Required taxonomy is missing: ${id}.`));
      termsByTaxonomy.set(id, []);
      continue;
    }
    if (taxonomy.allowFreeTags !== false) {
      issues.push(issue("FREE_TAGS_FORBIDDEN", `taxonomies.${id}`, "Free tags must be disabled."));
    }
    termsByTaxonomy.set(id, taxonomy.terms ?? []);
  }
  for (const taxonomy of taxonomies) {
    if (taxonomy.allowFreeTags !== false) {
      issues.push(
        issue("FREE_TAGS_FORBIDDEN", `taxonomies.${taxonomy.id}`, "Free tags must be disabled."),
      );
    }
  }

  const validateSet = (id, required, extraCode) => {
    const terms = termsByTaxonomy.get(id) ?? [];
    const keys = new Set();
    for (const [index, term] of terms.entries()) {
      const path = `taxonomies.${id}.terms[${index}]`;
      if (!STABLE_KEY.test(term.key ?? "") || keys.has(term.key)) {
        issues.push(
          issue(
            "STABLE_KEY_INVALID",
            `${path}.key`,
            "Stable keys must be unique lowercase ASCII slugs.",
          ),
        );
      }
      keys.add(term.key);
      if (!term.labels?.["pt-br"] || !term.labels?.en) {
        issues.push(
          issue(
            "TERM_LABELS_MISSING",
            `${path}.labels`,
            "Terms require Portuguese and English labels.",
          ),
        );
      }
      if (!Array.isArray(term.synonyms?.["pt-br"]) || !Array.isArray(term.synonyms?.en)) {
        issues.push(
          issue(
            "TERM_SYNONYMS_MISSING",
            `${path}.synonyms`,
            "Terms require bilingual synonym arrays.",
          ),
        );
      }
      if (required.has(term.key) && term.evidence?.type !== "plan-approved-seed") {
        issues.push(
          issue(
            "SEED_EVIDENCE_INVALID",
            `${path}.evidence`,
            "Seed terms require their plan citation.",
          ),
        );
      }
      if (!required.has(term.key)) {
        const citedLpsSource =
          term.evidence?.type === "authoritative-lps-source" &&
          /^https:\/\/(?:lps\.ufrj\.br|sites\.google\.com\/lps\.ufrj\.br)\//.test(
            term.evidence?.citation ?? "",
          );
        if (!citedLpsSource) {
          issues.push(
            issue(
              extraCode,
              `${path}.key`,
              `Unapproved ${id} requires an authoritative LPS citation.`,
            ),
          );
        }
      }
    }
    for (const key of required) {
      if (!keys.has(key)) {
        issues.push(
          issue(
            "REQUIRED_SEED_MISSING",
            `taxonomies.${id}`,
            `Required approved seed is missing: ${key}.`,
          ),
        );
      }
    }
  };

  validateSet("research-area", REQUIRED_RESEARCH_AREAS, "STABLE_KEY_TRANSLATED");
  validateSet("application-domain", REQUIRED_APPLICATION_DOMAINS, "UNSUPPORTED_DOMAIN");
  return termsByTaxonomy;
}

function validateFacets(facets, issues) {
  const seen = new Set();
  for (const [index, facet] of facets.entries()) {
    const path = `searchFacets[${index}]`;
    if (!STABLE_KEY.test(facet.id ?? "") || seen.has(facet.id)) {
      issues.push(
        issue("SEARCH_FACET_INVALID", `${path}.id`, "Search facet ids must be unique stable keys."),
      );
    }
    seen.add(facet.id);
    if (!facet.labels?.["pt-br"] || !facet.labels?.en || !FACET_SOURCES.has(facet.source)) {
      issues.push(
        issue(
          "SEARCH_FACET_INVALID",
          path,
          "Search facets require bilingual labels and an approved source.",
        ),
      );
    }
  }
  if (facets.length !== 9) {
    issues.push(
      issue(
        "SEARCH_FACET_COUNT_INVALID",
        "searchFacets",
        "Exactly nine approved search facets are required.",
      ),
    );
  }
}

function validateRoutes(contract, issues) {
  const pages = contract.pages ?? [];
  const pageMap = new Map();
  const destinations = new Map(LOCALES.map((locale) => [locale, new Set()]));
  for (const [index, page] of pages.entries()) {
    const path = `pages[${index}]`;
    if (!STABLE_KEY.test(page.key ?? "") || pageMap.has(page.key)) {
      issues.push(
        issue(
          "PAGE_KEY_INVALID",
          `${path}.key`,
          "Page keys must be unique language-neutral stable keys.",
        ),
      );
    }
    pageMap.set(page.key, page);
    if (!REQUIRED_PAGE_KEYS.has(page.key)) {
      issues.push(
        issue("UNCLASSIFIED_ROUTE", `${path}.key`, `Page key is not approved: ${page.key}.`),
      );
    }
    if (!contract.audiences?.includes(page.primaryAudience)) {
      issues.push(
        issue(
          "PRIMARY_AUDIENCE_INVALID",
          `${path}.primaryAudience`,
          "Each page requires one approved primary audience.",
        ),
      );
    }
    if (!STABLE_KEY.test(page.canonicalTask ?? "")) {
      issues.push(
        issue(
          "CANONICAL_TASK_INVALID",
          `${path}.canonicalTask`,
          "Each page requires one canonical task.",
        ),
      );
    }
    if (Array.isArray(page.tags) && page.tags.length > 0) {
      issues.push(issue("FREE_TAGS_FORBIDDEN", `${path}.tags`, "Pages cannot declare free tags."));
    }
    const maxDepth = page.maxDepth ?? 2;
    if (!Number.isInteger(maxDepth) || maxDepth < 2 || maxDepth > 5) {
      issues.push(
        issue(
          "MAX_DEPTH_INVALID",
          `${path}.maxDepth`,
          "maxDepth must be an integer between 2 and 5.",
        ),
      );
    }
    for (const locale of LOCALES) {
      const route = page.routes?.[locale];
      const routePath = `${path}.routes.${locale}`;
      if (typeof route !== "string" || !route.startsWith(`/${locale}/`) || !route.endsWith("/")) {
        issues.push(
          issue("LOCALE_ROUTE_INVALID", routePath, `Route must use the /${locale}/ locale root.`),
        );
        continue;
      }
      const depth = route.split("/").filter(Boolean).length - 1;
      if (depth > maxDepth) {
        issues.push(
          issue(
            "ROUTE_DEPTH_EXCEEDED",
            routePath,
            `Routes may be at most ${maxDepth} levels below locale.`,
          ),
        );
      }
      if (destinations.get(locale).has(route)) {
        issues.push(
          issue("DUPLICATE_DESTINATION", routePath, `Duplicate ${locale} destination: ${route}.`),
        );
      }
      destinations.get(locale).add(route);
    }
  }
  for (const page of pages) {
    if (page.parent !== null && !pageMap.has(page.parent)) {
      issues.push(
        issue("ORPHAN_PAGE", `pages.${page.key}.parent`, `Parent does not exist: ${page.parent}.`),
      );
    }
    if (page.parent && pageMap.get(page.parent)?.parent) {
      issues.push(
        issue(
          "HIERARCHY_DEPTH_EXCEEDED",
          `pages.${page.key}.parent`,
          "Page hierarchy may be at most two levels.",
        ),
      );
    }
  }
  for (const key of REQUIRED_PAGE_KEYS) {
    if (!pageMap.has(key)) {
      issues.push(issue("REQUIRED_ROUTE_MISSING", "pages", `Required route is missing: ${key}.`));
    }
  }
  validateNavigation(
    contract.primaryNavigation,
    REQUIRED_NAVIGATION,
    pageMap,
    "primaryNavigation",
    issues,
  );
  validateNavigation(contract.footer, REQUIRED_FOOTER, pageMap, "footer", issues);
  return pageMap;
}

function routeRows(contract, locale) {
  return contract.pages
    .map((page) => ({
      key: page.key,
      parent: page.parent,
      path: page.routes[locale],
      primaryAudience: page.primaryAudience,
      canonicalTask: page.canonicalTask,
    }))
    .sort((left, right) => left.path.localeCompare(right.path));
}

function sitemapDiagram(rows, locale) {
  const byParent = new Map();
  for (const row of rows) {
    const parent = row.parent ?? "root";
    byParent.set(parent, [...(byParent.get(parent) ?? []), row]);
  }
  const render = (parent, depth) =>
    (byParent.get(parent) ?? [])
      .sort((left, right) => left.path.localeCompare(right.path))
      .flatMap((row) => [
        `${"  ".repeat(depth)}- [${row.key}](${row.path}) - ${row.primaryAudience}; ${row.canonicalTask}`,
        ...render(row.key, depth + 1),
      ]);
  return `# LPS sitemap (${locale})\n\n${render("root", 0).join("\n")}\n`;
}

async function writeArtifacts(contract, outputDirectory) {
  await mkdir(outputDirectory, { recursive: true });
  await Promise.all(
    LOCALES.flatMap((locale) => {
      const rows = routeRows(contract, locale);
      return [
        writeFile(
          join(outputDirectory, `routes-${locale}.json`),
          `${JSON.stringify(rows, null, 2)}\n`,
        ),
        writeFile(join(outputDirectory, `sitemap-${locale}.md`), sitemapDiagram(rows, locale)),
      ];
    }),
  );
}

export async function runInformationArchitectureQa({
  routeFixture = process.env.IA_ROUTE_FIXTURE ?? IA_FIXTURE_PATH,
  taxonomyDirectory = process.env.IA_TAXONOMY_DIRECTORY ?? TAXONOMY_DIRECTORY,
  outputDirectory = process.env.IA_OUTPUT_DIRECTORY ?? IA_OUTPUT_DIRECTORY,
} = {}) {
  const contract = JSON.parse(await readFile(routeFixture, "utf8"));
  const files = await readTaxonomies(taxonomyDirectory);
  const issues = [];
  if (
    contract.schemaVersion !== 1 ||
    !LOCALES.every((locale) => contract.locales?.includes(locale))
  ) {
    issues.push(
      issue("IA_SCHEMA_INVALID", "schemaVersion", "IA schema v1 requires pt-br and en locales."),
    );
  }
  const taxonomies = files.flatMap((file) => file.value.taxonomies ?? []);
  const facets = files.flatMap((file) => file.value.searchFacets ?? []);
  const terms = validateTerms(taxonomies, issues);
  validateFacets(facets, issues);
  validateRoutes(contract, issues);
  const counts = {
    applicationDomains: terms.get("application-domain")?.length ?? 0,
    pages: contract.pages?.length ?? 0,
    researchAreas: terms.get("research-area")?.length ?? 0,
    routes: (contract.pages?.length ?? 0) * LOCALES.length,
    searchFacets: facets.length,
  };
  if (issues.length === 0) await writeArtifacts(contract, outputDirectory);
  return {
    lane: "ia",
    status: issues.length === 0 ? "passed" : "failed",
    routeFixture,
    taxonomyDirectory,
    outputDirectory,
    counts,
    issues,
  };
}

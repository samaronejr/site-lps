/**
 * Validates a crawl or fixture snapshot of the public surface.
 *
 * Every finding names the page path it was found on, so a failure fixture can
 * prove exactly which address broke which contract. The validator never trusts
 * the renderer: it reads the shipped documents.
 */

const ALLOWED_HREFLANG = new Set(["pt-BR", "en", "x-default"]);
const EMPLOYMENT_KINDS = new Set(["employment", "staff-position", "faculty-position"]);
const SCHEMA_CODE_PREFIX = "lps_schema_";

export function validateSnapshot(snapshot, options = {}) {
  const mode = options.mode ?? "seo";
  const siteUrl = (snapshot.siteUrl ?? "").replace(/\/+$/, "");
  const documents = snapshot.documents ?? [];
  const errors = [];
  const report = (code, path, detail) => errors.push({ code, path, detail });

  const pages = documents.filter((doc) => doc.status === 200 && doc.contentType.includes("html"));
  const xml = documents.filter((doc) => doc.contentType.includes("xml"));
  const byPath = new Map(documents.map((doc) => [doc.path, doc]));
  const parsed = new Map();

  for (const page of pages) {
    parsed.set(page.path, parsePage(page, siteUrl));
  }

  validatePages(parsed, siteUrl, report);
  validateHreflang(parsed, siteUrl, report);
  validateSchema(parsed, siteUrl, report);
  validateSitemaps(xml, parsed, byPath, siteUrl, report);
  validateFeeds(xml, report);
  validateRedirects(documents, byPath, siteUrl, report);

  const filtered =
    mode === "schema"
      ? errors.filter((error) => error.code.startsWith(SCHEMA_CODE_PREFIX))
      : errors;
  filtered.sort((left, right) =>
    `${left.code}${left.path}`.localeCompare(`${right.code}${right.path}`),
  );

  return {
    lane: mode === "schema" ? "schema" : "seo",
    status: filtered.length === 0 ? "passed" : "failed",
    schemaVersion: 1,
    counts: {
      documents: documents.length,
      pages: pages.length,
      xmlDocuments: xml.length,
      jsonLdBlocks: [...parsed.values()].filter((page) => page.graph !== null).length,
      errors: filtered.length,
    },
    errors: filtered,
  };
}

function parsePage(page, siteUrl) {
  const head = page.body.slice(0, Math.max(page.body.indexOf("</head>"), 0) || page.body.length);
  const titles = [...page.body.matchAll(/<title>([\s\S]*?)<\/title>/g)].map((match) =>
    decode(match[1].trim()),
  );
  const canonicals = attributes(head, "link", "rel", "canonical").map((tag) =>
    attribute(tag, "href"),
  );
  const descriptions = metas(head, "name", "description");
  const robots = metas(head, "name", "robots");
  const alternates = attributes(head, "link", "rel", "alternate")
    .filter((tag) => attribute(tag, "hreflang") !== "")
    .map((tag) => ({ hreflang: attribute(tag, "hreflang"), href: attribute(tag, "href") }));
  const openGraph = new Map(
    [...head.matchAll(/<meta property="(og:[^"]+)" content="([^"]*)">/g)].map((match) => [
      match[1],
      decode(match[2]),
    ]),
  );
  const blocks = [
    ...page.body.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g),
  ].map((match) => match[1]);
  let graph = null;
  let graphError = "";
  if (blocks.length > 0) {
    try {
      graph = JSON.parse(blocks[0]);
    } catch (error) {
      graphError = error.message;
    }
  }
  return {
    path: page.path,
    locale: page.path.startsWith("/en/") || page.path === "/en" ? "en" : "pt-br",
    canonicalExpected: `${siteUrl}${page.path.split("?")[0]}`,
    titles,
    canonicals,
    descriptions,
    robots,
    alternates,
    openGraph,
    blocks,
    graph,
    graphError,
    record: {
      type: metas(head, "name", "lps:record-type")[0] ?? "",
      kind: metas(head, "name", "lps:record-kind")[0] ?? "",
    },
  };
}

function validatePages(parsed, siteUrl, report) {
  const titles = new Map();
  const descriptions = new Map();

  for (const page of parsed.values()) {
    if (page.titles.length === 0 || page.titles[0] === "") {
      report("lps_seo_missing_title", page.path, "no document title");
    }
    if (page.titles.length > 1) {
      report("lps_seo_duplicate_title_tag", page.path, `${page.titles.length} title tags`);
    }
    if (page.descriptions.length === 0 || page.descriptions[0] === "") {
      report("lps_seo_missing_description", page.path, "no meta description");
    }
    if (page.descriptions.length > 1) {
      report(
        "lps_seo_duplicate_description_tag",
        page.path,
        `${page.descriptions.length} description tags`,
      );
    }
    if (page.canonicals.length === 0) {
      report("lps_seo_missing_canonical", page.path, "no canonical link");
    } else if (page.canonicals.length > 1) {
      report("lps_seo_multiple_canonical", page.path, `${page.canonicals.length} canonical links`);
    } else if (page.canonicals[0] !== page.canonicalExpected) {
      report(
        "lps_seo_canonical_not_self",
        page.path,
        `canonical ${page.canonicals[0]} does not match ${page.canonicalExpected}`,
      );
    }
    if (page.canonicals.some((href) => href.includes("?"))) {
      report("lps_seo_query_string_canonical", page.path, page.canonicals[0]);
    }
    if (page.robots.length !== 1) {
      report("lps_seo_robots_directive_not_unique", page.path, `${page.robots.length} robots tags`);
    }
    for (const property of ["og:type", "og:url", "og:title", "og:locale"]) {
      if (!page.openGraph.has(property)) {
        report("lps_seo_missing_open_graph", page.path, `missing ${property}`);
      }
    }
    const openGraphUrl = page.openGraph.get("og:url");
    if (openGraphUrl !== undefined && openGraphUrl !== page.canonicalExpected) {
      report("lps_seo_open_graph_url_mismatch", page.path, `og:url ${openGraphUrl}`);
    }

    if (isIndexable(page)) {
      const title = `${page.locale}\u0000${page.titles[0] ?? ""}`;
      const description = `${page.locale}\u0000${page.descriptions[0] ?? ""}`;
      if (titles.has(title)) {
        report("lps_seo_duplicate_title", page.path, `title shared with ${titles.get(title)}`);
      } else {
        titles.set(title, page.path);
      }
      if (descriptions.has(description)) {
        report(
          "lps_seo_duplicate_description",
          page.path,
          `description shared with ${descriptions.get(description)}`,
        );
      } else {
        descriptions.set(description, page.path);
      }
    }
  }
  void siteUrl;
}

function validateHreflang(parsed, siteUrl, report) {
  for (const page of parsed.values()) {
    if (page.alternates.length === 0) {
      continue;
    }
    const tags = page.alternates.map((alternate) => alternate.hreflang);
    for (const tag of tags) {
      if (!ALLOWED_HREFLANG.has(tag)) {
        report(
          "lps_seo_invalid_language_tag",
          page.path,
          `hreflang ${tag} is not a published BCP47 tag`,
        );
      }
    }
    if (!tags.includes("x-default")) {
      report("lps_seo_missing_x_default", page.path, "alternates without x-default");
    }
    const selfLinked = page.alternates.some(
      (alternate) => alternate.href === page.canonicalExpected,
    );
    if (!selfLinked) {
      report("lps_seo_hreflang_not_self_referential", page.path, "alternates omit this page");
    }
    for (const alternate of page.alternates) {
      if (alternate.hreflang === "x-default") {
        continue;
      }
      const target = alternate.href.startsWith(siteUrl) ? alternate.href.slice(siteUrl.length) : "";
      const counterpart = parsed.get(target);
      if (counterpart === undefined) {
        report("lps_seo_hreflang_target_missing", page.path, `${alternate.href} was not served`);
        continue;
      }
      const reciprocal = counterpart.alternates.some(
        (entry) => entry.href === page.canonicalExpected,
      );
      if (!reciprocal) {
        report(
          "lps_seo_hreflang_not_reciprocal",
          page.path,
          `${alternate.href} does not link back to ${page.canonicalExpected}`,
        );
      }
    }
  }
}

function validateSchema(parsed, siteUrl, report) {
  const doiOwners = new Map();

  for (const page of parsed.values()) {
    if (page.blocks.length === 0) {
      report("lps_schema_missing_json_ld", page.path, "no JSON-LD block");
      continue;
    }
    if (page.blocks.length > 1) {
      report("lps_schema_duplicate_json_ld", page.path, `${page.blocks.length} JSON-LD blocks`);
    }
    if (page.graph === null) {
      report("lps_schema_invalid_json", page.path, page.graphError);
      continue;
    }
    if (page.graph["@context"] !== "https://schema.org") {
      report("lps_schema_invalid_context", page.path, String(page.graph["@context"]));
    }
    const nodes = Array.isArray(page.graph["@graph"]) ? page.graph["@graph"] : [];
    if (nodes.length === 0) {
      report("lps_schema_empty_graph", page.path, "graph has no nodes");
      continue;
    }

    const identifiers = new Set();
    for (const node of nodes) {
      const id = typeof node["@id"] === "string" ? node["@id"] : "";
      if (id !== "") {
        if (identifiers.has(id)) {
          report("lps_schema_duplicate_node", page.path, `duplicate @id ${id}`);
        }
        identifiers.add(id);
      }
      if (typeof node["@type"] !== "string" || node["@type"] === "") {
        report("lps_schema_missing_type", page.path, `node ${id} has no @type`);
      }
      for (const forbidden of ["aggregateRating", "review", "ratingValue"]) {
        if (Object.hasOwn(node, forbidden)) {
          report("lps_schema_invented_rating", page.path, `node ${id} carries ${forbidden}`);
        }
      }
    }

    const types = nodes.map((node) => node["@type"]);
    if (!types.includes("WebSite")) {
      report("lps_schema_missing_website", page.path, "no WebSite node");
    }
    if (!types.includes("ResearchOrganization")) {
      report("lps_schema_missing_organization", page.path, "no ResearchOrganization node");
    }
    if (!isLocaleHome(page.path) && !types.includes("BreadcrumbList")) {
      report("lps_schema_missing_breadcrumb", page.path, "no BreadcrumbList node");
    }

    const jobPosting = nodes.find((node) => node["@type"] === "JobPosting");
    if (
      jobPosting !== undefined &&
      page.record.type === "lps_opportunity" &&
      !EMPLOYMENT_KINDS.has(page.record.kind)
    ) {
      report(
        "lps_schema_scholarship_as_jobposting",
        page.path,
        `opportunity kind "${page.record.kind}" published as JobPosting`,
      );
    }

    for (const node of nodes) {
      for (const value of dois(node)) {
        const doi = `${page.locale}\u0000${value}`;
        const owner = doiOwners.get(doi);
        if (owner !== undefined && owner !== page.path) {
          report("lps_schema_duplicate_doi", page.path, `${value} already published on ${owner}`);
        } else {
          doiOwners.set(doi, page.path);
        }
      }
    }
  }
  void siteUrl;
}

function validateSitemaps(xml, parsed, byPath, siteUrl, report) {
  const sitemaps = xml.filter(
    (doc) => doc.path.startsWith("/sitemap") && doc.path !== "/sitemap.xml",
  );
  const index = xml.find((doc) => doc.path === "/sitemap.xml");

  if (index === undefined) {
    report("lps_seo_missing_sitemap_index", "/sitemap.xml", "sitemap index was not served");
  } else if (!wellFormed(index.body)) {
    report("lps_seo_invalid_sitemap_xml", index.path, "sitemap index is not well-formed XML");
  }

  const listed = new Map();
  for (const sitemap of sitemaps) {
    if (!wellFormed(sitemap.body)) {
      report("lps_seo_invalid_sitemap_xml", sitemap.path, "sitemap is not well-formed XML");
      continue;
    }
    const locale = sitemap.path.replace("/sitemap-", "").replace(".xml", "");
    for (const location of locations(sitemap.body)) {
      const path = location.startsWith(siteUrl) ? location.slice(siteUrl.length) : "";
      if (path === "") {
        report("lps_seo_foreign_sitemap_entry", sitemap.path, location);
        continue;
      }
      if (!path.startsWith(`/${locale}/`)) {
        report("lps_seo_cross_locale_sitemap_entry", path, `listed in ${sitemap.path}`);
      }
      if (listed.has(path)) {
        report(
          "lps_seo_duplicate_sitemap_entry",
          path,
          `listed in ${sitemap.path} and ${listed.get(path)}`,
        );
      }
      listed.set(path, sitemap.path);

      const served = byPath.get(path);
      if (served !== undefined && served.status !== 200) {
        report(
          "lps_seo_sitemap_entry_not_served",
          path,
          `listed in ${sitemap.path} but answers ${served.status}`,
        );
        continue;
      }

      const page = parsed.get(path);
      if (page === undefined) {
        continue;
      }
      if (!isIndexable(page)) {
        report(
          "lps_seo_noindex_in_sitemap",
          path,
          `robots "${page.robots[0] ?? ""}" listed in ${sitemap.path}`,
        );
      }
      if (page.canonicals[0] !== undefined && page.canonicals[0] !== page.canonicalExpected) {
        report("lps_seo_non_canonical_in_sitemap", path, `canonical ${page.canonicals[0]}`);
      }
    }
  }

  for (const page of parsed.values()) {
    if (!isIndexable(page) || isSearch(page.path)) {
      continue;
    }
    if (!listed.has(page.path)) {
      report(
        "lps_seo_sitemap_missing_page",
        page.path,
        "indexable page absent from its locale sitemap",
      );
    }
  }
}

function validateFeeds(xml, report) {
  const feeds = xml.filter((doc) => doc.path.endsWith("/feed/"));
  if (feeds.length === 0) {
    report("lps_seo_missing_feed", "/", "no news or event feed was served");
  }
  for (const feed of feeds) {
    if (!wellFormed(feed.body)) {
      report("lps_seo_feed_invalid", feed.path, "feed is not well-formed XML");
      continue;
    }
    for (const element of ["<channel>", "<title>", "<link>", "<language>"]) {
      if (!feed.body.includes(element)) {
        report("lps_seo_feed_invalid", feed.path, `feed is missing ${element}`);
      }
    }
    for (const item of feed.body.split("<item>").slice(1)) {
      if (!item.includes("<link>") || !item.includes("<pubDate>")) {
        report("lps_seo_feed_item_incomplete", feed.path, "feed item lacks a link or a pubDate");
        break;
      }
    }
  }
}

function validateRedirects(documents, byPath, siteUrl, report) {
  for (const document of documents) {
    if (document.status !== 301 && document.status !== 302) {
      continue;
    }
    if (document.status === 302) {
      report("lps_seo_temporary_redirect", document.path, `status ${document.status}`);
      continue;
    }
    const target = document.location.startsWith(siteUrl)
      ? document.location.slice(siteUrl.length)
      : document.location;
    if (target === "") {
      report("lps_seo_redirect_without_target", document.path, "301 without a Location header");
      continue;
    }
    const next = byPath.get(target);
    if (next !== undefined && (next.status === 301 || next.status === 302)) {
      report(
        "lps_seo_redirect_chain",
        document.path,
        `${target} redirects again to ${next.location}`,
      );
    }
    if (next !== undefined && next.status === 404) {
      report("lps_seo_redirect_to_missing_target", document.path, `${target} answers 404`);
    }
  }
}

function isIndexable(page) {
  return !(page.robots[0] ?? "").includes("noindex");
}

function isSearch(path) {
  return path.includes("/busca/") || path.includes("/search/");
}

function isLocaleHome(path) {
  return path === "/pt-br/" || path === "/en/";
}

function dois(node) {
  const found = [];
  const values = [node.identifier, ...(Array.isArray(node.sameAs) ? node.sameAs : [node.sameAs])];
  for (const value of values) {
    if (typeof value === "string" && value.startsWith("https://doi.org/")) {
      found.push(value);
    }
  }
  return [...new Set(found)];
}

function locations(xml) {
  return [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1].trim());
}

function wellFormed(xml) {
  if (!xml.trimStart().startsWith("<?xml")) {
    return false;
  }
  const stack = [];
  for (const match of xml.matchAll(/<(\/?)([a-zA-Z][\w:-]*)([^>]*?)(\/?)>/g)) {
    const [, closing, name, rest, selfClosing] = match;
    if (rest.startsWith("?") || selfClosing === "/") {
      continue;
    }
    if (closing === "/") {
      if (stack.pop() !== name) {
        return false;
      }
    } else {
      stack.push(name);
    }
  }
  return stack.length === 0;
}

function metas(head, attributeName, value) {
  return attributes(head, "meta", attributeName, value).map((tag) =>
    decode(attribute(tag, "content")),
  );
}

function attributes(head, tag, attributeName, value) {
  const pattern = new RegExp(`<${tag}\\b[^>]*?>`, "g");
  return [...head.matchAll(pattern)]
    .map((match) => match[0])
    .filter((found) => attribute(found, attributeName).toLowerCase() === value.toLowerCase());
}

function attribute(tag, name) {
  const match = tag.match(new RegExp(`\\b${name}=["']([^"']*)["']`, "i"));
  return match === null ? "" : match[1];
}

function decode(value) {
  return value
    .replaceAll("&quot;", '"')
    .replaceAll("&#039;", "'")
    .replaceAll("&#39;", "'")
    .replaceAll("&lt;", "<")
    .replaceAll("&gt;", ">")
    .replaceAll("&amp;", "&");
}

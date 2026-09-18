/**
 * WCAG 2.2 AA / eMAG rule engine for the LPS public surface.
 *
 * The engine is deliberately independent from axe-core and pa11y: it audits the
 * server-rendered markup, the shipped stylesheet, and the authored corpus, so a
 * defect is reported with the exact route and selector even when a browser-based
 * scanner cannot reach the state.
 */

import { readdir, readFile } from "node:fs/promises";
import path from "node:path";
import { parse } from "parse5";

const INTERACTIVE_TAGS = new Set(["a", "button", "input", "select", "textarea", "summary"]);
const HEADING_TAGS = new Set(["h1", "h2", "h3", "h4", "h5", "h6"]);
const EMBEDDED_TAGS = new Set(["audio", "video"]);
const DOWNLOAD_EXTENSIONS = [
  ".pdf",
  ".zip",
  ".bib",
  ".csv",
  ".doc",
  ".docx",
  ".xls",
  ".xlsx",
  ".ppt",
  ".pptx",
];

/** BCP47 language-tag grammar, restricted to the well-formed subset this site may emit. */
const BCP47 = /^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|\d{3}))?(-[0-9a-zA-Z]{5,8})*$/;

/** Portuguese and English markers used for language-of-parts detection. */
const LANGUAGE_MARKERS = {
  "pt-br": [
    "laboratório",
    "processamento de sinais",
    "universidade federal",
    "pesquisa",
    "publicações",
    "oportunidades",
    "notícias",
    "acessibilidade",
    "início",
  ],
  en: [
    "signal processing laboratory",
    "federal university",
    "research areas",
    "publications",
    "opportunities",
    "accessibility",
    "skip to content",
  ],
};

/* ------------------------------------------------------------------ *
 * Tree helpers
 * ------------------------------------------------------------------ */

/** Parses a document into a parse5 tree. */
export function parseDocument(html) {
  return parse(html, { sourceCodeLocationInfo: true });
}

/** Depth-first element walk. */
export function* elements(node) {
  const children = node.childNodes ?? [];
  for (const child of children) {
    if (child.tagName) {
      yield child;
      yield* elements(child);
    }
  }
}

/** Returns one attribute value or an empty string. */
export function attr(element, name) {
  const found = (element.attrs ?? []).find((candidate) => candidate.name === name);
  return found ? found.value : "";
}

/** Reports whether an attribute is present at all. */
export function hasAttr(element, name) {
  return (element.attrs ?? []).some((candidate) => candidate.name === name);
}

/** Concatenated visible text of an element. */
export function textOf(node) {
  let text = "";
  for (const child of node.childNodes ?? []) {
    if (child.nodeName === "#text") {
      text += child.value;
    } else if (child.tagName) {
      if (attr(child, "aria-hidden") === "true") continue;
      text += textOf(child);
    }
  }
  return text.replace(/\s+/gu, " ").trim();
}

/** Builds a stable CSS-like selector for a finding. */
export function selectorOf(element, root) {
  const parts = [];
  let current = element;
  while (current?.tagName) {
    let part = current.tagName;
    const id = attr(current, "id");
    if (id) {
      parts.unshift(`${part}#${id}`);
      break;
    }
    const classes = attr(current, "class").split(/\s+/u).filter(Boolean);
    if (classes.length > 0) part += `.${classes[0]}`;
    const parent = current.parentNode;
    if (parent?.childNodes) {
      const siblings = parent.childNodes.filter((node) => node.tagName === current.tagName);
      if (siblings.length > 1) part += `:nth-of-type(${siblings.indexOf(current) + 1})`;
    }
    parts.unshift(part);
    current = parent === root ? null : parent;
  }
  return parts.join(" > ");
}

/** Indexes every element carrying an id. */
function idMap(root) {
  const map = new Map();
  for (const element of elements(root)) {
    const id = attr(element, "id");
    if (!id) continue;
    if (!map.has(id)) map.set(id, []);
    map.get(id).push(element);
  }
  return map;
}

/** Computes an accessible name for one element. */
export function accessibleName(element, ids) {
  const labelledby = attr(element, "aria-labelledby");
  if (labelledby) {
    const parts = labelledby
      .split(/\s+/u)
      .map((id) => ids.get(id)?.[0])
      .filter(Boolean)
      .map((target) => textOf(target));
    if (parts.join(" ").trim()) return parts.join(" ").trim();
  }
  const label = attr(element, "aria-label").trim();
  if (label) return label;
  const tag = element.tagName;
  if (tag === "img" || tag === "area") return attr(element, "alt").trim();
  if (tag === "input") {
    const type = attr(element, "type").toLowerCase();
    if (type === "submit" || type === "button" || type === "reset") {
      const value = attr(element, "value").trim();
      if (value) return value;
    }
    if (type === "image") return attr(element, "alt").trim();
  }
  if (tag === "input" || tag === "select" || tag === "textarea") {
    const id = attr(element, "id");
    if (id) {
      const explicit = element.ownerLabels?.find((candidate) => attr(candidate, "for") === id);
      if (explicit) return textOf(explicit);
    }
    let ancestor = element.parentNode;
    while (ancestor?.tagName) {
      if (ancestor.tagName === "label") return textOf(ancestor);
      ancestor = ancestor.parentNode;
    }
    return "";
  }
  const text = textOf(element);
  if (text) return text;
  return attr(element, "title").trim();
}

/** Attaches every `label[for]` to its target so name computation can find it. */
function bindLabels(root, ids) {
  for (const element of elements(root)) {
    if (element.tagName !== "label") continue;
    const target = attr(element, "for");
    if (!target) continue;
    for (const owner of ids.get(target) ?? []) {
      owner.ownerLabels = owner.ownerLabels ?? [];
      owner.ownerLabels.push(element);
    }
  }
}

/** Reports whether an element is inside an `aria-hidden` subtree. */
function isHidden(element) {
  let current = element;
  while (current?.tagName) {
    if (attr(current, "aria-hidden") === "true" || hasAttr(current, "hidden")) return true;
    current = current.parentNode;
  }
  return false;
}

/* ------------------------------------------------------------------ *
 * Document audit
 * ------------------------------------------------------------------ */

/**
 * Audits one rendered document.
 *
 * @param {{id: string, path: string, locale: string, state?: string, classes?: string[], html: string}} page
 * @returns {{page: string, route: string, findings: Array<object>}}
 */
export function auditDocument(page) {
  const document = parseDocument(page.html);
  const ids = idMap(document);
  bindLabels(document, ids);
  const findings = [];
  const classes = new Set(page.classes ?? []);
  const add = (code, impact, criterion, element, message) => {
    findings.push({
      code,
      impact,
      criterion,
      route: page.path,
      page: page.id,
      selector: element ? selectorOf(element, document) : "html",
      message,
    });
  };

  const html = [...elements(document)].find((element) => element.tagName === "html");
  const documentLang = html ? attr(html, "lang").trim() : "";
  if (!documentLang) {
    add(
      "lps_a11y_document_language_missing",
      "serious",
      "WCAG 3.1.1",
      html,
      "The document has no lang attribute.",
    );
  } else if (!BCP47.test(documentLang)) {
    add(
      "lps_a11y_language_tag_invalid",
      "serious",
      "WCAG 3.1.1",
      html,
      `Document language "${documentLang}" is not a well-formed BCP47 tag.`,
    );
  } else {
    const expected = page.locale === "en" ? "en" : "pt";
    if (!documentLang.toLowerCase().startsWith(expected)) {
      add(
        "lps_a11y_document_language_mismatch",
        "serious",
        "WCAG 3.1.1",
        html,
        `Document language "${documentLang}" does not match the ${page.locale} route.`,
      );
    }
  }

  const all = [...elements(document)];

  // Landmarks.
  const mains = all.filter(
    (element) => element.tagName === "main" || attr(element, "role") === "main",
  );
  if (mains.length !== 1) {
    add(
      "lps_a11y_main_landmark",
      "serious",
      "WCAG 1.3.1",
      mains[1] ?? null,
      `Expected exactly one main landmark, found ${mains.length}.`,
    );
  }
  const navs = all.filter((element) => element.tagName === "nav");
  const navNames = new Map();
  for (const nav of navs) {
    const name = accessibleName(nav, ids);
    if (!name) {
      add(
        "lps_a11y_landmark_name_missing",
        "serious",
        "WCAG 1.3.1",
        nav,
        "A navigation landmark has no accessible name.",
      );
      continue;
    }
    navNames.set(name, (navNames.get(name) ?? 0) + 1);
  }
  for (const [name, count] of navNames) {
    if (count > 1) {
      add(
        "lps_a11y_landmark_name_duplicate",
        "moderate",
        "WCAG 1.3.1",
        null,
        `${count} navigation landmarks share the accessible name "${name}".`,
      );
    }
  }

  // Skip link.
  const skip = all.find(
    (element) => element.tagName === "a" && attr(element, "class").includes("lps-skip-link"),
  );
  if (!skip) {
    add("lps_a11y_skip_link_missing", "serious", "WCAG 2.4.1", null, "No skip link is rendered.");
  } else {
    const target = attr(skip, "href").replace(/^#/u, "");
    if (!target || !ids.has(target)) {
      add(
        "lps_a11y_skip_link_target_missing",
        "critical",
        "WCAG 2.4.1",
        skip,
        `Skip link points at "#${target}" which does not exist in the document.`,
      );
    }
  }

  // Headings.
  const headings = all.filter((element) => HEADING_TAGS.has(element.tagName) && !isHidden(element));
  const h1s = headings.filter((element) => element.tagName === "h1");
  if (h1s.length === 0) {
    add(
      "lps_a11y_h1_missing",
      "serious",
      "WCAG 1.3.1",
      null,
      "The document has no level-1 heading.",
    );
  } else if (h1s.length > 1) {
    add(
      "lps_a11y_h1_duplicate",
      "moderate",
      "WCAG 1.3.1",
      h1s[1],
      `The document has ${h1s.length} level-1 headings.`,
    );
  }
  let previous = 0;
  for (const heading of headings) {
    const level = Number(heading.tagName.slice(1));
    if (previous !== 0 && level > previous + 1) {
      add(
        "lps_a11y_heading_level_skipped",
        "serious",
        "WCAG 1.3.1",
        heading,
        `Heading level jumps from h${previous} to h${level}.`,
      );
    }
    if (!textOf(heading)) {
      add(
        "lps_a11y_heading_empty",
        "serious",
        "WCAG 1.3.1",
        heading,
        "A heading has no text content.",
      );
    }
    previous = level;
  }

  // Duplicate ids.
  for (const [id, owners] of ids) {
    if (owners.length > 1) {
      add(
        "lps_a11y_duplicate_id",
        "moderate",
        "WCAG 4.1.1",
        owners[1],
        `id "${id}" is used ${owners.length} times.`,
      );
    }
  }

  // Images and authored media.
  for (const element of all) {
    if (element.tagName === "img") {
      if (!hasAttr(element, "alt")) {
        add(
          "lps_a11y_image_alt_missing",
          "critical",
          "WCAG 1.1.1",
          element,
          `Image "${attr(element, "src")}" has no alt attribute.`,
        );
      } else if (
        attr(element, "alt").trim() === "" &&
        attr(element, "role") !== "presentation" &&
        attr(element, "aria-hidden") !== "true"
      ) {
        add(
          "lps_a11y_image_decorative_undeclared",
          "moderate",
          "WCAG 1.1.1",
          element,
          "An image with empty alt is not declared decorative (role=presentation or aria-hidden).",
        );
      }
    }
    if (EMBEDDED_TAGS.has(element.tagName)) {
      if (hasAttr(element, "autoplay")) {
        add(
          "lps_a11y_media_autoplay",
          "serious",
          "WCAG 1.4.2",
          element,
          "Time-based media declares autoplay.",
        );
      }
      const tracks = [...elements(element)].filter((child) => child.tagName === "track");
      const captions = tracks.some((track) =>
        ["captions", "subtitles"].includes(attr(track, "kind")),
      );
      if (element.tagName === "video" && !captions) {
        add(
          "lps_a11y_media_captions_missing",
          "critical",
          "WCAG 1.2.2",
          element,
          "Video has no captions track.",
        );
      }
      const describedby = attr(element, "aria-describedby");
      const transcript = describedby && ids.has(describedby.split(/\s+/u)[0]);
      if (!transcript) {
        add(
          "lps_a11y_media_transcript_missing",
          "critical",
          "WCAG 1.2.1",
          element,
          "Time-based media has no transcript referenced through aria-describedby.",
        );
      }
    }
  }

  // Controls: names, labels, traps.
  for (const element of all) {
    if (!INTERACTIVE_TAGS.has(element.tagName) || isHidden(element)) continue;
    if (element.tagName === "input" && ["hidden"].includes(attr(element, "type").toLowerCase()))
      continue;
    const name = accessibleName(element, ids);
    if (!name) {
      const code =
        element.tagName === "input" ||
        element.tagName === "select" ||
        element.tagName === "textarea"
          ? "lps_a11y_field_label_missing"
          : "lps_a11y_control_name_missing";
      add(code, "critical", "WCAG 4.1.2", element, `${element.tagName} has no accessible name.`);
    }
    const tabindex = attr(element, "tabindex");
    if (tabindex && Number(tabindex) > 0) {
      add(
        "lps_a11y_positive_tabindex",
        "serious",
        "WCAG 2.4.3",
        element,
        `Positive tabindex ${tabindex} overrides the document focus order.`,
      );
    }
    if (hasAttr(element, "onkeydown") || hasAttr(element, "onkeypress")) {
      add(
        "lps_a11y_inline_key_handler",
        "critical",
        "WCAG 2.1.2",
        element,
        "An inline key handler can trap keyboard focus and is not allowed on this site.",
      );
    }
    const expanded = attr(element, "aria-expanded");
    if (expanded && !["button", "summary", "a"].includes(element.tagName)) {
      add(
        "lps_a11y_disclosure_semantics",
        "serious",
        "WCAG 4.1.2",
        element,
        "aria-expanded is used on an element without a disclosure role.",
      );
    }
  }
  for (const element of all) {
    if (INTERACTIVE_TAGS.has(element.tagName)) continue;
    const tabindex = attr(element, "tabindex");
    if (tabindex && Number(tabindex) > 0) {
      add(
        "lps_a11y_positive_tabindex",
        "serious",
        "WCAG 2.4.3",
        element,
        `Positive tabindex ${tabindex} overrides the document focus order.`,
      );
    }
    if (attr(element, "aria-expanded") && attr(element, "role") !== "button") {
      add(
        "lps_a11y_disclosure_semantics",
        "serious",
        "WCAG 4.1.2",
        element,
        "aria-expanded is used on an element without a disclosure role.",
      );
    }
  }

  // Labels must resolve.
  for (const element of all) {
    if (element.tagName !== "label") continue;
    const target = attr(element, "for");
    if (target && !ids.has(target)) {
      add(
        "lps_a11y_label_target_missing",
        "critical",
        "WCAG 1.3.1",
        element,
        `label[for="${target}"] does not match any control id.`,
      );
    }
  }

  // details/summary disclosures.
  for (const element of all) {
    if (element.tagName !== "details") continue;
    const summary = (element.childNodes ?? []).find((child) => child.tagName === "summary");
    if (!summary) {
      add(
        "lps_a11y_disclosure_summary_missing",
        "serious",
        "WCAG 4.1.2",
        element,
        "A details disclosure has no summary.",
      );
    }
  }

  // Tables.
  for (const element of all) {
    if (element.tagName !== "table") continue;
    const caption = (element.childNodes ?? []).find((child) => child.tagName === "caption");
    if (!caption && !accessibleName(element, ids)) {
      add(
        "lps_a11y_table_name_missing",
        "serious",
        "WCAG 1.3.1",
        element,
        "A data table has no caption or accessible name.",
      );
    }
    const headers = [...elements(element)].filter((child) => child.tagName === "th");
    if (headers.length === 0) {
      add(
        "lps_a11y_table_headers_missing",
        "serious",
        "WCAG 1.3.1",
        element,
        "A data table has no header cells.",
      );
    }
    for (const header of headers) {
      if (!attr(header, "scope")) {
        add(
          "lps_a11y_table_header_scope_missing",
          "serious",
          "WCAG 1.3.1",
          header,
          "A table header cell has no scope.",
        );
      }
    }
    const wrapper = element.parentNode;
    const wrapperClass = wrapper?.tagName ? attr(wrapper, "class") : "";
    const scrollable =
      wrapperClass.includes("lps-table-scroll") || wrapperClass.includes("wp-block-table");
    if (scrollable) {
      if (attr(wrapper, "tabindex") !== "0") {
        add(
          "lps_a11y_table_scroll_not_focusable",
          "serious",
          "WCAG 2.1.1",
          wrapper,
          "A horizontally scrollable table region is not keyboard focusable (tabindex=0 missing).",
        );
      }
      if (!accessibleName(wrapper, ids) || !attr(wrapper, "role")) {
        add(
          "lps_a11y_table_scroll_unnamed",
          "serious",
          "WCAG 4.1.2",
          wrapper,
          "A scrollable table region has no role=region with an accessible name.",
        );
      }
    }
  }

  // Downloads and document alternatives.
  for (const element of all) {
    if (element.tagName !== "a") continue;
    const href = attr(element, "href");
    if (!href) continue;
    const name = accessibleName(element, ids);
    const lower = href.toLowerCase();
    const isDownload =
      DOWNLOAD_EXTENSIONS.some((extension) => lower.split("?")[0].endsWith(extension)) ||
      lower.includes("lps_citation=");
    if (isDownload) {
      const format = /(pdf|bibtex|csl-json|csv|zip|docx?|xlsx?|pptx?)/iu.exec(name);
      if (!format) {
        add(
          "lps_a11y_download_format_missing",
          "serious",
          "WCAG 2.4.4",
          element,
          `Download link "${name || href}" does not state its file format in the accessible name.`,
        );
      }
    }
    if (lower.split("?")[0].endsWith(".pdf")) {
      const alternative =
        attr(element, "data-html-alternative") ||
        [...elements(element.parentNode ?? document)].some(
          (sibling) => sibling.tagName === "a" && attr(sibling, "data-html-alternative"),
        );
      if (!alternative) {
        add(
          "lps_a11y_pdf_only_essential_content",
          "critical",
          "WCAG 1.3.1",
          element,
          "A PDF is published without a declared accessible HTML alternative (data-html-alternative).",
        );
      }
    }
  }

  // Language of parts.
  const documentPrefix = documentLang.toLowerCase().startsWith("en") ? "en" : "pt-br";
  const foreign = documentPrefix === "en" ? "pt-br" : "en";
  for (const element of all) {
    const lang = attr(element, "lang");
    if (lang && !BCP47.test(lang)) {
      add(
        "lps_a11y_language_tag_invalid",
        "serious",
        "WCAG 3.1.2",
        element,
        `lang="${lang}" is not a well-formed BCP47 tag.`,
      );
    }
    const hreflang = attr(element, "hreflang");
    // `x-default` is the reserved hreflang value for the unmatched-locale target.
    // It is a valid hreflang, so it is not measured against the BCP47 grammar.
    if (hreflang && hreflang !== "x-default" && !BCP47.test(hreflang)) {
      add(
        "lps_a11y_language_tag_invalid",
        "serious",
        "WCAG 3.1.2",
        element,
        `hreflang="${hreflang}" is not a well-formed BCP47 tag.`,
      );
    }
  }
  for (const element of all) {
    if (element.childNodes?.some((child) => child.tagName)) continue;
    if (isHidden(element)) continue;
    const text = textOf(element).toLowerCase();
    if (!text) continue;
    let current = element;
    let declared = "";
    while (current?.tagName) {
      const lang = attr(current, "lang");
      if (lang) {
        declared = lang.toLowerCase();
        break;
      }
      current = current.parentNode;
    }
    const effective = declared || documentLang.toLowerCase();
    const effectivePrefix = effective.startsWith("en") ? "en" : "pt-br";
    if (effectivePrefix !== documentPrefix) continue;
    const href = attr(element, "href");
    const isAddress = /^(mailto:|https?:|[^\s@]+@[^\s@]+\.[^\s@]+$)/iu.test(href || text);
    if (isAddress) continue;
    const marker = LANGUAGE_MARKERS[foreign].find((needle) => text.includes(needle));
    if (marker) {
      add(
        "lps_a11y_language_of_parts_missing",
        "serious",
        "WCAG 3.1.2",
        element,
        `Text in ${foreign} ("${marker}") is not marked with a lang attribute inside a ${documentPrefix} document.`,
      );
    }
  }

  // Status and error announcements.
  const liveRegions = all.filter(
    (element) => ["status", "alert"].includes(attr(element, "role")) || attr(element, "aria-live"),
  );
  if (classes.has("status") || classes.has("error-announcement")) {
    const needsAlert = classes.has("error-announcement") && page.state === "boundary-error";
    if (liveRegions.length === 0) {
      add(
        "lps_a11y_status_region_missing",
        "serious",
        "WCAG 4.1.3",
        null,
        `State "${page.state}" must announce its result through role=status or role=alert.`,
      );
    } else if (needsAlert && !liveRegions.some((region) => attr(region, "role") === "alert")) {
      add(
        "lps_a11y_error_not_announced",
        "serious",
        "WCAG 3.3.1",
        liveRegions[0],
        "A boundary error state does not expose role=alert.",
      );
    }
  }

  // State conveyed by more than colour.
  for (const element of all) {
    const className = attr(element, "class");
    if (!/lps-(status|opportunity-state|event-state)\b/u.test(className)) continue;
    if (!textOf(element) && !accessibleName(element, ids)) {
      add(
        "lps_a11y_state_text_missing",
        "serious",
        "WCAG 1.4.1",
        element,
        "A state indicator carries no text, so its meaning depends on colour alone.",
      );
    }
  }

  // Third-party resources.
  for (const element of all) {
    const source =
      attr(element, "src") || (element.tagName === "link" ? attr(element, "href") : "");
    if (!source) continue;
    if (
      !["script", "iframe", "link", "img", "audio", "video", "object", "embed"].includes(
        element.tagName,
      )
    )
      continue;
    if (
      /^https?:\/\//iu.test(source) &&
      !source.includes("127.0.0.1") &&
      !source.includes("localhost")
    ) {
      add(
        "lps_a11y_third_party_resource",
        "serious",
        "Plan guardrail: no third-party assets",
        element,
        `The page loads a third-party resource: ${source}`,
      );
    }
  }

  return { page: page.id, route: page.path, template: page.template, state: page.state, findings };
}

/**
 * Audits one block-template source file against the shell contract.
 *
 * Every public template must render exactly one `main` landmark carrying the skip
 * target and must keep the locked header and footer parts, so no template can lose
 * the skip link, the language control, or the institutional landmarks.
 *
 * @param {string} name Template file name.
 * @param {string} source Template markup.
 */
export function auditTemplateSource(name, source) {
  const findings = [];
  const add = (code, impact, criterion, message) =>
    findings.push({
      code,
      impact,
      criterion,
      route: `wp-content/themes/lps-theme/templates/${name}`,
      page: name,
      selector: name,
      message,
    });
  const mains = [...source.matchAll(/<main\b[^>]*>/gu)];
  if (mains.length !== 1) {
    add(
      "lps_a11y_template_main_landmark",
      "critical",
      "WCAG 1.3.1",
      `Template renders ${mains.length} main landmarks; exactly one is required.`,
    );
  } else if (!/id="lps-main"/u.test(mains[0][0])) {
    add(
      "lps_a11y_template_skip_target_missing",
      "critical",
      "WCAG 2.4.1",
      "The main landmark does not carry the id the skip link targets.",
    );
  }
  if (!source.includes("wp:lps-theme/header")) {
    add(
      "lps_a11y_template_header_missing",
      "serious",
      "WCAG 3.2.3",
      "The template does not include the site header part.",
    );
  }
  if (!source.includes("wp:lps-theme/footer")) {
    add(
      "lps_a11y_template_footer_missing",
      "serious",
      "WCAG 3.2.3",
      "The template does not include the site footer part.",
    );
  }
  return findings;
}

/* ------------------------------------------------------------------ *
 * Stylesheet audit
 * ------------------------------------------------------------------ */

/** Parses `:root` custom properties out of a stylesheet. */
export function cssTokens(css) {
  const tokens = new Map();
  const root = /:root\s*\{([^}]*)\}/su.exec(css);
  if (!root) return tokens;
  // Comments are legitimate inside :root; without stripping them a declaration
  // that follows a comment in the same `;` segment is silently dropped, which
  // blinds the contrast audit to every pair that references it.
  const declarations = root[1].replace(/\/\*.*?\*\//gsu, "");
  for (const declaration of declarations.split(";")) {
    const match = /^\s*(--[\w-]+)\s*:\s*(.+)$/su.exec(declaration);
    if (match) tokens.set(match[1], match[2].trim());
  }
  return tokens;
}

/** Converts a hex colour to sRGB channels. */
function channels(hex) {
  const clean = hex.replace("#", "").trim();
  const full =
    clean.length === 3
      ? clean
          .split("")
          .map((part) => part + part)
          .join("")
      : clean;
  return [0, 2, 4].map((offset) => Number.parseInt(full.slice(offset, offset + 2), 16) / 255);
}

/** Relative luminance per WCAG 2.x. */
export function luminance(hex) {
  const [r, g, b] = channels(hex).map((channel) =>
    channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4,
  );
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Contrast ratio between two hex colours. */
export function contrastRatio(foreground, background) {
  const light = Math.max(luminance(foreground), luminance(background));
  const dark = Math.min(luminance(foreground), luminance(background));
  return (light + 0.05) / (dark + 0.05);
}

/**
 * Colour pairs the rendered surface actually uses, with the threshold that applies.
 * `kind` is `text` (4.5:1), `large` (3:1) or `ui` (3:1 non-text contrast).
 */
export const CONTRAST_PAIRS = [
  { id: "body-text", foreground: "--color-text", background: "--color-canvas", kind: "text" },
  {
    id: "body-on-surface",
    foreground: "--color-text",
    background: "--color-surface",
    kind: "text",
  },
  {
    id: "muted-text",
    foreground: "--color-text-muted",
    background: "--color-canvas",
    kind: "text",
  },
  {
    id: "meta-text",
    foreground: "--color-text-muted",
    background: "--color-surface",
    kind: "text",
  },
  {
    id: "link-rest",
    foreground: "--color-action",
    background: "--color-canvas",
    kind: "text",
  },
  {
    id: "link-hover",
    foreground: "--color-action-hover",
    background: "--color-canvas",
    kind: "text",
  },
  { id: "kicker", foreground: "--color-action", background: "--color-canvas", kind: "text" },
  { id: "heading", foreground: "--color-text", background: "--color-surface", kind: "large" },
  { id: "wordmark", foreground: "--color-anchor", background: "--color-canvas", kind: "large" },
  {
    id: "affiliation-bar",
    foreground: "--color-surface",
    background: "--color-anchor",
    kind: "text",
  },
  {
    id: "primary-button",
    foreground: "--color-surface",
    background: "--color-action",
    kind: "text",
  },
  {
    id: "primary-button-hover",
    foreground: "--color-surface",
    background: "--color-action-hover",
    kind: "text",
  },
  { id: "focus-ring", foreground: "--color-action", background: "--color-canvas", kind: "ui" },
  {
    id: "focus-ring-dark",
    foreground: "--color-focus-on-dark",
    background: "--color-anchor",
    kind: "ui",
  },
  {
    id: "control-border",
    foreground: "--color-boundary-strong",
    background: "--color-surface",
    kind: "ui",
  },
  { id: "error-text", foreground: "--color-error", background: "--color-canvas", kind: "text" },
  { id: "error-wash", foreground: "--color-error", background: "--color-error-wash", kind: "text" },
  { id: "warning-text", foreground: "--color-warning", background: "--color-canvas", kind: "text" },
  {
    id: "warning-wash",
    foreground: "--color-warning",
    background: "--color-warning-wash",
    kind: "text",
  },
  { id: "success-text", foreground: "--color-success", background: "--color-canvas", kind: "text" },
  {
    id: "success-wash",
    foreground: "--color-success",
    background: "--color-success-wash",
    kind: "text",
  },
  { id: "info-wash", foreground: "--color-anchor", background: "--color-info-wash", kind: "text" },
];

const THRESHOLD = { text: 4.5, large: 3, ui: 3 };

/** Audits the shipped stylesheet for contrast, focus, motion, print, and target size. */
export function auditStylesheet(css, source = "assets/css/theme.css") {
  const findings = [];
  const tokens = cssTokens(css);
  const add = (code, impact, criterion, selector, message) =>
    findings.push({ code, impact, criterion, route: source, page: source, selector, message });

  for (const pair of CONTRAST_PAIRS) {
    const foreground = tokens.get(pair.foreground);
    const background = tokens.get(pair.background);
    if (!foreground || !background) {
      add(
        "lps_a11y_contrast_token_missing",
        "serious",
        "WCAG 1.4.3",
        `:root { ${pair.foreground}, ${pair.background} }`,
        `Contrast pair "${pair.id}" references a token that the stylesheet does not define.`,
      );
      continue;
    }
    const ratio = contrastRatio(foreground, background);
    const required = THRESHOLD[pair.kind];
    if (ratio < required) {
      add(
        pair.kind === "ui" ? "lps_a11y_contrast_ui" : "lps_a11y_contrast_text",
        "serious",
        pair.kind === "ui" ? "WCAG 1.4.11" : "WCAG 1.4.3",
        `:root { ${pair.foreground} on ${pair.background} }`,
        `Contrast pair "${pair.id}" is ${ratio.toFixed(2)}:1, below the required ${required}:1.`,
      );
    }
  }

  if (!/:focus-visible\s*\{[^{}]*outline:/su.test(css)) {
    add(
      "lps_a11y_focus_style_missing",
      "critical",
      "WCAG 2.4.7",
      ":focus-visible",
      "The stylesheet defines no visible focus outline.",
    );
  }
  const outlineRemovals = [...css.matchAll(/([^{}]+)\{([^{}]*outline\s*:\s*(none|0)[^{}]*)\}/gsu)];
  for (const removal of outlineRemovals) {
    const selector = removal[1].trim();
    if (/:focus(?!-visible)/u.test(selector)) {
      add(
        "lps_a11y_focus_outline_removed",
        "critical",
        "WCAG 2.4.7",
        selector,
        "A focus rule removes the outline without providing an alternative indicator.",
      );
    }
  }

  // --rule-focus is a complete border value ("<width> solid <color>"); the
  // width component is what WCAG 2.4.11 measures. It may itself reference a
  // width primitive (var(--line-focus)), so the token map resolves one level
  // of indirection before the px/rem width is compared to the 2px minimum.
  const focusValue = tokens.get("--rule-focus") ?? "";
  const widthRef = /^var\((--[\w-]+)\)/u.exec(focusValue.trim());
  const widthSource = widthRef ? (tokens.get(widthRef[1]) ?? "") : focusValue;
  const focusRule = /([\d.]+)(px|rem)/u.exec(widthSource);
  const focusPx = focusRule
    ? Number(focusRule[1]) * (focusRule[2] === "rem" ? 16 : 1)
    : 0;
  if (focusPx < 2) {
    add(
      "lps_a11y_focus_indicator_thin",
      "serious",
      "WCAG 2.4.11",
      ":root { --rule-focus }",
      "The focus indicator is thinner than the 2px minimum required for a visible indicator.",
    );
  }

  const controlMin = tokens.get("--control-min");
  const controlRem = controlMin ? Number.parseFloat(controlMin) : 0;
  if (!controlMin || controlRem * 16 < 24) {
    add(
      "lps_a11y_target_size_token",
      "serious",
      "WCAG 2.5.8",
      ":root { --control-min }",
      `The minimum control size token is ${controlMin ?? "absent"}, below the 24px minimum target size.`,
    );
  }

  const reduced =
    /@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{((?:[^{}]|\{[^{}]*\})*)\}/su.exec(css);
  if (!reduced) {
    add(
      "lps_a11y_reduced_motion_missing",
      "serious",
      "WCAG 2.3.3",
      "@media (prefers-reduced-motion: reduce)",
      "The stylesheet has no reduced-motion block.",
    );
  } else {
    const block = reduced[1];
    if (
      !/transition-duration\s*:\s*0/su.test(block) ||
      !/animation-duration\s*:\s*0/su.test(block)
    ) {
      add(
        "lps_a11y_reduced_motion_incomplete",
        "serious",
        "WCAG 2.3.3",
        "@media (prefers-reduced-motion: reduce)",
        "The reduced-motion block does not neutralize both transition and animation duration.",
      );
    }
  }

  const print = /@media\s*print\s*\{((?:[^{}]|\{[^{}]*\})*)\}/su.exec(css);
  if (!print) {
    add(
      "lps_a11y_print_stylesheet_missing",
      "moderate",
      "eMAG 3.8",
      "@media print",
      "The stylesheet has no print rules, so printed pages are not verified.",
    );
  } else if (
    /(^|[\s,{])(main|article|\.lps-main-content)[^{}]*\{[^{}]*display\s*:\s*none/su.test(print[1])
  ) {
    add(
      "lps_a11y_print_hides_content",
      "serious",
      "eMAG 3.8",
      "@media print",
      "The print stylesheet hides main content.",
    );
  }

  return findings;
}

/* ------------------------------------------------------------------ *
 * Authored corpus audit
 * ------------------------------------------------------------------ */

const LOCALE_TAGS = { "pt-br": "pt-BR", en: "en" };

/** Audits the CMS-authored corpus for accessibility-relevant editorial defects. */
export async function auditCorpus(corpusDir = "content/corpus") {
  const findings = [];
  const recordsDir = path.join(corpusDir, "records");
  const files = (await readdir(recordsDir)).filter((file) => file.endsWith(".json")).sort();
  const add = (code, impact, criterion, record, selector, message) =>
    findings.push({
      code,
      impact,
      criterion,
      route: `corpus:${record}`,
      page: record,
      selector,
      message,
    });

  for (const file of files) {
    const record = JSON.parse(await readFile(path.join(recordsDir, file), "utf8"));
    const id = record.inventoryRecord ?? file;
    for (const [locale, variant] of Object.entries(record.locales ?? {})) {
      if (!LOCALE_TAGS[locale] || !BCP47.test(LOCALE_TAGS[locale])) {
        add(
          "lps_a11y_corpus_locale_tag_invalid",
          "serious",
          "WCAG 3.1.1",
          id,
          `locales.${locale}`,
          `Locale key "${locale}" has no well-formed BCP47 tag.`,
        );
      }
      const media = variant.media ?? [];
      for (const [index, asset] of media.entries()) {
        const selector = `locales.${locale}.media[${index}]`;
        if (asset.decorative === true) continue;
        if (!asset.alt || !String(asset.alt).trim()) {
          add(
            "lps_a11y_corpus_media_alt_missing",
            "critical",
            "WCAG 1.1.1",
            id,
            selector,
            "An authored media asset has neither alt text nor a decorative declaration.",
          );
        }
        if (["audio", "video"].includes(asset.kind) && !asset.transcript) {
          add(
            "lps_a11y_corpus_transcript_missing",
            "critical",
            "WCAG 1.2.1",
            id,
            selector,
            "An authored time-based media asset has no transcript.",
          );
        }
      }
      const body = Array.isArray(variant.content)
        ? variant.content.join("\n")
        : String(variant.content ?? "");
      const pdfLinks = [...body.matchAll(/https?:\/\/\S+\.pdf/giu)].map((match) => match[0]);
      for (const link of pdfLinks) {
        if (!variant.accessibleAlternative) {
          add(
            "lps_a11y_corpus_pdf_only_essential_content",
            "critical",
            "WCAG 1.3.1",
            id,
            `locales.${locale}.content`,
            `Essential content is published only as PDF (${link}) with no declared accessible alternative.`,
          );
        }
      }
    }
  }
  return findings;
}

/** Sorts findings deterministically and summarizes them. */
export function summarize(findings) {
  const counts = { critical: 0, serious: 0, moderate: 0, minor: 0 };
  for (const finding of findings) counts[finding.impact] = (counts[finding.impact] ?? 0) + 1;
  return {
    findingCount: findings.length,
    blocking: counts.critical + counts.serious,
    counts,
  };
}

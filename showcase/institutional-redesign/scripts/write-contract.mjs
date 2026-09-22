#!/usr/bin/env node
/**
 * Writes the machine-readable design contract for the "Signal" revision.
 *
 * The contract is generated from the shipped stylesheet rather than hand-typed, so
 * the token values, the scale steps and the contrast ratios cannot drift from what
 * the theme actually renders: every number below is read out of
 * `wp-content/themes/lps-theme/assets/css/theme.css` and recomputed with WCAG 2.2
 * relative luminance.
 *
 * Usage: node showcase/institutional-redesign/scripts/write-contract.mjs [--check]
 * `--check` verifies the file on disk already matches without rewriting it.
 */

import { readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { contrastRatio, cssTokens, resolveTokens } from "../../../scripts/lib/a11y.mjs";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, "..", "..", "..");
const cssPath = resolve(repoRoot, "wp-content/themes/lps-theme/assets/css/theme.css");
const outPath = resolve(repoRoot, "docs/design/design-contract.json");

const tokens = resolveTokens(cssTokens(readFileSync(cssPath, "utf8")));
const value = (token) => {
  const found = tokens.get(token);
  if (!found) throw new Error(`Token ${token} is not declared in :root.`);
  return found;
};

/** Palette slugs: the single set shared by :root, theme.json and this contract. */
const palette = [
  ["--color-canvas", "Canvas", "General page background", "MEASURED"],
  [
    "--color-surface",
    "Surface",
    "Reading, form and card surfaces; the only field for full-colour mark artwork",
    "MEASURED",
  ],
  ["--color-surface-alt", "Alt surface", "Quiet bands, table headers, metadata chips", "MEASURED"],
  [
    "--color-anchor",
    "Institutional anchor",
    "Masthead-navigation accent, deep bands, headings",
    "MEASURED",
  ],
  ["--color-anchor-deep", "Anchor depth", "Hero and footer substrate", "MEASURED (derived)"],
  [
    "--color-anchor-soft",
    "Anchor soft",
    "Depth inside anchor bands and the closing action band",
    "MEASURED (derived)",
  ],
  [
    "--color-action",
    "Action",
    "Links, primary controls, focus outline on light surfaces",
    "MEASURED",
  ],
  [
    "--color-action-hover",
    "Action depth",
    "Link and control hover and pressed states",
    "MEASURED (derived)",
  ],
  [
    "--color-action-light",
    "Action highlight",
    "Gradient and illustration highlight inside brand bands",
    "MEASURED (derived)",
  ],
  [
    "--color-wash-action",
    "Action wash",
    "Current-page navigation pill, quiet badges",
    "MEASURED (derived)",
  ],
  [
    "--color-wash-action-subtle",
    "Action wash subtle",
    "Tag fields and light hover fills",
    "MEASURED (derived)",
  ],
  ["--color-accent", "Waveform accent", "Waveform motif, hairline gradients, bullets", "MEASURED"],
  [
    "--color-accent-soft",
    "Accent soft",
    "Accent on dark substrates and hairlines",
    "MEASURED (derived)",
  ],
  ["--color-accent-wash", "Accent wash", "Accent chip field", "MEASURED (derived)"],
  ["--color-text", "Text", "Body and heading text", "MEASURED"],
  ["--color-text-muted", "Muted text", "Secondary text: leads, summaries, captions", "MEASURED"],
  ["--color-text-soft", "Soft text", "Metadata, labels, technical annotations", "MEASURED"],
  ["--color-rule-quiet", "Quiet rule", "Hairline separators and card borders", "MEASURED"],
  ["--color-rule-hover", "Rule hover", "Separator and border hover state", "MEASURED (derived)"],
  [
    "--color-boundary-strong",
    "Strong boundary",
    "Control borders and any boundary that carries meaning",
    "MEASURED",
  ],
  ["--color-success", "Success", "Success text and state, 7:1 class", "MEASURED"],
  ["--color-warning", "Warning", "Warning text and state, 7:1 class", "MEASURED"],
  ["--color-error", "Error", "Error text and state, 7:1 class", "MEASURED"],
  ["--color-info-wash", "Info wash", "Informational notice field", "MEASURED (derived)"],
  ["--color-success-wash", "Success wash", "Success notice field", "MEASURED (derived)"],
  ["--color-warning-wash", "Warning wash", "Warning notice field", "MEASURED (derived)"],
  ["--color-error-wash", "Error wash", "Error notice field", "MEASURED (derived)"],
];

const colorTokens = palette.map(([token, name, usage, tag]) => ({
  token,
  name,
  // `role` is the slug the editor palette and the validator both key on.
  role: token.replace("--color-", ""),
  slug: token.replace("--color-", ""),
  value: value(token).toUpperCase(),
  usage,
  tag,
}));

/**
 * CSS-only primitives. They are declared in :root and validated by the theme
 * checker, but they are not palette slugs: an editor never picks "print ink" or a
 * footer address tone from the colour panel. Pairs may reference them.
 */
const primitives = [
  ["--color-focus-on-dark", "focus-on-dark", "Focus ring on anchor and anchor-deep bands"],
  ["--color-on-dark", "on-dark", "Utility-bar links and hero support text"],
  ["--color-on-dark-body", "on-dark-body", "Footer column text"],
  ["--color-on-dark-address", "on-dark-address", "Footer address block"],
  ["--color-on-dark-meta", "on-dark-meta", "Footer legal line"],
  ["--color-on-dark-lead", "on-dark-lead", "Hero and closing-band lead paragraphs"],
  ["--color-on-dark-code", "on-dark-code", "Code on the anchor-deep substrate"],
  ["--color-accent-ink", "accent-ink", "Text on the accent chip field"],
  ["--color-warning-ink", "warning-ink", "Warning text on the warning field"],
  ["--color-error-ink", "error-ink", "Error text on the error field"],
  ["--color-warning-rule", "warning-rule", "Warning notice border"],
  ["--color-error-rule", "error-rule", "Error notice border"],
  ["--color-info-rule", "info-rule", "Informational notice border"],
  [
    "--color-navy-950",
    "navy-deep",
    "Deepest institutional substrate: hero, footer, skip link text",
  ],
  ["--color-navy-900", "navy", "Anchor bands and the session link's inverted hover fill"],
  [
    "--color-cyan-500",
    "cyan-mid",
    "Mid cyan: current-page indicator bars and field indexes at the 3:1 graphic floor",
  ],
  ["--color-slate-600", "slate-deep", "Memoriam monogram field"],
  ["--color-slate-500", "slate", "Memoriam monogram gradient end"],
  ["--color-print-ink", "print-ink", "Print text colour"],
  ["--color-print-rule", "print-rule", "Print separator colour"],
].map(([token, role, usage]) => ({
  token,
  value: value(token).toUpperCase(),
  role,
  usage,
  tag: "MEASURED (primitive)",
}));

/**
 * Every pair the rendered surfaces actually use. `kind` maps to the WCAG 2.2
 * threshold: text 4.5:1, large text 3:1, ui (non-text) 3:1.
 */
const pairs = [
  ["body-on-canvas", "--color-text", "--color-canvas", "text", "body text"],
  ["body-on-surface", "--color-text", "--color-surface", "text", "body text in cards and records"],
  ["body-on-alt", "--color-text", "--color-surface-alt", "text", "body text on quiet bands"],
  ["muted-on-canvas", "--color-text-muted", "--color-canvas", "text", "leads and summaries"],
  ["muted-on-surface", "--color-text-muted", "--color-surface", "text", "card summaries"],
  ["soft-on-surface", "--color-text-soft", "--color-surface", "text", "metadata and labels"],
  ["link-on-canvas", "--color-action", "--color-canvas", "text", "inline links"],
  ["link-on-surface", "--color-action", "--color-surface", "text", "links inside cards"],
  ["link-hover-on-surface", "--color-action-hover", "--color-surface", "text", "link hover"],
  ["kicker-on-canvas", "--color-action", "--color-canvas", "text", "section kickers"],
  ["heading-on-surface", "--color-anchor", "--color-surface", "large", "headings"],
  ["anchor-text-on-canvas", "--color-anchor", "--color-canvas", "large", "display headings"],
  ["nav-label-on-surface", "--color-anchor", "--color-surface", "text", "primary navigation"],
  [
    "nav-label-on-action-wash",
    "--color-action-hover",
    "--color-wash-action",
    "text",
    "current-page navigation pill",
  ],
  [
    "nav-label-on-anchor",
    "--color-surface",
    "--color-anchor",
    "text",
    "utility bar on the institutional anchor",
  ],
  ["utility-text-on-anchor", "--color-on-dark", "--color-anchor", "text", "utility bar links"],
  ["button-label-on-action", "--color-surface", "--color-action", "text", "primary button"],
  [
    "button-label-on-action-hover",
    "--color-surface",
    "--color-action-hover",
    "text",
    "primary button hover",
  ],
  ["ghost-button-label", "--color-anchor", "--color-surface", "text", "secondary button"],
  ["tag-label-on-wash", "--color-action-hover", "--color-wash-action-subtle", "text", "tag chips"],
  ["accent-chip-label", "--color-accent-ink", "--color-accent-wash", "text", "accent chips"],
  ["hero-title-on-deep", "--color-surface", "--color-anchor-deep", "large", "hero statement"],
  ["hero-lead-on-deep", "--color-on-dark-lead", "--color-anchor-deep", "text", "hero lead"],
  ["hero-kicker-on-deep", "--color-accent", "--color-anchor-deep", "text", "hero kicker"],
  ["hero-support-on-deep", "--color-on-dark", "--color-anchor-deep", "text", "hero support list"],
  ["footer-body-on-deep", "--color-on-dark-body", "--color-anchor-deep", "text", "footer columns"],
  [
    "footer-address-on-deep",
    "--color-on-dark-address",
    "--color-anchor-deep",
    "text",
    "footer address",
  ],
  [
    "footer-meta-on-deep",
    "--color-on-dark-meta",
    "--color-anchor-deep",
    "text",
    "footer legal line",
  ],
  ["notice-info-text", "--color-anchor", "--color-info-wash", "text", "informational notice"],
  ["notice-warning-text", "--color-warning-ink", "--color-warning-wash", "text", "warning notice"],
  ["notice-error-text", "--color-error-ink", "--color-error-wash", "text", "error notice"],
  ["status-success", "--color-success", "--color-success-wash", "text", "success status pill"],
  ["status-warning", "--color-warning", "--color-warning-wash", "text", "warning status pill"],
  ["status-error", "--color-error", "--color-error-wash", "text", "error status pill"],
  ["success-text-on-surface", "--color-success", "--color-surface", "text", "success text"],
  ["warning-text-on-surface", "--color-warning", "--color-surface", "text", "warning text"],
  ["error-text-on-surface", "--color-error", "--color-surface", "text", "error text"],
  [
    "focus-outline-on-canvas",
    "--color-action",
    "--color-canvas",
    "ui",
    "focus ring on light surfaces",
  ],
  ["focus-outline-on-surface", "--color-action", "--color-surface", "ui", "focus ring on cards"],
  [
    "focus-outline-on-anchor",
    "--color-focus-on-dark",
    "--color-anchor",
    "ui",
    "focus ring on anchor bands",
  ],
  [
    "control-boundary-on-surface",
    "--color-boundary-strong",
    "--color-surface",
    "ui",
    "input and control borders",
  ],
  [
    "control-boundary-on-canvas",
    "--color-boundary-strong",
    "--color-canvas",
    "ui",
    "control borders on the canvas",
  ],
  [
    "rule-hairline-on-surface",
    "--color-rule-quiet",
    "--color-surface",
    "decorative",
    "hairline separators (no meaning of their own)",
  ],

  // Session and member surfaces
  [
    "session-link-on-anchor",
    "--color-surface",
    "--color-anchor",
    "text",
    "session link in the utility bar",
  ],
  [
    "session-link-hover-on-surface",
    "--color-navy-900",
    "--color-surface",
    "text",
    "session link hover fill",
  ],
  [
    "session-link-active-on-accent",
    "--color-navy-950",
    "--color-accent",
    "text",
    "signed-in session link",
  ],
  ["skip-link-on-accent", "--color-navy-950", "--color-accent", "text", "skip link"],
  ["event-date-on-anchor", "--color-surface", "--color-anchor", "text", "agenda date block"],
  ["monogram-on-anchor", "--color-surface", "--color-anchor", "text", "person monogram"],
  [
    "memoriam-monogram-on-slate",
    "--color-surface",
    "--color-slate-600",
    "text",
    "in-memoriam monogram",
  ],
  ["table-head-on-surface", "--color-text-soft", "--color-surface", "text", "table header cells"],
  ["timeline-year-on-canvas", "--color-anchor", "--color-canvas", "text", "timeline year gutter"],
  [
    "partner-name-on-surface",
    "--color-text-muted",
    "--color-surface",
    "text",
    "partner band names",
  ],
  [
    "nav-current-bar-on-surface",
    "--color-cyan-500",
    "--color-surface",
    "ui",
    "current-page indicator bar",
  ],
  [
    "kicker-index-on-surface",
    "--color-cyan-500",
    "--color-surface",
    "ui",
    "section kicker index bar",
  ],
  [
    "accent-rule-on-anchor",
    "--color-accent",
    "--color-anchor",
    "ui",
    "brand rule above the footer",
  ],
];

const thresholds = { text: 4.5, large: 3, ui: 3, decorative: 1 };

const colorPairs = pairs.map(([id, foreground, background, kind, context]) => {
  const ratio = contrastRatio(value(foreground), value(background));
  return {
    id,
    foreground,
    background,
    kind,
    minRatio: thresholds[kind],
    expectedRatio: Number(ratio.toFixed(2)),
    context,
  };
});

const scale = [
  ["--type-display", "display", "Hero statement"],
  ["--type-h1", "h1", "Page title"],
  ["--type-h2", "h2", "Section title"],
  ["--type-h3", "h3", "Card and group title"],
  ["--type-h4", "h4", "Record title"],
  ["--type-lead", "lead", "Lead paragraph"],
  ["--type-body", "body", "Body text"],
  ["--type-reading", "reading", "Long-form reading"],
  ["--type-small", "small", "Card body, captions"],
  ["--type-meta", "meta", "Metadata, kickers, table headers"],
].map(([token, role, usage]) => ({ token, role, usage, size: value(token) }));

const contract = {
  schemaVersion: 1,
  id: "lps-design-contract",
  title:
    'LPS institutional design system — "Blueprint" direction: technical-document layout grammar, artwork-derived palette, hard-edged depth',
  revision: "2026-09-21",
  plan: "lps-website-ulw-plan task-02 (direction superseded in place; product truth unchanged)",
  supersedes: {
    document:
      "design-contract.json revision 2026-09-21 (Signal: reference-led layout, soft depth, IBM Plex)",
    note: 'This revision keeps the reference-led layout grammar of the Signal revision and replaces its surface language: squared corners, ruled depth instead of blurred shadows, a display face over an interface face, and a palette retuned between the artwork blue and the anchor navy. Every accessibility floor, rights rule, media rule and governance constraint is carried forward unchanged; the bans are not repealed, they are converted from "no effect at all" to "no effect outside the token layer".',
  },
  reference: {
    url: "https://www.fee.unicamp.br/",
    observationDate: "2026-09-21",
    adopted: [
      "Two-tier header: a slim utility bar above a matte masthead",
      "A ruled navigation row that separates identity from destinations",
      "One claim in the first viewport, with a real action text link rather than a decorative control",
      "Journey cards as the first content block after the hero",
      "Card grids for research areas and projects, with a kicker per group",
      "An agenda list with a date block per entry",
      "A useful-links band and a partner band",
      "A multi-column institutional footer over a deep navy substrate",
    ],
    notAdopted: [
      "The reference palette (its lime utility bar, navy and maroon bands)",
      "Its carousel, its language-dropdown with flag icons, its third-party translation widget and its accessibility overlay",
      "Any reference copy, image, mark or measurement",
    ],
  },
  dials: { designVariance: 4, motionIntensity: 3, visualDensity: 6 },
  colors: { tokens: colorTokens, primitives, pairs: colorPairs },
  typography: {
    families: [
      {
        token: "--font-display",
        stack: value("--font-display"),
        owns: "Display headings only: hero claim, page titles, section titles, card titles, statistics, monograms",
        license: "SIL OFL 1.1 (Space Grotesk, Florian Karsten)",
      },
      {
        token: "--font-interface",
        stack: value("--font-interface"),
        owns: "Body and long-form reading, navigation, controls, tables, labels",
        license: "SIL OFL 1.1 (Inter, The Inter Project Authors)",
      },
      {
        token: "--font-mono",
        stack: value("--font-mono"),
        owns: "Course codes, record identifiers, dates, table headers, field labels, code samples and technical metadata",
        license: "SIL OFL 1.1 (JetBrains Mono, JetBrains)",
      },
    ],
    scale,
    rules: [
      "Body text is 16 CSS px; reading text 18 px; line heights 1.6-1.65 for prose and 1.02-1.1 for display headings",
      "Reading measure about 68ch; leads about 60ch; interface text about 74ch",
      "The display face is restricted to headings and statistics; the mono face to codes, dates, table headers, field labels and code",
      "No essential text below the 12px meta floor; meta never carries instructions on its own",
      "Self-hosted woff2 only, font-display: swap, no remote font request at any time",
    ],
  },
  spacing: {
    baseUnitPx: 4,
    tokens: [
      "--space-1",
      "--space-2",
      "--space-3",
      "--space-4",
      "--space-5",
      "--space-6",
      "--space-8",
      "--space-10",
      "--space-12",
      "--space-16",
      "--space-20",
      "--space-24",
    ].map((token) => ({ token, value: value(token) })),
    sectionRhythm:
      "Section padding --section-y, clamped 52-96px; 64-96px between major bands; page gutters clamp 16-48px",
  },
  geometry: {
    maxContentWidth: "82.5rem (1320px)",
    grid: "One column below 44rem; two columns 44-64rem; three or four columns above 64rem for card grids; the reading column stays within 68ch at every width",
    controlRadius: "2px",
    imageRadius: "2px",
    radiusScale: [
      ["--radius-xs", "micro detail: chips, inline code, focus corners"],
      ["--radius-sm", "controls: buttons, inputs, pagination"],
      ["--radius-md", "reserved: nested frames that need one step more than a control"],
      ["--radius-lg", "reserved: large frames, not used by default"],
      ["--radius-xl", "reserved: not used by default"],
      ["--radius-pill", "tags and status pills only, never rectangular controls"],
    ].map(([token, usage]) => ({ token, value: value(token), usage })),
    elevation: [
      ["--shadow-xs", "ruled resting edge: one hairline below a panel"],
      ["--shadow-sm", "hovered controls and journey cards: a 2px hard offset"],
      ["--shadow-md", "hovered cards and menus: a 4px hard offset"],
      ["--shadow-lg", "reserved: overlays and dialogs, 6px hard offset"],
      ["--shadow-nav-current", "current-page navigation bar, inset, non-blurred"],
    ].map(([token, usage]) => ({ token, value: value(token), usage })),
    rules: [
      "Radii and elevation are declared as tokens; a surface references them and never writes a literal radius or shadow",
      "Depth is ruled, not blurred: no decorative blur shadows, only hairlines and hard offsets",
      "Imagery stays rectangular in content: photographs and diagrams are never given a decorative radius or a coloured frame",
      "Compartments are separated by hairlines first and by elevation second; no universal card wrapper around plain prose",
      "Gradients exist only as --gradient-* tokens in :root, and only on brand bands: hero, page header, closing band, timeline node rail and monogram",
      "The waveform motif is the supplied artwork referenced as an asset (--signal-line, --signal-line-dark); no surface redraws it",
    ],
  },
  motion: {
    tokens: [
      ["--motion-fast", "state change: colour, border"],
      ["--motion-standard", "state change: shadow, disclosure"],
      ["--motion-slow", "reserved: entrance of a single hero element"],
      ["--ease-state", "standard state easing"],
      ["--ease-out", "deceleration for hover lift"],
    ].map(([token, usage]) => ({ token, value: value(token), usage })),
    rules: [
      "Motion is state response only: never layout, never scroll-hijack, never looping decoration",
      "Only composited properties may transition: colour, background, border, transform, opacity and shadow",
      "prefers-reduced-motion: reduce removes every transform, transition and smooth scroll",
      "No animation carries meaning that the static page does not already carry",
    ],
  },
  logo: {
    mobile: {
      rule: "The 2026-09-21 revision drops the separate compact file: the masthead carries the full-colour artwork alone at every width, scaling by clamp(13rem, 52vw, 27rem) with a 208px floor, and the laboratory name is carried by the page title, the footer wordmark, the link's accessible name and the structured data rather than by a second file.",
      minimumWidthPx: 208,
      variants: ["full"],
      surfaces: ["light"],
    },
    source: {
      path: "assets/brand/lps_logo_vector.svg",
      sha256: "f369f9e49c81e297d30fa8e240267667dddf8b89f0d26c494636f9429027a23b",
      bytes: 53019,
      viewBox: "48 190 2052 301",
      aspectRatio: 6.817,
      note: "Owner-supplied artwork; bytes preserved verbatim as a source asset and used unchanged.",
    },
    variants: [
      {
        id: "full",
        path: "wp-content/themes/lps-theme/assets/img/mark/lps-mark-full.svg",
        viewBox: "0 0 2052 301",
        aspectRatio: 6.817,
        use: "Desktop masthead and any placement at least 240px wide on a light surface",
        surfaces: ["light"],
        minWidthPx: 240,
      },
      {
        id: "symbol-source",
        path: "assets/brand/lps_logo_symbol.svg",
        viewBox: "70 200 1320 280",
        aspectRatio: 4.714,
        derivation: {
          method: "path-selection only",
          keptPaths: ["reference-line", "waveform", "separator", "lps-lettering"],
          droppedPaths: ["laboratory-name", "computational-intelligence"],
          note: "The descriptive lettering is set as live text throughout the site, so the symbol carries the waveform and the LPS lettering only.",
        },
        use: "Constrained placements and derived marks; keeps the waveform and LPS lettering",
        surfaces: ["light"],
        minHeightPx: 28,
        themeMirror:
          "wp-content/themes/lps-theme/assets/img/mark/lps-mark-compact.svg (1308x301, same four paths)",
      },
      {
        id: "symbol-source-reversed",
        path: "assets/brand/lps_logo_symbol_inverse.svg",
        viewBox: "70 200 1320 280",
        aspectRatio: 4.714,
        use: "Reversed derivation source for dark-band placements",
        surfaces: ["dark"],
        decorative: true,
      },
      {
        id: "reversed",
        path: "wp-content/themes/lps-theme/assets/img/mark/lps-mark-reversed.svg",
        viewBox: "0 0 2052 301",
        use: "Footer and any full-lockup placement on the anchor-deep and anchor substrates",
        surfaces: ["dark"],
        minWidthPx: 200,
      },
      {
        id: "symbol",
        path: "wp-content/themes/lps-theme/assets/img/mark/lps-mark-symbol.svg",
        viewBox: "0 0 886.5 301",
        use: "Waveform motif on light brand bands (--signal-line); decorative, never the sole carrier of a name",
        surfaces: ["light"],
        decorative: true,
      },
      {
        id: "symbol-reversed",
        path: "wp-content/themes/lps-theme/assets/img/mark/lps-mark-symbol-reversed.svg",
        viewBox: "0 0 886.5 301",
        use: "Waveform motif on navy bands (--signal-line-dark); derived from the reversed variant by selecting existing paths only",
        surfaces: ["dark"],
        decorative: true,
      },
      {
        id: "text-wordmark",
        path: null,
        use: "Fallback when artwork cannot be resolved, and the only permitted lockup for UFRJ and COPPE institutional names",
        surfaces: ["light", "dark"],
        minHeightPx: 20,
      },
    ],
    rules: [
      "The full-colour artwork appears on light surfaces; the anchor-deep bands (hero, footer) carry the reversed variant; the two motifs are decorative and never carry meaning",
      "The artwork amendment covers the LPS mark alone: UFRJ/COPPE marks remain text-only beside it",
      "Clear space around the mark equals the height of the letter L; the artwork is never recoloured, stretched, rotated or given effects",
      "Mobile masthead uses the compact variant, never a crop of the full lockup",
      "The waveform gradient inside the artwork is part of the artwork and is used only inside it",
      "The full name and the Inteligência Computacional descriptor are set as live text beside the mark, so they translate and scale as text",
      "Derived variants (compact, symbol, symbol-reversed, mono, reversed) are produced by path selection from the source; no geometry is redrawn",
    ],
  },
  navigation: {
    primary: ["Sobre", "Pesquisa", "Pessoas", "Ensino", "Oportunidades", "Notícias e eventos"],
    utility: ["Publicações", "Infraestrutura", "Contato", "Acessibilidade", "PT / EN"],
    rules: [
      "A visible route to contact and accessibility information is preserved",
      "Mobile uses a labelled native disclosure, operable by keyboard and applied without scripting",
      "The utility bar carries the session entry: Entrar / Sign in for visitors, Minha area / My area once a session exists",
      "Skip link, breadcrumbs where useful, visible focus, and a stable institutional footer are mandatory",
      "Submenus open on hover for pointers and on focus for keyboards, and every destination stays reachable by tab order",
    ],
  },
  surfaces: [
    {
      id: "homepage",
      mode: "persuade",
      taskDensity: "low",
      states: {
        empty:
          "Every module keeps its heading and renders an honest not-published notice instead of inventing content",
        error:
          "A failed query renders the module notice; the page never shows a partial record as if it were complete",
        "long-content":
          "Card grids wrap to additional rows; titles clamp to three lines; the hero statement keeps its own measure",
        "image-free":
          "Cards without a cleared image fall back to the waveform motif on the anchor substrate, never a broken frame",
      },
    },
    {
      id: "professor",
      mode: "read",
      taskDensity: "medium",
      states: {
        empty:
          "The record renders its identity, role and affiliation with a notice that no further material is published",
        error: "Unavailable relations are listed as unavailable, never silently omitted",
        "long-content":
          "Biography runs in the 68ch reading column; the metadata rail collapses above the content on small screens",
        attribution: "Affiliation, area and external identifiers are always shown together",
      },
    },
    {
      id: "offering",
      mode: "operate",
      taskDensity: "high",
      states: {
        empty:
          "The offering renders its code, title and status with an empty checklist rather than a hidden section",
        error:
          "A rejected publish attempt keeps the editor input and shows the blocking reason inline",
        "long-content":
          "The materials table scrolls inside its own frame; the page never scrolls horizontally",
        "scan-pending": "Pending scan work is visible as a status pill, not as a silent omission",
      },
    },
    {
      id: "sign-in",
      mode: "operate",
      taskDensity: "low",
      states: {
        empty:
          "The form renders with both fields empty, a labelled remember-me and a route to a new password",
        error:
          "A rejected attempt re-renders the form with the reason in text, the attempted username kept and the password cleared",
        "signed-in":
          "An existing session shows the account and its destinations instead of a second login form",
        "long-content":
          "The guidance column sits beside the form above 60rem and beneath it below that; nothing scrolls sideways",
      },
    },
    {
      id: "professor-area",
      mode: "operate",
      taskDensity: "medium",
      states: {
        empty: "A member with no authored content sees the next action rather than an empty frame",
        error: "Unavailable relations are listed as unavailable, never silently omitted",
        "long-content":
          "Subject and note tables scroll inside their own frame; the page never scrolls sideways",
        anonymous:
          "An anonymous visitor is redirected to the sign-in surface with the requested destination preserved",
      },
    },
    {
      id: "editor-dashboard",
      mode: "operate",
      taskDensity: "highest",
      states: {
        empty: "Each queue renders its own empty state with the next useful action",
        error: "Failed actions report the exact field and keep the rest of the form intact",
        "long-content":
          "Task lists stay one column with dense rows; counts stay visible while scrolling",
        lifecycle:
          "Draft, review, published and archived are distinguished by text as well as colour",
      },
    },
  ],
  applications: [
    {
      id: "desktop-branding",
      spec: "Masthead: full-colour artwork alone at 13-26rem wide (208-416px), no repeated wordmark text beside it; footer: reversed artwork on the anchor-deep substrate",
    },
    {
      id: "mobile-branding",
      spec: "The same full-colour artwork scales by clamp to 46vw (minimum 208px); it is never cropped and never shrunk below the lettering's legibility floor",
    },
    {
      id: "news-cover",
      spec: "16:9 cover: the artwork reversed on the anchor-deep field, kicker in the accent colour, headline in the interface family at h2-h1 scale",
    },
    {
      id: "course-document-cover",
      spec: "A4 cover: artwork at 40mm width on a light field, course code in the mono family, title at h1 scale",
    },
    {
      id: "social-preview",
      spec: "1200x630 social card (lps-og-default.png): anchor-deep field, white plate with the artwork and one accent rule; no text below 24px rendered size",
    },
    {
      id: "print",
      spec: "Grayscale-safe: the mark degrades to the mono symbol, hairlines and hard offsets stay visible, gradient fields are dropped",
    },
  ],
  bans: {
    effects: [
      "a linear-gradient, radial-gradient or conic-gradient composed outside the --gradient-* token layer",
      "a box-shadow or text-shadow written as a literal outside the --shadow-* tokens",
      "a border-radius written as a literal outside the --radius-* tokens",
      "a pill radius on a rectangular control surface (tags and status pills only)",
      "backdrop-filter and any blurred substrate",
      "transition: all, and any transition of a layout property",
      "scroll-hijack, scroll-linked animation and forced smooth scrolling",
      "marquee, autoplay media, looping background animation and parallax",
      "a waveform drawn by any surface instead of referenced from the supplied artwork",
      "a colour literal outside the :root token block, in CSS or in markup",
      "decorative photography, stock imagery and fabricated claims of research impact",
    ],
    note: "The 2026-09-18 revision banned these effects outright. This revision keeps the ban on ad-hoc effects and moves the permitted ones into the token layer: a gradient, shadow or radius exists once, as a token, and surfaces reference it. The decorative-waveform ban is unchanged and now enforced by asset identity — the motif is the supplied waveform, referenced. Depth is drawn with rules and hard offsets, so the ban on blurred substrates holds without banning separation.",
  },
};

const serialized = `${JSON.stringify(contract, null, 2)}\n`;

if (process.argv.includes("--check")) {
  const current = readFileSync(outPath, "utf8");
  if (current !== serialized) {
    console.error("design-contract.json is out of date: regenerate with this script.");
    process.exitCode = 1;
  } else {
    console.log("design-contract.json matches the shipped stylesheet.");
  }
} else {
  writeFileSync(outPath, serialized, "utf8");
  console.log(
    `Wrote docs/design/design-contract.json — ${colorTokens.length} palette tokens, ${colorPairs.length} contrast pairs.`,
  );
}

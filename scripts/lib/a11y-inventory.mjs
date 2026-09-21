/**
 * Public route, state, locale, and interaction inventory for the accessibility gate.
 *
 * The inventory is authoritative: every block template shipped by the theme must be
 * claimed by at least one entry, and every entry must be captured live before the
 * gate can pass. Skipping a template is a gate error, not a silent omission.
 */

import { readdir } from "node:fs/promises";
import path from "node:path";

export const THEME_TEMPLATE_DIR = "wp-content/themes/lps-theme/templates";

/**
 * Every audited public surface.
 *
 * `template` names the block template WordPress resolves for the route.
 * `state` names the authored/derived state the capture must exercise.
 * `classes` names the accessibility concern classes the route is expected to carry.
 */
export const ROUTES = [
  // Home, both locales.
  {
    id: "home-pt",
    template: "front-page.html",
    locale: "pt-br",
    path: "/pt-br/",
    state: "populated",
    classes: ["landmarks", "headings", "navigation", "disclosure", "language-switch"],
  },
  {
    id: "home-en",
    template: "front-page.html",
    locale: "en",
    path: "/en/",
    state: "populated",
    classes: ["landmarks", "headings", "navigation", "disclosure", "language-switch"],
  },

  // Institutional pages rendered by the generic page template.
  {
    id: "about-pt",
    template: "page.html",
    locale: "pt-br",
    path: "/pt-br/sobre/",
    state: "populated",
    classes: ["headings", "reading"],
  },
  {
    id: "about-en",
    template: "page.html",
    locale: "en",
    path: "/en/about/",
    state: "populated",
    classes: ["headings", "reading"],
  },
  {
    id: "privacy-pt",
    template: "page.html",
    locale: "pt-br",
    path: "/pt-br/privacidade/",
    state: "populated",
    classes: ["reading"],
  },
  {
    id: "privacy-en",
    template: "page.html",
    locale: "en",
    path: "/en/privacy/",
    state: "populated",
    classes: ["reading"],
  },
  {
    id: "accessibility-pt",
    template: "page.html",
    locale: "pt-br",
    path: "/pt-br/acessibilidade/",
    state: "populated",
    classes: ["barrier-reporting", "reading"],
  },
  {
    id: "accessibility-en",
    template: "page.html",
    locale: "en",
    path: "/en/accessibility/",
    state: "populated",
    classes: ["barrier-reporting", "reading"],
  },
  {
    id: "contact-pt",
    template: "page.html",
    locale: "pt-br",
    path: "/pt-br/contato/",
    state: "populated",
    classes: ["contact-handoff"],
  },
  {
    id: "contact-en",
    template: "page.html",
    locale: "en",
    path: "/en/contact/",
    state: "populated",
    classes: ["contact-handoff"],
  },
  {
    id: "collaborate-pt",
    template: "page.html",
    locale: "pt-br",
    path: "/pt-br/colabore/",
    state: "populated",
    classes: ["contact-handoff"],
  },
  {
    id: "collaborate-en",
    template: "page.html",
    locale: "en",
    path: "/en/collaborate/",
    state: "populated",
    classes: ["contact-handoff"],
  },
  {
    id: "infrastructure-pt",
    template: "page.html",
    locale: "pt-br",
    path: "/pt-br/infraestrutura/",
    state: "populated",
    classes: ["reading", "external-links"],
  },
  {
    id: "infrastructure-en",
    template: "page.html",
    locale: "en",
    path: "/en/infrastructure/",
    state: "populated",
    classes: ["reading", "external-links"],
  },
  {
    id: "media-pt",
    template: "page.html",
    locale: "pt-br",
    path: "/pt-br/midia-acessivel/",
    state: "authored-media",
    classes: ["images", "figures", "tables", "downloads", "transcripts", "language-of-parts"],
  },
  {
    id: "media-en",
    template: "page.html",
    locale: "en",
    path: "/en/accessible-media/",
    state: "authored-media",
    classes: ["images", "figures", "tables", "downloads", "transcripts", "language-of-parts"],
  },

  // Search surface: prompt, results, empty, boundary error, pagination, facets.
  {
    id: "search-prompt-pt",
    template: "page-busca.html",
    locale: "pt-br",
    path: "/pt-br/busca/",
    state: "prompt",
    classes: ["forms", "status"],
  },
  {
    id: "search-results-pt",
    template: "page-busca.html",
    locale: "pt-br",
    path: "/pt-br/busca/?q=sinais",
    state: "results",
    classes: ["forms", "status", "filters", "pagination"],
  },
  {
    id: "search-empty-pt",
    template: "page-busca.html",
    locale: "pt-br",
    path: "/pt-br/busca/?q=zzzznaoexistezzzz",
    state: "empty",
    classes: ["status"],
  },
  {
    id: "search-error-pt",
    template: "page-busca.html",
    locale: "pt-br",
    path: "/pt-br/busca/?q=a",
    state: "boundary-error",
    classes: ["error-announcement"],
  },
  {
    id: "search-facet-pt",
    template: "page-busca.html",
    locale: "pt-br",
    path: "/pt-br/busca/?q=sinais&record=lps_publication",
    state: "faceted",
    classes: ["filters", "status"],
  },
  {
    id: "search-prompt-en",
    template: "page-search.html",
    locale: "en",
    path: "/en/search/",
    state: "prompt",
    classes: ["forms", "status"],
  },
  {
    id: "search-results-en",
    template: "page-search.html",
    locale: "en",
    path: "/en/search/?q=signal",
    state: "results",
    classes: ["forms", "status", "filters", "pagination"],
  },
  {
    id: "search-empty-en",
    template: "page-search.html",
    locale: "en",
    path: "/en/search/?q=zzzznotfoundzzzz",
    state: "empty",
    classes: ["status"],
  },
  {
    id: "search-error-en",
    template: "page-search.html",
    locale: "en",
    path: "/en/search/?q=a",
    state: "boundary-error",
    classes: ["error-announcement"],
  },

  // Core WordPress search template (header search control).
  {
    id: "core-search-pt",
    template: "search.html",
    locale: "pt-br",
    path: "/pt-br/?s=sinais",
    state: "results",
    classes: ["forms", "status"],
  },
  {
    id: "core-search-en",
    template: "search.html",
    locale: "en",
    path: "/en/?s=signal",
    state: "results",
    classes: ["forms", "status"],
  },

  // Generic index and date archive fallbacks.
  {
    id: "core-archive-pt",
    template: "archive.html",
    locale: "pt-br",
    path: "/pt-br/2026/",
    state: "populated",
    classes: ["headings", "pagination"],
  },
  {
    id: "core-single-pt",
    template: "single.html",
    locale: "pt-br",
    path: "/pt-br/nota-de-acessibilidade/",
    state: "populated",
    classes: ["reading"],
  },

  // Record archives.
  {
    id: "people-pt",
    template: "archive-lps_person.html",
    locale: "pt-br",
    path: "/pt-br/pessoas/",
    state: "populated",
    classes: ["filters", "status", "images"],
  },
  {
    id: "people-en",
    template: "archive-lps_person.html",
    locale: "en",
    path: "/en/people/",
    state: "populated",
    classes: ["filters", "status", "images"],
  },
  {
    id: "people-filtered-pt",
    template: "archive-lps_person.html",
    locale: "pt-br",
    path: "/pt-br/pessoas/?role%5B%5D=student",
    state: "filtered",
    classes: ["filters", "status"],
  },
  {
    id: "people-empty-pt",
    template: "archive-lps_person.html",
    locale: "pt-br",
    path: "/pt-br/pessoas/?role%5B%5D=does-not-exist",
    state: "empty",
    classes: ["filters", "status"],
  },
  {
    id: "projects-pt",
    template: "archive-lps_project.html",
    locale: "pt-br",
    path: "/pt-br/projetos/",
    state: "populated",
    classes: ["filters", "pagination"],
  },
  {
    id: "projects-en",
    template: "archive-lps_project.html",
    locale: "en",
    path: "/en/projects/",
    state: "populated",
    classes: ["filters", "pagination"],
  },
  {
    id: "publications-pt",
    template: "archive-lps_publication.html",
    locale: "pt-br",
    path: "/pt-br/publicacoes/",
    state: "populated",
    classes: ["filters", "pagination"],
  },
  {
    id: "publications-en",
    template: "archive-lps_publication.html",
    locale: "en",
    path: "/en/publications/",
    state: "populated",
    classes: ["filters", "pagination"],
  },
  {
    id: "research-pt",
    template: "archive-lps_research_area.html",
    locale: "pt-br",
    path: "/pt-br/pesquisa/",
    state: "populated",
    classes: ["headings"],
  },
  {
    id: "research-en",
    template: "archive-lps_research_area.html",
    locale: "en",
    path: "/en/research/",
    state: "populated",
    classes: ["headings"],
  },
  {
    id: "news-pt",
    template: "archive-lps_news.html",
    locale: "pt-br",
    path: "/pt-br/noticias/",
    state: "populated",
    classes: ["headings", "pagination"],
  },
  {
    id: "news-en",
    template: "archive-lps_news.html",
    locale: "en",
    path: "/en/news/",
    state: "populated",
    classes: ["headings", "pagination"],
  },
  {
    id: "events-pt",
    template: "archive-lps_event.html",
    locale: "pt-br",
    path: "/pt-br/eventos/",
    state: "populated",
    classes: ["headings", "state-labels"],
  },
  {
    id: "events-en",
    template: "archive-lps_event.html",
    locale: "en",
    path: "/en/events/",
    state: "populated",
    classes: ["headings", "state-labels"],
  },
  {
    id: "opportunities-pt",
    template: "archive-lps_opportunity.html",
    locale: "pt-br",
    path: "/pt-br/oportunidades/",
    state: "populated",
    classes: ["state-labels", "contact-handoff"],
  },
  {
    id: "opportunities-en",
    template: "archive-lps_opportunity.html",
    locale: "en",
    path: "/en/opportunities/",
    state: "populated",
    classes: ["state-labels", "contact-handoff"],
  },
  {
    id: "organizations-pt",
    template: "archive-lps_organization.html",
    locale: "pt-br",
    path: "/pt-br/organizacoes/",
    state: "populated",
    classes: ["images"],
  },
  {
    id: "organizations-en",
    template: "archive-lps_organization.html",
    locale: "en",
    path: "/en/organizations/",
    state: "populated",
    classes: ["images"],
  },

  // Single records, including derived and boundary states.
  {
    id: "person-pt",
    template: "single-lps_person.html",
    locale: "pt-br",
    path: "/pt-br/pessoas/ana-alvares-fixture/",
    state: "populated",
    classes: ["images", "headings", "external-links"],
  },
  {
    id: "person-en",
    template: "single-lps_person.html",
    locale: "en",
    path: "/en/people/fixture-technical-staff/",
    state: "populated",
    classes: ["images", "headings"],
  },
  {
    id: "person-photo-withheld-pt",
    template: "single-lps_person.html",
    locale: "pt-br",
    path: "/pt-br/pessoas/equipe-tecnica-fixture/",
    state: "photo-withheld",
    classes: ["images"],
  },
  {
    id: "project-pt",
    template: "single-lps_project.html",
    locale: "pt-br",
    path: "/pt-br/projetos/projeto-demonstracao-fixture/",
    state: "populated",
    classes: ["headings", "relationships"],
  },
  {
    id: "project-en",
    template: "single-lps_project.html",
    locale: "en",
    path: "/en/projects/demonstration-project-fixture/",
    state: "populated",
    classes: ["headings", "relationships"],
  },
  {
    id: "publication-pt",
    template: "single-lps_publication.html",
    locale: "pt-br",
    path: "/pt-br/publicacoes/publicacao-demonstracao-fixture/",
    state: "populated",
    classes: ["downloads", "citation", "external-links"],
  },
  {
    id: "publication-en",
    template: "single-lps_publication.html",
    locale: "en",
    path: "/en/publications/demonstration-publication-fixture/",
    state: "populated",
    classes: ["downloads", "citation", "external-links"],
  },
  {
    id: "research-area-pt",
    template: "single-lps_research_area.html",
    locale: "pt-br",
    path: "/pt-br/pesquisa/processamento-de-sinais-fixture/",
    state: "populated",
    classes: ["headings"],
  },
  {
    id: "news-single-pt",
    template: "single-lps_news.html",
    locale: "pt-br",
    path: "/pt-br/noticias/novo-laboratorio/",
    state: "populated",
    classes: ["reading"],
  },
  {
    id: "event-cancelled-pt",
    template: "single-lps_event.html",
    locale: "pt-br",
    path: "/pt-br/eventos/workshop-cancelado/",
    state: "cancelled",
    // A derived record state rendered on load is not a status message: it is
    // permanent page content, announced by its heading and visible label.
    classes: ["state-labels"],
  },
  {
    id: "opportunity-open-pt",
    template: "single-lps_opportunity.html",
    locale: "pt-br",
    path: "/pt-br/oportunidades/bolsa-doutorado-sinais/",
    state: "open",
    classes: ["state-labels", "contact-handoff"],
  },
  {
    id: "opportunity-open-en",
    template: "single-lps_opportunity.html",
    locale: "en",
    path: "/en/opportunities/doctoral-scholarship-signal-processing/",
    state: "open",
    classes: ["state-labels", "contact-handoff"],
  },
  {
    id: "opportunity-closed-pt",
    template: "single-lps_opportunity.html",
    locale: "pt-br",
    path: "/pt-br/oportunidades/estagio-encerrado/",
    state: "closed",
    classes: ["state-labels"],
  },
  {
    id: "organization-pt",
    template: "single-lps_organization.html",
    locale: "pt-br",
    path: "/pt-br/organizacoes/parceiro-publico-fixture/",
    state: "populated",
    classes: ["images", "external-links"],
  },

  // Teaching surfaces: landing, course detail, and the canonical offering page.
  {
    id: "teaching-pt",
    template: "archive-lps_course.html",
    locale: "pt-br",
    path: "/pt-br/ensino/",
    state: "populated",
    classes: ["headings", "navigation"],
  },
  {
    id: "teaching-en",
    template: "archive-lps_course.html",
    locale: "en",
    path: "/en/teaching/",
    state: "populated",
    classes: ["headings", "navigation"],
  },
  {
    id: "course-pt",
    template: "single-lps_course.html",
    locale: "pt-br",
    path: "/pt-br/ensino/disciplinas/sinais-e-sistemas-fixture/",
    state: "populated",
    classes: ["headings", "relationships"],
  },
  {
    id: "course-en",
    template: "single-lps_course.html",
    locale: "en",
    path: "/en/teaching/courses/signals-and-systems-fixture/",
    state: "populated",
    classes: ["headings", "relationships"],
  },
  {
    id: "offering-pt",
    template: "single-lps_offering.html",
    locale: "pt-br",
    path: "/pt-br/ensino/disciplinas/sinais-e-sistemas-fixture/2026-2-semester/t01/",
    state: "current",
    // Temporal status is permanent page content announced by its visible label.
    classes: ["headings", "relationships", "state-labels"],
  },
  {
    id: "offering-en",
    template: "single-lps_offering.html",
    locale: "en",
    path: "/en/teaching/courses/signals-and-systems-fixture/2026-2-semester/t01/",
    state: "current",
    classes: ["headings", "relationships", "state-labels"],
  },

  // Error state.
  {
    id: "not-found-pt",
    template: "404.html",
    locale: "pt-br",
    path: "/pt-br/rota-inexistente-a11y/",
    state: "not-found",
    // The 404 state is the page itself, announced by its own level-one heading,
    // not a message injected into an existing page.
    classes: ["headings"],
    expectStatus: 404,
  },
  {
    id: "not-found-en",
    template: "404.html",
    locale: "en",
    path: "/en/missing-route-a11y/",
    state: "not-found",
    // The 404 state is the page itself, announced by its own level-one heading,
    // not a message injected into an existing page.
    classes: ["headings"],
    expectStatus: 404,
  },
];

/**
 * Templates that no public URL can resolve, with the reason.
 *
 * WordPress requires `index.html` in every block theme, but `front-page.html`,
 * `archive.html`, `single.html`, `page.html` and `search.html` resolve every public
 * query this site answers, so `index.html` never renders. It is audited as template
 * source instead of being skipped.
 */
export const FALLBACK_TEMPLATES = {
  "index.html":
    "Required block-theme fallback; every public query resolves to a more specific template, so the file is audited as source.",
};

/** Keyboard journeys that must be executed with a real browser in both locales. */
export const KEYBOARD_JOURNEYS = [
  { id: "search", locales: ["pt-br", "en"] },
  { id: "filter", locales: ["pt-br", "en"] },
  { id: "language-switch", locales: ["pt-br", "en"] },
  { id: "opportunity-contact", locales: ["pt-br", "en"] },
  { id: "citation-download", locales: ["pt-br", "en"] },
  { id: "barrier-reporting", locales: ["pt-br", "en"] },
];

/** Returns every route entry. */
export function routes() {
  return ROUTES.map((route) => ({ ...route }));
}

/** Returns the template file names shipped by the theme. */
export async function themeTemplates(templateDir = THEME_TEMPLATE_DIR) {
  const entries = await readdir(templateDir);
  return entries.filter((entry) => path.extname(entry) === ".html").sort();
}

/**
 * Reports templates that no inventory entry claims.
 *
 * @returns {Promise<{templates: string[], covered: string[], uncovered: string[], unknown: string[]}>}
 */
export async function templateCoverage(templateDir = THEME_TEMPLATE_DIR) {
  const templates = await themeTemplates(templateDir);
  const claimed = new Set([
    ...ROUTES.map((route) => route.template),
    ...Object.keys(FALLBACK_TEMPLATES),
  ]);
  const uncovered = templates.filter((template) => !claimed.has(template));
  const unknown = [...claimed].filter((template) => !templates.includes(template)).sort();
  return {
    templates,
    covered: templates.filter((template) => claimed.has(template)),
    fallbackOnly: templates.filter((template) => template in FALLBACK_TEMPLATES),
    uncovered,
    unknown,
  };
}

/**
 * Presentation components for the institutional redesign preview.
 *
 * These emit the same class vocabulary the WordPress theme renders
 * (`wp-content/themes/lps-theme/includes/*.php`), so the stylesheet under review is
 * the one that ships: `assets/css/theme.css` is copied into the build untouched.
 *
 * Everything is server-rendered HTML with no client JavaScript. Interactive
 * behaviour — the mobile navigation panel — uses the native <details> disclosure,
 * exactly like the theme's Shell::header_markup().
 */

import { navigation, site, utilityLinks } from "../content/site.mjs";

export const esc = (value) =>
  String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");

/**
 * Resolves a localized value. Route slugs are lowercase (`pt-br`), while the content
 * source uses the BCP-47 form (`pt-BR`) — the mapping lives here so content stays
 * readable and unambiguous.
 */
export const pick = (value, locale) => {
  if (!value || typeof value !== "object" || Array.isArray(value)) return value;
  const key = locale === "pt-br" ? "pt-BR" : "en";
  return value[key] ?? value["pt-BR"] ?? value.en;
};

/** Localized UI strings that are not content. */
const ui = (locale) => {
  const en = locale === "en";
  return {
    skip: en ? "Skip to content" : "Pular para o conteúdo",
    menu: en ? "Menu and site tools" : "Menu e ferramentas do site",
    navLabel: en ? "Primary navigation" : "Navegação principal",
    quickLabel: en ? "Quick access" : "Acesso rápido",
    localeLabel: en ? "Language" : "Idioma",
    searchLabel: en ? "Search the LPS website" : "Buscar no site do LPS",
    searchButton: en ? "Search" : "Buscar",
    collaborate: en ? "Collaborate" : "Colabore",
    home: en ? "Home" : "Início",
    onThisPage: en ? "On this page" : "Nesta página",
    brandName: en ? "Signal Processing Laboratory" : "Laboratório de Processamento de Sinais",
    brandDescriptor: en
      ? "Computational intelligence · UFRJ/COPPE"
      : "Inteligência computacional · UFRJ/COPPE",
    readMore: en ? "Read more" : "Saiba mais",
    brandLinkLabel: en
      ? "Signal Processing Laboratory — home"
      : "Laboratório de Processamento de Sinais — início",
    signIn: en ? "Sign in" : "Entrar",
    signInTitle: en ? "Sign in" : "Entrar no site",
    myArea: en ? "My area" : "Minha área",
    memberArea: en ? "Faculty area" : "Área do professor",
    sessionSignedIn: en ? "Signed in" : "Conectado",
    seeAll: en ? "See all" : "Ver tudo",
    whereLabel: en ? "Where to find us" : "Onde nos encontrar",
    officeLabel: en ? "Office hours" : "Atendimento",
    agendaEmptyTitle: en ? "No event is currently scheduled" : "Nenhum evento agendado no momento",
    agendaEmptyBody: en
      ? "The public source does not record a calendar of future events. When a call, seminar or defence is scheduled, it is published here."
      : "A fonte pública não registra uma agenda de eventos futuros. Quando houver chamada, seminário ou defesa agendada, ela é publicada aqui.",
  };
};

export const localePath = (route, locale) => route[locale];

/** Absolute-in-build path for a route entry that carries both locales. */
const href = (target, locale) => (typeof target === "string" ? target : pick(target, locale));

/* ---------------------------------------------------------------------------
 * Shell
 * ------------------------------------------------------------------------ */

/* The masthead carries the artwork alone: the lockup already sets the laboratory
 * name in type, so repeating it beside the mark would say it twice and shrink the
 * mark. The link keeps an accessible name for assistive technology. The artwork is
 * sized by the stylesheet (height clamped, width follows the lockup's ~2.05:1
 * canvas), so it grows with the viewport instead of being pinned to a fixed slot. */
function brandMarkup(locale) {
  const t = ui(locale);
  return `<a class="lps-brand" href="${locale === "en" ? "/en/" : "/"}" aria-label="${esc(t.brandLinkLabel)}">
<span class="lps-logo-slot"><img class="lps-logo" src="/assets/img/mark/lps-coppe-blue-lockup.svg" alt="" width="1622" height="804" decoding="async"></span>
</a>`;
}

export function header(locale, currentPath, alternates) {
  // The search form renders twice — once in the masthead row, once inside the
  // no-JavaScript disclosure panel — so each instance carries its own control id;
  // a duplicated id would break the label association in half the rendered pages.
  let searchInstance = 0;
  const t = ui(locale);
  const home = locale === "en" ? "/en/" : "/";
  const searchAction = locale === "en" ? "/en/search/" : "/busca/";
  const utility = utilityLinks
    .map(
      (link) =>
        `<li><a href="${href(link.href, locale)}">${esc(pick(link.label, locale))}</a></li>`,
    )
    .join("");

  const navItems = navigation
    .map((item) => {
      const url = href(item.href, locale);
      const current = currentPath === url ? ' aria-current="page"' : "";
      if (!item.children) {
        return `<li class="lps-nav-item"><a class="lps-nav-link"${current} href="${url}">${esc(pick(item.label, locale))}</a></li>`;
      }
      const submenu = item.children
        .map(
          (child) =>
            `<li><a href="${href(child.href, locale)}">${esc(pick(child.label, locale))}</a></li>`,
        )
        .join("");
      return `<li class="lps-nav-item"><a class="lps-nav-link"${current} href="${url}">${esc(pick(item.label, locale))}<span class="lps-nav-caret" aria-hidden="true"></span></a><ul class="lps-nav-menu">${submenu}</ul></li>`;
    })
    .join("");

  const searchForm = () => {
    searchInstance += 1;
    const id = `lps-search-input-${searchInstance}`;
    return `<form class="lps-search" role="search" action="${searchAction}" method="get">
<label for="${id}">${esc(t.searchLabel)}</label>
<div><input id="${id}" name="q" type="search" autocomplete="off"><button class="lps-button" type="submit">${esc(t.searchButton)}</button></div>
</form>`;
  };

  const localeSwitch = `<nav class="lps-locale-switch" aria-label="${esc(t.localeLabel)}">
<a${locale === "pt-br" ? ' aria-current="page"' : ""} hreflang="pt-BR" lang="pt-BR" href="${alternates?.pt ?? "/"}">PT</a>
<a${locale === "en" ? ' aria-current="page"' : ""} hreflang="en" lang="en" href="${alternates?.en ?? "/en/"}">EN</a>
</nav>`;

  return `<a class="lps-skip-link" href="#lps-main">${esc(t.skip)}</a>
<header class="lps-site-header">
<div class="lps-utility-bar">
<div class="lps-utility-inner lps-page-grid">
<p>${esc(locale === "en" ? "Signal Processing Laboratory · UFRJ · COPPE" : "Laboratório de Processamento de Sinais · UFRJ · COPPE")}</p>
<nav aria-label="${esc(t.quickLabel)}"><ul class="lps-utility-links">${utility}</ul></nav>
${localeSwitch}
<a class="lps-session-link" href="${locale === "en" ? "/en/sign-in/" : "/entrar/"}">${esc(t.signIn)}</a>
</div>
</div>
<div class="lps-masthead lps-page-grid">
${brandMarkup(locale)}
<div class="lps-shell-tools">
${searchForm()}
<a class="lps-button lps-button-primary" href="${locale === "en" ? "/en/contact/" : "/contato/"}">${esc(t.collaborate)}</a>
</div>
</div>
<div class="lps-masthead-nav lps-page-grid">
<nav class="lps-primary-nav" aria-label="${esc(t.navLabel)}"><ul>${navItems}</ul></nav>
</div>
<details class="lps-shell-disclosure">
<summary>${esc(t.menu)}</summary>
<div class="lps-nav-panel lps-page-grid">
<nav class="lps-primary-nav" aria-label="${esc(t.navLabel)}"><ul>${navItems}</ul></nav>
<div class="lps-shell-tools">${searchForm()}<a class="lps-button lps-button-primary" href="${locale === "en" ? "/en/contact/" : "/contato/"}">${esc(t.collaborate)}</a></div>
</div>
</details>
</header>`.replace(`href="${home}"`, `href="${home}"`);
}

export function footer(locale) {
  const t = ui(locale);
  const en = locale === "en";
  const columns = [
    {
      heading: en ? "The laboratory" : "O laboratório",
      links: [
        [en ? "About" : "Sobre", en ? "/en/about/" : "/sobre/"],
        [en ? "History" : "História", `${en ? "/en/about/" : "/sobre/"}#historia`],
        [en ? "People" : "Pessoas", en ? "/en/people/" : "/pessoas/"],
        [en ? "Infrastructure" : "Infraestrutura", en ? "/en/infrastructure/" : "/infraestrutura/"],
        [
          en ? "Visual identity" : "Identidade visual",
          en ? "/en/visual-identity/" : "/identidade-visual/",
        ],
      ],
    },
    {
      heading: en ? "Research" : "Pesquisa",
      links: [
        [en ? "Research areas" : "Linhas de pesquisa", en ? "/en/research/" : "/pesquisa/"],
        [en ? "Projects" : "Projetos", en ? "/en/projects/" : "/projetos/"],
        [en ? "Publications" : "Publicações", en ? "/en/publications/" : "/publicacoes/"],
        [en ? "Teaching" : "Ensino", en ? "/en/teaching/" : "/ensino/"],
        [en ? "News" : "Notícias e eventos", en ? "/en/news/" : "/noticias/"],
      ],
    },
    {
      heading: en ? "Take part" : "Participe",
      links: [
        [en ? "Opportunities" : "Oportunidades", en ? "/en/opportunities/" : "/oportunidades/"],
        [en ? "Search" : "Busca", en ? "/en/search/" : "/busca/"],
        [en ? "Contact" : "Contato", en ? "/en/contact/" : "/contato/"],
        [en ? "Accessibility" : "Acessibilidade", en ? "/en/accessibility/" : "/acessibilidade/"],
        [en ? "Privacy" : "Privacidade", en ? "/en/privacy/" : "/privacidade/"],
      ],
    },
  ];

  const columnsMarkup = columns
    .map(
      (column) => `<div>
<h2>${esc(column.heading)}</h2>
<ul>${column.links.map(([label, url]) => `<li><a href="${url}">${esc(label)}</a></li>`).join("")}</ul>
</div>`,
    )
    .join("");

  return `<footer class="lps-site-footer">
<div class="lps-footer-inner lps-page-grid">
<div class="lps-footer-brand">
<a class="lps-wordmark lps-wordmark-light" href="${en ? "/en/" : "/"}" aria-label="LPS — ${esc(t.home)}"><img class="lps-logo" src="/assets/img/mark/lps-coppe-reversed-lockup.svg" alt="LPS — ${esc(t.brandName)}" width="1622" height="804" loading="lazy" decoding="async"></a>
<p>${esc(en ? "Part of COPPE at the Federal University of Rio de Janeiro." : "Parte da COPPE na Universidade Federal do Rio de Janeiro.")}</p>
<address>
${esc(site.address.line1)}<br>
${esc(site.address.line2)}<br>
${esc(site.address.line3)}<br>
${esc(site.address.city)}<br>
<a href="${site.phone.href}">${esc(site.phone.label)}</a> · ${esc(pick(site.phone.note, locale))}<br>
<a href="mailto:${site.emails.office}">${esc(site.emails.office)}</a>
</address>
</div>
${columnsMarkup}
</div>
<div class="lps-footer-bottom lps-page-grid">
<p>${esc(en ? `© ${new Date().getFullYear()} LPS/UFRJ. Content published under institutional responsibility.` : `© ${new Date().getFullYear()} LPS/UFRJ. Conteúdo publicado sob responsabilidade institucional.`)}</p>
<ul>
<li><a href="${site.external.pee}">PEE/COPPE</a></li>
<li><a href="${site.external.coppe}">COPPE</a></li>
<li><a href="${site.external.ufrj}">UFRJ</a></li>
<li><a href="${site.external.legacy}">${esc(en ? "Previous site" : "Site anterior")}</a></li>
</ul>
</div>
</footer>`;
}

/* ---------------------------------------------------------------------------
 * Page furniture
 * ------------------------------------------------------------------------ */

export function breadcrumbs(locale, trail) {
  const t = ui(locale);
  const items = [`<li><a href="${locale === "en" ? "/en/" : "/"}">${esc(t.home)}</a></li>`];
  trail.forEach((crumb, index) => {
    const last = index === trail.length - 1;
    items.push(
      last
        ? `<li aria-current="page">${esc(pick(crumb.label, locale))}</li>`
        : `<li><a href="${href(crumb.href, locale)}">${esc(pick(crumb.label, locale))}</a></li>`,
    );
  });
  return `<nav class="lps-breadcrumbs lps-page-grid" aria-label="${esc(locale === "en" ? "Breadcrumb" : "Trilha de navegação")}"><ol>${items.join("")}</ol></nav>`;
}

export function pageHeader({ kicker, title, lead, meta }) {
  return `<div class="lps-page-header">
<div class="lps-page-header-inner lps-page-grid">
<p class="lps-kicker">${esc(kicker)}</p>
<h1 class="lps-page-title">${esc(title)}</h1>
${lead ? `<p class="lps-lead">${esc(lead)}</p>` : ""}
${meta ? `<p class="lps-meta lps-mt-6">${esc(meta)}</p>` : ""}
</div>
</div>`;
}

export function section({ id, tone = "", labelledBy, inner }) {
  return `<section${id ? ` id="${id}"` : ""} class="lps-section${tone ? ` lps-section--${tone}` : ""}"${labelledBy ? ` aria-labelledby="${labelledBy}"` : ""}>
${inner}
</section>`;
}

export function sectionHead({ kicker, title, lead, action, id, center = false }) {
  return `<div class="lps-section-head${center ? " lps-section-head--center" : ""}">
<div>
${kicker ? `<p class="lps-kicker">${esc(kicker)}</p>` : ""}
<h2${id ? ` id="${id}"` : ""}>${esc(title)}</h2>
</div>
${lead || action ? `<div>${lead ? `<p class="lps-lead">${esc(lead)}</p>` : ""}${action ? `<p class="lps-mt-4"><a class="lps-more" href="${action.href}">${esc(action.label)}</a></p>` : ""}</div>` : ""}
</div>`;
}

export function card({
  title,
  body,
  meta,
  tags,
  action,
  accent = false,
  numbered = false,
  href: linkHref,
  media = false,
  foot,
}) {
  const inner = `<div class="lps-card-body">
${numbered ? `<span class="lps-card-index" aria-hidden="true">${esc(numbered)}</span>` : ""}
${title ? `<h3 class="lps-card-title">${linkHref ? `<a href="${linkHref}">${esc(title)}</a>` : esc(title)}</h3>` : ""}
${body ? `<p>${esc(body)}</p>` : ""}
${tags ? `<ul class="lps-term-token">${tags.map((tag) => `<li>${esc(tag)}</li>`).join("")}</ul>` : ""}
</div>
${action || meta || foot ? `<div class="lps-card-foot">${meta ? `<span class="lps-meta">${esc(meta)}</span>` : "<span></span>"}${action ? `<a class="lps-more" href="${action.href}">${esc(action.label)}</a>` : (foot ?? "")}</div>` : ""}`;

  return `<article class="lps-card${accent ? " lps-card--accent" : ""}${numbered ? " lps-card--numbered" : ""}">${
    media ? '<div class="lps-card-media" aria-hidden="true"></div>' : ""
  }${inner}</article>`;
}

export function personCard(locale, person) {
  const role = pick(person.role, locale);
  return `<article class="lps-person-card" id="${esc(person.slug)}">
<div class="lps-person-header">
<span class="lps-monogram${person.inMemoriam ? " lps-monogram--memoriam" : ""}" aria-hidden="true">${esc(person.initials)}</span>
<div>
<h3>${esc(person.name)}</h3>
<p class="lps-role">${esc(role)}</p>
</div>
</div>
<p class="lps-summary">${esc(pick(person.affiliation, locale))}</p>
<ul class="lps-term-token">${(pick(person.areas, locale) || []).map((area) => `<li>${esc(area)}</li>`).join("")}</ul>
<div class="lps-person-contact">
<a class="lps-more" href="${locale === "en" ? `/en/people/${person.slug}/` : `/pessoas/${person.slug}/`}">${esc(ui(locale).readMore)}</a>
${person.email ? `<a class="lps-meta" href="mailto:${esc(person.email)}">${esc(person.email)}</a>` : '<span class="lps-meta">in memoriam</span>'}
</div>
</article>`;
}

export function statBand(locale, stats) {
  return `<div class="lps-stat-band"><ul class="lps-page-grid">${stats
    .map(
      (stat) =>
        `<li class="lps-stat"><strong>${esc(stat.value)}</strong><span>${esc(pick(stat.label, locale))}</span></li>`,
    )
    .join("")}</ul></div>`;
}

export function timeline(locale, entries) {
  return `<ol class="lps-timeline">${entries
    .map(
      (entry) =>
        `<li><span class="lps-timeline-year">${esc(entry.year)}</span><div><p>${esc(pick(entry.text, locale))}</p></div></li>`,
    )
    .join("")}</ol>`;
}

export function courseTable(locale, rows, caption) {
  return `<div class="lps-table-scroll">
<table>
<caption>${esc(caption)}</caption>
<thead><tr><th scope="col">${esc(locale === "en" ? "Code" : "Código")}</th><th scope="col">${esc(locale === "en" ? "Course" : "Disciplina")}</th><th scope="col">${esc(locale === "en" ? "Professor" : "Professor")}</th><th scope="col">${esc(locale === "en" ? "Level" : "Nível")}</th></tr></thead>
<tbody>${rows
    .map((row) => {
      const level = pick(row.level, locale);
      const variant = level === "Graduate" ? "lps-level-grad" : "lps-level-undergrad";
      return `<tr><td><span class="lps-course-code">${esc(row.code)}</span></td><th scope="row">${esc(pick(row.title, locale))}</th><td>${esc(row.professor)}</td><td><span class="lps-level ${variant}">${esc(level)}</span></td></tr>`;
    })
    .join("")}</tbody>
</table>
</div>`;
}

export function alert({ tone = "info", title, body, list }) {
  const cls = tone === "info" ? "lps-alert lps-alert-info" : `lps-alert lps-alert-${tone}`;
  return `<div class="${cls}">
${title ? `<h2>${esc(title)}</h2>` : ""}
${body ? `<p>${esc(body)}</p>` : ""}
${list ? `<ul>${list.map((item) => `<li>${esc(item)}</li>`).join("")}</ul>` : ""}
</div>`;
}

export function ctaBand({ title, body, actions }) {
  return `<div class="lps-cta-band lps-cta-band--split">
<div>
<h2>${esc(title)}</h2>
<p>${esc(body)}</p>
</div>
<div class="lps-button-row">
${actions.map((action, index) => `<a class="lps-button ${index === 0 ? "lps-button-primary" : "lps-button-ghost"}" href="${action.href}">${esc(action.label)}</a>`).join("")}
</div>
</div>`;
}

export function facts(items) {
  return `<dl class="lps-facts">${items
    .map((item) => `<dt>${esc(item.term)}</dt><dd>${item.html ?? esc(item.description)}</dd>`)
    .join("")}</dl>`;
}

export function aside(locale, items, { left = true } = {}) {
  const t = ui(locale);
  return `<aside class="lps-aside${left ? " lps-aside--start" : ""}">
<nav class="lps-toc" aria-label="${esc(t.onThisPage)}">
<h2>${esc(t.onThisPage)}</h2>
<ul>${items.map((item) => `<li><a href="#${item.id}">${esc(pick(item.label, locale))}</a></li>`).join("")}</ul>
</nav>
</aside>`;
}

/* ---------------------------------------------------------------------------
 * Document
 * ------------------------------------------------------------------------ */

export function document(locale, { title, description, path, body, canonicalPath, alternates }) {
  return `<!doctype html>
<html lang="${locale === "en" ? "en" : "pt-BR"}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(title)}</title>
<meta name="description" content="${esc(description)}">
<link rel="canonical" href="${canonicalPath ?? path}">
<link rel="icon" href="/assets/img/mark/favicon-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="/assets/img/mark/apple-touch-icon.png">
<link rel="stylesheet" href="/assets/css/theme.css">
<meta name="theme-color" content="#061829">
<link rel="manifest" href="/assets/img/mark/site.webmanifest">
<meta property="og:type" content="website">
<meta property="og:title" content="${esc(title)}">
<meta property="og:description" content="${esc(description)}">
<meta property="og:image" content="/assets/img/mark/lps-mark-social.png">
</head>
<body>
${header(locale, path, alternates)}
<main id="lps-main" class="lps-main-content">
${body}
</main>
${footer(locale)}
</body>
</html>
`.replace("/* spacer */", "");
}

export const strings = { ui, pick, href };

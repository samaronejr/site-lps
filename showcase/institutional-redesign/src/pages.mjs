/**
 * Page composition for the institutional redesign preview.
 *
 * Each builder returns { title, description, body } for one locale. The body is
 * composed only from the components in components.mjs, so every page exercises the
 * shipped theme stylesheet. Content comes from ../content/site.mjs, which is quoted
 * from the public LPS sources recorded in content/inventory/.
 */

import {
  about,
  accessibility,
  contact,
  events,
  hero,
  infrastructure,
  journeys,
  member,
  news,
  opportunities,
  partnerLogos,
  people,
  privacy,
  projects,
  publications,
  research,
  site,
  teaching,
  utilityLinks,
} from "../content/site.mjs";
import {
  alert,
  aside,
  card,
  courseTable,
  ctaBand,
  esc,
  facts,
  pageHeader,
  personCard,
  pick,
  section,
  sectionHead,
  statBand,
  strings,
  timeline,
} from "./components.mjs";

const { ui } = strings;
const t = (value, locale) => pick(value, locale);

const link = (locale, pt, en) => (locale === "en" ? en : pt);

/** Localized list: one localized value per element. */
const tl = (value, locale) =>
  Array.isArray(value) ? value.map((item) => pick(item, locale)) : pick(value, locale);

/** Accent-insensitive comparison, because the two records are edited apart. */
const sameName = (left, right) =>
  String(left)
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .trim() ===
  String(right)
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .trim();

/** Courses taught by one professor, read from the single teaching record. */
const coursesFor = (person) =>
  [...teaching.graduate, ...teaching.undergraduate].filter((course) =>
    sameName(course.professor, person.name),
  );

const personPath = (person, locale) =>
  locale === "en" ? `/en/people/${person.slug}/` : `/pessoas/${person.slug}/`;

/* ---------------------------------------------------------------------------
 * Home
 * ------------------------------------------------------------------------ */

function home(locale) {
  const en = locale === "en";
  const u = ui(locale);

  // Duotone hero headline: the accent phrase renders in lime (FEEC grammar).
  const heroTitle = (loc) => {
    const title = esc(t(hero.title, loc));
    const accent = esc(t(hero.accent, loc));
    const at = title.indexOf(accent);
    if (at === -1) return title;
    return `${title.slice(0, at)}<span class="lps-hero-accent">${accent}</span>${title.slice(at + accent.length)}`;
  };

  const heroBlock = `<div class="lps-hero">
<div class="lps-hero-inner lps-page-grid">
<div>
<p class="lps-kicker">${esc(t(hero.kicker, locale))}</p>
<h1 class="lps-hero-title">${heroTitle(locale)}</h1>
<p class="lps-hero-lead">${esc(t(hero.lead, locale))}</p>
<div class="lps-hero-actions">${hero.actions
    .map(
      (action, index) =>
        `<a class="lps-button ${index === 0 ? "lps-button-primary" : "lps-button-ghost"}" href="${t(action.href, locale)}">${esc(t(action.label, locale))}</a>`,
    )
    .join("")}</div>
</div>
<div class="lps-hero-support">
<h2>${esc(en ? "What the laboratory does" : "O que o laboratório faz")}</h2>
<ul>
${[
  en
    ? "Signal processing and computational intelligence research at COPPE/UFRJ"
    : "Pesquisa em processamento de sinais e inteligência computacional na COPPE/UFRJ",
  en
    ? "Online filtering and event simulation in the ATLAS experiment at CERN"
    : "Filtragem online e simulação de eventos no experimento ATLAS, no CERN",
  en
    ? "Passive sonar and underwater acoustics with the Brazilian Navy"
    : "Sonar passivo e acústica submarina com a Marinha do Brasil",
  en
    ? "Applied projects with industry, from prototype to operation"
    : "Projetos aplicados com a indústria, do protótipo à operação",
]
  .map((item) => `<li><span>${esc(item)}</span></li>`)
  .join("")}
</ul>
</div>
</div>
</div>`;

  const journeyBlock = section({
    id: "percurso",
    labelledBy: "home-journeys",
    inner: `<div class="lps-page-grid">
${sectionHead({
  kicker: t(journeys.kicker, locale),
  title: t(journeys.title, locale),
  lead: t(journeys.lead, locale),
  id: "home-journeys",
})}
<ul class="lps-home-journeys">
${journeys.items
  .map(
    (item) => `<li class="lps-journey">
<span class="lps-journey-label">${esc(t(item.meta, locale))}</span>
<h3>${esc(t(item.title, locale))}</h3>
<p>${esc(t(item.body, locale))}</p>
<a class="lps-more" href="${t(item.href, locale)}">${esc(t(item.action, locale))}</a>
</li>`,
  )
  .join("")}
</ul>
</div>`,
  });

  const researchBlock = section({
    tone: "tint",
    id: "pesquisa",
    labelledBy: "home-research",
    inner: `<div class="lps-page-grid">
${sectionHead({
  kicker: t(research.kicker, locale),
  title: t(research.title, locale),
  lead: t(research.lead, locale),
  id: "home-research",
  action: { href: link(locale, "/pesquisa/", "/en/research/"), label: `${u.seeAll} →` },
})}
<div class="lps-grid lps-grid--3">
${research.areas
  .slice(0, 6)
  .map((area) =>
    card({
      title: t(area.title, locale),
      body: t(area.body, locale),
      tags: t(area.topics, locale).slice(0, 3),
      accent: true,
    }),
  )
  .join("")}
</div>
</div>`,
  });

  const projectsBlock = section({
    id: "projetos",
    labelledBy: "home-projects",
    inner: `<div class="lps-page-grid">
${sectionHead({
  kicker: en ? "Projects" : "Projetos",
  title: en ? "Research in partnership" : "Pesquisa em parceria",
  lead: en
    ? "Selected projects recorded in the laboratory public material, with their partners."
    : "Projetos selecionados registrados no material público do laboratório, com seus parceiros.",
  id: "home-projects",
  action: { href: link(locale, "/projetos/", "/en/projects/"), label: `${u.seeAll} →` },
})}
<div class="lps-grid lps-grid--3">
${projects
  .slice(0, 3)
  .map((project) =>
    card({
      title: t(project.title, locale),
      body: t(project.summary, locale),
      meta: project.period,
      tags: t(project.tags, locale),
      href: link(locale, "/projetos/", "/en/projects/"),
      media: true,
    }),
  )
  .join("")}
</div>
</div>`,
  });

  const teachingBlock = section({
    tone: "alt",
    id: "ensino",
    labelledBy: "home-teaching",
    inner: `<div class="lps-page-grid">
<div class="lps-split">
<div>
<p class="lps-kicker">${esc(en ? "Teaching" : "Ensino")}</p>
<h2 id="home-teaching">${esc(en ? "Courses, materials and supervision" : "Disciplinas, materiais e orientação")}</h2>
<p class="lps-lead">${esc(
      en
        ? "The laboratory teaches at the Polytechnic School and in the Electrical Engineering Program at COPPE, from instrumentation to deep learning."
        : "O laboratório atua na Escola Politécnica e no Programa de Engenharia Elétrica da COPPE, da instrumentação ao aprendizado profundo.",
    )}</p>
<p><a class="lps-more" href="${link(locale, "/ensino/", "/en/teaching/")}">${esc(en ? "All courses" : "Todas as disciplinas")}</a></p>
</div>
<div>
${courseTable(locale, [...teaching.graduate.slice(0, 2), ...teaching.undergraduate.slice(0, 1)], en ? "Courses currently taught by the laboratory" : "Disciplinas atualmente ministradas pelo laboratório")}
</div>
</div>
</div>`,
  });

  const peopleBlock = section({
    id: "pessoas",
    labelledBy: "home-people",
    inner: `<div class="lps-page-grid">
${sectionHead({
  kicker: en ? "People" : "Pessoas",
  title: en ? "Who works here" : "Quem trabalha aqui",
  lead: en
    ? "Four full-time professors, two of them full professors, plus post-doctoral researchers and graduate and undergraduate students."
    : "Quatro professores em tempo integral, dos quais dois são titulares, além de pesquisadores de pós-doutorado e estudantes de pós-graduação e graduação.",
  id: "home-people",
  action: { href: link(locale, "/pessoas/", "/en/people/"), label: `${u.seeAll} →` },
})}
<div class="lps-people-grid">
${people
  .map((person) => personCard(locale, person))
  .join("")}
</div>
</div>`,
  });

  const newsBlock = section({
    tone: "tint",
    id: "noticias",
    labelledBy: "home-news",
    inner: `<div class="lps-page-grid">
${sectionHead({
  kicker: en ? "News and events" : "Notícias e eventos",
  title: en ? "Latest institutional records" : "Últimos registros institucionais",
  lead: en
    ? "Each entry is dated and traceable to the public source it came from."
    : "Cada entrada é datada e rastreável à fonte pública de origem.",
  id: "home-news",
  action: { href: link(locale, "/noticias/", "/en/news/"), label: `${u.seeAll} →` },
})}
<ol class="lps-agenda">
${news
  .slice(0, 4)
  .map(
    (item) => `<li>
<div class="lps-event-date"><strong>${esc(t(item.dateLabel, locale))}</strong><span>${esc(t(item.kicker, locale))}</span></div>
<div>
<h3>${esc(t(item.title, locale))}</h3>
<p>${esc(t(item.summary, locale))}</p>
</div>
<a class="lps-more" href="${t(item.source, locale)}" rel="external">${esc(en ? "Source" : "Fonte")}</a>
</li>`,
  )
  .join("")}
</ol>
</div>`,
  });

  // Partners and funders with no logo in the marks set ride the marquee as
  // text chips alongside the images.
  const partnerNames = [
    "Embraer",
    en ? "Energy Research Company" : "Empresa de Pesquisa Energética",
    "OLX",
    "National Instruments",
    "Samsung",
    "Murabei",
    "CAPES",
  ];

  const partnersBlock = section({
    id: "parceiros",
    labelledBy: "home-partners",
    inner: `<div class="lps-page-grid">
${sectionHead({
  kicker: en ? "Partners and funders" : "Parceiros e financiadores",
  title: en
    ? "Industry, agencies and international collaborations"
    : "Indústria, agências e colaborações internacionais",
  lead: en
    ? "Companies, funding agencies and the international collaborations that support laboratory projects."
    : "Empresas, agências de fomento e as colaborações internacionais que sustentam os projetos do laboratório.",
  id: "home-partners",
  action: {
    href: link(locale, "/infraestrutura/#parcerias", "/en/infrastructure/#parcerias"),
    label: `${u.seeAll} →`,
  },
})}
<div class="lps-partner-marquee">
<ul class="lps-partner-track lps-partner-track--roomy">
${partnerLogos
  .map(
    (logo) =>
      `<li><img src="/assets/img/partners/${logo.file}" alt="${esc(logo.name)}" loading="lazy" decoding="async" /></li>`,
  )
  .join("\n")}
${partnerNames.map((partner) => `<li class="lps-partner-chip">${esc(partner)}</li>`).join("\n")}
${[...partnerLogos, ...partnerNames]
  .map((item) =>
    typeof item === "string"
      ? `<li class="lps-partner-chip" aria-hidden="true">${esc(item)}</li>`
      : `<li aria-hidden="true"><img src="/assets/img/partners/${item.file}" alt="" loading="lazy" decoding="async" /></li>`,
  )
  .join("\n")}
</ul>
</div>
</div>`,
  });

  const cta = `<div class="lps-page-grid">${ctaBand({
    title: en
      ? "Bring a problem in signal processing or machine learning"
      : "Traga um problema de processamento de sinais ou aprendizado de máquina",
    body: en
      ? "The laboratory carries out contract research, R&D projects and talent development with industry and public bodies."
      : "O laboratório realiza pesquisa contratada, projetos de P&D e formação de pessoal com a indústria e órgãos públicos.",
    actions: [
      {
        href: link(locale, "/contato/", "/en/contact/"),
        label: en ? "Talk to the laboratory" : "Fale com o laboratório",
      },
      {
        href: link(locale, "/infraestrutura/", "/en/infrastructure/"),
        label: en ? "Capabilities" : "Capacidades",
      },
    ],
  })}</div>`;

  return {
    title: en
      ? "LPS — Signal Processing Laboratory, UFRJ/COPPE"
      : "LPS — Laboratório de Processamento de Sinais, UFRJ/COPPE",
    description: t(hero.lead, locale).slice(0, 155),
    body: `${heroBlock}${statBand(locale, hero.stats)}${journeyBlock}${researchBlock}${projectsBlock}${teachingBlock}${peopleBlock}${newsBlock}${partnersBlock}<section class="lps-section">${cta}</section>`,
  };
}

/* ---------------------------------------------------------------------------
 * About
 * ------------------------------------------------------------------------ */

function aboutPage(locale) {
  const en = locale === "en";
  const body = `${pageHeader({
    kicker: en ? "About" : "Sobre",
    title: en ? "About LPS" : "Sobre o LPS",
    lead: en
      ? "Founded in 1996 at the Federal University of Rio de Janeiro, the Signal Processing Laboratory works in teaching, research and extension, from junior research initiation to post-doctorate."
      : "Fundado em 1996 na Universidade Federal do Rio de Janeiro, o Laboratório de Processamento de Sinais atua em ensino, pesquisa e extensão, da iniciação científica júnior ao pós-doutorado.",
    meta: `${en ? "Founded" : "Fundação"} ${site.founded} · ${t(site.affiliation, locale)}`,
  })}
<div class="lps-page-grid">
<div class="lps-with-aside lps-with-aside--single">
<div class="lps-flow">
<section class="lps-section lps-section--flush" id="historia" aria-labelledby="about-history">
${sectionHead({ kicker: en ? "History" : "História", title: en ? "A timeline of the laboratory" : "Linha do tempo do laboratório", id: "about-history" })}
${timeline(locale, about.history)}
</section>
<section class="lps-section" id="missao" aria-labelledby="about-mission">
${sectionHead({ kicker: en ? "Purpose" : "Propósito", title: en ? "Mission and vision" : "Missão e visão", id: "about-mission" })}
<div class="lps-grid lps-grid--2">
${card({ title: en ? "Mission" : "Missão", body: t(about.mission, locale), accent: true })}
${card({ title: en ? "Vision" : "Visão", body: t(about.vision, locale) })}
</div>
</section>
<section class="lps-section" aria-labelledby="about-values">
${sectionHead({ kicker: en ? "Values" : "Valores", title: en ? "What guides the work" : "O que orienta o trabalho", id: "about-values" })}
<div class="lps-grid lps-grid--4">
${about.values.map((value) => card({ title: t(value.title, locale), body: t(value.body, locale) })).join("")}
</div>
</section>
<section class="lps-section" id="identidade" aria-labelledby="about-identity">
${sectionHead({ kicker: en ? "Identity" : "Identidade", title: en ? "The laboratory mark" : "A marca do laboratório", id: "about-identity" })}
<div class="lps-grid lps-grid--2">
<div class="lps-flow">${t(about.identity, locale)
    .map((paragraph) => `<p class="lps-reading">${esc(paragraph)}</p>`)
    .join("")}
<p><a class="lps-more" href="${link(locale, "/identidade-visual/", "/en/visual-identity/")}">${esc(en ? "Mark applications and files" : "Aplicações e arquivos da marca")}</a></p>
</div>
<div class="lps-card lps-card--flush"><div class="lps-card-media" style="min-block-size:14rem"></div></div>
</div>
</section>
<section class="lps-section" id="parceiros" aria-labelledby="about-partners">
${sectionHead({ kicker: en ? "Partners" : "Parceiros", title: en ? "Institutions working with the laboratory" : "Instituições que atuam com o laboratório", id: "about-partners" })}
<div class="lps-partner-marquee">
<ul class="lps-partner-track">
${partnerLogos
  .map(
    (logo) =>
      `<li><img src="/assets/img/partners/${logo.file}" alt="${esc(logo.name)}" loading="lazy" decoding="async" /></li>`,
  )
  .join("")}
${partnerLogos
  .map(
    (logo) =>
      `<li aria-hidden="true"><img src="/assets/img/partners/${logo.file}" alt="" loading="lazy" decoding="async" /></li>`,
  )
  .join("")}
</ul>
</div>
</section>
<section class="lps-section" id="localizacao" aria-labelledby="about-location">
${sectionHead({ kicker: en ? "Location" : "Localização", title: en ? "Where the laboratory is" : "Onde o laboratório está", id: "about-location" })}
<div class="lps-location">
<div>
${facts([
  {
    term: en ? "Address" : "Endereço",
    description: `${site.address.line1} — ${site.address.line2} — ${site.address.line3} — ${site.address.city}`,
  },
  {
    term: en ? "Phone" : "Telefone",
    description: `${site.phone.label} — ${t(site.phone.note, locale)}`,
  },
  { term: en ? "Administrative office" : "Secretaria", description: site.emails.office },
])}
<p><a class="lps-more" href="https://www.google.com/maps/search/?api=1&amp;query=${encodeURIComponent(site.address.full)}" target="_blank" rel="noopener">${esc(en ? "Open in Google Maps" : "Abrir no Google Maps")}</a></p>
</div>
<div class="lps-map-card">
<iframe
  title="${esc(en ? "Map showing the laboratory at the UFRJ Technology Centre" : "Mapa do laboratório no Centro de Tecnologia da UFRJ")}"
  src="https://maps.google.com/maps?hl=${en ? "en-US" : "pt-BR"}&amp;ll=-22.862347,-43.229037&amp;output=embed&amp;q=-22.861981,-43.228811&amp;z=17"
  loading="lazy"
  referrerpolicy="no-referrer-when-downgrade"
  allowfullscreen
></iframe>
</div>
</div>
</section>
</div>
</div>
</div>`;

  return {
    title: en ? "About LPS — LPS/UFRJ" : "Sobre o LPS — LPS/UFRJ",
    description: en
      ? "History, mission, vision, values and location of the Signal Processing Laboratory at UFRJ/COPPE."
      : "História, missão, visão, valores e localização do Laboratório de Processamento de Sinais da UFRJ/COPPE.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Research
 * ------------------------------------------------------------------------ */

function researchPage(locale) {
  const en = locale === "en";
  const body = `${pageHeader({
    kicker: t(research.kicker, locale),
    title: t(research.title, locale),
    lead: t(research.lead, locale),
  })}
<div class="lps-page-grid">
<div class="lps-with-aside">
<div class="lps-flow">
<section class="lps-section lps-section--flush" id="linhas" aria-labelledby="research-areas">
${sectionHead({ kicker: en ? "Areas" : "Áreas", title: en ? "Six research fronts" : "Seis frentes de pesquisa", id: "research-areas" })}
<div class="lps-grid lps-grid--2">
${research.areas
  .map((area) =>
    card({
      title: t(area.title, locale),
      body: t(area.body, locale),
      tags: t(area.topics, locale),
      accent: true,
    }),
  )
  .join("")}
</div>
</section>
<section class="lps-section" id="projetos" aria-labelledby="research-projects">
${sectionHead({ kicker: en ? "Projects" : "Projetos", title: en ? "Selected projects" : "Projetos selecionados", id: "research-projects", action: { href: link(locale, "/projetos/", "/en/projects/"), label: en ? "All projects" : "Todos os projetos" } })}
<div class="lps-grid lps-grid--3">
${projects
  .slice(0, 3)
  .map((project) =>
    card({
      title: t(project.title, locale),
      body: t(project.summary, locale),
      tags: t(project.tags, locale),
      meta: project.period,
    }),
  )
  .join("")}
</div>
</section>
<section class="lps-section" id="evidencias" aria-labelledby="research-outputs">
${sectionHead({ kicker: en ? "Outputs" : "Produção", title: en ? "Evidence of the work" : "Evidências do trabalho", id: "research-outputs" })}
${alert({
  tone: "info",
  title: en
    ? "No authoritative publications feed exists yet"
    : "Ainda não existe um feed público consolidado de publicações",
  body: t(publications.note, locale),
})}
<p class="lps-mt-6"><a class="lps-more" href="${link(locale, "/publicacoes/", "/en/publications/")}">${esc(en ? "How to consult the output" : "Como consultar a produção")}</a></p>
</section>
<section class="lps-section" id="infraestrutura" aria-labelledby="research-infrastructure">
${sectionHead({ kicker: en ? "Infrastructure" : "Infraestrutura", title: en ? "Capabilities behind the research" : "Capacidades por trás da pesquisa", id: "research-infrastructure", action: { href: link(locale, "/infraestrutura/", "/en/infrastructure/"), label: en ? "Facilities" : "Instalações" } })}
<div class="lps-grid lps-grid--3">
${[
  {
    value: "310 m²",
    label: { "pt-BR": "Área do laboratório no Bloco H", en: "Laboratory area in Building H" },
  },
  {
    value: "≈40",
    label: { "pt-BR": "Postos de trabalho no domínio LPS", en: "Workstations in the LPS domain" },
  },
  {
    value: "24/7",
    label: { "pt-BR": "Operação da colaboração ATLAS", en: "ATLAS collaboration operation" },
  },
]
  .map(
    (stat) =>
      `<div class="lps-card"><strong class="lps-stat" style="display:grid;gap:0"><strong style="color:var(--c-blue-600);font-size:2rem;line-height:1">${esc(stat.value)}</strong><span class="lps-summary">${esc(t(stat.label, locale))}</span></strong></div>`,
  )
  .join("")}
</div>
</section>
<section class="lps-section" id="contato" aria-labelledby="research-contact">
${sectionHead({ kicker: en ? "Collaboration" : "Colaboração", title: en ? "Research partnerships" : "Parcerias de pesquisa", id: "research-contact" })}
<div class="lps-grid lps-grid--2">
${card({ title: en ? "Contract research" : "Pesquisa contratada", body: en ? "Projects with industry and public bodies, with formal instruments through the university foundation." : "Projetos com a indústria e órgãos públicos, com instrumentos formais pela fundação da universidade." })}
${card({ title: en ? "International collaboration" : "Colaboração internacional", body: en ? "Joint work with research groups abroad, including the ATLAS experiment at CERN." : "Trabalho conjunto com grupos de pesquisa no exterior, incluindo o experimento ATLAS do CERN." })}
</div>
<div class="lps-mt-8">${ctaBand({
    title: en ? "Start a research conversation" : "Comece uma conversa de pesquisa",
    body: en
      ? "Describe the problem and the laboratory will point to the group that can work on it."
      : "Descreva o problema e o laboratório indicará o grupo que pode trabalhar nele.",
    actions: [
      { href: link(locale, "/contato/", "/en/contact/"), label: en ? "Contact" : "Contato" },
    ],
  })}</div>
</section>
</div>
${aside(locale, [
  { id: "linhas", label: { "pt-BR": "Linhas de pesquisa", en: "Research areas" } },
  { id: "projetos", label: { "pt-BR": "Projetos", en: "Projects" } },
  { id: "evidencias", label: { "pt-BR": "Produção", en: "Outputs" } },
  { id: "infraestrutura", label: { "pt-BR": "Infraestrutura", en: "Infrastructure" } },
  { id: "contato", label: { "pt-BR": "Contato", en: "Contact" } },
])}
</div>
</div>
</div>`;

  return {
    title: en ? "Research — LPS/UFRJ" : "Pesquisa — LPS/UFRJ",
    description: t(research.lead, locale).slice(0, 155),
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Projects
 * ------------------------------------------------------------------------ */

function projectsPage(locale) {
  const en = locale === "en";
  const body = `${pageHeader({
    kicker: en ? "Research" : "Pesquisa",
    title: en ? "Projects" : "Projetos",
    lead: en
      ? "Research and development projects recorded in the laboratory public material, with the partner institutions they were built with."
      : "Projetos de pesquisa e desenvolvimento registrados no material público do laboratório, com as instituições parceiras com que foram construídos.",
    meta: en
      ? "Source: the laboratory public pages, January 2025."
      : "Fonte: páginas públicas do laboratório, janeiro de 2025.",
  })}
<div class="lps-page-grid">
<div class="lps-section lps-section--flush">
${alert({
  tone: "info",
  title: en ? "What this list is, and what it is not" : "O que esta lista é, e o que ela não é",
  body: en
    ? "The public project index names two initiatives (HEP event simulation and the ATLAS online filtering system) and the faculty pages describe the applied projects below. A complete, current portfolio requires laboratory review."
    : "O índice público de projetos nomeia duas iniciativas (simulação de eventos em HEP e o sistema de filtragem online do ATLAS) e as páginas dos professores descrevem os projetos aplicados abaixo. Um portfólio completo e atual exige revisão do laboratório.",
})}
<div class="lps-grid lps-grid--2 lps-mt-8">
${projects
  .map((project) =>
    card({
      title: t(project.title, locale),
      body: t(project.summary, locale),
      tags: t(project.tags, locale),
      media: true,
      meta: `${project.period} · ${t(project.partners, locale).join(", ")}`,
      foot: `<a class="lps-meta" href="${project.source}" rel="external">${esc(en ? "Public source" : "Fonte pública")}</a>`,
    }),
  )
  .join("")}
</div>
<div class="lps-mt-10">${ctaBand({
    title: en ? "Propose a project" : "Proponha um projeto",
    body: en
      ? "The laboratory works with companies and public bodies on applied signal processing and machine learning problems."
      : "O laboratório atua com empresas e órgãos públicos em problemas aplicados de processamento de sinais e aprendizado de máquina.",
    actions: [
      {
        href: link(locale, "/infraestrutura/#parcerias", "/en/infrastructure/#parcerias"),
        label: en ? "Capabilities" : "Capacidades",
      },
      { href: link(locale, "/contato/", "/en/contact/"), label: en ? "Contact" : "Contato" },
    ],
  })}</div>
</div>
</div>`;

  return {
    title: en ? "Projects — LPS/UFRJ" : "Projetos — LPS/UFRJ",
    description: en
      ? "Research and development projects at the Signal Processing Laboratory, UFRJ/COPPE."
      : "Projetos de pesquisa e desenvolvimento do Laboratório de Processamento de Sinais, UFRJ/COPPE.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * People
 * ------------------------------------------------------------------------ */

function peoplePage(locale) {
  const en = locale === "en";
  const body = `${pageHeader({
    kicker: en ? "People" : "Pessoas",
    title: en ? "The laboratory team" : "A equipe do laboratório",
    lead: en
      ? "Four full-time professors — two of them full professors — coordinate the laboratory together with post-doctoral researchers and graduate and undergraduate students."
      : "Quatro professores em tempo integral — dois deles titulares — coordenam o laboratório junto com pesquisadores de pós-doutorado e estudantes de pós-graduação e graduação.",
  })}
<div class="lps-page-grid">
<section class="lps-section lps-section--flush" aria-labelledby="people-faculty">
${sectionHead({ kicker: en ? "Faculty" : "Corpo docente", title: en ? "Professors" : "Professores", id: "people-faculty" })}
<div class="lps-people-grid">
${people.map((person) => personCard(locale, person)).join("")}
</div>
</section>
<section class="lps-section" id="equipe" aria-labelledby="people-team">
${sectionHead({ kicker: en ? "Team" : "Equipe", title: en ? "Researchers and students" : "Pesquisadores e estudantes", id: "people-team" })}
<div class="lps-grid lps-grid--2">
${card({
  title: en ? "Post-doctoral researchers" : "Pesquisadores de pós-doutorado",
  body: en
    ? "The laboratory hosts post-doctoral researchers who take part in the ATLAS, sonar and industry projects, usually with funding from research agencies."
    : "O laboratório recebe pesquisadores de pós-doutorado que participam dos projetos do ATLAS, de sonar e dos projetos com a indústria, normalmente com financiamento de agências de fomento.",
})}
${card({
  title: en ? "Graduate and undergraduate students" : "Estudantes de pós-graduação e graduação",
  body: en
    ? "Master and doctoral students at PEE/COPPE and undergraduate students at Poli/UFRJ develop their research inside the laboratory."
    : "Estudantes de mestrado e doutorado do PEE/COPPE e estudantes de graduação da Poli/UFRJ desenvolvem sua pesquisa dentro do laboratório.",
})}
</div>
${alert({
  tone: "warning",
  title: en ? "The full team list is pending review" : "A lista completa da equipe aguarda revisão",
  body: en
    ? "The public source does not publish a current list of post-doctoral researchers and students, and personal data cannot be published without a documented legal basis and each person's agreement."
    : "A fonte pública não publica uma lista atual de pesquisadores de pós-doutorado e estudantes, e dados pessoais não podem ser publicados sem base legal documentada e concordância de cada pessoa.",
}).replace(
  '<div class="lps-alert lps-alert-warning">',
  '<div class="lps-alert lps-alert-warning lps-mt-8">',
)}
</section>
<section class="lps-section" aria-labelledby="people-join">
${sectionHead({ kicker: en ? "Take part" : "Participe", title: en ? "Work with the laboratory" : "Trabalhe com o laboratório", id: "people-join" })}
<div class="lps-grid lps-grid--3">
${opportunities.tracks
  .slice(0, 3)
  .map((track) => card({ title: t(track.title, locale), body: t(track.body, locale) }))
  .join("")}
</div>
<p class="lps-mt-6"><a class="lps-more" href="${link(locale, "/oportunidades/", "/en/opportunities/")}">${esc(en ? "Opportunities and how to apply" : "Oportunidades e como se candidatar")}</a></p>
</section>
<section class="lps-section">
${ctaBand({
  title: en
    ? "The professors' own pages carry their full CVs and course material"
    : "As páginas próprias dos professores reúnem currículos e material didático",
  body: en
    ? "Each professor maintains a public page with their research interests, courses and support material."
    : "Cada professor mantém uma página pública com seus interesses de pesquisa, disciplinas e material de apoio.",
  actions: [
    { href: site.external.github, label: "GitHub LPS" },
    { href: site.external.lattes, label: "Lattes CNPq" },
  ],
})}
</section>
</div>`;

  return {
    title: en ? "People — LPS/UFRJ" : "Pessoas — LPS/UFRJ",
    description: en
      ? "Professors and research team of the Signal Processing Laboratory, UFRJ/COPPE."
      : "Professores e equipe de pesquisa do Laboratório de Processamento de Sinais, UFRJ/COPPE.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Teaching
 * ------------------------------------------------------------------------ */

function teachingPage(locale) {
  const en = locale === "en";
  const body = `${pageHeader({
    kicker: en ? "Teaching" : "Ensino",
    title: en ? "Courses and materials" : "Disciplinas e materiais",
    lead: en
      ? "Courses taught by laboratory professors in the Electrical Engineering Program at COPPE and at the Polytechnic School of UFRJ, from instrumentation to deep learning and quantum machine learning."
      : "Disciplinas ministradas pelos professores do laboratório no Programa de Engenharia Elétrica da COPPE e na Escola Politécnica da UFRJ, da instrumentação ao aprendizado profundo e ao aprendizado de máquina quântico.",
  })}
<div class="lps-page-grid">
<section class="lps-section lps-section--flush" id="pos-graduacao" aria-labelledby="teaching-graduate">
${sectionHead({ kicker: en ? "COPPE · PEE" : "COPPE · PEE", title: en ? "Graduate courses" : "Disciplinas de pós-graduação", id: "teaching-graduate", action: { href: site.external.pee, label: "PEE/COPPE" } })}
${courseTable(locale, teaching.graduate, en ? "Graduate courses offered by the laboratory" : "Disciplinas de pós-graduação oferecidas pelo laboratório")}
</section>
<section class="lps-section" id="graduacao" aria-labelledby="teaching-undergraduate">
${sectionHead({ kicker: en ? "Poli/UFRJ" : "Poli/UFRJ", title: en ? "Undergraduate courses" : "Disciplinas de graduação", id: "teaching-undergraduate" })}
${courseTable(locale, teaching.undergraduate, en ? "Undergraduate courses taught by laboratory professors" : "Disciplinas de graduação ministradas por professores do laboratório")}
<p class="lps-meta lps-mt-4">${esc(en ? "Codes marked “—” are courses whose official code is not recorded in the public source." : "Os códigos marcados com “—” são disciplinas cujo código oficial não consta na fonte pública.")}</p>
</section>
<section class="lps-section" id="materiais" aria-labelledby="teaching-materials">
${sectionHead({ kicker: en ? "Materials" : "Materiais", title: en ? "Course material and support" : "Material didático e apoio", id: "teaching-materials" })}
<div class="lps-grid lps-grid--2">
${teaching.materials
  .map((material) =>
    card({
      title: t(material.title, locale),
      body: t(material.body, locale),
      action: material.link
        ? { href: material.link.href, label: t(material.link.label, locale) }
        : null,
    }),
  )
  .join("")}
</div>
${alert({
  tone: "warning",
  title: en
    ? "Course material lives outside this domain"
    : "O material didático está fora deste domínio",
  body: en
    ? "Syllabi, problem sets and support material are published on the professors' own pages. Migration of that material into the institutional site requires each author's rights review — it is linked, not copied."
    : "Planos de aula, listas e material de apoio são publicados nas páginas próprias dos professores. A migração desse material para o site institucional exige análise de direitos de cada autor — ele é referenciado, não copiado.",
}).replace(
  '<div class="lps-alert lps-alert-warning">',
  '<div class="lps-alert lps-alert-warning lps-mt-8">',
)}
</section>
<section class="lps-section">
${ctaBand({
  title: en ? "Studying at LPS" : "Estudar no LPS",
  body: en
    ? "Admission to master and doctoral studies runs through the selection process of the Electrical Engineering Program at COPPE/UFRJ."
    : "O ingresso em mestrado e doutorado se dá pelo processo seletivo do Programa de Engenharia Elétrica da COPPE/UFRJ.",
  actions: [
    {
      href: link(locale, "/oportunidades/", "/en/opportunities/"),
      label: en ? "Opportunities" : "Oportunidades",
    },
    { href: site.external.pee, label: "PEE/COPPE" },
  ],
})}
</section>
</div>`;

  return {
    title: en ? "Teaching — LPS/UFRJ" : "Ensino — LPS/UFRJ",
    description: en
      ? "Graduate and undergraduate courses taught by the Signal Processing Laboratory at UFRJ/COPPE."
      : "Disciplinas de pós-graduação e graduação ministradas pelo Laboratório de Processamento de Sinais da UFRJ/COPPE.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Infrastructure
 * ------------------------------------------------------------------------ */

function infrastructurePage(locale) {
  const en = locale === "en";
  const body = `${pageHeader({
    kicker: en ? "Infrastructure" : "Infraestrutura",
    title: en ? "Facilities and capabilities" : "Instalações e capacidades",
    lead: en
      ? "A 310 m² laboratory in Building H of the UFRJ Technology Centre, with an acoustic room, a lecture room and about 40 workstations in its own computing domain."
      : "Um laboratório de 310 m² no Bloco H do Centro de Tecnologia da UFRJ, com sala acústica, sala de palestras e cerca de 40 postos de trabalho em domínio computacional próprio.",
    meta: en
      ? "Source: COPPE EMBRAPII institutional profile."
      : "Fonte: perfil institucional COPPE EMBRAPII.",
  })}
<div class="lps-page-grid">
<section class="lps-section lps-section--flush" aria-labelledby="infra-facts">
${sectionHead({ kicker: en ? "Facilities" : "Instalações", title: en ? "What the laboratory has" : "O que o laboratório tem", id: "infra-facts" })}
${statBand(locale, infrastructure.facts).replace("lps-stat-band", "lps-stat-band lps-shadow-none")}
<div class="lps-grid lps-grid--2 lps-mt-10">
${card({ title: en ? "Capabilities" : "Capacidades", body: tl(infrastructure.capabilities, locale).join(" · ") })}
${card({
  title: en ? "Equipment" : "Equipamentos",
  body: en
    ? "State-of-the-art instrumentation equipment for system development and analysis, plus software for programmable device development."
    : "Equipamentos de instrumentação no estado da arte para desenvolvimento e análise de sistemas, além de software para desenvolvimento de dispositivos programáveis.",
})}
</div>
</section>
<section class="lps-section" id="parcerias" aria-labelledby="infra-partners">
${sectionHead({ kicker: en ? "Partnerships" : "Parcerias", title: en ? "Who the laboratory works with" : "Com quem o laboratório trabalha", id: "infra-partners", lead: en ? "Companies, funding agencies and international collaborations recorded in the public institutional profile." : "Empresas, agências de fomento e colaborações internacionais registradas no perfil institucional público." })}
<h3 class="lps-kicker">${esc(en ? "Companies" : "Empresas")}</h3>
<ul class="lps-logo-band">${infrastructure.partners.map((partner) => `<li>${esc(partner)}</li>`).join("")}</ul>
<h3 class="lps-kicker lps-mt-8">${esc(en ? "Funding agencies" : "Agências de fomento")}</h3>
<ul class="lps-logo-band">${infrastructure.funders.map((funder) => `<li>${esc(funder)}</li>`).join("")}</ul>
<h3 class="lps-kicker lps-mt-8">${esc(en ? "International collaboration" : "Colaboração internacional")}</h3>
<ul class="lps-logo-band"><li>CERN · ATLAS</li><li>${esc(en ? "Brazilian Navy · Navy Research Institute" : "Marinha do Brasil · Instituto de Pesquisas da Marinha")}</li></ul>
</section>
<section class="lps-section" aria-labelledby="infra-collaboration">
${sectionHead({ kicker: en ? "Collaborate" : "Colabore", title: en ? "Three ways to work with the laboratory" : "Três formas de trabalhar com o laboratório", id: "infra-collaboration" })}
<div class="lps-grid lps-grid--3">
${infrastructure.collaboration.map((item, index) => card({ title: t(item.title, locale), body: t(item.body, locale), numbered: String(index + 1).padStart(2, "0") })).join("")}
</div>
</section>
<section class="lps-section" aria-labelledby="infra-location">
${sectionHead({ kicker: en ? "Location" : "Localização", title: en ? "Visit the laboratory" : "Visite o laboratório", id: "infra-location" })}
<div class="lps-split">
<div>${facts([
    { term: en ? "Address" : "Endereço", description: site.address.line1 },
    {
      term: en ? "Building" : "Bloco",
      description: `${site.address.line2} — ${site.address.line3}`,
    },
    { term: en ? "Postcode" : "CEP", description: site.address.city },
    {
      term: en ? "Telephone" : "Telefone",
      description: `${site.phone.label} (${t(site.phone.note, locale)})`,
    },
    {
      term: en ? "Office" : "Secretaria",
      description: site.emails.office,
      html: `<a href="mailto:${site.emails.office}">${esc(site.emails.office)}</a>`,
    },
  ])}</div>
<div>${card({
    title: en ? "Technical visits and meetings" : "Visitas técnicas e reuniões",
    body: en
      ? "The laboratory has a lecture and meeting room and receives technical visits by appointment. Requests go through the laboratory office."
      : "O laboratório dispõe de sala de palestras e reuniões e recebe visitas técnicas com agendamento. Os pedidos são feitos pela secretaria do laboratório.",
    action: {
      href: link(locale, "/contato/", "/en/contact/"),
      label: en ? "Request a visit" : "Solicitar visita",
    },
  })}</div>
</div>
</section>
</div>`;

  return {
    title: en ? "Infrastructure — LPS/UFRJ" : "Infraestrutura — LPS/UFRJ",
    description: en
      ? "Facilities, capabilities and partnerships of the Signal Processing Laboratory, UFRJ/COPPE."
      : "Instalações, capacidades e parcerias do Laboratório de Processamento de Sinais, UFRJ/COPPE.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Opportunities
 * ------------------------------------------------------------------------ */

function opportunitiesPage(locale) {
  const en = locale === "en";
  const body = `${pageHeader({
    kicker: en ? "Take part" : "Participe",
    title: en ? "Opportunities" : "Oportunidades",
    lead: en
      ? "Research initiation, master and doctoral places, post-doctorate and project collaboration at the Signal Processing Laboratory."
      : "Iniciação científica, vagas de mestrado e doutorado, pós-doutorado e colaboração em projetos no Laboratório de Processamento de Sinais.",
  })}
<div class="lps-page-grid">
<section class="lps-section lps-section--flush" aria-labelledby="opp-status">
${sectionHead({ kicker: en ? "Current status" : "Situação atual", title: en ? "Open calls" : "Chamadas abertas", id: "opp-status" })}
${alert({ tone: "warning", title: en ? "No open call right now" : "Nenhuma chamada aberta no momento", body: t(opportunities.notice, locale) })}
</section>
<section class="lps-section" aria-labelledby="opp-tracks">
${sectionHead({ kicker: en ? "Tracks" : "Trilhas", title: en ? "Where the laboratory takes people in" : "Por onde o laboratório recebe pessoas", id: "opp-tracks" })}
<div class="lps-grid lps-grid--2">
${opportunities.tracks.map((track) => card({ title: t(track.title, locale), body: t(track.body, locale), accent: true })).join("")}
</div>
</section>
<section class="lps-section" aria-labelledby="opp-how">
${sectionHead({ kicker: en ? "How to apply" : "Como se candidatar", title: en ? "Three steps" : "Três passos", id: "opp-how" })}
<ol class="lps-timeline">
${t(opportunities.howto, locale)
  .map(
    (step, index) =>
      `<li><span class="lps-timeline-year">${String(index + 1).padStart(2, "0")}</span><div><p>${esc(step)}</p></div></li>`,
  )
  .join("")}
</ol>
<div class="lps-mt-10">${ctaBand({
    title: en ? "Talk to the laboratory office" : "Fale com a secretaria do laboratório",
    body: en
      ? "The office answers questions on availability, requirements and documents before you apply."
      : "A secretaria responde dúvidas sobre disponibilidade, requisitos e documentos antes da candidatura.",
    actions: [
      { href: `mailto:${site.emails.office}`, label: site.emails.office },
      {
        href: link(locale, "/contato/", "/en/contact/"),
        label: en ? "All contacts" : "Todos os contatos",
      },
    ],
  })}</div>
</section>
</div>`;

  return {
    title: en ? "Opportunities — LPS/UFRJ" : "Oportunidades — LPS/UFRJ",
    description: en
      ? "Research initiation, graduate places, post-doctorate and collaboration at the Signal Processing Laboratory."
      : "Iniciação científica, vagas de pós-graduação, pós-doutorado e colaboração no Laboratório de Processamento de Sinais.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * News and events
 * ------------------------------------------------------------------------ */

function newsPage(locale) {
  const en = locale === "en";
  const body = `${pageHeader({
    kicker: en ? "News and events" : "Notícias e eventos",
    title: en ? "News and events" : "Notícias e eventos",
    lead: en
      ? "Dated institutional records about the laboratory, each traceable to the public source it came from."
      : "Registros institucionais datados sobre o laboratório, cada um rastreável à fonte pública de origem.",
  })}
<div class="lps-page-grid">
<section class="lps-section lps-section--flush" aria-labelledby="news-list">
${sectionHead({ kicker: en ? "Records" : "Registros", title: en ? "Institutional timeline" : "Linha do tempo institucional", id: "news-list" })}
<ol class="lps-agenda">
${news
  .map(
    (item) => `<li>
<div class="lps-event-date"><strong>${esc(t(item.dateLabel, locale))}</strong><span>${esc(t(item.kicker, locale))}</span></div>
<div>
<h3>${esc(t(item.title, locale))}</h3>
<p>${esc(t(item.summary, locale))}</p>
<p class="lps-meta lps-mt-4">${esc(en ? "Source" : "Fonte")}: <a href="${item.source}" rel="external">${esc(new URL(item.source).hostname)}</a></p>
</div>
<span class="lps-meta">${esc(t(item.dateLabel, locale))}</span>
</li>`,
  )
  .join("")}
</ol>
</section>
<section class="lps-section" id="agenda" aria-labelledby="news-agenda">
${sectionHead({ kicker: en ? "Agenda" : "Agenda", title: en ? "Upcoming events" : "Próximos eventos", id: "news-agenda" })}
${
  events.length === 0
    ? `<div class="lps-empty-state">
<p class="lps-kicker">${esc(en ? "NO SCHEDULED EVENTS" : "SEM EVENTOS AGENDADOS")}</p>
<h2 id="news-agenda-empty">${esc(ui(locale).agendaEmptyTitle)}</h2>
<p>${esc(ui(locale).agendaEmptyBody)}</p>
</div>`
    : ""
}
</section>
<section class="lps-section" aria-labelledby="news-legacy">
${sectionHead({ kicker: en ? "Previous site" : "Site anterior", title: en ? "Where the older records live" : "Onde ficam os registros antigos", id: "news-legacy" })}
<div class="lps-grid lps-grid--2">
${card({
  title: en ? "The previous institutional site" : "Site institucional anterior",
  body: en
    ? "The laboratory site hosted on Google Sites remains the record for older material, including opportunities announcements and professor pages."
    : "O site do laboratório hospedado no Google Sites permanece como registro do material antigo, incluindo avisos de oportunidades e páginas de professores.",
  action: { href: site.external.legacy, label: en ? "Open previous site" : "Abrir site anterior" },
})}
${card({
  title: en ? "Lossless redirects are planned" : "Redirecionamentos sem perda estão planejados",
  body: en
    ? "Every legacy URL inventoried for migration keeps a recorded disposition, so no published address is silently dropped."
    : "Cada URL legada inventariada para migração mantém uma destinação registrada, de modo que nenhum endereço publicado é descartado em silêncio.",
})}
</div>
</section>
</div>`;

  return {
    title: en ? "News and events — LPS/UFRJ" : "Notícias e eventos — LPS/UFRJ",
    description: en
      ? "Dated institutional records and agenda of the Signal Processing Laboratory."
      : "Registros institucionais datados e agenda do Laboratório de Processamento de Sinais.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Publications
 * ------------------------------------------------------------------------ */

function publicationsPage(locale) {
  const en = locale === "en";
  const body = `${pageHeader({
    kicker: en ? "Research" : "Pesquisa",
    title: en ? "Publications" : "Publicações",
    lead: en
      ? "The scientific output associated with the laboratory, and how to consult it today."
      : "A produção científica associada ao laboratório, e como consultá-la hoje.",
  })}
<div class="lps-page-grid">
<section class="lps-section lps-section--flush" aria-labelledby="pub-note">
${sectionHead({ kicker: en ? "Status" : "Situação", title: en ? "No consolidated feed yet" : "Ainda sem feed consolidado", id: "pub-note" })}
${alert({ tone: "info", body: t(publications.note, locale) })}
</section>
<section class="lps-section" aria-labelledby="pub-channels">
${sectionHead({ kicker: en ? "Channels" : "Canais", title: en ? "Where the output can be consulted" : "Onde a produção pode ser consultada", id: "pub-channels" })}
<div class="lps-grid lps-grid--3">
${publications.channels
  .map((channel) =>
    card({
      title: t(channel.title, locale),
      body: t(channel.body, locale),
      foot: `<ul class="lps-source-list">${channel.links.map((item) => `<li><a class="lps-meta" href="${item.href}" rel="external">${esc(item.label)}</a></li>`).join("")}</ul>`,
    }),
  )
  .join("")}
</div>
</section>
<section class="lps-section">
${ctaBand({
  title: en
    ? "A publication feed requires institutional ownership"
    : "Um feed de publicações exige responsável institucional",
  body: en
    ? "Reconciling publication metadata against authoritative sources is migration work, not a design decision. Until then, the laboratory does not publish a list it cannot verify."
    : "Reconciliar metadados de publicações com fontes autoritativas é trabalho de migração, não uma decisão de design. Até lá, o laboratório não publica uma lista que não pode verificar.",
  actions: [
    {
      href: link(locale, "/contato/", "/en/contact/"),
      label: en ? "Contact the laboratory" : "Fale com o laboratório",
    },
  ],
})}
</section>
</div>`;

  return {
    title: en ? "Publications — LPS/UFRJ" : "Publicações — LPS/UFRJ",
    description: en
      ? "How to consult the scientific output associated with the Signal Processing Laboratory."
      : "Como consultar a produção científica associada ao Laboratório de Processamento de Sinais.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Contact
 * ------------------------------------------------------------------------ */

function contactPage(locale) {
  const en = locale === "en";
  const body = `${pageHeader({
    kicker: en ? "Contact" : "Contato",
    title: en ? "Contact the laboratory" : "Fale com o laboratório",
    lead: en
      ? "The laboratory office is the first stop for administrative matters, projects, technical visits and press requests."
      : "A secretaria do laboratório é o primeiro caminho para assuntos administrativos, projetos, visitas técnicas e pedidos de imprensa.",
  })}
<div class="lps-page-grid">
<section class="lps-section lps-section--flush" aria-labelledby="contact-channels">
${sectionHead({ kicker: en ? "Channels" : "Canais", title: en ? "Who to write to" : "Para quem escrever", id: "contact-channels" })}
<div class="lps-grid lps-grid--3">
${contact.channels
  .map((channel) =>
    card({
      title: t(channel.title, locale),
      body: t(channel.body, locale),
      foot: `<ul class="lps-source-list">${channel.items
        .map((item) => `<li><a class="lps-meta" href="${item.href}">${esc(item.label)}</a></li>`)
        .join("")}</ul>`,
    }),
  )
  .join("")}
</div>
</section>
<section class="lps-section" aria-labelledby="contact-address">
${sectionHead({ kicker: en ? "Addresses" : "Endereços", title: en ? "Where to find the laboratory" : "Onde encontrar o laboratório", id: "contact-address" })}
<div class="lps-grid lps-grid--2">
${contact.buildings
  .map((building) =>
    card({
      title: t(building.title, locale),
      body: building.address,
      action: { href: building.maps, label: en ? "Open in maps" : "Abrir no mapa" },
    }),
  )
  .join("")}
</div>
<p class="lps-meta lps-mt-6">${esc(`${site.phone.label} · ${t(site.phone.note, locale)} · `)}<a href="mailto:${site.emails.office}">${esc(site.emails.office)}</a></p>
</section>
<section class="lps-section" aria-labelledby="contact-pending">
${sectionHead({ kicker: en ? "Pending" : "Pendências", title: en ? "Contacts still to be named" : "Contatos ainda a nomear", id: "contact-pending" })}
<div class="lps-grid lps-grid--2">
${card({
  title: en ? "Accessibility reporting" : "Relato de acessibilidade",
  body: en
    ? "No formal accessibility reporting channel has been named. Until it is, the office receives accessibility reports and forwards them to the responsible team."
    : "Nenhum canal formal de relato de acessibilidade foi nomeado. Até que exista, a secretaria recebe os relatos e os encaminha à equipe responsável.",
})}
${card({
  title: en ? "Privacy and personal data" : "Privacidade e dados pessoais",
  body: en
    ? "No privacy contact and no documented legal basis for processing personal data exist yet. Both are launch blockers recorded in the migration material, not oversights hidden by this redesign."
    : "Ainda não existem contato de privacidade nem base legal documentada para tratamento de dados pessoais. Ambos são bloqueios de lançamento registrados no material de migração, não omissões escondidas por este redesenho.",
})}
</div>
</section>
</div>`;

  return {
    title: en ? "Contact — LPS/UFRJ" : "Contato — LPS/UFRJ",
    description: en
      ? "Channels and addresses of the Signal Processing Laboratory, UFRJ/COPPE."
      : "Canais e endereços do Laboratório de Processamento de Sinais, UFRJ/COPPE.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Accessibility
 * ------------------------------------------------------------------------ */

function accessibilityPage(locale) {
  const en = locale === "en";
  const checklist = [
    en
      ? "Text contrast of at least 4.5:1, verified by a computed audit rather than by eye."
      : "Contraste de texto de ao menos 4,5:1, verificado por auditoria computada e não a olho.",
    en
      ? "Visible focus on every interactive element, with a 3:1 focus indicator."
      : "Foco visível em todos os elementos interativos, com indicador de 3:1.",
    en
      ? "Full keyboard operation, including navigation, search and the mobile menu."
      : "Operação completa por teclado, incluindo navegação, busca e menu móvel.",
    en
      ? "Reduced-motion preference switches every transition off."
      : "A preferência de movimento reduzido desliga todas as transições.",
    en
      ? "Reflow at 320 CSS pixels and 200% zoom without horizontal scrolling."
      : "Refluxo em 320 CSS pixels e 200% de zoom sem rolagem horizontal.",
    en
      ? "One H1 per page, sequential headings, landmarks and a skip link."
      : "Um H1 por página, títulos sequenciais, marcos de navegação e link de salto.",
    en ? "44px minimum target size for controls." : "Tamanho mínimo de 44px para alvos de toque.",
    en
      ? "The site works without JavaScript: navigation and search are native form and disclosure elements."
      : "O site funciona sem JavaScript: navegação e busca são elementos nativos de formulário e disclosure.",
  ];
  const body = `${pageHeader({
    kicker: en ? "Accessibility" : "Acessibilidade",
    title: en ? "Accessibility statement" : "Declaração de acessibilidade",
    lead: t(accessibility.statement, locale),
  })}
<div class="lps-page-grid">
<section class="lps-section lps-section--flush" aria-labelledby="a11y-checklist">
${sectionHead({ kicker: en ? "Commitments" : "Compromissos", title: en ? "What this site guarantees" : "O que este site garante", id: "a11y-checklist" })}
<ul class="lps-record-list">
${checklist.map((item) => `<li>${esc(item)}</li>`).join("")}
</ul>
</section>
<section class="lps-section" aria-labelledby="a11y-report">
${sectionHead({ kicker: en ? "Reporting" : "Relato", title: en ? "Found a barrier?" : "Encontrou uma barreira?", id: "a11y-report" })}
${alert({ tone: "warning", body: `${t(accessibility.contact, locale)} ${site.emails.office}.` })}
<p class="lps-mt-6">${esc(en ? "Reports are answered by the laboratory office while a named channel is pending institutional decision." : "Os relatos são respondidos pela secretaria do laboratório enquanto o canal nomeado aguarda decisão institucional.")}</p>
</section>
<section class="lps-section">
${ctaBand({
  title: en
    ? "Accessibility is verified on every release"
    : "A acessibilidade é verificada a cada publicação",
  body: en
    ? "Contrast, keyboard operation, reflow and reduced motion are part of the release checks, together with link and schema validation."
    : "Contraste, operação por teclado, refluxo e movimento reduzido fazem parte das checagens de publicação, junto com validação de links e de esquema.",
  actions: [
    { href: link(locale, "/privacidade/", "/en/privacy/"), label: en ? "Privacy" : "Privacidade" },
  ],
})}
</section>
</div>`;

  return {
    title: en ? "Accessibility — LPS/UFRJ" : "Acessibilidade — LPS/UFRJ",
    description: en
      ? "Accessibility commitments and reporting for the LPS website."
      : "Compromissos de acessibilidade e canal de relato do site do LPS.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Privacy
 * ------------------------------------------------------------------------ */

function privacyPage(locale) {
  const en = locale === "en";
  const body = `${pageHeader({
    kicker: en ? "Privacy" : "Privacidade",
    title: en ? "Privacy on this site" : "Privacidade neste site",
    lead: en
      ? "This site is built to collect as little as possible: no cookies for anonymous visitors, no third-party requests and no public forms."
      : "Este site é construído para coletar o mínimo possível: nenhum cookie para visitantes anônimos, nenhuma requisição a terceiros e nenhum formulário público.",
  })}
<div class="lps-page-grid">
<section class="lps-section lps-section--flush" aria-labelledby="privacy-facts">
${sectionHead({ kicker: en ? "Technical behaviour" : "Comportamento técnico", title: en ? "Four facts" : "Quatro fatos", id: "privacy-facts" })}
<ul class="lps-record-list">
${tl(privacy.facts, locale)
  .map((fact) => `<li>${esc(fact)}</li>`)
  .join("")}
</ul>
</section>
<section class="lps-section" aria-labelledby="privacy-note">
${sectionHead({ kicker: en ? "Scope" : "Escopo", title: en ? "What this page covers" : "O que esta página cobre", id: "privacy-note" })}
${alert({ tone: "info", body: t(privacy.note, locale) })}
</section>
</div>`;

  return {
    title: en ? "Privacy — LPS/UFRJ" : "Privacidade — LPS/UFRJ",
    description: en
      ? "Data practices of the LPS website: no cookies, no tracking, no third-party requests."
      : "Práticas de dados do site do LPS: sem cookies, sem rastreamento, sem requisições a terceiros.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Visual identity
 * ------------------------------------------------------------------------ */

function identityPage(locale) {
  const en = locale === "en";
  const applications = [
    {
      src: "/assets/img/mark/lps-mark-full.svg",
      title: en ? "Full mark, colour" : "Marca completa, colorida",
      body: en
        ? "Light surfaces only. Minimum 240px wide in the masthead."
        : "Somente sobre superfícies claras. Mínimo de 240px de largura no cabeçalho.",
      surface: "light",
    },
    {
      src: "/assets/img/mark/lps-mark-compact.svg",
      title: en ? "Compact mark, colour" : "Marca compacta, colorida",
      body: en
        ? "Constrained places: mobile masthead, cards, e-mail signatures."
        : "Locais restritos: cabeçalho móvel, cartões, assinaturas de e-mail.",
      surface: "light",
    },
    {
      src: "/assets/img/mark/lps-mark-reversed.svg",
      title: en ? "Full mark, reversed" : "Marca completa, reversa",
      body: en
        ? "Dark navy surfaces: footer, covers, presentation closing slides."
        : "Superfícies azul-escuras: rodapé, capas, slides de encerramento.",
      surface: "dark",
    },
    {
      src: "/assets/img/mark/lps-mark-mono-symbol.svg",
      title: en ? "Signal only, mono" : "Somente o sinal, monocromático",
      body: en
        ? "Decorative or ruled contexts where colour cannot be printed."
        : "Contextos decorativos ou impressos sem cor.",
      surface: "light",
      w: 1040,
      h: 520,
    },
    {
      src: "/assets/img/mark/lps-coppe-blue.svg",
      title: en ? "COPPE/Poli/UFRJ lockup" : "Marca conjunta COPPE/Poli/UFRJ",
      body: en
        ? "Official institutional artwork pairing the LPS mark with COPPE, Poli and UFRJ lettering."
        : "Arte institucional oficial que une a marca LPS ao letreiro COPPE, Poli e UFRJ.",
      surface: "light",
      w: 2047,
      h: 1448,
    },
  ];

  const body = `${pageHeader({
    kicker: en ? "Identity" : "Identidade",
    title: en ? "Visual identity" : "Identidade visual",
    lead: en
      ? "The laboratory mark pairs a signal with the LPS lettering, backed by the full name and the Computational Intelligence descriptor."
      : "A marca do laboratório une um sinal à sigla LPS, acompanhados do nome por extenso e do descritor Inteligência Computacional.",
    meta: en
      ? "Source artwork supplied by the laboratory; vectorised with the lettering outlined."
      : "Arte-fonte fornecida pelo laboratório; vetorizada com as letras em curvas.",
  })}
<div class="lps-page-grid">
<section class="lps-section lps-section--flush" aria-labelledby="identity-meaning">
${sectionHead({ kicker: en ? "Meaning" : "Significado", title: en ? "Why the mark looks like this" : "Por que a marca é assim", id: "identity-meaning" })}
<div class="lps-flow lps-reading">
${t(about.identity, locale)
  .map((paragraph) => `<p>${esc(paragraph)}</p>`)
  .join("")}
</div>
</section>
<section class="lps-section" aria-labelledby="identity-applications">
${sectionHead({ kicker: en ? "Applications" : "Aplicações", title: en ? "Approved variants" : "Variantes aprovadas", id: "identity-applications" })}
<div class="lps-grid lps-grid--2">
${applications
  .map(
    (item) => `<article class="lps-card lps-card--flush">
<div class="lps-card-media lps-card-media--plain" style="min-block-size:11rem;background:${item.surface === "dark" ? "var(--c-navy-900)" : "var(--c-surface)"}" aria-hidden="true">
<img src="${item.src}" alt="" width="${item.w ?? 2052}" height="${item.h ?? 301}" loading="lazy" decoding="async" style="object-fit:contain;padding:1.5rem;background:transparent">
</div>
<div class="lps-card-body" style="padding:var(--space-6)">
<h3 class="lps-card-title">${esc(item.title)}</h3>
<p>${esc(item.body)}</p>
</div>
</article>`,
  )
  .join("")}
</div>
</section>
<section class="lps-section" aria-labelledby="identity-rules">
${sectionHead({ kicker: en ? "Usage" : "Uso", title: en ? "Rules that keep the mark legible" : "Regras que mantêm a marca legível", id: "identity-rules" })}
<div class="lps-split">
<div class="lps-flow">${[
    en
      ? "Clear space around the mark equals the height of the letter L in every direction."
      : "O espaço livre ao redor da marca equivale à altura da letra L em todas as direções.",
    en
      ? "Never recolour, stretch, rotate or add effects to the mark; the waveform gradient is part of the artwork."
      : "Nunca recolorir, esticar, rotacionar ou aplicar efeitos à marca; o degradê da forma de onda faz parte da arte.",
    en
      ? "On dark navy surfaces use the reversed variant; on light surfaces use the colour variant."
      : "Sobre superfícies azul-escuras use a variante reversa; sobre superfícies claras use a variante colorida.",
    en
      ? "The mark never replaces the institutional marks of UFRJ or COPPE, which stay text-only beside it."
      : "A marca nunca substitui as marcas institucionais da UFRJ ou da COPPE, que permanecem em texto ao lado dela.",
  ]
    .map((rule) => `<p class="lps-reading">${esc(rule)}</p>`)
    .join("")}</div>
<div>${card({
    title: en ? "Brand package" : "Pacote da marca",
    body: en
      ? "The laboratory keeps the official files — blue and white signal, blue and white full mark, and the complete brand package — in its own drive, linked from the previous site."
      : "O laboratório mantém os arquivos oficiais — sinal azul e branco, marca completa azul e branca, e o pacote completo da marca — em seu próprio drive, com link no site anterior.",
    action: {
      href: "https://sites.google.com/lps.ufrj.br/lps/sobre/identidade-visual",
      label: en ? "Official files" : "Arquivos oficiais",
    },
  })}</div>
</div>
</section>
</div>`;

  return {
    title: en ? "Visual identity — LPS/UFRJ" : "Identidade visual — LPS/UFRJ",
    description: en
      ? "The LPS mark, its variants and usage rules."
      : "A marca do LPS, suas variantes e regras de uso.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Search
 * ------------------------------------------------------------------------ */

function searchPage(locale) {
  const en = locale === "en";
  const sample = [
    {
      kind: en ? "Research area" : "Linha de pesquisa",
      title: t(research.areas[1].title, locale),
      body: t(research.areas[1].body, locale),
      href: link(locale, "/pesquisa/#linhas", "/en/research/#linhas"),
    },
    {
      kind: en ? "Project" : "Projeto",
      title: t(projects[0].title, locale),
      body: t(projects[0].summary, locale),
      href: link(locale, "/projetos/", "/en/projects/"),
    },
    {
      kind: en ? "Person" : "Pessoa",
      title: people[3].name,
      body: t(people[3].affiliation, locale),
      href: `${link(locale, "/pessoas/", "/en/people/")}#${people[3].slug}`,
    },
    {
      kind: en ? "Course" : "Disciplina",
      title: `${teaching.graduate[3].code} — ${t(teaching.graduate[3].title, locale)}`,
      body: `${teaching.graduate[3].professor} · ${t(teaching.graduate[3].level, locale)}`,
      href: link(locale, "/ensino/#pos-graduacao", "/en/teaching/#pos-graduacao"),
    },
  ];

  const body = `${pageHeader({
    kicker: en ? "Search" : "Busca",
    title: en ? "Search the site" : "Buscar no site",
    lead: en
      ? "Search runs on the server and works without JavaScript. Facets narrow the result set by collection, area and year."
      : "A busca roda no servidor e funciona sem JavaScript. Os filtros restringem o resultado por coleção, área e ano.",
  })}
<div class="lps-page-grid">
<section class="lps-section lps-section--flush">
<div class="lps-search-surface">
<form class="lps-search-form" role="search" action="${en ? "/en/search/" : "/busca/"}" method="get">
<label for="search-query">${esc(en ? "What are you looking for?" : "O que você procura?")}</label>
<div class="lps-search-row"><input id="search-query" name="q" type="search" autocomplete="off"><button class="lps-button lps-button-primary" type="submit">${esc(en ? "Search" : "Buscar")}</button></div>
<p class="lps-search-hint">${esc(en ? "Try a research area, a professor name, a course code or a partner." : "Tente uma linha de pesquisa, um nome de professor, um código de disciplina ou um parceiro.")}</p>
<div class="lps-grid lps-grid--3">
${[
  {
    name: "collection",
    title: en ? "Collection" : "Coleção",
    values: en
      ? ["Research area", "Project", "Person", "Course"]
      : ["Linha de pesquisa", "Projeto", "Pessoa", "Disciplina"],
  },
  {
    name: "area",
    title: en ? "Area" : "Área",
    values: en
      ? ["Computational intelligence", "Signal processing", "Sonar", "HEP"]
      : ["Inteligência computacional", "Processamento de sinais", "Sonar", "HEP"],
  },
  {
    name: "period",
    title: en ? "Period" : "Período",
    values: ["2020 —", "2015 — 2019", "2010 — 2014", en ? "before 2010" : "antes de 2010"],
  },
]
  .map(
    (facet) =>
      `<fieldset class="lps-search-facet"><legend>${esc(facet.title)}</legend><ul class="lps-search-facet-values">${facet.values
        .map(
          (value) =>
            `<li class="lps-search-facet-value"><input type="checkbox" name="${facet.name}" value="${esc(value)}" id="facet-${facet.name}-${esc(value)}"><label for="facet-${facet.name}-${esc(value)}">${esc(value)}</label></li>`,
        )
        .join("")}</ul></fieldset>`,
  )
  .join("")}
</div>
</form>
</div>
<h2 id="search-results" class="lps-mt-8">${esc(en ? "Results" : "Resultados")}</h2>
<p class="lps-result-count">${esc(en ? "4 results for the current query (sample data)." : "4 resultados para a consulta atual (dados de exemplo).")}</p>
<ol class="lps-search-results lps-mt-4" aria-labelledby="search-results">
${sample
  .map(
    (result) => `<li class="lps-search-result">
<p class="lps-search-kind">${esc(result.kind)}</p>
<h3><a href="${result.href}">${esc(result.title)}</a></h3>
<p class="lps-summary">${esc(result.body)}</p>
</li>`,
  )
  .join("")}
</ol>
<div class="lps-search-pagination"><span class="lps-search-step" aria-disabled="true">${esc(en ? "Previous" : "Anterior")}</span><span class="lps-meta">1 / 1</span><span class="lps-search-step" aria-disabled="true">${esc(en ? "Next" : "Próxima")}</span></div>
</section>
<section class="lps-section">
${ctaBand({
  title: en ? "Nothing matched?" : "Não encontrou?",
  body: en
    ? "The laboratory office can point you to the right record or person."
    : "A secretaria do laboratório pode indicar o registro ou a pessoa certa.",
  actions: [{ href: link(locale, "/contato/", "/en/contact/"), label: en ? "Contact" : "Contato" }],
})}
</section>
</div>`;

  return {
    title: en ? "Search — LPS/UFRJ" : "Busca — LPS/UFRJ",
    description: en
      ? "Search the LPS institutional site by collection, area and period."
      : "Busque no site institucional do LPS por coleção, área e período.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Route table
 * ------------------------------------------------------------------------ */

/* ---------------------------------------------------------------------------
 * Member surfaces — sign-in and the professor's own area
 * ------------------------------------------------------------------------ */

/**
 * The sign-in page. The published site posts these fields to the theme's own
 * route (`Shell::signin_path`); in this preview the form is inert and says so,
 * because a static export cannot authenticate anybody.
 */
function signInPage(locale) {
  const en = locale === "en";
  const copy = member.signIn;
  const body = `${pageHeader({
    kicker: t(copy.kicker, locale),
    title: t(copy.title, locale),
    lead: t(copy.lead, locale),
  })}
<div class="lps-page-grid">
<section class="lps-section lps-section--flush" aria-labelledby="signin-form">
${sectionHead({ kicker: en ? "Credentials" : "Credenciais", title: t(copy.form.legend, locale), id: "signin-form" })}
<div class="lps-split">
<form class="lps-dashboard-form lps-signin-form" id="signin" method="post" action="${en ? "/en/sign-in/" : "/entrar/"}">
<fieldset class="lps-fieldset">
<legend>${esc(t(copy.form.legend, locale))}</legend>
<p class="lps-field">
<label for="signin-user">${esc(t(copy.form.user, locale))}</label>
<input id="signin-user" name="log" type="text" autocomplete="username" required>
</p>
<p class="lps-field">
<label for="signin-pass">${esc(t(copy.form.password, locale))}</label>
<input id="signin-pass" name="pwd" type="password" autocomplete="current-password" required>
</p>
<p class="lps-checkbox">
<input id="signin-remember" name="rememberme" type="checkbox" value="forever">
<label for="signin-remember">${esc(t(copy.form.remember, locale))}</label>
</p>
<p class="lps-field-hint">${esc(t(copy.form.hint, locale))}</p>
<p class="lps-button-row"><button class="lps-button lps-button-primary" type="submit">${esc(t(copy.form.submit, locale))}</button><a class="lps-button lps-button-ghost lps-sso-google" href="${en ? "/en/sign-in/" : "/entrar/"}" aria-label="${esc(en ? "Sign in with Google Workspace — @lps.ufrj.br accounts that already exist here" : "Entrar com Google Workspace — contas @lps.ufrj.br que já existam aqui")}"><svg class="lps-sso-icon" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z" fill="#4285F4"/><path d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z" fill="#34A853"/><path d="M3.97 10.71a5.4 5.4 0 0 1 0-3.42V4.96H.96a9 9 0 0 0 0 8.08l3.01-2.33z" fill="#FBBC05"/><path d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.59C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.96l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z" fill="#EA4335"/></svg><span>${esc(en ? "Sign in with Google" : "Entrar com Google")}</span></a><a class="lps-more" href="${en ? "/en/contact/" : "/contato/"}">${esc(t(copy.form.lost, locale))}</a></p>
</fieldset>
${alert({ tone: "info", body: t(copy.notice, locale) })}
</form>
<div class="lps-signin-side">
<h2 class="lps-kicker">${esc(t(copy.audience.title, locale))}</h2>
<ul class="lps-signin-audience">
${copy.audience.items
  .map(
    (item) =>
      `<li><strong>${esc(t(item.name, locale))}</strong><p>${esc(t(item.detail, locale))}</p></li>`,
  )
  .join("\n")}
</ul>
<h2 class="lps-kicker lps-mt-8">${esc(t(copy.help.title, locale))}</h2>
<ol class="lps-signin-help">
${copy.help.steps.map((step) => `<li>${esc(t(step, locale))}</li>`).join("\n")}
</ol>
</div>
</div>
</section>
<section class="lps-section" aria-labelledby="signin-states">
${sectionHead({ kicker: en ? "States" : "Estados", title: en ? "What the form answers" : "O que o formulário responde", id: "signin-states" })}
<div class="lps-grid lps-grid--3">
${card({
  title: t(copy.error.credentials, locale).split(".")[0],
  body: t(copy.error.credentials, locale),
  foot: `<p class="lps-meta">${esc(en ? "wrong credentials" : "credenciais incorretas")}</p>`,
})}
${card({
  title: t(copy.error.empty, locale).split(".")[0],
  body: t(copy.error.empty, locale),
  foot: `<p class="lps-meta">${esc(en ? "empty field" : "campo vazio")}</p>`,
})}
${card({
  title: t(copy.error.secondFactor, locale).split(".")[0],
  body: t(copy.error.secondFactor, locale),
  foot: `<p class="lps-meta">${esc(en ? "second factor" : "segundo fator")}</p>`,
})}
</div>
</section>
</div>`;

  return {
    title: en ? "Sign in — LPS/UFRJ" : "Entrar — LPS/UFRJ",
    description: en
      ? "Restricted area for professors, laboratory staff and site administrators of the Signal Processing Laboratory, UFRJ/COPPE."
      : "Área restrita para professores, equipe do laboratório e administradores do site do Laboratório de Processamento de Sinais, UFRJ/COPPE.",
    body,
  };
}

/**
 * The signed-in working area: what a professor sees after signing in. Course and
 * material data comes from the public repository, the account block is labelled
 * as a demonstration, and the authoring form posts to the theme's member route in
 * the published site.
 */
function professorAreaPage(locale) {
  const en = locale === "en";
  const copy = member.area;
  const courses = coursesFor(people[3]);
  const person = people[3];
  const body = `${pageHeader({
    kicker: t(copy.kicker, locale),
    title: t(copy.title, locale),
    lead: t(copy.lead, locale),
  })}
<div class="lps-page-grid">
<div class="lps-with-aside">
<div class="lps-stack">
<section class="lps-section lps-section--flush" aria-labelledby="area-page">
${sectionHead({ kicker: en ? "Public page" : "Página pública", title: t(copy.page.title, locale), id: "area-page" })}
${card({
  title: person.name,
  body: t(copy.page.body, locale),
  foot: `<span class="lps-button-row"><a class="lps-button lps-button-primary" href="${en ? "/en/sign-in/" : "/entrar/"}">${esc(t(copy.page.edit, locale))}</a><a class="lps-more" href="${personPath(person, locale)}">${esc(t(copy.page.view, locale))}</a></span>`,
})}
</section>
<section class="lps-section" aria-labelledby="area-subjects">
${sectionHead({ kicker: en ? "Teaching" : "Docência", title: t(copy.subjects.title, locale), id: "area-subjects" })}
<div class="lps-table-scroll">
<table>
<caption>${esc(t(copy.subjects.title, locale))}</caption>
<thead><tr><th scope="col">${esc(t(copy.subjects.columns.code, locale))}</th><th scope="col">${esc(t(copy.subjects.columns.course, locale))}</th><th scope="col">${esc(t(copy.subjects.columns.level, locale))}</th><th scope="col">${esc(t(copy.subjects.columns.term, locale))}</th><th scope="col">${esc(t(copy.subjects.columns.state, locale))}</th></tr></thead>
<tbody>${courses
    .map(
      (course) =>
        `<tr><td><span class="lps-course-code">${esc(course.code)}</span></td><th scope="row">${esc(t(course.title, locale))}</th><td><span class="lps-level ${t(course.level, "en") === "Graduate" ? "lps-level-grad" : "lps-level-undergrad"}">${esc(t(course.level, locale))}</span></td><td>2026.2</td><td><span class="lps-status lps-status-success">${esc(t(copy.subjects.state, locale))}</span></td></tr>`,
    )
    .join("\n")}</tbody>
</table>
</div>
</section>
<section class="lps-section" aria-labelledby="area-notes">
${sectionHead({ kicker: en ? "Authoring" : "Publicação", title: t(copy.notes.title, locale), id: "area-notes" })}
${
  person.notes?.length
    ? `<ul class="lps-record-list">${person.notes
        .map(
          (note) =>
            `<li><h3><a href="${note.href}">${esc(t(note.title, locale))}</a></h3><p class="lps-summary">${esc(t(note.summary, locale))}</p></li>`,
        )
        .join("")}</ul>`
    : alert({ tone: "info", body: t(copy.notes.empty, locale) })
}
<form class="lps-dashboard-form lps-mt-8" method="post" action="${en ? "/en/sign-in/" : "/entrar/"}">
<fieldset class="lps-fieldset">
<legend>${esc(t(copy.notes.form.legend, locale))}</legend>
<p class="lps-field"><label for="note-title">${esc(t(copy.notes.form.title, locale))}</label><input id="note-title" name="title" type="text" required></p>
<p class="lps-field"><label for="note-course">${esc(t(copy.notes.form.course, locale))}</label>
<select id="note-course" name="course">${courses.map((course) => `<option>${esc(`${course.code} — ${t(course.title, locale)}`)}</option>`).join("")}</select></p>
<p class="lps-field"><label for="note-summary">${esc(t(copy.notes.form.summary, locale))}</label><textarea id="note-summary" name="summary" rows="3"></textarea></p>
<p class="lps-field"><label for="note-link">${esc(t(copy.notes.form.link, locale))}</label><input id="note-link" name="link" type="url"><span class="lps-field-hint">${esc(t(copy.notes.form.hint, locale))}</span></p>
<p class="lps-checkbox"><input id="note-visible" name="visible" type="checkbox" value="1" checked><label for="note-visible">${esc(t(copy.notes.form.visibility, locale))}</label></p>
<p class="lps-button-row"><a class="lps-button lps-button-primary" href="${en ? "/en/sign-in/" : "/entrar/"}">${esc(en ? "Sign in to publish" : "Entre para publicar")}</a></p>
</fieldset>
</form>
</section>
</div>
<aside class="lps-aside lps-aside--start">
<section class="lps-toc" aria-labelledby="area-account">
<h2 id="area-account">${esc(t(copy.account.title, locale))}</h2>
<dl class="lps-facts">
<dt>${esc(t(copy.account.role, locale))}</dt><dd>${esc(t(copy.account.roleValue, locale))}</dd>
<dt>${esc(t(copy.account.secondFactor, locale))}</dt><dd>${esc(t(copy.account.secondFactorValue, locale))}</dd>
<dt>${esc(t(copy.account.scope, locale))}</dt><dd>${esc(t(copy.account.scopeValue, locale))}</dd>
</dl>
<p class="lps-meta lps-mt-4">${esc(t(copy.account.name, locale))}</p>
<p class="lps-button-row lps-mt-4"><a class="lps-button lps-button-ghost" href="${en ? "/en/sign-in/" : "/entrar/"}">${esc(t(copy.account.signOut, locale))}</a></p>
</section>
<section class="lps-toc lps-mt-6" aria-labelledby="area-tasks">
<h2 id="area-tasks">${esc(t(copy.tasks.title, locale))}</h2>
<ul class="lps-task-list">${copy.tasks.items
    .map((item) => `<li><span class="lps-task-link">${esc(t(item, locale))}</span></li>`)
    .join("")}</ul>
</section>
</aside>
</div>
<section class="lps-section">
${alert({ tone: "warning", title: en ? "Demonstration data" : "Dados de demonstração", body: t(copy.demo, locale) })}
</section>
<section class="lps-section">
${ctaBand({
  title: en
    ? "Administrators keep the same entry point"
    : "Administradores entram pelo mesmo caminho",
  body: en
    ? "Site administration adds the review queue, the role list and the site settings to what this account can reach."
    : "A administração do site acrescenta a fila de revisão, a lista de papéis e a configuração do site ao alcance desta conta.",
  actions: [{ href: en ? "/en/sign-in/" : "/entrar/", label: t(copy.account.adminLink, locale) }],
})}
</section>
</div>`;

  return {
    title: en ? "Your working area — LPS/UFRJ" : "Sua área de trabalho — LPS/UFRJ",
    description: en
      ? "The signed-in area where each professor maintains their page, courses and lesson material at the Signal Processing Laboratory."
      : "A área autenticada em que cada professor mantém sua página, suas disciplinas e seu material de aula no Laboratório de Processamento de Sinais.",
    body,
  };
}

/* ---------------------------------------------------------------------------
 * Person pages
 * ------------------------------------------------------------------------ */

function personPageFor(person) {
  return (locale) => {
    const en = locale === "en";
    const copy = member.personPage;
    const courses = coursesFor(person);
    const body = `${pageHeader({
      kicker: t(person.role, locale),
      title: person.name,
      lead: t(person.affiliation, locale),
    })}
<div class="lps-page-grid">
<div class="lps-with-aside">
<div class="lps-stack">
<section class="lps-section lps-section--flush" aria-labelledby="person-about">
${sectionHead({ kicker: en ? "Background" : "Trajetória", title: t(copy.about, locale), id: "person-about" })}
<div class="lps-reading">${t(person.bio, locale)
      .map((paragraph) => `<p>${esc(paragraph)}</p>`)
      .join("")}</div>
${person.inMemoriam ? alert({ tone: "info", body: t(copy.memoriam, locale) }) : ""}
</section>
<section class="lps-section" aria-labelledby="person-areas">
${sectionHead({ kicker: en ? "Research" : "Pesquisa", title: t(copy.areas, locale), id: "person-areas" })}
<ul class="lps-term-token">${(t(person.areas, locale) || [])
      .map((area) => `<li>${esc(area)}</li>`)
      .join("")}</ul>
</section>
<section class="lps-section" aria-labelledby="person-subjects">
${sectionHead({ kicker: en ? "Teaching" : "Docência", title: t(copy.subjects, locale), id: "person-subjects" })}
${
  courses.length
    ? `<div class="lps-table-scroll">
<table>
<caption>${esc(`${t(copy.subjects, locale)} — ${person.name}`)}</caption>
<thead><tr><th scope="col">${esc(en ? "Code" : "Código")}</th><th scope="col">${esc(en ? "Course" : "Disciplina")}</th><th scope="col">${esc(en ? "Level" : "Nível")}</th></tr></thead>
<tbody>${courses
        .map(
          (course) =>
            `<tr><td><span class="lps-course-code">${esc(course.code)}</span></td><th scope="row">${esc(t(course.title, locale))}</th><td>${esc(t(course.level, locale))}</td></tr>`,
        )
        .join("")}</tbody>
</table>
</div>`
    : `${
        person.teaching && t(person.teaching, locale)?.length
          ? `<p class="lps-meta">${esc(t(copy.subjectsAreas, locale))}</p><ul class="lps-term-token">${t(
              person.teaching,
              locale,
            )
              .map((area) => `<li>${esc(area)}</li>`)
              .join("")}</ul>`
          : ""
      }${alert({ tone: "info", title: t(copy.subjects, locale), body: t(copy.subjectsEmpty, locale) })}`
}
</section>
<section class="lps-section" aria-labelledby="person-notes">
${sectionHead({ kicker: en ? "Lessons" : "Aulas", title: t(copy.notes, locale), id: "person-notes" })}
${
  person.notes?.length
    ? `<ul class="lps-record-list">${person.notes
        .map(
          (note) =>
            `<li><h3><a href="${note.href}">${esc(t(note.title, locale))}</a></h3><p class="lps-summary">${esc(t(note.summary, locale))}</p></li>`,
        )
        .join("")}</ul>`
    : alert({ tone: "info", body: t(copy.notesEmpty, locale) })
}
</section>
<section class="lps-section" aria-labelledby="person-where">
${sectionHead({ kicker: en ? "Links" : "Links", title: t(copy.where, locale), id: "person-where" })}
<div class="lps-grid lps-grid--2">
${card({
  title: t(copy.contact, locale),
  body: person.email ? person.email : `${site.emails.office}`,
  foot: person.email
    ? `<a class="lps-more" href="mailto:${esc(person.email)}">${esc(en ? "Write an email" : "Escrever e-mail")}</a>`
    : `<a class="lps-more" href="mailto:${site.emails.office}">${esc(site.emails.office)}</a>`,
})}
${card({
  title: en ? "External profiles" : "Perfis externos",
  body: en
    ? "Lattes, code repositories and professional profiles are maintained by the professor."
    : "Lattes, repositórios de código e perfis profissionais são mantidos pelo professor.",
  foot: `<ul class="lps-source-list">${person.links
    .map((item) => `<li><a class="lps-meta" href="${item.href}">${esc(item.label)}</a></li>`)
    .join("")}</ul>`,
})}
</div>
</section>
<section class="lps-section">
${ctaBand({
  title: t(copy.owner, locale),
  body: en
    ? "Editing this page uses the same account as the laboratory's editorial system; nothing here is editable without signing in."
    : "A edição desta página usa a mesma conta do sistema editorial do laboratório; nada aqui é editável sem autenticação.",
  actions: [{ href: en ? "/en/sign-in/" : "/entrar/", label: t(member.signIn.title, locale) }],
})}
</section>
</div>
${aside(locale, [
  { id: "person-about", label: copy.about },
  { id: "person-areas", label: copy.areas },
  { id: "person-subjects", label: copy.subjects },
  { id: "person-notes", label: copy.notes },
  { id: "person-where", label: copy.where },
])}
</div>
</div>`;

    return {
      title: `${person.name} — LPS/UFRJ`,
      description: en
        ? `${person.name}: ${t(person.role, locale)} at the Signal Processing Laboratory, UFRJ/COPPE.`
        : `${person.name}: ${t(person.role, locale)} no Laboratório de Processamento de Sinais, UFRJ/COPPE.`,
      body,
    };
  };
}

export const routes = [
  { pt: "/", en: "/en/", breadcrumb: null, build: home },
  {
    pt: "/sobre/",
    en: "/en/about/",
    breadcrumb: { "pt-BR": "Sobre", en: "About" },
    build: aboutPage,
  },
  {
    pt: "/pesquisa/",
    en: "/en/research/",
    breadcrumb: { "pt-BR": "Pesquisa", en: "Research" },
    build: researchPage,
  },
  {
    pt: "/projetos/",
    en: "/en/projects/",
    breadcrumb: { "pt-BR": "Projetos", en: "Projects" },
    build: projectsPage,
  },
  {
    pt: "/publicacoes/",
    en: "/en/publications/",
    breadcrumb: { "pt-BR": "Publicações", en: "Publications" },
    build: publicationsPage,
  },
  {
    pt: "/pessoas/",
    en: "/en/people/",
    breadcrumb: { "pt-BR": "Pessoas", en: "People" },
    build: peoplePage,
  },
  {
    pt: "/ensino/",
    en: "/en/teaching/",
    breadcrumb: { "pt-BR": "Ensino", en: "Teaching" },
    build: teachingPage,
  },
  {
    pt: "/infraestrutura/",
    en: "/en/infrastructure/",
    breadcrumb: { "pt-BR": "Infraestrutura", en: "Infrastructure" },
    build: infrastructurePage,
  },
  {
    pt: "/oportunidades/",
    en: "/en/opportunities/",
    breadcrumb: { "pt-BR": "Oportunidades", en: "Opportunities" },
    build: opportunitiesPage,
  },
  {
    pt: "/noticias/",
    en: "/en/news/",
    breadcrumb: { "pt-BR": "Notícias e eventos", en: "News and events" },
    build: newsPage,
  },
  {
    pt: "/contato/",
    en: "/en/contact/",
    breadcrumb: { "pt-BR": "Contato", en: "Contact" },
    build: contactPage,
  },
  {
    pt: "/acessibilidade/",
    en: "/en/accessibility/",
    breadcrumb: { "pt-BR": "Acessibilidade", en: "Accessibility" },
    build: accessibilityPage,
  },
  {
    pt: "/privacidade/",
    en: "/en/privacy/",
    breadcrumb: { "pt-BR": "Privacidade", en: "Privacy" },
    build: privacyPage,
  },
  {
    pt: "/identidade-visual/",
    en: "/en/visual-identity/",
    breadcrumb: { "pt-BR": "Identidade visual", en: "Visual identity" },
    build: identityPage,
  },
  {
    pt: "/entrar/",
    en: "/en/sign-in/",
    breadcrumb: { "pt-BR": "Entrar", en: "Sign in" },
    build: signInPage,
  },
  {
    pt: "/area-do-professor/",
    en: "/en/faculty-area/",
    breadcrumb: { "pt-BR": "Área do professor", en: "Faculty area" },
    build: professorAreaPage,
  },
  ...people.map((person) => ({
    pt: `/pessoas/${person.slug}/`,
    en: `/en/people/${person.slug}/`,
    breadcrumb: { "pt-BR": person.name, en: person.name },
    parents: [
      {
        href: { "pt-BR": "/pessoas/", en: "/en/people/" },
        label: { "pt-BR": "Pessoas", en: "People" },
      },
    ],
    build: personPageFor(person),
  })),
  {
    pt: "/busca/",
    en: "/en/search/",
    breadcrumb: { "pt-BR": "Busca", en: "Search" },
    build: searchPage,
  },
];

export { utilityLinks };

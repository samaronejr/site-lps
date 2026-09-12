# Bilingual information architecture

This is the frozen public information architecture for LPS. Portuguese (`pt-br`) is the
editorial authority; English (`en`) is a separately reviewed locale, never a fallback. The
machine contract is `tests/fixtures/ia/routes.json`; `npm run qa:ia` validates it with the
controlled vocabularies and writes deterministic locale diagrams and route tables.

The public URL contract is lowercase ASCII, locale-prefixed, and trailing-slash terminated.
`/pt-br/` and `/en/` are permanent locale roots. Stable keys are language-neutral internal
identifiers and are never translated; localized labels and path segments are the only translated
parts. Query strings are not canonical records. This intentionally does not reproduce FEEC's
scale or opaque URL structure.

## Approved global navigation

Primary navigation appears in this order in both locales, with persistent search and locale
controls. `Colabore`/`Collaborate` is the persistent primary task CTA, not an extra primary-nav
item.

| Stable key | Portuguese | English | Portuguese route | English route |
| --- | --- | --- | --- | --- |
| about | Sobre | About | `/pt-br/sobre/` | `/en/about/` |
| research | Pesquisa | Research | `/pt-br/pesquisa/` | `/en/research/` |
| people | Pessoas | People | `/pt-br/pessoas/` | `/en/people/` |
| publications | Publicações | Publications | `/pt-br/publicacoes/` | `/en/publications/` |
| infrastructure | Infraestrutura | Infrastructure | `/pt-br/infraestrutura/` | `/en/infrastructure/` |
| opportunities | Oportunidades | Opportunities | `/pt-br/oportunidades/` | `/en/opportunities/` |
| news | Notícias | News | `/pt-br/noticias/` | `/en/news/` |

The footer repeats institutional affiliation with UFRJ/COPPE and has these direct public links:

| Stable key | Portuguese | English | Portuguese route | English route |
| --- | --- | --- | --- | --- |
| contact | Contato | Contact | `/pt-br/contato/` | `/en/contact/` |
| events | Eventos | Events | `/pt-br/eventos/` | `/en/events/` |
| privacy | Privacidade | Privacy | `/pt-br/privacidade/` | `/en/privacy/` |
| accessibility | Acessibilidade | Accessibility | `/pt-br/acessibilidade/` | `/en/accessibility/` |

Administration categories, editorial workflow states, and taxonomies are not public navigation.
There are no contributor-created tags.

## Sitemap and page hierarchy

Every public page has exactly one primary audience and one canonical task in the machine route
fixture. The route and parent hierarchy is at most two levels below a locale root:

```text
/{locale}/
├── sobre | about
├── pesquisa | research
│   └── :research-area
├── projetos | projects
│   └── :project
├── pessoas | people
│   └── :person
├── publicacoes | publications
│   └── :publication
├── infraestrutura | infrastructure
├── oportunidades | opportunities
│   └── :opportunity
├── noticias | news
│   └── :news
├── eventos | events
│   └── :event
├── colabore | collaborate
├── contato | contact
├── privacidade | privacy
└── acessibilidade | accessibility
```

`{locale}` is translated by the paired mapping in the fixture (`pt-br` or `en`); every displayed
Portuguese segment above maps to its English route segment there. Detail patterns are routing
templates, not a permission to create arbitrary taxonomy pages. News and events retain paired
interface routes but their editorial records are Portuguese-first; English summaries are optional
and only appear after review. All other required route classes have reviewed English coverage as
defined by editorial governance.

| Page class | Primary audience | Canonical task |
| --- | --- | --- |
| Home | Site visitor | Understand LPS |
| About | Site visitor | Understand institutional mission |
| Research and research area | Academic peer | Find or understand research themes |
| Projects and project detail | Industry, government, or funder | Find or understand projects |
| People and person detail | Prospective researcher | Find or understand people |
| Publications and publication detail | Academic peer | Find or read a publication record |
| Infrastructure | Industry, government, or funder | Assess capabilities |
| Opportunities and opportunity detail | Prospective researcher | Find or apply to an opportunity |
| News and news detail | Journalist or public | Read news |
| Events and event detail | Current LPS community | Find or attend events |
| Collaborate | Industry, government, or funder | Start collaboration |
| Contact | Site visitor | Find an approved contact route |
| Privacy | Site visitor | Understand the privacy notice |
| Accessibility | Site visitor | Understand the accessibility notice |

## Homepage sequence and journeys

The homepage sequence is fixed: institutional mission and proof; research themes; validated
evidence and outputs; featured projects; the Join, Collaborate, and Partner journeys; people and
research participation; infrastructure and capabilities; latest publications, news, and events;
partners and funders; and a collaboration/contact close.

The three co-primary journeys map to the defined audience outcomes: **Join LPS** reaches
opportunities for prospective researchers; **Collaborate** reaches the collaboration route for
academic peers and institutional collaborators; **Partner** reaches collaboration and
capability/project evidence for companies, government, and funders. Claims and featured records
remain subject to the governance source and review gates.

## Controlled vocabulary and search

`content/taxonomies/controlled-vocabularies.yaml` has the complete approved research areas:
`instrumentation`, `signal-processing`, `computational-intelligence`, and
`software-engineering`; and approved application domains: `electrical-nuclear-energy`,
`oil-and-gas`, `high-energy-physics`, `defense`, `medicine`, `veterinary-science`, and
`data-quality`. Each has Portuguese and English labels plus controlled bilingual synonyms.

Those seeds are the plan-approved scope cited to
`.omo/plans/lps-institutional-website.md` (Must have, structured-record vocabulary contract).
They are the only terms admitted without further source review. An additional research area or
application domain must carry an `authoritative-lps-source` evidence object with a direct current
`https://lps.ufrj.br/` or current LPS Google Sites citation. It stays out of the vocabulary until
that source is cited and editorially accepted. The current inventory contains no source-backed
vocabulary expansion blocker because no additional term is proposed.

`content/taxonomies/search-facets.yaml` freezes the nine server-rendered search facets:
content type, research area, application domain, project status, publication type, publication
year, person role, person status, and opportunity type. Facets use AND across facets and OR within
a facet. Search has no free-tag facet.

## Executable contract

Run `npm run qa:ia`. It rejects translated/non-neutral keys, a third route level, duplicate locale
destinations, unsupported domains without authoritative LPS evidence, free tags, missing required
navigation, absent primary audience or canonical task, and orphan pages. On success it writes:

- `.omo/evidence/task-5/generated/sitemap-pt-br.md`
- `.omo/evidence/task-5/generated/sitemap-en.md`
- `.omo/evidence/task-5/generated/routes-pt-br.json`
- `.omo/evidence/task-5/generated/routes-en.json`

The checker is a content-system contract only; it does not publish pages or infer review, privacy,
rights, or contact approval from a route.

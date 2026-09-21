# LPS Google Sites scrape — 2026-09-21

Fresh anonymous public-HTTP scrape of the current LPS Google Sites estate,
captured 2026-09-21 to feed the modern-refresh content model. Method: public
`GET` only, no authentication, no Drive document opened (Drive links are
recorded as links, never fetched). This report is editorial evidence, not a
publication approval: every claim below needs the collection owner's review
before it ships as a reviewed CMS record.

Source policy reminder: public visibility is not a reuse license. No image
payload was retained; professor portraits and Drive-hosted brand PDFs/PNGs are
link-only.

## Pages captured

| # | URL | Title | Notes |
| --- | --- | --- | --- |
| 1 | `https://sites.google.com/lps.ufrj.br/lps/in%C3%ADcio` | LPS/COPPE | Home: about paragraphs, team size, address footer, `secretaria@lps.ufrj.br` mailto, LinkedIn link |
| 2 | `https://sites.google.com/lps.ufrj.br/lps/sobre/sobre` | LPS/COPPE - Sobre | Home text repeated + Missão / Visão / Valores |
| 3 | `https://sites.google.com/lps.ufrj.br/lps/sobre/identidade-visual` | LPS/COPPE - Identidade Visual | 5 Drive links: signal-only white/blue (PDF/PNG), full-mark white/blue (PDF/PNG), full brand pack |
| 4 | `https://sites.google.com/lps.ufrj.br/lps/professores` | LPS/COPPE - Professores | 5 entries: Calôba, Seixas, Natanael Jr., Jodafons, Queiroz (in memoriam) |
| 5 | `https://sites.google.com/lps.ufrj.br/lps/projetos` | LPS/COPPE - Projetos | 2 self-linked index entries, no scope/dates/team |
| 6 | `https://sites.google.com/lps.ufrj.br/lps/not%C3%ADcias/oportunidades-de-bolsa` | LPS/COPPE - Oportunidades de bolsa | Single line: `Em breve` |
| 7 | `https://sites.google.com/lps.ufrj.br/caloba/in%C3%ADcio` | caloba | Full Calôba bio + Lattes `3238659802153968` |
| 8 | `https://sites.google.com/lps.ufrj.br/seixas/in%C3%ADcio` | seixas | Short Seixas bio + Lattes `1404632471755241` |
| 9 | `https://sites.google.com/lps.ufrj.br/jodafons/in%C3%ADcio` | jodafons | Jodafons bio + interest areas + Lattes `3592331377050716` |
| 10 | `https://sites.google.com/lps.ufrj.br/namourajr/in%C3%ADcio` | natmourajr | Long Natanael bio (ATLAS, sonar/Marinha, oil & gas, teaching, coordination roles) + Lattes `2696393506316122` |
| 11 | `https://sites.google.com/lps.ufrj.br/acmq/in%C3%ADcio` | acmq | Queiroz memorial bio + Lattes `7901653289226815` |
| 12 | `https://www.embrapii.coppe.ufrj.br/laboratorios/1228/lps-laboratorio-de-processamento-de-sinais` | Laboratórios \| Coppe Emprapii | EMBRAPII profile: coordinator, areas, industry list, facilities |

Observed global navigation (Google Sites menu): Início · Sobre (Sobre,
Identidade Visual) · Professores (5 individual sites) · Chat · Notícias ·
Oportunidades de bolsa · Projetos · Lorenzetti · Glance · Intranet · Tutoriais
(sbatch, imagem, Singularity, SLURM, Maestro) · Área do administrador.
`Lorenzetti`, `Glance`, `infra` subsites redirect to Google sign-in
(private-excluded, unchanged since the 2026-08-30 inventory).

## Institutional core (pages 1–2)

- **Name:** Laboratório de Processamento de Sinais (LPS). Founded **1996** at
  UFRJ. Teaching, research, and outreach from junior scientific initiation
  (secondary/technical students) through postdoc; undergrad at Poli/UFRJ,
  graduate at COPPE/UFRJ.
- **Partnership signature:** national and international collaborations in
  energy (electrical, nuclear, oil & gas), experimental high-energy physics,
  quantum computing, defense, medicine, and data quality; close industry ties;
  highly qualified alumni feeding industry demand.
- **Team (as published):** 4 full-time professors, 2 of them full professors
  (*titulares*), plus postdocs, graduate and undergraduate students.
- **Address (footer):** Av. Athos da Silveira Ramos, 149 — Cidade
  Universitária da UFRJ, Ilha do Fundão, Centro de Tecnologia, Bloco H,
  Sala 220, CEP 21941-914, Rio de Janeiro – RJ.
- **Missão:** generate knowledge and technological innovation through teaching,
  research, and outreach; train highly qualified professionals at every level;
  act as a scientific/technological advancement agent with public/private
  strategic partnerships for sustainable development.
- **Visão:** national/international reference lab for innovative engineering
  solutions focused on computational intelligence, signal processing, and
  software development; machine-learning models in complex environments
  (critical systems, noisy scenarios, robustness, interpretability).
- **Valores:** Inovação (spin-offs of technology-based companies), Colaboração
  (solid national/international partnerships), Excelência (technical, scientific,
  innovation), Formação (stimulating academic/professional environment).

## People (pages 4, 7–11)

| Professor | Title / status | Research signature (source wording) |
| --- | --- | --- |
| Luiz Pereira Calôba | Professor Titular (Emérito); COPPE + Poli | Signal processing & applications, neural networks (teaching since 1989); UFRJ–CERN collaboration initiator/coordinator 1988–1998; CNPq 1A researcher; 18 PhD + 60 MSc supervised; ~300 full papers; Ordem Nacional do Mérito Científico (2007); Academia Nacional de Engenharia (2013) |
| José Manoel de Seixas | Professor Titular UFRJ | Computational intelligence, high-energy calorimetry, sonar technology, signal processing, electronic instrumentation; EMBRAPII-listed coordinator |
| Natanael Nunes de Moura Junior | Professor UFRJ; LPS coordinator; Sonar Lab coordinator; head of Computational Intelligence (PESC/COPPE) | ATLAS/CERN online filtering + energy estimation (ensemble/deep learning); passive sonar + under-water acoustics with Marinha do Brasil / IPqM; oil & gas (Mero/Libra passive seismic, CENPES/Petrobras barrier degradation, offshore digital-twin prototype); teaching: Sistemas Lineares, Aprendizado Profundo, Kernel, compactação de sinais |
| João Victor da Fonseca Pinto (Jodafons) | Adjunto DEL/UFRJ (2025); permanent LPS researcher; ATLAS associate | ATLAS online electron classification with ANNs (first NN in ATLAS online electron selection); ex-coordinator (2021–2022) of the ATLAS online e/γ filtering group; interests: HEP event simulation/reconstruction, workload orchestration, AI (shallow/deep, generative), quantum computing |
| Antônio Carlos Moreirão de Queiroz | Titular DEL + PESC (in memoriam) | Electrostatic energy harvesting, circuit theory, EM theory, switched-current filter structures, high-frequency continuous-time filters, multi-resonance networks (Tesla-coil generalizations), analog microelectronics, RF, history of science |

Lattes IDs: Calôba `3238659802153968`, Seixas `1404632471755241`, Jodafons
`3592331377050716`, Natanael `2696393506316122`, Queiroz `7901653289226815`
(all `http://lattes.cnpq.br/<id>`).

## Projects & opportunities (pages 5–6)

- Project index lists exactly two entries, both self-links with no detail
  page: **Simulação de eventos em HEP** and **Sistema de Filtragem Online do
  ATLAS**. No scope, dates, team, funders, or status published — migrate as an
  index record with an explicit gap note (matches corpus record-005).
- Opportunities page contains only **“Em breve”** — the new site must render
  this as an honest empty state, never as an invented listing.

## EMBRAPII profile (page 12)

- Coordinator: Prof. José Manoel de Seixas, tel. (21) 3938-8205,
  `seixas@lps.ufrj.br` (profile-published contact; still needs the public-contact
  governance decision before reuse as a site-wide contact).
- Core areas: electronic instrumentation; analog & digital signal processing;
  software engineering; computational intelligence. Technology-based spin-offs
  noted as an innovation vocation signal.
- Industry collaboration roster: **Petrobras, Eletrobras, Embraer, Inmetro,
  Empresa de Pesquisa Energética, OLX, National Instruments, Samsung, Murabei**.
- Facilities: **310 m²**; presentation/virtual-meeting room; acoustic room;
  **~40 PCs** on the LPS domain; programmable-device development software;
  state-of-the-art instrumentation for system development/analysis.

## Visual identity page (page 3)

Five Google Drive links (authentication-gated, not opened): signal-only white
(PDF), signal-only blue (PDF + PNG), full mark white (PDF), full mark blue
(PDF + PNG), complete brand pack. The modern horizontal SVG lockups in
`wp-content/themes/lps-modern/assets/img/logo/` were derived from the
repository's already-cleared vector source, not from these Drive files.

## Content mapping for the modern homepage

| Homepage section | Source |
| --- | --- |
| Hero (founded 1996, UFRJ/COPPE, teaching→postdoc span) | Pages 1–2 |
| Stats (1996 · 4 docentes · 310 m² · ~40 estações) | Pages 1, 12 |
| Journeys (IC/graduação · pós-graduação · parcerias) | Pages 1–2, 12 |
| Research grid (6 areas) | Page 1 partnership areas |
| Featured projects (2, with gap note) | Page 5 |
| About + mission/vision/values | Page 2 |
| People (4 active + memorial link) | Pages 4, 7–11 |
| Infrastructure (rooms, PCs, software, datacenter docs link) | Page 12 + `lps-ufrj-br.github.io/datacenter` (pre-existing inventory link) |
| Opportunities CTA (honest “em breve” state) | Page 6 |
| Partners strip | Page 12 roster + CERN/ATLAS, Marinha, RENAFAE (faculty pages) |
| Contact footer (address only; no invented email/phone) | Page 1 footer |

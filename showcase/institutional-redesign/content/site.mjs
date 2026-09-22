/**
 * Content source for the institutional redesign preview.
 *
 * Every fact in this file is traceable to a public LPS/UFRJ source captured for the
 * migration inventory (`content/inventory/urls.csv`, `content/corpus/evidence/`):
 *
 * - https://sites.google.com/lps.ufrj.br/lps/inicio            (home, about, mission/vision/values)
 * - https://sites.google.com/lps.ufrj.br/lps/professores       (faculty list)
 * - https://sites.google.com/lps.ufrj.br/lps/projetos          (project index)
 * - https://sites.google.com/lps.ufrj.br/lps/noticias/oportunidades-de-bolsa
 * - faculty subsites: /caloba, /seixas, /namourajr, /jodafons, /acmq
 * - https://www.embrapii.coppe.ufrj.br/laboratorios/1228/lps-... (facilities, industry partners)
 * - https://www.pee.ufrj.br/lps-laboratorio-de-processamento-de-sinais/ (area summary)
 *
 * Nothing was invented: names, numbers, course codes, partners and addresses are quoted
 * from those captures. Where the source is silent (open calls, publication feed, contact
 * approvals), the page says so instead of filling the gap.
 */

export const site = {
  name: "Laboratório de Processamento de Sinais",
  acronym: "LPS",
  tagline: {
    "pt-BR": "Processamento de sinais e inteligência computacional na COPPE/UFRJ",
    en: "Signal processing and computational intelligence at COPPE/UFRJ",
  },
  affiliation: {
    "pt-BR": "UFRJ · COPPE · Programa de Engenharia Elétrica",
    en: "UFRJ · COPPE · Electrical Engineering Program",
  },
  founded: 1996,
  address: {
    line1: "Av. Athos da Silveira Ramos, 149",
    line2: "Centro de Tecnologia, Bloco H, sala 220",
    line3: "Cidade Universitária, Ilha do Fundão",
    city: "Rio de Janeiro — RJ, CEP 21941-914",
    full: "Av. Athos da Silveira Ramos, 149 — Centro de Tecnologia, Bloco H, sala 220 — Cidade Universitária, Ilha do Fundão — Rio de Janeiro/RJ — CEP 21941-914",
  },
  phone: {
    label: "(21) 3938-8205",
    href: "tel:+552139388205",
    note: { "pt-BR": "Ramal 8205", en: "Extension 8205" },
  },
  emails: {
    office: "secretaria@lps.ufrj.br",
  },
  external: {
    pee: "https://www.pee.ufrj.br/",
    coppe: "https://coppe.ufrj.br/",
    ufrj: "https://ufrj.br/",
    lattes: "https://lattes.cnpq.br/",
    github: "https://github.com/lps-ufrj-br",
    cern: "https://home.cern/science/experiments/atlas",
    legacy: "https://sites.google.com/lps.ufrj.br/lps/in%C3%ADcio",
  },
};

/** Primary navigation — the destinations the redesign publishes. */
export const navigation = [
  {
    key: "about",
    href: { "pt-BR": "/sobre/", en: "/en/about/" },
    label: { "pt-BR": "Sobre", en: "About" },
    children: [
      {
        href: { "pt-BR": "/sobre/#historia", en: "/en/about/#historia" },
        label: { "pt-BR": "História", en: "History" },
      },
      {
        href: { "pt-BR": "/sobre/#missao", en: "/en/about/#missao" },
        label: { "pt-BR": "Missão, visão e valores", en: "Mission, vision and values" },
      },
      {
        href: { "pt-BR": "/infraestrutura/", en: "/en/infrastructure/" },
        label: { "pt-BR": "Infraestrutura", en: "Infrastructure" },
      },
      {
        href: { "pt-BR": "/identidade-visual/", en: "/en/visual-identity/" },
        label: { "pt-BR": "Identidade visual", en: "Visual identity" },
      },
    ],
  },
  {
    key: "research",
    href: { "pt-BR": "/pesquisa/", en: "/en/research/" },
    label: { "pt-BR": "Pesquisa", en: "Research" },
    children: [
      {
        href: { "pt-BR": "/pesquisa/#linhas", en: "/en/research/#linhas" },
        label: { "pt-BR": "Linhas de pesquisa", en: "Research areas" },
      },
      {
        href: { "pt-BR": "/projetos/", en: "/en/projects/" },
        label: { "pt-BR": "Projetos", en: "Projects" },
      },
      {
        href: { "pt-BR": "/publicacoes/", en: "/en/publications/" },
        label: { "pt-BR": "Publicações", en: "Publications" },
      },
    ],
  },
  {
    key: "people",
    href: { "pt-BR": "/pessoas/", en: "/en/people/" },
    label: { "pt-BR": "Pessoas", en: "People" },
  },
  {
    key: "teaching",
    href: { "pt-BR": "/ensino/", en: "/en/teaching/" },
    label: { "pt-BR": "Ensino", en: "Teaching" },
    children: [
      {
        href: { "pt-BR": "/ensino/#pos-graduacao", en: "/en/teaching/#pos-graduacao" },
        label: { "pt-BR": "Pós-graduação", en: "Graduate" },
      },
      {
        href: { "pt-BR": "/ensino/#graduacao", en: "/en/teaching/#graduacao" },
        label: { "pt-BR": "Graduação", en: "Undergraduate" },
      },
      {
        href: { "pt-BR": "/ensino/#materiais", en: "/en/teaching/#materiais" },
        label: { "pt-BR": "Materiais didáticos", en: "Course materials" },
      },
    ],
  },
  {
    key: "opportunities",
    href: { "pt-BR": "/oportunidades/", en: "/en/opportunities/" },
    label: { "pt-BR": "Oportunidades", en: "Opportunities" },
  },
  {
    key: "news",
    href: { "pt-BR": "/noticias/", en: "/en/news/" },
    label: { "pt-BR": "Notícias e eventos", en: "News and events" },
  },
];

/** Quick links for the slim utility bar. */
export const utilityLinks = [
  {
    href: { "pt-BR": "/publicacoes/", en: "/en/publications/" },
    label: { "pt-BR": "Publicações", en: "Publications" },
  },
  {
    href: { "pt-BR": "/infraestrutura/", en: "/en/infrastructure/" },
    label: { "pt-BR": "Infraestrutura", en: "Infrastructure" },
  },
  {
    href: { "pt-BR": "/contato/", en: "/en/contact/" },
    label: { "pt-BR": "Contato", en: "Contact" },
  },
  {
    href: { "pt-BR": "/acessibilidade/", en: "/en/accessibility/" },
    label: { "pt-BR": "Acessibilidade", en: "Accessibility" },
  },
];

/** Home hero. */
export const hero = {
  kicker: {
    "pt-BR": "Laboratório de Processamento de Sinais · UFRJ/COPPE",
    en: "Signal Processing Laboratory · UFRJ/COPPE",
  },
  title: {
    "pt-BR": "Sinais, dados e inteligência computacional a serviço da engenharia",
    en: "Signals, data and computational intelligence in the service of engineering",
  },
  accent: {
    "pt-BR": "inteligência computacional",
    en: "computational intelligence",
  },
  lead: {
    "pt-BR":
      "Fundado em 1996, o LPS reúne ensino, pesquisa e extensão na COPPE/UFRJ. Pesquisamos processamento de sinais, aprendizado de máquina e engenharia de software aplicados a energia, defesa, medicina, óleo e gás e física experimental de altas energias.",
    en: "Founded in 1996, LPS brings together teaching, research and extension work at COPPE/UFRJ. We research signal processing, machine learning and software engineering applied to energy, defence, medicine, oil and gas, and experimental high-energy physics.",
  },
  actions: [
    {
      href: { "pt-BR": "/pesquisa/", en: "/en/research/" },
      label: { "pt-BR": "Conheça a pesquisa", en: "Explore the research" },
      variant: "primary",
    },
    {
      href: { "pt-BR": "/ensino/", en: "/en/teaching/" },
      label: { "pt-BR": "Disciplinas e materiais", en: "Courses and materials" },
      variant: "ghost",
    },
  ],
  stats: [
    { value: "1996", label: { "pt-BR": "Ano de fundação", en: "Founded" } },
    { value: "4", label: { "pt-BR": "Professores em tempo integral", en: "Full-time professors" } },
    {
      value: "310 m²",
      label: { "pt-BR": "Instalações no Bloco H", en: "Facilities in Building H" },
    },
    { value: "1988", label: { "pt-BR": "Colaboração UFRJ–CERN", en: "UFRJ–CERN collaboration" } },
  ],
};

/** Three institutional entrances, in the reference site's journey grammar. */
export const journeys = {
  kicker: { "pt-BR": "Formação", en: "Education" },
  title: { "pt-BR": "Escolha o seu percurso", en: "Choose your path" },
  lead: {
    "pt-BR":
      "A atuação do laboratório cobre da iniciação científica júnior ao pós-doutorado, na Escola Politécnica e na COPPE.",
    en: "The laboratory works from junior research initiation to post-doctorate, at the Polytechnic School and at COPPE.",
  },
  items: [
    {
      title: { "pt-BR": "Pós-graduação", en: "Graduate studies" },
      body: {
        "pt-BR":
          "Mestrado e doutorado no Programa de Engenharia Elétrica da COPPE, na área de Inteligência Computacional.",
        en: "Master and doctoral degrees in the Electrical Engineering Program at COPPE, in the Computational Intelligence area.",
      },
      meta: { "pt-BR": "COPPE · PEE", en: "COPPE · PEE" },
      href: { "pt-BR": "/ensino/#pos-graduacao", en: "/en/teaching/#pos-graduacao" },
      action: { "pt-BR": "Ver disciplinas", en: "See courses" },
    },
    {
      title: { "pt-BR": "Graduação", en: "Undergraduate" },
      body: {
        "pt-BR":
          "Disciplinas de instrumentação, sistemas lineares, computação e processamento de sinais na Escola Politécnica (Poli/UFRJ).",
        en: "Courses in instrumentation, linear systems, computing and signal processing at the Polytechnic School (Poli/UFRJ).",
      },
      meta: { "pt-BR": "Poli · DEE/COPPE", en: "Poli · DEE/COPPE" },
      href: { "pt-BR": "/ensino/#graduacao", en: "/en/teaching/#graduacao" },
      action: { "pt-BR": "Ver disciplinas", en: "See courses" },
    },
    {
      title: { "pt-BR": "Extensão e parcerias", en: "Extension and partnerships" },
      body: {
        "pt-BR":
          "Projetos com indústria, institutos de pesquisa e órgãos públicos, da prova de conceito ao sistema em operação.",
        en: "Projects with industry, research institutes and public bodies, from proof of concept to systems in operation.",
      },
      meta: { "pt-BR": "P&D · consultoria", en: "R&D · consulting" },
      href: { "pt-BR": "/infraestrutura/#parcerias", en: "/en/infrastructure/#parcerias" },
      action: { "pt-BR": "Como colaborar", en: "How to collaborate" },
    },
  ],
};

/** Research areas. Sources: PEE/LPS area summary and the faculty pages. */
export const research = {
  kicker: { "pt-BR": "Pesquisa", en: "Research" },
  title: { "pt-BR": "Linhas de pesquisa", en: "Research areas" },
  lead: {
    "pt-BR":
      "As principais áreas de atuação são a instrumentação eletrônica, o processamento analógico e digital de sinais, a engenharia de software e a inteligência computacional.",
    en: "The main areas of activity are electronic instrumentation, analog and digital signal processing, software engineering and computational intelligence.",
  },
  areas: [
    {
      slug: "processamento-de-sinais",
      title: { "pt-BR": "Processamento digital de sinais", en: "Digital signal processing" },
      body: {
        "pt-BR":
          "Filtragem, compressão, estimação e detecção de sinais em ambientes ruidosos e de alta taxa de eventos.",
        en: "Filtering, compression, estimation and detection of signals in noisy, high-rate environments.",
      },
      topics: {
        "pt-BR": [
          "Filtragem adaptativa",
          "Compressão de sinais",
          "Estimação de energia",
          "Detecção de novidade",
        ],
        en: ["Adaptive filtering", "Signal compression", "Energy estimation", "Novelty detection"],
      },
    },
    {
      slug: "inteligencia-computacional",
      title: { "pt-BR": "Inteligência computacional", en: "Computational intelligence" },
      body: {
        "pt-BR":
          "Redes neurais rasas e profundas, aprendizado por kernel e generative AI aplicados a problemas de engenharia.",
        en: "Shallow and deep neural networks, kernel learning and generative AI applied to engineering problems.",
      },
      topics: {
        "pt-BR": [
          "Aprendizado profundo",
          "Aprendizado por kernel",
          "Ensemble learning",
          "Autoencoders",
        ],
        en: ["Deep learning", "Kernel learning", "Ensemble learning", "Autoencoders"],
      },
    },
    {
      slug: "modelagem-e-analise-de-dados",
      title: { "pt-BR": "Modelagem e análise de dados", en: "Data modelling and analysis" },
      body: {
        "pt-BR":
          "Modelagem supervisionada e não supervisionada, engenharia de características, séries temporais e sistemas de recomendação.",
        en: "Supervised and unsupervised modelling, feature engineering, time series and recommender systems.",
      },
      topics: {
        "pt-BR": [
          "Engenharia de características",
          "Séries temporais",
          "Sistemas de recomendação",
          "Qualidade de dados",
        ],
        en: ["Feature engineering", "Time series", "Recommender systems", "Data quality"],
      },
    },
    {
      slug: "deteccao-e-monitoramento",
      title: {
        "pt-BR": "Detecção, falhas e monitoramento",
        en: "Detection, failure and monitoring",
      },
      body: {
        "pt-BR":
          "Detecção de falhas, fraudes e novidades, gêmeos digitais e monitoramento de sistemas críticos.",
        en: "Failure, fraud and novelty detection, digital twins and monitoring of critical systems.",
      },
      topics: {
        "pt-BR": [
          "Detecção de anomalias",
          "Gêmeo digital",
          "Séries temporais financeiras",
          "Segurança de processos",
        ],
        en: ["Anomaly detection", "Digital twin", "Financial time series", "Process safety"],
      },
    },
    {
      slug: "fisica-de-altas-energias",
      title: {
        "pt-BR": "Física experimental de altas energias",
        en: "Experimental high-energy physics",
      },
      body: {
        "pt-BR":
          "Colaboração UFRJ–CERN desde 1988: simulação de eventos, reconstrução e filtragem online no experimento ATLAS.",
        en: "UFRJ–CERN collaboration since 1988: event simulation, reconstruction and online filtering in the ATLAS experiment.",
      },
      topics: {
        "pt-BR": [
          "Simulação de eventos",
          "Filtragem online (trigger)",
          "Calorimetria",
          "Identificação de partículas",
        ],
        en: ["Event simulation", "Online triggering", "Calorimetry", "Particle identification"],
      },
    },
    {
      slug: "sonar-e-sinais-acusticos",
      title: { "pt-BR": "Sonar e sinais acústicos", en: "Sonar and acoustic signals" },
      body: {
        "pt-BR":
          "Processamento de sinais acústicos submarinos, classificação de contatos e síntese de dados acústicos.",
        en: "Underwater acoustic signal processing, contact classification and synthetic acoustic data.",
      },
      topics: {
        "pt-BR": [
          "Sonar passivo",
          "Sonar rebocado",
          "Classificação de sinais",
          "Simuladores acústicos",
        ],
        en: ["Passive sonar", "Towed array sonar", "Signal classification", "Acoustic simulators"],
      },
    },
  ],
  strata: {
    projects: {
      title: { "pt-BR": "Projetos em destaque", en: "Featured projects" },
      href: { "pt-BR": "/projetos/", en: "/en/projects/" },
    },
    evidence: {
      title: { "pt-BR": "Produção e evidências", en: "Outputs and evidence" },
      href: { "pt-BR": "/publicacoes/", en: "/en/publications/" },
    },
    infrastructure: {
      title: { "pt-BR": "Infraestrutura e capacidades", en: "Infrastructure and capabilities" },
      href: { "pt-BR": "/infraestrutura/", en: "/en/infrastructure/" },
    },
    contact: {
      title: { "pt-BR": "Colaboração e contato", en: "Collaboration and contact" },
      href: { "pt-BR": "/contato/", en: "/en/contact/" },
    },
  },
};

/** Featured projects. Sources: /lps/projetos and the faculty pages. */
export const projects = [
  {
    slug: "atlas-filtragem-online",
    title: {
      "pt-BR": "Filtragem online de elétrons no ATLAS",
      en: "Online electron filtering at ATLAS",
    },
    summary: {
      "pt-BR":
        "Sistema de filtragem online do experimento ATLAS (CERN), com classificação de elétrons baseada em redes neurais — a primeira atuação de um método neural na seleção online de elétrons do experimento.",
      en: "Online filtering system for the ATLAS experiment (CERN), with neural-network electron classification — the first time a neural method took part in the online electron selection of the experiment.",
    },
    area: "fisica-de-altas-energias",
    period: "2018 —",
    partners: { "pt-BR": ["CERN · ATLAS", "UFRJ"], en: ["CERN · ATLAS", "UFRJ"] },
    tags: {
      "pt-BR": ["Trigger", "Redes neurais", "HEP"],
      en: ["Trigger", "Neural networks", "HEP"],
    },
    source: "https://sites.google.com/lps.ufrj.br/lps/projetos",
  },
  {
    slug: "simulacao-de-eventos-hep",
    title: { "pt-BR": "Simulação de eventos em HEP", en: "Event simulation in HEP" },
    summary: {
      "pt-BR":
        "Simulação e reconstrução de eventos em física de altas energias, com orquestração de workloads para grandes volumes de dados.",
      en: "Simulation and reconstruction of high-energy physics events, with workload orchestration for large data volumes.",
    },
    area: "fisica-de-altas-energias",
    period: "2018 —",
    partners: { "pt-BR": ["CERN · ATLAS"], en: ["CERN · ATLAS"] },
    tags: {
      "pt-BR": ["Simulação", "Workflows", "Computação científica"],
      en: ["Simulation", "Workflows", "Scientific computing"],
    },
    source: "https://sites.google.com/lps.ufrj.br/lps/projetos",
  },
  {
    slug: "sonar-passivo-marinha",
    title: {
      "pt-BR": "Sonar passivo e sinais acústicos submarinos",
      en: "Passive sonar and underwater acoustic signals",
    },
    summary: {
      "pt-BR":
        "Detecção de novidade, classificação de contatos, acompanhamento de alvos e desenvolvimento de tecnologia para sonar rebocado, em parceria com o Instituto de Pesquisas da Marinha.",
      en: "Novelty detection, contact classification, target tracking and towed-array sonar technology, in partnership with the Brazilian Navy Research Institute.",
    },
    area: "sonar-e-sinais-acusticos",
    period: "2016 —",
    partners: {
      "pt-BR": ["Marinha do Brasil", "Instituto de Pesquisas da Marinha"],
      en: ["Brazilian Navy", "Navy Research Institute"],
    },
    tags: {
      "pt-BR": ["Sonar", "Defesa", "Deep learning"],
      en: ["Sonar", "Defence", "Deep learning"],
    },
    source: "https://sites.google.com/lps.ufrj.br/namourajr/in%C3%ADcio",
  },
  {
    slug: "sismica-passiva-mero",
    title: {
      "pt-BR": "Sísmica passiva para monitoramento do Campo de Mero",
      en: "Passive seismic monitoring of the Mero field",
    },
    summary: {
      "pt-BR":
        "Monitoramento de reservatórios com técnicas avançadas de análise de sinais, no âmbito do consórcio de Libra.",
      en: "Reservoir monitoring with advanced signal analysis techniques, within the Libra consortium.",
    },
    area: "modelagem-e-analise-de-dados",
    period: "2020 —",
    partners: {
      "pt-BR": ["Consórcio de Libra", "Petrobras"],
      en: ["Libra Consortium", "Petrobras"],
    },
    tags: {
      "pt-BR": ["Óleo e gás", "Sísmica", "Séries temporais"],
      en: ["Oil and gas", "Seismic", "Time series"],
    },
    source: "https://sites.google.com/lps.ufrj.br/namourajr/in%C3%ADcio",
  },
  {
    slug: "gemeo-digital-offshore",
    title: {
      "pt-BR": "Gêmeo digital industrial para segurança de processo",
      en: "Industrial digital twin for process safety",
    },
    summary: {
      "pt-BR":
        "Protótipo de gêmeo digital orientado à segurança de processos em planta offshore, integrando dados, modelos computacionais e aprendizado de máquina.",
      en: "Digital-twin prototype focused on process safety in an offshore plant, integrating data, computational models and machine learning.",
    },
    area: "deteccao-e-monitoramento",
    period: "2021 —",
    partners: { "pt-BR": ["CENPES/Petrobras"], en: ["CENPES/Petrobras"] },
    tags: {
      "pt-BR": ["Gêmeo digital", "MLOps", "Risco operacional"],
      en: ["Digital twin", "MLOps", "Operational risk"],
    },
    source: "https://sites.google.com/lps.ufrj.br/namourajr/in%C3%ADcio",
  },
  {
    slug: "seguranca-de-processos",
    title: {
      "pt-BR": "Degradação de barreiras e modelagem probabilística de acidentes",
      en: "Barrier degradation and probabilistic accident modelling",
    },
    summary: {
      "pt-BR":
        "Incorporação de inteligência artificial à Gestão Dinâmica de Barreiras de Segurança de Processos, com suporte à tomada de decisão em cenários críticos.",
      en: "Bringing artificial intelligence into dynamic process-safety barrier management, supporting decision-making in critical scenarios.",
    },
    area: "deteccao-e-monitoramento",
    period: "2019 —",
    partners: { "pt-BR": ["CENPES/Petrobras"], en: ["CENPES/Petrobras"] },
    tags: {
      "pt-BR": ["Confiabilidade", "Modelagem probabilística", "IA"],
      en: ["Reliability", "Probabilistic modelling", "AI"],
    },
    source: "https://sites.google.com/lps.ufrj.br/namourajr/in%C3%ADcio",
  },
];

/** Faculty. Source: /lps/professores and each professor's public subsite. */
export const people = [
  {
    slug: "natanael-nunes-de-moura-junior",
    name: "Natanael Nunes de Moura Junior",
    initials: "NM",
    role: { "pt-BR": "Coordenador do LPS · Professor", en: "LPS coordinator · Professor" },
    affiliation: {
      "pt-BR":
        "Professor da UFRJ · Coordenador do Laboratório de Processamento de Sinais e do Laboratório de Tecnologia Sonar · Chefe da área de Inteligência Computacional do PEE/COPPE",
      en: "UFRJ professor · Coordinator of the Signal Processing Laboratory and of the Sonar Technology Laboratory · Head of the Computational Intelligence area at PEE/COPPE",
    },
    areas: {
      "pt-BR": [
        "Processamento de sinais",
        "Inteligência computacional",
        "Aprendizado de máquina",
        "Sonar",
      ],
      en: ["Signal processing", "Computational intelligence", "Machine learning", "Sonar"],
    },
    bio: {
      "pt-BR": [
        "Formação integral na UFRJ: graduação em Engenharia Eletrônica e de Computação, mestrado e doutorado em Engenharia Elétrica. Sua trajetória acadêmica e profissional está ligada ao Laboratório de Processamento de Sinais.",
        "No experimento ATLAS/CERN atua em filtragem e estimação de sinais em ambientes de altas taxas, com métodos de estimação de energia, filtragem online e identificação offline de partículas empregando ensemble learning e deep learning.",
        "Na área de defesa, colabora com a Marinha do Brasil em sonar passivo e processamento de sinais acústicos submarinos. Em óleo e gás, atua em projetos de sísmica passiva, segurança de processos e gêmeos digitais industriais.",
      ],
      en: [
        "Full academic training at UFRJ: undergraduate degree in Electronic and Computer Engineering, master and doctorate in Electrical Engineering. His academic and professional career is tied to the Signal Processing Laboratory.",
        "In the ATLAS/CERN experiment he works on filtering and signal estimation in high-rate environments, with energy estimation, online filtering and offline particle identification using ensemble learning and deep learning.",
        "In defence, he collaborates with the Brazilian Navy on passive sonar and underwater acoustic signal processing. In oil and gas, he works on passive seismic, process safety and industrial digital twin projects.",
      ],
    },
    teaching: {
      "pt-BR": [
        "Aprendizado Profundo",
        "Aprendizado por Kernel",
        "Tópicos Especiais em Aprendizado de Máquina",
        "Compactação de Sinais",
      ],
      en: [
        "Deep Learning",
        "Kernel Learning",
        "Special Topics in Machine Learning",
        "Signal Compression",
      ],
    },
    links: [
      { label: "Lattes", href: "http://lattes.cnpq.br/2696393506316122" },
      { label: "GitHub", href: "https://github.com/natmourajr" },
      { label: "LinkedIn", href: "https://www.linkedin.com/in/natanael-moura-junior-425a3294/" },
    ],
    email: "natmourajr@lps.ufrj.br",
    notes: [
      {
        title: { "pt-BR": "Página do professor — disciplinas e material de aula", en: "Faculty page — courses and class materials" },
        href: "https://sites.google.com/lps.ufrj.br/namourajr",
        summary: {
          "pt-BR": "Página mantida pelo professor com a ementa e o material das disciplinas de graduação e pós-graduação.",
          en: "Professor-maintained page with syllabi and material for the undergraduate and graduate courses.",
        },
      },
    ],
  },
  {
    slug: "jose-manoel-de-seixas",
    name: "José Manoel de Seixas",
    initials: "JS",
    role: {
      "pt-BR": "Professor Titular · Coordenador (EMBRAPII)",
      en: "Full Professor · Coordinator (EMBRAPII)",
    },
    affiliation: {
      "pt-BR":
        "Professor Titular da UFRJ · Programa de Engenharia Elétrica da COPPE · Escola Politécnica",
      en: "Full professor at UFRJ · Electrical Engineering Program at COPPE · Polytechnic School",
    },
    areas: {
      "pt-BR": [
        "Inteligência computacional",
        "Calorimetria de altas energias",
        "Tecnologia sonar",
        "Instrumentação eletrônica",
      ],
      en: [
        "Computational intelligence",
        "High-energy calorimetry",
        "Sonar technology",
        "Electronic instrumentation",
      ],
    },
    bio: {
      "pt-BR": [
        "Graduado em Matemática (1979) e em Engenharia Elétrica (1979) pela PUC-Rio, com mestrado (1983) e doutorado (1994) em Engenharia Elétrica pela UFRJ. É Professor Titular da UFRJ.",
        "Atua em circuitos elétricos, magnéticos e eletrônicos, com ênfase em inteligência computacional, calorimetria de altas energias, tecnologia sonar, processamento de sinais e instrumentação eletrônica.",
        "É coordenador do LPS no perfil institucional do laboratório junto à COPPE EMBRAPII.",
      ],
      en: [
        "Degrees in Mathematics (1979) and Electrical Engineering (1979) from PUC-Rio, with a master (1983) and doctorate (1994) in Electrical Engineering from UFRJ. He is a full professor at UFRJ.",
        "He works on electrical, magnetic and electronic circuits, with emphasis on computational intelligence, high-energy calorimetry, sonar technology, signal processing and electronic instrumentation.",
        "He is the LPS coordinator in the laboratory institutional profile at COPPE EMBRAPII.",
      ],
    },
    teaching: {
      "pt-BR": ["Processamento de sinais", "Instrumentação eletrônica"],
      en: ["Signal processing", "Electronic instrumentation"],
    },
    links: [{ label: "Lattes", href: "http://lattes.cnpq.br/1404632471755241" }],
    email: "seixas@lps.ufrj.br",
    notes: [
      {
        title: { "pt-BR": "Página do professor", en: "Faculty page" },
        href: "https://sites.google.com/lps.ufrj.br/seixas",
        summary: {
          "pt-BR": "Página mantida pelo professor, com atuação, disciplinas e orientações.",
          en: "Professor-maintained page with research activity, courses and supervision.",
        },
      },
    ],
  },
  {
    slug: "luiz-pereira-caloba",
    name: "Luiz Pereira Calôba",
    initials: "LC",
    role: { "pt-BR": "Professor Titular (Emérito)", en: "Full Professor (Emeritus)" },
    affiliation: {
      "pt-BR":
        "Professor Titular (Emérito) da UFRJ · COPPE e Escola Politécnica · Membro da Academia Nacional de Engenharia",
      en: "Full professor (Emeritus) at UFRJ · COPPE and Polytechnic School · Member of the Brazilian National Academy of Engineering",
    },
    areas: {
      "pt-BR": [
        "Processamento de sinais",
        "Redes neurais",
        "Filtros ativos",
        "História da ciência",
      ],
      en: ["Signal processing", "Neural networks", "Active filters", "History of science"],
    },
    bio: {
      "pt-BR": [
        "Engenheiro Eletrônico (UFRJ, 1969), M.Sc.EE (UFRJ, 1970), Dr. Ing. (U. Grenoble I, 1974) e Livre Docente (UFRJ, 1987). Ingressou como docente na UFRJ em 1974.",
        "Em 1988 foi o iniciador e coordenador por dez anos da colaboração entre a UFRJ e o CERN na área de processamento de sinais para física de altas energias, colaboração que gerou um grande número de teses e publicações.",
        "Foi presidente do Conselho Nacional de Redes Neurais (1995-1999) e da Sociedade Brasileira de Automática (1999-2001). Recebeu a comenda da Ordem Nacional do Mérito Científico em 2007 e foi eleito membro da Academia Nacional de Engenharia em 2013.",
      ],
      en: [
        "Electronic engineer (UFRJ, 1969), M.Sc.EE (UFRJ, 1970), Dr. Ing. (U. Grenoble I, 1974) and Livre Docente (UFRJ, 1987). He joined UFRJ as a lecturer in 1974.",
        "In 1988 he started and coordinated for ten years the UFRJ–CERN collaboration on signal processing for high-energy physics, a collaboration that produced a large number of theses and publications.",
        "He chaired the Brazilian National Council for Neural Networks (1995-1999) and the Brazilian Society for Automation (1999-2001). He received the National Order of Scientific Merit in 2007 and was elected to the Brazilian National Academy of Engineering in 2013.",
      ],
    },
    teaching: {
      "pt-BR": [
        "CPE-721 Redes Neurais Feedforward",
        "CPE-722 Redes Neurais Não Supervisionadas e Agrupamentos",
      ],
      en: [
        "CPE-721 Feedforward Neural Networks",
        "CPE-722 Unsupervised Neural Networks and Clustering",
      ],
    },
    links: [{ label: "Lattes", href: "http://lattes.cnpq.br/3238659802153968" }],
    email: "caloba@lps.ufrj.br",
    notes: [
      {
        title: { "pt-BR": "Página do professor — redes neurais e circuitos", en: "Faculty page — neural networks and circuits" },
        href: "https://sites.google.com/lps.ufrj.br/caloba",
        summary: {
          "pt-BR": "Página mantida pelo professor, com o histórico das disciplinas de redes neurais que o laboratório oferece desde 1989.",
          en: "Professor-maintained page with the history of the neural-network courses the laboratory has offered since 1989.",
        },
      },
    ],
  },
  {
    slug: "joao-victor-da-fonseca-pinto",
    name: "João Victor da Fonseca Pinto",
    initials: "JP",
    role: {
      "pt-BR": "Professor Adjunto · Pesquisador ATLAS",
      en: "Adjunct professor · ATLAS researcher",
    },
    affiliation: {
      "pt-BR":
        "Professor adjunto do Departamento de Engenharia Eletrônica e de Computação (Poli/UFRJ) · Professor e pesquisador permanente do LPS · Pesquisador associado do experimento ATLAS",
      en: "Adjunct professor at the Department of Electronic and Computer Engineering (Poli/UFRJ) · Permanent professor and researcher at LPS · Associate researcher in the ATLAS experiment",
    },
    areas: {
      "pt-BR": [
        "Simulação e reconstrução de eventos em HEP",
        "Orquestração de workloads",
        "Inteligência artificial",
        "Computação quântica",
      ],
      en: [
        "HEP event simulation and reconstruction",
        "Workload orchestration",
        "Artificial intelligence",
        "Quantum computing",
      ],
    },
    bio: {
      "pt-BR": [
        "Graduado em Engenharia Eletrônica e de Computação pela UFRJ (2016) e doutor em Inteligência Computacional pelo Programa de Engenharia Elétrica da COPPE (2022). Membro da RENAFAE desde 2015.",
        "Desde 2018 é autor de publicações do experimento ATLAS (CERN), colaborando pelo LPS nas áreas de inteligência computacional, filtragem online de eventos e simulação de eventos.",
        "Coordenou (2021-2022) o grupo internacional de pesquisa em filtragem online para elétrons e fótons do ATLAS. Atualmente é pesquisador associado do experimento e professor adjunto na UFRJ.",
      ],
      en: [
        "Degree in Electronic and Computer Engineering from UFRJ (2016) and doctorate in Computational Intelligence from the Electrical Engineering Program at COPPE (2022). Member of RENAFAE since 2015.",
        "Since 2018 he has authored publications of the ATLAS experiment (CERN), collaborating through LPS on computational intelligence, online event filtering and event simulation.",
        "He coordinated (2021-2022) the international ATLAS research group on online filtering for electrons and photons. He is currently an associate researcher in the experiment and an adjunct professor at UFRJ.",
      ],
    },
    teaching: {
      "pt-BR": [
        "CPE-782 Análise de Componentes Independentes (ICA)",
        "CPE-886 Quantum Machine Learning",
        "EEL710 Instrumentação e Técnicas de Medidas",
      ],
      en: [
        "CPE-782 Independent Component Analysis (ICA)",
        "CPE-886 Quantum Machine Learning",
        "EEL710 Instrumentation and Measurement Techniques",
      ],
    },
    links: [
      { label: "Lattes", href: "http://lattes.cnpq.br/3592331377050716" },
      { label: "GitHub", href: "https://github.com/jodafons" },
      { label: "LinkedIn", href: "https://br.linkedin.com/in/jodafons" },
    ],
    email: "jodafons@lps.ufrj.br",
    notes: [
      {
        title: { "pt-BR": "CPE-886 — Quantum Machine Learning", en: "CPE-886 — Quantum Machine Learning" },
        href: "https://sites.google.com/lps.ufrj.br/jodafons/pee/cpe-886-quantum-machine-learning",
        summary: {
          "pt-BR": "Plano de aula, listas e material de apoio publicados pelo professor para a disciplina de pós-graduação.",
          en: "Syllabus, problem sets and supporting material published by the professor for the graduate course.",
        },
      },
      {
        title: { "pt-BR": "Página do professor", en: "Faculty page" },
        href: "https://sites.google.com/lps.ufrj.br/jodafons",
        summary: {
          "pt-BR": "Página mantida pelo professor, com disciplinas, projetos e código.",
          en: "Professor-maintained page with courses, projects and code.",
        },
      },
    ],
  },
  {
    slug: "antonio-carlos-moreirao-de-queiroz",
    name: "Antônio Carlos Moreirão de Queiroz",
    initials: "AQ",
    inMemoriam: true,
    role: { "pt-BR": "In memoriam · Professor Titular", en: "In memoriam · Full professor" },
    affiliation: {
      "pt-BR":
        "Professor Titular do Departamento de Engenharia Eletrônica e de Computação (Poli/UFRJ) e do Programa de Engenharia Elétrica da COPPE",
      en: "Full professor at the Department of Electronic and Computer Engineering (Poli/UFRJ) and at the Electrical Engineering Program at COPPE",
    },
    areas: {
      "pt-BR": [
        "Teoria de circuitos",
        "Teoria eletromagnética",
        "Microeletrônica analógica",
        "História da ciência",
      ],
      en: [
        "Circuit theory",
        "Electromagnetic theory",
        "Analog microelectronics",
        "History of science",
      ],
    },
    bio: {
      "pt-BR": [
        "Bacharel em Engenharia Eletrônica pela UFRJ (1979), mestre (1984) e doutor (1990) em Engenharia Elétrica pela COPPE/UFRJ.",
        "Seus interesses incluíam captação de energia com geradores eletrostáticos, estruturas de filtros de corrente chaveada, projeto de filtros de tempo contínuo de alta frequência, redes de ressonância múltipla, microeletrônica analógica, circuitos de radiofrequência e história da ciência.",
      ],
      en: [
        "Bachelor in Electronic Engineering from UFRJ (1979), master (1984) and doctorate (1990) in Electrical Engineering from COPPE/UFRJ.",
        "His interests included energy harvesting with electrostatic generators, switched-current filter structures, high-frequency continuous-time filter design, multiple resonance networks, analog microelectronics, radio-frequency circuits and the history of science.",
      ],
    },
    teaching: null,
    links: [{ label: "Lattes", href: "http://lattes.cnpq.br/7901653289226815" }],
    email: null,
    notes: [
      {
        title: { "pt-BR": "Arquivo do professor", en: "Faculty archive" },
        href: "https://sites.google.com/lps.ufrj.br/acmq",
        summary: {
          "pt-BR": "Acervo mantido pelo laboratório: teoria de circuitos, microfiltros com capacitores chaveados e história da ciência.",
          en: "Archive kept by the laboratory: circuit theory, switched-capacitor microfilters and the history of science.",
        },
      },
    ],
  },
];

/** Teaching. Sources: faculty subsites (course pages) and the faculty pages. */
export const teaching = {
  graduate: [
    {
      code: "CPE-721",
      title: { "pt-BR": "Redes Neurais Feedforward", en: "Feedforward Neural Networks" },
      professor: "Luiz Pereira Calôba",
      level: { "pt-BR": "Pós-graduação", en: "Graduate" },
    },
    {
      code: "CPE-722",
      title: {
        "pt-BR": "Redes Neurais Não Supervisionadas e Agrupamentos",
        en: "Unsupervised Neural Networks and Clustering",
      },
      professor: "Luiz Pereira Calôba",
      level: { "pt-BR": "Pós-graduação", en: "Graduate" },
    },
    {
      code: "CPE-782",
      title: {
        "pt-BR": "Análise de Componentes Independentes (ICA)",
        en: "Independent Component Analysis (ICA)",
      },
      professor: "João Victor da Fonseca Pinto",
      level: { "pt-BR": "Pós-graduação", en: "Graduate" },
    },
    {
      code: "CPE-886",
      title: { "pt-BR": "Quantum Machine Learning", en: "Quantum Machine Learning" },
      professor: "João Victor da Fonseca Pinto",
      level: { "pt-BR": "Pós-graduação", en: "Graduate" },
    },
    {
      code: "RAP-2026",
      title: { "pt-BR": "Regime de Aluno Participante", en: "Participating Student Scheme" },
      professor: "João Victor da Fonseca Pinto",
      level: { "pt-BR": "Pós-graduação", en: "Graduate" },
    },
  ],
  undergraduate: [
    {
      code: "EEL710",
      title: {
        "pt-BR": "Instrumentação e Técnicas de Medidas",
        en: "Instrumentation and Measurement Techniques",
      },
      professor: "João Victor da Fonseca Pinto",
      level: { "pt-BR": "Graduação", en: "Undergraduate" },
    },
    {
      code: "EEL710",
      title: {
        "pt-BR": "Instrumentação e Técnicas de Medidas",
        en: "Instrumentation and Measurement Techniques",
      },
      professor: "Natanael Nunes de Moura Junior",
      level: { "pt-BR": "Graduação", en: "Undergraduate" },
    },
    {
      code: "—",
      title: { "pt-BR": "Computação I (laboratório)", en: "Computing I (laboratory)" },
      professor: "Natanael Nunes de Moura Junior",
      level: { "pt-BR": "Graduação", en: "Undergraduate" },
    },
    {
      code: "—",
      title: { "pt-BR": "Sistemas Lineares I e II", en: "Linear Systems I and II" },
      professor: "Natanael Nunes de Moura Junior",
      level: { "pt-BR": "Graduação", en: "Undergraduate" },
    },
    {
      code: "—",
      title: { "pt-BR": "Teoria Eletromagnética II", en: "Electromagnetic Theory II" },
      professor: "Natanael Nunes de Moura Junior",
      level: { "pt-BR": "Graduação", en: "Undergraduate" },
    },
  ],
  materials: [
    {
      title: {
        "pt-BR": "Páginas de disciplina no site do professor",
        en: "Course pages on the professor site",
      },
      body: {
        "pt-BR":
          "Planos de aula, listas e material de apoio das disciplinas CPE-721, CPE-722, CPE-782, CPE-886 e EEL710 são publicados nas páginas próprias dos professores, mantidas fora deste domínio.",
        en: "Syllabi, problem sets and supporting material for CPE-721, CPE-722, CPE-782, CPE-886 and EEL710 are published on the professors' own pages, maintained outside this domain.",
      },
      link: {
        href: "https://sites.google.com/lps.ufrj.br/jodafons/pee/cpe-886-quantum-machine-learning",
        label: { "pt-BR": "Exemplo: CPE-886", en: "Example: CPE-886" },
      },
    },
    {
      title: { "pt-BR": "Formação continuada", en: "Continuing education" },
      body: {
        "pt-BR":
          "A atuação do laboratório abrange a iniciação científica júnior com estudantes do ensino médio e técnico, a graduação na Poli/UFRJ e o pós-doutorado.",
        en: "The laboratory works from junior research initiation with secondary and technical students, through undergraduate teaching at Poli/UFRJ, to post-doctoral supervision.",
      },
      link: null,
    },
  ],
};

/** Facilities and capabilities. Source: COPPE EMBRAPII institutional profile. */
export const infrastructure = {
  facts: [
    { value: "310 m²", label: { "pt-BR": "Área construída", en: "Floor area" } },
    { value: "≈40", label: { "pt-BR": "Computadores pessoais", en: "Personal computers" } },
    { value: "1", label: { "pt-BR": "Sala acústica", en: "Acoustic room" } },
    {
      value: "1",
      label: { "pt-BR": "Sala de palestras e reuniões", en: "Lecture and meeting room" },
    },
  ],
  capabilities: [
    {
      "pt-BR": "Instrumentação eletrônica e processamento analógico e digital de sinais",
      en: "Electronic instrumentation and analog and digital signal processing",
    },
    {
      "pt-BR": "Engenharia de software e desenvolvimento de dispositivos programáveis",
      en: "Software engineering and programmable device development",
    },
    {
      "pt-BR": "Equipamentos de instrumentação para desenvolvimento e análise de sistemas",
      en: "Instrumentation equipment for system development and analysis",
    },
    {
      "pt-BR": "Domínio computacional próprio (domínio LPS) com cerca de 40 postos de trabalho",
      en: "Own computing domain (LPS domain) with about 40 workstations",
    },
  ],
  partners: [
    "Petrobras",
    "Eletrobras",
    "Embraer",
    "Inmetro",
    "Empresa de Pesquisa Energética",
    "OLX",
    "National Instruments",
    "Samsung",
    "Murabei",
  ],
  funders: ["CNPq", "CAPES", "FAPERJ"],
  collaboration: [
    {
      title: { "pt-BR": "Pesquisa contratada e P&D", en: "Contract research and R&D" },
      body: {
        "pt-BR":
          "Projetos de alta relevância com empresas, avançando a capacidade de produção da indústria nacional com inovação.",
        en: "High-relevance projects with companies, advancing the innovation capacity of national industry.",
      },
    },
    {
      title: { "pt-BR": "Formação de pessoal", en: "Talent development" },
      body: {
        "pt-BR":
          "Egressos do LPS atendem às demandas do mercado de trabalho com alta qualificação; empresas de base tecnológica foram criadas no âmbito do laboratório.",
        en: "LPS graduates meet labour-market demands with high qualification; technology-based companies have been created within the laboratory.",
      },
    },
    {
      title: { "pt-BR": "Cooperação internacional", en: "International cooperation" },
      body: {
        "pt-BR":
          "Colaborações com pesquisadores de energia, física experimental de altas energias, computação quântica, defesa, medicina e qualidade de dados.",
        en: "Collaborations with researchers in energy, experimental high-energy physics, quantum computing, defence, medicine and data quality.",
      },
    },
  ],
};

/** News and events. Sources: faculty pages (dated facts) and the opportunities page. */
export const news = [
  {
    slug: "professor-adjunto-2025",
    kicker: { "pt-BR": "Pessoas", en: "People" },
    title: {
      "pt-BR": "João Victor da Fonseca Pinto assume como professor adjunto na UFRJ",
      en: "João Victor da Fonseca Pinto joins UFRJ as adjunct professor",
    },
    date: "2025-01-01",
    dateLabel: { "pt-BR": "2025", en: "2025" },
    summary: {
      "pt-BR":
        "Pesquisador do ATLAS e professor permanente do LPS passa a acumular a docência no Departamento de Engenharia Eletrônica e de Computação da Escola Politécnica.",
      en: "ATLAS researcher and permanent LPS professor now also teaches at the Department of Electronic and Computer Engineering of the Polytechnic School.",
    },
    source: "https://sites.google.com/lps.ufrj.br/jodafons/in%C3%ADcio",
  },
  {
    slug: "coordenacao-filtragem-atlas",
    kicker: { "pt-BR": "Pesquisa", en: "Research" },
    title: {
      "pt-BR": "Coordenação do grupo de filtragem online de elétrons e fótons do ATLAS",
      en: "Coordination of the ATLAS electron and photon online filtering group",
    },
    date: "2021-06-01",
    dateLabel: { "pt-BR": "2021-2022", en: "2021-2022" },
    summary: {
      "pt-BR":
        "O LPS coordenou o grupo internacional de pesquisa em filtragem online para elétrons e fótons do experimento ATLAS.",
      en: "LPS coordinated the international research group on online filtering for electrons and photons in the ATLAS experiment.",
    },
    source: "https://sites.google.com/lps.ufrj.br/jodafons/in%C3%ADcio",
  },
  {
    slug: "redes-neurais-selecao-online-atlas",
    kicker: { "pt-BR": "Pesquisa", en: "Research" },
    title: {
      "pt-BR": "Primeira atuação de redes neurais na seleção online de elétrons do ATLAS",
      en: "First neural network in ATLAS online electron selection",
    },
    date: "2019-01-01",
    dateLabel: { "pt-BR": "2019", en: "2019" },
    summary: {
      "pt-BR":
        "Um esforço colaborativo atualizou a classificação online de elétrons baseada em redes neurais artificiais — contribuição de grande importância para o experimento.",
      en: "A collaborative effort upgraded the online electron classification based on artificial neural networks — a contribution of major importance to the experiment.",
    },
    source: "https://sites.google.com/lps.ufrj.br/jodafons/in%C3%ADcio",
  },
  {
    slug: "colaboracao-ufrj-cern",
    kicker: { "pt-BR": "História", en: "History" },
    title: {
      "pt-BR": "Colaboração UFRJ–CERN completa mais de três décadas",
      en: "UFRJ–CERN collaboration passes three decades",
    },
    date: "1988-01-01",
    dateLabel: { "pt-BR": "Desde 1988", en: "Since 1988" },
    summary: {
      "pt-BR":
        "Iniciada em 1988 no LPS, a colaboração em processamento de sinais para física de altas energias gerou um grande número de teses e publicações e segue ativa.",
      en: "Started in 1988 at LPS, the collaboration on signal processing for high-energy physics has produced a large number of theses and publications and remains active.",
    },
    source: "https://sites.google.com/lps.ufrj.br/caloba/in%C3%ADcio",
  },
  {
    slug: "fundacao-do-laboratorio",
    kicker: { "pt-BR": "História", en: "History" },
    title: {
      "pt-BR": "Fundação do Laboratório de Processamento de Sinais",
      en: "Founding of the Signal Processing Laboratory",
    },
    date: "1996-01-01",
    dateLabel: { "pt-BR": "1996", en: "1996" },
    summary: {
      "pt-BR":
        "O LPS é fundado na Universidade Federal do Rio de Janeiro, dedicado a atividades de ensino, pesquisa e extensão.",
      en: "LPS is founded at the Federal University of Rio de Janeiro, dedicated to teaching, research and extension activities.",
    },
    source: "https://sites.google.com/lps.ufrj.br/lps/in%C3%ADcio",
  },
];

/** Events. The public source lists none, so the agenda states that honestly. */
export const events = [];

/** Publications. The public source has no authoritative feed; the page says so. */
export const publications = {
  note: {
    "pt-BR":
      "O LPS não mantém, hoje, um feed público de publicações sob responsabilidade do laboratório. A produção científica associada ao laboratório está registrada nos currículos Lattes dos professores e nas publicações das colaborações internacionais de que o laboratório participa.",
    en: "LPS does not currently maintain a public publications feed under the laboratory's responsibility. The scientific output associated with the laboratory is recorded in the professors' Lattes CVs and in the publications of the international collaborations the laboratory takes part in.",
  },
  channels: [
    {
      title: { "pt-BR": "Currículos Lattes", en: "Lattes CVs" },
      body: {
        "pt-BR": "Registro individual e atualizado da produção de cada professor.",
        en: "Individual, up-to-date record of each professor's output.",
      },
      links: [
        { label: "José Manoel de Seixas", href: "http://lattes.cnpq.br/1404632471755241" },
        { label: "Luiz Pereira Calôba", href: "http://lattes.cnpq.br/3238659802153968" },
        { label: "Natanael Nunes de Moura Junior", href: "http://lattes.cnpq.br/2696393506316122" },
        { label: "João Victor da Fonseca Pinto", href: "http://lattes.cnpq.br/3592331377050716" },
      ],
    },
    {
      title: { "pt-BR": "Colaboração ATLAS (CERN)", en: "ATLAS collaboration (CERN)" },
      body: {
        "pt-BR":
          "Publicações do experimento ATLAS, que reúnem a contribuição do laboratório em filtragem online, simulação e reconstrução de eventos.",
        en: "Publications of the ATLAS experiment, which carry the laboratory's contribution to online filtering, event simulation and reconstruction.",
      },
      links: [{ label: "ATLAS — CERN", href: "https://home.cern/science/experiments/atlas" }],
    },
    {
      title: { "pt-BR": "Código e dados abertos", en: "Code and open data" },
      body: {
        "pt-BR":
          "Repositórios públicos da organização LPS no GitHub. Mantidos externamente, sem licença declarada — link, não cópia.",
        en: "Public repositories of the LPS GitHub organisation. Externally maintained, with no declared licence — linked, not copied.",
      },
      links: [{ label: "github.com/lps-ufrj-br", href: "https://github.com/lps-ufrj-br" }],
    },
  ],
};

/** Opportunities. Source says calls are announced when open; nothing is open today. */
export const opportunities = {
  notice: {
    "pt-BR":
      'Não há chamada aberta publicada no momento. A página de oportunidades do laboratório no site institucional anterior registra "em breve".',
    en: 'No call is open at the moment. The laboratory\'s opportunities page on the previous institutional site reads "coming soon".',
  },
  tracks: [
    {
      title: { "pt-BR": "Iniciação científica", en: "Undergraduate research" },
      body: {
        "pt-BR":
          "Para estudantes de graduação da UFRJ interessados em processamento de sinais, aprendizado de máquina e instrumentação.",
        en: "For UFRJ undergraduate students interested in signal processing, machine learning and instrumentation.",
      },
    },
    {
      title: { "pt-BR": "Mestrado e doutorado", en: "Master and doctorate" },
      body: {
        "pt-BR":
          "Seleção pelo Programa de Engenharia Elétrica da COPPE, com possibilidade de orientação na área de Inteligência Computacional.",
        en: "Selection through the Electrical Engineering Program at COPPE, with supervision available in the Computational Intelligence area.",
      },
    },
    {
      title: { "pt-BR": "Iniciação científica júnior", en: "Junior research initiation" },
      body: {
        "pt-BR":
          "O laboratório atua com estudantes do ensino médio e técnico em atividades de formação.",
        en: "The laboratory works with secondary and technical students in training activities.",
      },
    },
    {
      title: { "pt-BR": "Pós-doutorado e colaborações", en: "Post-doctorate and collaborations" },
      body: {
        "pt-BR":
          "Pesquisadores de pós-doutorado participam das atividades do laboratório, com financiamento de agências de fomento.",
        en: "Post-doctoral researchers take part in the laboratory's activities, with funding from research agencies.",
      },
    },
  ],
  howto: {
    "pt-BR": [
      "Acompanhe as chamadas publicadas nesta página e nos canais institucionais do PEE/COPPE.",
      "Contate a secretaria do laboratório para verificar disponibilidade de vagas e requisitos do projeto.",
      "Para ingresso em mestrado ou doutorado, o caminho formal é a seleção do Programa de Engenharia Elétrica da COPPE/UFRJ.",
    ],
    en: [
      "Follow the calls published on this page and on the PEE/COPPE institutional channels.",
      "Contact the laboratory office to check availability and project requirements.",
      "For master or doctoral admission, the formal route is the selection process of the Electrical Engineering Program at COPPE/UFRJ.",
    ],
  },
};

/** About page: mission, vision, values, history. Source: /lps/sobre/sobre. */
export const about = {
  history: [
    {
      year: "1996",
      text: {
        "pt-BR":
          "Fundação do Laboratório de Processamento de Sinais na UFRJ, dedicado a ensino, pesquisa e extensão.",
        en: "Founding of the Signal Processing Laboratory at UFRJ, dedicated to teaching, research and extension.",
      },
    },
    {
      year: "1988",
      text: {
        "pt-BR":
          "Início da colaboração UFRJ–CERN em processamento de sinais para física de altas energias, iniciada e coordenada por dez anos pelo Prof. Luiz Pereira Calôba.",
        en: "Start of the UFRJ–CERN collaboration on signal processing for high-energy physics, started and coordinated for ten years by Prof. Luiz Pereira Calôba.",
      },
    },
    {
      year: "1989",
      text: {
        "pt-BR":
          "Cursos de redes neurais iniciados, com forte atuação de divulgação da área nas engenharias no Brasil.",
        en: "Neural network courses begin, with strong dissemination of the field across Brazilian engineering.",
      },
    },
    {
      year: "2018",
      text: {
        "pt-BR":
          "O LPS passa a assinar publicações do experimento ATLAS (CERN) e atua na classificação online de elétrons com redes neurais.",
        en: "LPS begins signing ATLAS experiment (CERN) publications and works on online electron classification with neural networks.",
      },
    },
    {
      year: "2021",
      text: {
        "pt-BR":
          "Coordenação do grupo internacional de filtragem online para elétrons e fótons do ATLAS.",
        en: "Coordination of the international ATLAS online filtering group for electrons and photons.",
      },
    },
    {
      year: "2025",
      text: {
        "pt-BR":
          "Docente do laboratório assume como professor adjunto no Departamento de Engenharia Eletrônica e de Computação da Poli/UFRJ.",
        en: "A laboratory lecturer joins the Department of Electronic and Computer Engineering at Poli/UFRJ as adjunct professor.",
      },
    },
  ],
  mission: {
    "pt-BR":
      "Gerar conhecimento e inovação tecnológica por meio de atividades de ensino, pesquisa e extensão, formando profissionais altamente qualificados em todos os níveis, do ensino médio ao pós-doutorado, e atuando como agente de avanço científico e tecnológico em parcerias estratégicas com os setores público e privado.",
    en: "To generate knowledge and technological innovation through teaching, research and extension, training highly qualified professionals at every level from secondary school to post-doctorate, and acting as an agent of scientific and technological advance in strategic partnerships with the public and private sectors.",
  },
  vision: {
    "pt-BR":
      "Ser um laboratório de referência nacional e internacional no desenvolvimento de soluções inovadoras em engenharia, com foco em inteligência computacional, processamento de sinais e desenvolvimento de software, aplicando modelos de aprendizado de máquina em ambientes complexos — sistemas críticos, cenários ruidosos e aplicações que exigem robustez e interpretabilidade.",
    en: "To be a national and international reference laboratory in the development of innovative engineering solutions, focused on computational intelligence, signal processing and software development, applying machine learning models in complex environments — critical systems, noisy scenarios and applications demanding robustness and interpretability.",
  },
  values: [
    {
      title: { "pt-BR": "Inovação", en: "Innovation" },
      body: {
        "pt-BR":
          "Vocação para a inovação em engenharia elétrica e computação, inclusive com a criação de empresas de base tecnológica.",
        en: "A vocation for innovation in electrical engineering and computing, including the creation of technology-based companies.",
      },
    },
    {
      title: { "pt-BR": "Colaboração", en: "Collaboration" },
      body: {
        "pt-BR":
          "Parcerias sólidas com pesquisadores e instituições nacionais e internacionais em energia, defesa, medicina e física.",
        en: "Solid partnerships with national and international researchers and institutions in energy, defence, medicine and physics.",
      },
    },
    {
      title: { "pt-BR": "Excelência", en: "Excellence" },
      body: {
        "pt-BR": "Busca da excelência técnica, científica e de inovação em todas as atividades.",
        en: "Pursuit of technical, scientific and innovation excellence in every activity.",
      },
    },
    {
      title: { "pt-BR": "Formação", en: "Education" },
      body: {
        "pt-BR":
          "Preparação de profissionais altamente qualificados para a academia e a indústria, em ambiente estimulante.",
        en: "Preparation of highly qualified professionals for academia and industry, in a stimulating environment.",
      },
    },
  ],
  identity: {
    "pt-BR": [
      "A marca do laboratório é composta por um sinal (a forma de onda) e pela sigla LPS, acompanhadas do nome por extenso e do descritor Inteligência Computacional.",
      "O sinal representa o objeto de trabalho do laboratório: a leitura, o tratamento e a interpretação de sinais. O azul é a cor institucional herdada da identidade original; o degradê acompanha a variação de amplitude da forma de onda.",
      "As aplicações oficiais — sinal em azul e branco, marca completa em azul e branco, além do pacote completo da marca — são mantidas pelos responsáveis pela comunicação do laboratório.",
    ],
    en: [
      "The laboratory mark is made of a signal (the waveform) and the LPS lettering, accompanied by the full name and the Computational Intelligence descriptor.",
      "The signal represents the laboratory's object of work: reading, treating and interpreting signals. Blue is the institutional colour inherited from the original identity; the gradient follows the amplitude variation of the waveform.",
      "The official applications — blue and white signal, blue and white full mark, plus the complete brand package — are maintained by those responsible for laboratory communications.",
    ],
  },
};

/** Partner institutions shown in the marquee on the about page. */
export const partnerLogos = [
  { name: "Eletrobras Cepel", file: "eletrobras-cepel.png" },
  { name: "Inmetro", file: "inmetro.png" },
  { name: "IPqM — Instituto de Pesquisas da Marinha", file: "ipqm.png" },
  { name: "IRD — Instituto de Radioproteção e Dosimetria", file: "ird.png" },
  { name: "CERN", file: "cern.png" },
  { name: "PPGEE — Universidade Federal da Bahia", file: "ppgee-ufba.png" },
  { name: "UFF — Universidade Federal Fluminense", file: "uff.png" },
  { name: "UFJF — Universidade Federal de Juiz de Fora", file: "ufjf.png" },
  { name: "Argonne National Laboratory", file: "argonne.png" },
  { name: "Brookhaven National Laboratory", file: "brookhaven.png" },
  { name: "CBPF — Centro Brasileiro de Pesquisas Físicas", file: "cbpf.png" },
  { name: "CEPARM", file: "ceparm.png" },
  { name: "Embrapa", file: "embrapa.png" },
  { name: "HUCFF — Hospital Universitário Clementino Fraga Filho", file: "hucff.png" },
  { name: "Petrobras", file: "petrobras.gif" },
  { name: "Marinha do Brasil", file: "marinha.png" },
  { name: "RENAFAE", file: "renafae.png" },
  { name: "Rede-TB", file: "rede-tb.png" },
];

/** Contact page. */
export const contact = {
  channels: [
    {
      title: { "pt-BR": "Secretaria do laboratório", en: "Laboratory office" },
      body: {
        "pt-BR": "Para assuntos administrativos, projetos, estágios e visitas técnicas.",
        en: "For administrative matters, projects, internships and technical visits.",
      },
      items: [{ label: "secretaria@lps.ufrj.br", href: "mailto:secretaria@lps.ufrj.br" }],
    },
    {
      title: { "pt-BR": "Coordenação", en: "Coordination" },
      body: {
        "pt-BR": "Coordenação do laboratório no perfil institucional da COPPE EMBRAPII.",
        en: "Laboratory coordination in the COPPE EMBRAPII institutional profile.",
      },
      items: [
        { label: "Prof. José Manoel de Seixas", href: "mailto:seixas@lps.ufrj.br" },
        { label: "(21) 3938-8205", href: "tel:+552139388205" },
      ],
    },
    {
      title: { "pt-BR": "Imprensa e comunicação", en: "Press and communication" },
      body: {
        "pt-BR":
          "Pedidos de entrevista e uso da marca devem ser encaminhados à secretaria, que responde em nome do laboratório.",
        en: "Interview requests and brand usage must be sent to the office, which answers on behalf of the laboratory.",
      },
      items: [{ label: "secretaria@lps.ufrj.br", href: "mailto:secretaria@lps.ufrj.br" }],
    },
  ],
  buildings: [
    {
      title: { "pt-BR": "Sede do laboratório", en: "Laboratory headquarters" },
      address:
        "Av. Athos da Silveira Ramos, 149 — Centro de Tecnologia, Bloco H, sala 220 — Ilha do Fundão — Rio de Janeiro/RJ — CEP 21941-914",
      maps: "https://www.google.com/maps/search/?api=1&query=Centro+de+Tecnologia+Bloco+H+UFRJ",
    },
    {
      title: { "pt-BR": "Referência do PEE/COPPE", en: "PEE/COPPE reference" },
      address:
        "Av. Horácio Macedo, 2030 — Centro de Tecnologia, Bloco H — Cidade Universitária — Rio de Janeiro/RJ — CEP 21941-598",
      maps: "https://www.google.com/maps/search/?api=1&query=COPPE+UFRJ+Bloco+H",
    },
  ],
};

/** Accessibility statement inputs — honest about the current state. */
export const accessibility = {
  statement: {
    "pt-BR":
      "O site do LPS é publicado com o objetivo de atender à WCAG 2.2 nível AA e ao eMAG: contraste mínimo de 4,5:1 para texto, foco visível, navegação completa por teclado, respeito à preferência de movimento reduzido e reflow em 320 CSS px com 200% de zoom. A verificação é feita a cada publicação.",
    en: "The LPS website is published with the goal of meeting WCAG 2.2 level AA and eMAG: minimum 4.5:1 contrast for text, visible focus, full keyboard navigation, respect for reduced-motion preference and reflow at 320 CSS px with 200% zoom. Verification runs on every release.",
  },
  contact: {
    "pt-BR":
      "Um canal formal de relato de problemas de acessibilidade ainda não foi nomeado pela instituição. Até que exista, a secretaria do laboratório recebe relatos pelo e-mail",
    en: "A formal channel for reporting accessibility problems has not yet been named by the institution. Until it exists, the laboratory office receives reports at",
  },
};

/** Privacy: the site sets no cookies for anonymous visitors and runs no third-party code. */
export const privacy = {
  facts: [
    {
      "pt-BR": "Nenhum cookie é gravado para visitantes anônimos.",
      en: "No cookie is set for anonymous visitors.",
    },
    {
      "pt-BR":
        "Nenhuma requisição a terceiros é feita em tempo de execução: fontes, imagens e scripts são servidos pelo próprio domínio.",
      en: "No third-party request is made at runtime: fonts, images and scripts are served from this domain.",
    },
    {
      "pt-BR": "Nenhuma ferramenta de análise, publicidade ou rastreamento é embarcada.",
      en: "No analytics, advertising or tracking tool is embedded.",
    },
    {
      "pt-BR":
        "Dados pessoais de visitantes não são coletados: não há formulário público neste site.",
      en: "No personal data is collected from visitors: there is no public form on this site.",
    },
  ],
  note: {
    "pt-BR":
      "Esta página descreve o comportamento técnico do site. A base legal para o tratamento de dados pessoais em outros contextos institucionais é de responsabilidade da universidade.",
    en: "This page describes the technical behaviour of the site. The legal basis for personal-data processing in other institutional contexts is the university's responsibility.",
  },
};

/**
 * Member surfaces: the public sign-in page and the signed-in working area.
 *
 * Every string here is copy the theme renders verbatim (`AuthSurfaces` and
 * `MemberSurfaces`), so the wording, the labels and the state messages are
 * reviewed once, in one language-pair, instead of being spelled inline in the
 * markup builders.
 *
 * The two security facts the copy must state, because they are true of the
 * implementation: the entry point is the site's own form, never a bare
 * wp-login.php redirect, and privileged roles require a second factor before the
 * session is granted any editing capability.
 */
export const member = {
  signIn: {
    kicker: { "pt-BR": "Acesso restrito", en: "Restricted access" },
    title: { "pt-BR": "Entrar no site", en: "Sign in" },
    lead: {
      "pt-BR":
        "A área restrita é destinada a professores, à equipe do laboratório e aos administradores do site. O acesso é individual, exige segundo fator para papéis privilegiados e fica registrado para auditoria.",
      en: "The restricted area is for professors, laboratory staff and site administrators. Access is individual, requires a second factor for privileged roles and is recorded for audit.",
    },
    form: {
      legend: { "pt-BR": "Identificação", en: "Credentials" },
      user: { "pt-BR": "Usuário ou e-mail", en: "Username or email" },
      password: { "pt-BR": "Senha", en: "Password" },
      remember: { "pt-BR": "Manter a sessão neste navegador", en: "Keep me signed in on this device" },
      submit: { "pt-BR": "Entrar", en: "Sign in" },
      lost: { "pt-BR": "Esqueci minha senha", en: "I forgot my password" },
      hint: {
        "pt-BR":
          "Use sempre um navegador confiável: a sessão dá acesso à edição de conteúdo do laboratório.",
        en: "Use a trusted browser: the session grants access to the laboratory's content editing.",
      },
    },
    error: {
      title: { "pt-BR": "Não foi possível entrar", en: "Sign-in failed" },
      credentials: {
        "pt-BR": "Usuário ou senha não conferem. Confira os dados e tente novamente.",
        en: "The username or password does not match. Check the details and try again.",
      },
      empty: {
        "pt-BR": "Preencha usuário e senha para continuar.",
        en: "Fill in both the username and the password to continue.",
      },
      secondFactor: {
        "pt-BR":
          "A conta exige segundo fator. Conclua a verificação no aplicativo autenticador para esta sessão valer como professor ou administrador.",
        en: "This account requires a second factor. Complete the verification in the authenticator app so the session counts as professor or administrator.",
      },
    },
    audience: {
      title: { "pt-BR": "Quem entra por aqui", en: "Who signs in here" },
      items: [
        {
          name: { "pt-BR": "Professores e pesquisadores", en: "Professors and researchers" },
          detail: {
            "pt-BR":
              "Mantêm a própria página: disciplinas que lecionam, turmas, aulas e notas. O que é publicado aparece na página pública do professor.",
            en: "Maintain their own page: the courses they teach, classes, lessons and notes. What is published appears on the professor's public page.",
          },
        },
        {
          name: { "pt-BR": "Equipe do laboratório", en: "Laboratory staff" },
          detail: {
            "pt-BR": "Mantêm notícias, projetos, publicações e o acervo de materiais.",
            en: "Maintain news, projects, publications and the material archive.",
          },
        },
        {
          name: { "pt-BR": "Administradores do site", en: "Site administrators" },
          detail: {
            "pt-BR": "Revisam o conteúdo, administram papéis e cuidam da configuração do site.",
            en: "Review content, manage roles and take care of the site configuration.",
          },
        },
      ],
    },
    help: {
      title: { "pt-BR": "Primeiro acesso e ajuda", en: "First access and help" },
      steps: [
        {
          "pt-BR": "A conta é criada pela secretaria do laboratório, com o e-mail institucional.",
          en: "The account is created by the laboratory office, with the institutional email address.",
        },
        {
          "pt-BR":
            "No primeiro acesso, o segundo fator é cadastrado em um aplicativo autenticador; sem ele o papel de professor ou administrador não é concedido.",
          en: "On first access, the second factor is enrolled in an authenticator app; without it the professor or administrator role is not granted.",
        },
        {
          "pt-BR": "A recuperação de senha é feita pelo link abaixo, no domínio do próprio site.",
          en: "Password recovery uses the link below, on the site's own domain.",
        },
        {
          "pt-BR":
            "Problemas de acesso são tratados por secretaria@lps.ufrj.br; nunca compartilhe a senha.",
          en: "Access problems are handled by secretaria@lps.ufrj.br; never share your password.",
        },
      ],
    },
    notice: {
      "pt-BR":
        "Pré-visualização de projeto: este formulário é uma demonstração e não autentica ninguém. Na versão publicada ele envia as credenciais ao WordPress, no mesmo domínio, e a resposta é a área de trabalho do professor.",
      en: "Design preview: this form is a demonstration and authenticates nobody. In the published version it posts the credentials to WordPress on the same domain, and the response is the professor's working area.",
    },
  },
  area: {
    kicker: { "pt-BR": "Área do professor", en: "Faculty area" },
    title: { "pt-BR": "Sua área de trabalho", en: "Your working area" },
    lead: {
      "pt-BR":
        "Aqui ficam a sua página pública, as disciplinas que você leciona e o material de aula que você publica. O que você escreve aqui aparece no site sem passar por outra pessoa.",
      en: "Your public page, the courses you teach and the lesson material you publish live here. What you write here appears on the site without passing through anyone else.",
    },
    demo: {
      "pt-BR":
        "Demonstração: os dados de conta abaixo são fictícios. As disciplinas vêm do repositório público do laboratório e o material listado é o que já está publicado.",
      en: "Demonstration: the account details below are fictitious. The courses come from the laboratory's public repository and the material listed is what is already published.",
    },
    account: {
      title: { "pt-BR": "Conta nesta sessão", en: "Account in this session" },
      name: { "pt-BR": "Sessão de demonstração", en: "Demonstration session" },
      role: { "pt-BR": "Papel", en: "Role" },
      roleValue: { "pt-BR": "Professor (demonstração)", en: "Professor (demonstration)" },
      secondFactor: { "pt-BR": "Segundo fator", en: "Second factor" },
      secondFactorValue: { "pt-BR": "Ativo", en: "Active" },
      scope: { "pt-BR": "Alcance", en: "Scope" },
      scopeValue: { "pt-BR": "Própria página, disciplinas e material de aula", en: "Own page, courses and lesson material" },
      signOut: { "pt-BR": "Encerrar sessão", en: "Sign out" },
      adminLink: { "pt-BR": "Administração do site", en: "Site administration" },
    },
    page: {
      title: { "pt-BR": "Sua página pública", en: "Your public page" },
      body: {
        "pt-BR":
          "É a página que qualquer visitante vê. Você edita a biografia, as linhas de atuação e os contatos; a lista de disciplinas vem do cadastro da disciplina.",
        en: "This is the page any visitor sees. You edit the biography, the research areas and the contact details; the course list comes from the course record.",
      },
      edit: { "pt-BR": "Editar minha página", en: "Edit my page" },
      view: { "pt-BR": "Ver a página publicada", en: "View the published page" },
    },
    subjects: {
      title: { "pt-BR": "Disciplinas que você leciona", en: "Courses you teach" },
      empty: {
        "pt-BR": "Nenhuma disciplina está associada a esta conta neste semestre.",
        en: "No course is associated with this account this term.",
      },
      columns: {
        code: { "pt-BR": "Código", en: "Code" },
        course: { "pt-BR": "Disciplina", en: "Course" },
        level: { "pt-BR": "Nível", en: "Level" },
        term: { "pt-BR": "Período", en: "Term" },
        state: { "pt-BR": "Situação", en: "State" },
        action: { "pt-BR": "Ação", en: "Action" },
      },
      action: { "pt-BR": "Abrir a turma", en: "Open the class" },
      state: { "pt-BR": "Publicada", en: "Published" },
    },
    notes: {
      title: { "pt-BR": "Aulas e notas", en: "Lessons and notes" },
      empty: {
        "pt-BR":
          "Você ainda não publicou material neste semestre. O material publicado aparece também na sua página pública.",
        en: "You have not published material this term. Published material also appears on your public page.",
      },
      form: {
        legend: { "pt-BR": "Publicar uma nota de aula", en: "Publish a lesson note" },
        title: { "pt-BR": "Título", en: "Title" },
        course: { "pt-BR": "Disciplina", en: "Course" },
        summary: { "pt-BR": "Resumo", en: "Summary" },
        link: { "pt-BR": "Endereço do material", en: "Material address" },
        visibility: { "pt-BR": "Visível na página pública", en: "Visible on the public page" },
        submit: { "pt-BR": "Publicar", en: "Publish" },
        hint: {
          "pt-BR": "O arquivo é anexado na biblioteca de mídia; aqui entra o endereço depois do envio.",
          en: "The file is attached in the media library; this field takes the address after upload.",
        },
      },
    },
    news: {
      title: { "pt-BR": "Suas notícias", en: "Your news" },
      empty: { "pt-BR": "Nenhuma notícia publicada por esta conta.", en: "No news published by this account." },
      action: { "pt-BR": "Escrever uma notícia", en: "Write a news item" },
    },
    tasks: {
      title: { "pt-BR": "Tarefas da semana", en: "This week's tasks" },
      items: [
        {
          "pt-BR": "Revisar a ementa de CPE-886 antes do início do período.",
          en: "Review the CPE-886 syllabus before the term starts.",
        },
        {
          "pt-BR": "Publicar a primeira nota de aula da turma de graduação.",
          en: "Publish the first lesson note for the undergraduate class.",
        },
        {
          "pt-BR": "Confirmar a lista de orientandos do semestre.",
          en: "Confirm the list of supervisees for the term.",
        },
      ],
    },
  },
  personPage: {
    kicker: { "pt-BR": "Pessoas", en: "People" },
    about: { "pt-BR": "Quem é", en: "Background" },
    areas: { "pt-BR": "Linhas de atuação", en: "Research areas" },
    subjects: { "pt-BR": "Disciplinas", en: "Courses" },
    subjectsAreas: {
      "pt-BR": "Áreas em que leciona, segundo a página do professor:",
      en: "Areas taught, according to the professor's own page:",
    },
    subjectsEmpty: {
      "pt-BR":
        "A oferta com código e turma é registrada no sistema de ensino da UFRJ. Quando houver uma oferta do laboratório registrada aqui, ela aparece nesta seção.",
      en: "The coded offering with its class group is recorded in the UFRJ teaching system. When a laboratory offering is recorded here, it appears in this section.",
    },
    notes: { "pt-BR": "Aulas e notas", en: "Lessons and notes" },
    notesEmpty: {
      "pt-BR":
        "O material de aula é publicado pelo professor na área restrita. Quando houver material deste professor, ele aparece aqui.",
      en: "Lesson material is published by the professor in the restricted area. When this professor publishes material, it appears here.",
    },
    where: { "pt-BR": "Onde encontrar", en: "Where to find" },
    owner: {
      "pt-BR": "É este professor? Entre para editar esta página.",
      en: "Is this you? Sign in to edit this page.",
    },
    memoriam: {
      "pt-BR":
        "Página mantida em memória. O acervo do professor continua disponível no laboratório.",
      en: "Page kept in memoriam. The professor's archive remains available at the laboratory.",
    },
    contact: { "pt-BR": "Contato", en: "Contact" },
  },
};

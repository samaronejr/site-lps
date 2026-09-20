import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";
import { checkDocumentation } from "../../docs/docs-checker.mjs";

const ROOT = new URL("../../../", import.meta.url).pathname.replace(/\/$/, "");
const NOW = "2026-09-19T00:00:00Z";

/**
 * Task 19 — synchronized governance, technical documentation and the
 * Portuguese editor handbook.
 *
 * The suite proves three things: the real documentation contract passes
 * against the current code (the synchronization gate); the checker still
 * catches every defect class the plan's failure path names (stale source
 * digest, invalid command, missing collection owner, a handbook claiming
 * global professor editing); and the unresolved role owners, contacts and
 * approvals are recorded as unresolved rather than filled with invented
 * names. The live faculty workflow the handbook documents is exercised by
 * `tests/e2e/lps-redesign/task-19.spec.mjs`.
 */

const GUIDE = "docs/handbook/guia-docente-painel.md";

function read(path) {
  return readFileSync(`${ROOT}/${path}`, "utf8");
}

function runFixture(name) {
  return checkDocumentation({
    root: ROOT,
    contractPath: `tests/fixtures/docs/${name}/contract.json`,
    now: NOW,
  });
}

function findings(report, rule) {
  return report.findings.filter((finding) => finding.rule === rule);
}

describe("task-19: the real documentation set passes its own contract", () => {
  it("reports zero findings and zero advisories against current code", async () => {
    const report = await checkDocumentation({
      root: ROOT,
      contractPath: "docs/documentation-contract.json",
      now: NOW,
    });
    expect(report.findings).toEqual([]);
    expect(report.advisories).toEqual([]);
    expect(report.status).toBe("passed");
    expect(report.coverage.documented).toBe(report.coverage.total);
  });

  it("checks the Portuguese faculty guide as part of the document set", async () => {
    const report = await checkDocumentation({
      root: ROOT,
      contractPath: "docs/documentation-contract.json",
      now: NOW,
    });
    expect(report.documents).toContain(GUIDE);
    const freshnessIds = JSON.parse(read("docs/documentation-contract.json")).freshness.map(
      (entry) => entry.id,
    );
    expect(freshnessIds).toContain("faculty-dashboard-labels");
  });
});

describe("task-19: the checker catches every failure-path defect class", () => {
  it("catches a stale source digest and names the changed source", async () => {
    const report = await runFixture("expired-translation");
    const stale = findings(report, "stale-documentation");
    expect(stale).toHaveLength(1);
    expect(stale[0].changedSources).toEqual([
      "wp-content/plugins/lps-content-model/includes/class-translationpolicy.php",
    ]);
  });

  it("catches an invalid documented command", async () => {
    const report = await runFixture("unknown-command");
    const hits = findings(report, "unknown-command");
    expect(hits).toHaveLength(1);
    expect(hits[0].command).toBe("node scripts/absent-tool.mjs");
  });

  it("catches a collection owner missing from every document", async () => {
    const report = await runFixture("uncovered-token");
    const hits = findings(report, "undocumented-item");
    expect(hits).toHaveLength(1);
    expect(hits[0].id).toBe("setting-accessibility-contact");
  });

  it("catches a handbook claiming global professor editing", async () => {
    const report = await runFixture("global-professor-claim");
    const hits = findings(report, "capability-claim-mismatch");
    expect(report.status).toBe("failed");
    expect(hits).toHaveLength(1);
    expect(hits[0].role).toBe("professor");
    expect(hits[0].claimed).toContain("grant-scope");
    expect(hits[0].claimed).toContain("settings");
    expect(hits[0].allowed).not.toContain("grant-scope");
    expect(hits[0].allowed).not.toContain("settings");
  });
});

describe("task-19: the Portuguese guide uses the labels the dashboard renders", () => {
  const guide = read(GUIDE);
  const surfaces = read("wp-content/themes/lps-theme/includes/class-dashboardsurfaces.php");
  const dashboard = read("wp-content/plugins/lps-content-model/includes/class-taskdashboard.php");
  const routes = read("wp-content/themes/lps-theme/includes/class-dashboardroutes.php");

  it("names only task, button and state labels that exist in the renderer", () => {
    const documentedLabels = [
      "Meu perfil",
      "Minhas ofertas",
      "Enviar notícia",
      "Fila de revisão",
      "Criar oferta",
      "Área da oferta",
      "Adicionar unidade",
      "Adicionar material",
      "Copiar para o próximo período",
      "Criar a unidade em rascunho",
      "Criar o material em rascunho",
      "Criar o rascunho do próximo período",
      "Publicar unidade",
      "Publicar material",
      "Publicar agora",
      "Agendar",
      "Retirar",
      "Baixar",
      "Enviar e selecionar",
      "Enviar para revisão",
      "Reenviar para revisão",
      "Enviar proposta para revisão",
      "Revisei a equipe docente para o novo período",
      "Materiais liberados a levar",
      "Período de destino",
      "Turma de destino",
      "Equipe docente",
      "Rascunho",
      "Em revisão",
      "Público",
      "Retirado",
      "Aguardando revisão",
      "Aprovado",
      "Rejeitado",
      "Verificação pendente",
      "Verificação falhou",
    ];
    for (const label of documentedLabels) {
      expect(guide, `guide must document "${label}"`).toContain(label);
      const rendered = surfaces.includes(label) || dashboard.includes(label);
      expect(rendered, `"${label}" must be a real dashboard label`).toBe(true);
    }
  });

  it("documents the real dashboard routes", () => {
    for (const path of [
      "/pt-br/painel/",
      "/pt-br/painel/perfil/",
      "/pt-br/painel/noticias/",
      "/pt-br/painel/ofertas/",
    ]) {
      expect(guide).toContain(path);
    }
    for (const segment of ["'painel'", "'perfil'", "'noticias'", "'ofertas'"]) {
      expect(routes).toContain(segment);
    }
  });

  it("never grants the scoped roles a capability the policy denies", () => {
    // The guide must not carry the machine-checked matrix header, so its
    // prose can never drift into a false capability claim undetected.
    expect(guide).not.toContain("Allowed actions (policy)");
    // The delegate boundary is stated as a denial, not an omission.
    expect(guide).toContain("Publicar, liberar material e copiar para o próximo período são");
    expect(guide).toContain("exclusivas do professor");
    // The professor boundary names the editor-owned fields as denied.
    expect(guide).toContain("nunca edita ofertas fora do seu escopo");
  });
});

describe("task-19: unresolved owners, contacts and approvals stay unresolved", () => {
  it("records the unresolved owner set in governance without inventing names", () => {
    const governance = read("docs/content/governance.md");
    expect(governance).toContain("Role owners, contacts and approvals: unresolved");
    expect(governance).toContain("no duty has a named holder yet");
    // No fabricated contact addresses anywhere in the governance doc.
    expect(governance).not.toMatch(/@[a-z0-9.-]+\.[a-z]{2,}/i);
  });

  it("keeps the contact settings documented as launch blockers, not values", () => {
    const dictionary = read("docs/content/data-dictionary.md");
    expect(dictionary).toContain("unresolved launch blockers");
    expect(dictionary).toContain("must not be filled with a personal address");
  });

  it("keeps the release ledger truthful: unapproved objectives stay unapproved", () => {
    const objectives = JSON.parse(read("docs/operations/recovery-objectives.json"));
    const runbook = read("docs/operations/release-runbook-index.md");
    for (const objective of objectives.objectives ?? objectives) {
      if (objective.status !== "approved") {
        expect(runbook).toContain("unapproved");
      }
      expect(objective.rpo ?? null).toBeNull();
      expect(objective.rto ?? null).toBeNull();
    }
    // The owner table names roles and blockers, never invented people.
    expect(runbook).toContain("none — launch blocker");
    expect(runbook).not.toMatch(/backup operator.*@/i);
  });
});

const filterForm = document.querySelector(".filter-panel");
const filterStatus = document.querySelector("#filter-status");
const sampleForm = document.querySelector(".sample-form");

filterForm?.addEventListener("submit", (event) => {
  event.preventDefault();
  const selected = filterForm.querySelectorAll('input[type="checkbox"]:checked').length;
  filterStatus.textContent = `${selected} ${selected === 1 ? "tipo selecionado" : "tipos selecionados"}; 3 resultados na amostra.`;
});

filterForm?.addEventListener("reset", () => {
  queueMicrotask(() => {
    filterStatus.textContent = "Filtros limpos; 3 resultados na amostra.";
  });
});

sampleForm?.addEventListener("submit", (event) => {
  event.preventDefault();
  const invalid = sampleForm.querySelector('[aria-invalid="true"]');
  if (invalid instanceof HTMLElement) {
    invalid.focus();
  }
});

/*
 * Utility-band disclosures: light-dismiss, Escape, and search focus.
 *
 * The site ships no other client code — the locale switch and the search
 * toggle are native <details> elements. This adds the two behaviours the
 * element cannot express: closing on an outside pointer press or Escape,
 * and moving focus into the search field the moment its panel opens.
 */
(() => {
  const openUtilityDisclosures = () => document.querySelectorAll(".lps-utility-bar details[open]");

  document.addEventListener("pointerdown", (event) => {
    openUtilityDisclosures().forEach((details) => {
      if (!details.contains(event.target)) {
        details.open = false;
      }
    });
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") {
      return;
    }
    openUtilityDisclosures().forEach((details) => {
      details.open = false;
      const summary = details.querySelector("summary");
      if (summary) {
        summary.focus();
      }
    });
  });

  document.addEventListener(
    "toggle",
    (event) => {
      const details = event.target;
      if (!(details instanceof HTMLDetailsElement) || !details.open) {
        return;
      }
      if (!details.classList.contains("lps-search-disclosure")) {
        return;
      }
      const input = details.querySelector(".lps-search-panel input");
      if (input) {
        input.focus();
      }
    },
    true,
  );
})();

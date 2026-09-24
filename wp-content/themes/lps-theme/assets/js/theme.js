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

/*
 * News slider: the ‹ › buttons page the track by one slide and keep their
 * disabled state honest — a control that cannot move stays visibly off.
 * The track itself is a scroll-snap region, so touch swipes need no code.
 */
(() => {
  document.querySelectorAll("[data-lps-slider]").forEach((track) => {
    const prev = document.querySelector(`[data-slider-prev][aria-controls="${track.id}"]`);
    const next = document.querySelector(`[data-slider-next][aria-controls="${track.id}"]`);
    if (!prev || !next) {
      return;
    }
    const step = () => {
      const slide = track.querySelector(".lps-slide");
      if (!slide) {
        return track.clientWidth;
      }
      const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
      return slide.getBoundingClientRect().width + gap;
    };
    const sync = () => {
      const max = track.scrollWidth - track.clientWidth;
      prev.disabled = track.scrollLeft <= 1;
      next.disabled = track.scrollLeft >= max - 1;
    };
    prev.addEventListener("click", () => track.scrollBy({ left: -step(), behavior: "smooth" }));
    next.addEventListener("click", () => track.scrollBy({ left: step(), behavior: "smooth" }));
    track.addEventListener("scroll", sync, { passive: true });
    window.addEventListener("resize", sync);
    sync();
  });
})();

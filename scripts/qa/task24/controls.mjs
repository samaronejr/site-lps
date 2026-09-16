// Exhaustive DOM inventory. Equivalence is exact component markup + ancestor
// context + rendered style + geometry + locale + viewport, never tag-only sampling.
import { createHash } from "node:crypto";
export const sha = (value) => createHash("sha256").update(value).digest("hex");
export const CONTROL_SELECTOR =
  "a[href],button,input:not([type=hidden]),select,textarea,summary,[tabindex],[contenteditable=true],audio[controls],video[controls],[role=button],[role=checkbox],[role=switch],[aria-disabled=true]";
export async function enumerate(page, cell) {
  const controls = await page.locator(CONTROL_SELECTOR).evaluateAll((elements) => {
    const selector = (el) => {
      const parts = [];
      while (el && el.tagName !== "HTML") {
        const tag = el.tagName.toLowerCase();
        parts.unshift(
          `${tag}:nth-of-type(${[...el.parentElement.children].filter((s) => s.tagName === el.tagName).indexOf(el) + 1})`,
        );
        el = el.parentElement;
      }
      return `html > ${parts.join(" > ")}`;
    };
    return elements.map((el) => {
      const s = getComputedStyle(el),
        r = el.getBoundingClientRect();
      const ancestors = [];
      let p = el.parentElement;
      while (p && p !== document.body) {
        ancestors.push(`${p.tagName}.${p.className}${p.getAttribute("role") || ""}`);
        p = p.parentElement;
      }
      const style = {};
      for (const name of [
        "color",
        "backgroundColor",
        "fontFamily",
        "fontSize",
        "fontWeight",
        "lineHeight",
        "padding",
        "border",
        "display",
        "position",
        "textDecoration",
        "outline",
        "transform",
        "transitionProperty",
        "transitionDuration",
        "transitionTimingFunction",
      ])
        style[name] = s[name];
      const closed = el.closest("details:not([open])");
      const rendered =
        s.display !== "none" &&
        s.visibility !== "hidden" &&
        r.width > 0 &&
        r.height > 0 &&
        (!closed || el.tagName === "SUMMARY");
      return {
        selector: selector(el),
        tag: el.tagName.toLowerCase(),
        type: el.type || null,
        role: el.getAttribute("role"),
        name: el.getAttribute("aria-label") || el.textContent.trim() || el.name || "",
        href: el.getAttribute("href"),
        absoluteHref: el.href || null,
        target: el.target || null,
        download: el.hasAttribute("download"),
        html: el.outerHTML,
        ancestors,
        style,
        geometry: { width: r.width, height: r.height },
        rendered,
        hiddenReason: rendered
          ? null
          : s.display === "none"
            ? "display:none"
            : closed
              ? "closed disclosure"
              : "not rendered",
        disabled: el.disabled || el.getAttribute("aria-disabled") === "true",
        readOnly: el.readOnly || false,
        value: el.value ?? null,
        checked: el.checked ?? null,
        open: el.tagName === "SUMMARY" ? el.parentElement.open : null,
        options: el.options
          ? [...el.options].map((o) => ({ value: o.value, label: o.label, disabled: o.disabled }))
          : null,
        form: el.form
          ? {
              selector: selector(el.form),
              action: el.form.action,
              method: el.form.method,
              html: el.form.outerHTML,
            }
          : null,
        region: el.closest("header") ? "shell" : el.closest("footer") ? "footer" : "main",
        current: el.getAttribute("aria-current"),
      };
    });
  });
  return controls.map((control) => classify(control, cell));
}
export function classify(control, cell) {
  const required = requiredStates(control);
  const signature = JSON.stringify({
    viewport: cell.viewport,
    locale: cell.locale,
    template: control.region === "main" ? cell.template : "shared-shell",
    html: control.html,
    ancestors: control.ancestors,
    style: control.style,
    geometry: control.geometry,
    form: control.form,
    required,
  });
  return { ...control, required, signature, group: sha(signature).slice(0, 20) };
}
export function requiredStates(c) {
  if (!c.rendered) return [];
  if (c.disabled) return ["rest", "disabled"];
  const states = ["rest", "hover", "focus", "pressed", "settled"];
  if (c.style.transitionDuration.split(",").some((value) => Number.parseFloat(value) > 0))
    states.push("reduced-motion");
  if (c.current) states.push("current");
  if (c.tag === "summary") states.push("closed", "open");
  if (c.tag === "input" && c.type === "checkbox")
    states.push("checked", "unchecked", "checked-action");
  else if (c.tag === "input" || c.tag === "textarea")
    states.push(...(c.readOnly ? ["readonly"] : ["empty", "filled"]));
  if (c.tag === "input" && /minlength="[2-9][0-9]*"/.test(c.html)) states.push("valid", "invalid");
  if (c.tag === "select")
    states.push(
      "expanded",
      ...c.options.filter((o) => !o.disabled).map((_o, i) => `selected-${i}`),
    );
  if (c.tag === "figure" && c.role === "region")
    states.push("scroll-start", "scroll-keyboard", "scroll-end");
  if (c.tag === "video" || c.tag === "audio")
    states.push(
      "media-state",
      "loadedmetadata",
      "playing",
      "paused",
      "keyboard-seek",
      "cue-1",
      "cue-2",
      "cue-3",
      "captions-disabled",
      "captions-showing",
      "native-controls",
      "native-overflow",
      "fullscreen",
      "fullscreen-exit",
      "native-download",
      "speed-menu",
      "speed-selected",
      "native-pip",
      "native-pip-mid",
      "native-pip-settled",
      "pip-exit",
      "keyboard-exit",
    );
  if (c.tag === "a" || c.tag === "button") states.push("action");
  if (c.tag === "input" && c.type !== "checkbox" && !c.readOnly) states.push("input-action");
  return states;
}
export async function identity(page) {
  const value = await page.evaluate(() => ({
    url: location.href,
    lang: document.documentElement.lang,
    h1: document.querySelector("h1")?.textContent.trim() ?? null,
    main: document.querySelector("main")?.innerHTML ?? "",
    disclosures: [...document.querySelectorAll("details")].map((el) => el.open),
  }));
  return { ...value, main: undefined, mainHash: sha(value.main) };
}
export async function collectSourceAria(page, expected) {
  assertIdentity(await identity(page), expected, "before source ARIA collection");
  const aria = await page.locator("body").ariaSnapshot();
  assertIdentity(await identity(page), expected, "after source ARIA collection");
  return aria;
}
export function assertIdentity(actual, expected, context) {
  for (const key of ["url", "lang", "h1", "mainHash"])
    if (actual[key] !== expected[key])
      throw Error(
        `${context}: wrong-route/state ${key}: expected ${expected[key]}, got ${actual[key]}`,
      );
}

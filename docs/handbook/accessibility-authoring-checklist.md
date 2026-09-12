# Accessibility authoring checklist

For editors, translators and reviewers. The conformance target is WCAG 2.2 AA plus eMAG; the
evidence and defect record is [the accessibility conformance report](../quality/accessibility-conformance.md),
which is owned by the accessibility tranche — read it, do not restate it here.

This checklist is about what an author controls. Product-level defects (focus rings, landmarks,
target sizes, contrast tokens) are the theme's responsibility and are covered by the gate.

## Before you submit a record

- [ ] The heading structure is sequential: one `h1` per page, no level skipped, no heading used to
      make text large.
- [ ] Link text describes its destination out of context. No "click here", "read more" or a bare URL
      as the link text.
- [ ] A link that leaves the site or downloads a file says so in its text, including the format when
      the format matters.
- [ ] Nothing depends on colour alone. A status, deadline or result also has text.
- [ ] No instruction relies on shape, size, position or a sensory cue ("the box on the right").
- [ ] Abbreviations and acronyms are expanded on first use in the record.
- [ ] Inline language changes are marked, so a screen reader switches pronunciation.
- [ ] Tables are data tables with real header cells; no layout table, no merged decoration.
- [ ] Long identifiers (DOI, URL) are allowed to wrap; do not insert manual breaks or hyphens.
- [ ] Text is not an image. A figure never carries essential text only in pixels.

## Images and media

- [ ] Every image is either meaningful with per-usage alternative text, or explicitly marked
      decorative. The same photograph in two contexts gets two different descriptions.
- [ ] Alternative text describes the informative content, not the file name and not the credit.
- [ ] Credit, rights holder, license and privacy review are recorded before the asset is published.
- [ ] Video has captions; audio has a transcript. A captioned video also has its transcript
      available as text.
- [ ] No autoplay, no carousel, no motion that conveys information.
- [ ] A person photograph exists only with documented rights and privacy review; a withheld photo is
      an intentional, labelled state, not an empty box.

## Documents

- [ ] Essential information exists as accessible HTML. A PDF is never the only route.
- [ ] Every essential PDF has an HTML summary and context in the record.
- [ ] A legacy PDF that cannot be remediated stays out of the public path or is published as
      archived, non-essential material with its limits stated.

## Bilingual authoring

- [ ] The record's locale field matches the language actually written.
- [ ] No mixed-language field, and no Portuguese sentence left inside an English variant.
- [ ] Both locales carry the same essential information; the English variant is reviewed, not
      machine-translated. See [translation and freshness](translation-freshness.md).

## Status, dates and states

- [ ] An opportunity's window comes from its date fields; the body text never contradicts them.
- [ ] A cancelled or postponed event states its status explicitly.
- [ ] An empty listing shows the documented empty state with an explanation, not a blank area.
- [ ] An error or validation message identifies the field and what to do, in text.

## Verifying your work

| Command | Scope |
| --- | --- |
| `npm run qa:a11y` | The full accessibility gate: route inventory, rendered documents, stylesheet rules, authored-corpus rules, axe, pa11y and keyboard journeys. |
| `node scripts/qa/a11y/static-scan.mjs` | axe, pa11y, reflow and target-size measurement over the static surface seams; needs no server. |
| `node scripts/qa/a11y/static-keyboard.mjs` | Focus order, focus visibility, obscured focus, trap sweep and reduced-motion measurement. |
| `npm run test -- tests/js/a11y.test.mjs` | The authored-defect fixtures (missing alt, broken label, keyboard trap, low contrast, obscured focus, wrong language, PDF-only essential content) asserted to the exact selector. |

A failing gate names the route and selector. Fix the content or the media record; do not waive a
finding because automation passed elsewhere.

## Reporting a barrier

The site must expose a barrier-reporting route (`_lps_report_contact` on institutional pages and the
`accessibility_contact` site setting). No accessibility contact has been named yet: that is an open
launch blocker (`accessibility-contact` in
`tests/fixtures/governance/role-collection-matrix.json`), and it must not be filled with a personal
address.

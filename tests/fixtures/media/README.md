# Local authored-media QA fixture

These files are test data only, not an annual report, seminar recording, scientific output, or evidence of institutional activities. They are mounted only by the local `.wp-env.json` fixture configuration and are excluded from the product build.

## Content and rights

`assets/content.json` is the bilingual content source. The seed uses it for the contextual HTML document and transcript; `generate.mjs` uses it for the PDF and WebVTT captions. Both PDFs are one-page, tagged documents. Source Serif 4 is embedded under the existing SIL Open Font License (`wp-content/themes/lps-theme/assets/fonts/OFL.txt`); the font retains that license.

The text and three numbered video cards were authored locally for this repository's QA and are dedicated to the public domain under CC0-1.0 (https://creativecommons.org/publicdomain/zero/1.0/). No third-party footage, institutional marks, photographs, voices or music are used. The original text, poster, captions and generated video may be copied and redistributed without attribution. This dedication does not relicense the embedded font or generation tools.

`qa-demo.webm` is a **silent** 640x360 VP8 video, 10 fps, 12 seconds. It shows numbered cards 1, 2, 3 for four seconds each, with bilingual QA/silence labels. Localized captions identify the visible card and absence of audio; the adjacent transcript contains the same timed descriptions. Silence is intentional, not a missing soundtrack. Native playback, seek, caption, volume and fullscreen controls are not hidden or disabled.

## Regeneration and local delivery

With the repository Playwright dependency, `/usr/bin/chromium`, and a local ffmpeg executable supporting MJPEG image2pipe input and VP8/WebM output:

```sh
FFMPEG=/path/to/ffmpeg node tests/fixtures/media/generate.mjs
npm run env:start
LPS_BASE_URL=http://localhost:8888 node --test --test-concurrency=1 tests/integration/authored-media.test.mjs
```

Generated assets are checked in; developers do not need an encoder to serve the fixture. The generator processes a fixed 120-frame sequence without elapsed-time sampling. PDF metadata can change on regeneration; byte identity is not promised. When regenerating/changing assets or content on an already-seeded DB, bump `LPS_A11Y_SEED_VERSION` so the normal one-time fixture upgrade refreshes HTML and exact byte labels. Do not patch page content per request or disable KSES: `video[src]` is the supported saved markup.

The mount places assets at the normal uploads URL. HTTP serves the static PDF/WebM/VTT bytes with their media types; the same-origin `download` anchor supplies browser download semantics. The HTML alternative is a named section on the same localized media page, not Home or a generic document index.

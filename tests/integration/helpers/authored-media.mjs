import assert from "node:assert/strict";

/** Arm in the page before a keyboard action; fail on error or a bounded deadline. */
export async function armMediaEvent(page, event) {
  await page.evaluate((name) => {
    const video = document.querySelector("video");
    window.mediaSignal = new Promise((resolve, reject) => {
      const controller = new AbortController();
      const timer = setTimeout(() => {
        controller.abort();
        reject(new Error(`Media deadline: ${name}`));
      }, 10000);
      const finish = (error) => {
        clearTimeout(timer);
        controller.abort();
        if (error) reject(error);
        else resolve();
      };
      video.addEventListener(name, () => finish(), { signal: controller.signal });
      video.addEventListener(
        "error",
        () => finish(new Error(`Media error: ${video.error?.code}`)),
        { signal: controller.signal },
      );
    });
  }, event);
}

export async function keyboardTo(page, selector) {
  for (let step = 0; step < 100; step += 1) {
    await page.keyboard.press("Tab");
    if (await page.evaluate((s) => document.activeElement?.matches(s), selector)) return;
  }
  assert.fail(`Keyboard cannot reach ${selector}`);
}

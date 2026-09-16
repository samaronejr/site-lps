import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./tests/e2e",
  outputDir: "./test-results/playwright",
  reporter: [["list"], ["html", { open: "never", outputFolder: "playwright-report" }]],
  retries: 0,
  timeout: 30_000,
  // The disposable Playground server shares this host; fewer workers keep the
  // WASM PHP workers and SQLite writer from stalling a request past its bound.
  workers: 2,
  use: {
    baseURL: process.env.LPS_BASE_URL ?? "http://127.0.0.1:8888",
    // The staging edge terminates TLS with a locally issued certificate.
    ignoreHTTPSErrors: true,
    trace: "retain-on-failure",
  },
  projects: [
    { name: "chromium", use: { ...devices["Desktop Chrome"] } },
    { name: "firefox", use: { ...devices["Desktop Firefox"] } },
    { name: "webkit", use: { ...devices["Desktop Safari"] } },
  ],
});

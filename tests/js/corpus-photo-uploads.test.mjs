import { existsSync, readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const corpus = JSON.parse(readFileSync("content/import/launch-corpus.json", "utf8"));
const uploadPrefix = "/wp-content/uploads/";

describe("launch corpus photo uploads", () => {
  it("tracks every referenced upload and both memorial portraits", () => {
    const checked = [];

    for (const record of corpus.records) {
      const url = record.meta?._lps_photo_url;
      if (typeof url !== "string" || !url.startsWith(uploadPrefix)) continue;

      const relativePath = url.slice(uploadPrefix.length);
      checked.push(relativePath);
      expect(existsSync(`content/media/uploads/${relativePath}`), url).toBe(true);
    }

    expect(checked).toContain("people/jose-seixas.jpg");
    expect(checked).toContain("people/antonio-moreirao.jpg");
  });
});

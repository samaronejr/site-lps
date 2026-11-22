import { execFileSync } from "node:child_process";
import { readdirSync } from "node:fs";

const root = "content/media/uploads/people";
for (const file of readdirSync(root)) {
  if (!file.endsWith(".jpg") || /-(112|400|800)\.jpg$/.test(file)) continue;
  for (const width of [112, 400, 800]) {
    for (const [format, quality] of [
      ["jpg", "86"],
      ["webp", "84"],
    ]) {
      execFileSync("convert", [
        `${root}/${file}`,
        "-resize",
        `${width}x`,
        "-strip",
        "-quality",
        quality,
        `${root}/${file.slice(0, -4)}-${width}.${format}`,
      ]);
    }
  }
}

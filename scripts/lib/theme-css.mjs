import { transform } from "lightningcss";
import postcss from "postcss";

export function compileThemeCss(source) {
  const publicSheet = postcss.parse(source);
  const privateSheet = publicSheet.clone();
  const privateOnly = (rule) =>
    rule.selectors.every((selector) =>
      /\.lps-(?:dashboard|intranet|news-preview|proposal-diff|migration-diff)/.test(selector),
    );
  publicSheet.walkRules((rule) => {
    if (privateOnly(rule)) rule.remove();
  });
  privateSheet.walkRules((rule) => {
    if (!privateOnly(rule)) rule.remove();
  });
  privateSheet.walkAtRules((rule) => {
    if (!rule.nodes?.length) rule.remove();
  });
  const minify = (sheet) =>
    transform({ filename: "theme.css", code: Buffer.from(sheet.toString()), minify: true }).code;
  return { publicCss: minify(publicSheet), privateCss: minify(privateSheet) };
}

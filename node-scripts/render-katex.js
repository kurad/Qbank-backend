// node-scripts/render-katex.js
const katex = require("katex");

const mode = process.argv[2] || "display"; // display | inline
const displayMode = mode === "display";

let input = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", (chunk) => (input += chunk));
process.stdin.on("end", () => {
  const latex = (input || "").trim();

  try {
    const html = katex.renderToString(latex, {
      displayMode,
      throwOnError: true,          // IMPORTANT: throw so we can log real failures
      strict: "ignore",
      output: "svg",
    });

    // Extract ONLY the <svg>...</svg>
    const m = html.match(/<svg[\s\S]*?<\/svg>/i);
    if (!m) {
      process.stderr.write(`[KaTeX] No <svg> found. Raw output:\n${html}\n`);
      process.stdout.write("__KATEX_ERROR__NO_SVG__");
      return;
    }

    process.stdout.write(m[0]);
  } catch (e) {
    process.stderr.write(`[KaTeX] Render failed: ${e && e.message ? e.message : e}\n`);
    process.stdout.write("__KATEX_ERROR__" + (e && e.message ? e.message : "UNKNOWN"));
  }
});

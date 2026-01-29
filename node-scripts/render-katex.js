// node-scripts/render-katex.js
const katex = require("katex");

const mode = process.argv[2] || "display"; // "display" | "inline"
const displayMode = mode === "display";

// Read full stdin as LaTeX
let input = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", (chunk) => (input += chunk));
process.stdin.on("end", () => {
  const latex = (input || "").trim();

  try {
    const out = katex.renderToString(latex, {
      displayMode,
      throwOnError: false,
      output: "svg",
      strict: "ignore",
    });

    // KaTeX output includes wrapper spans. Extract ONLY the <svg>...</svg>.
    const m = out.match(/<svg[\s\S]*?<\/svg>/i);

    if (!m) {
      process.stdout.write(""); // no svg found
      return;
    }

    process.stdout.write(m[0]); // raw SVG only
  } catch (e) {
    process.stdout.write(""); // keep it harmless
  }
});

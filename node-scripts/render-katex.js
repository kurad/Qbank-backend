// node-scripts/render-katex.js
const path = require("path");

// Load katex from your project node_modules explicitly (works under PHP-FPM too)
let katex;
try {
  const katexPath = path.join(__dirname, "..", "node_modules", "katex");
  katex = require(katexPath);
} catch (e) {
  // If this fails, PHP will see Math error, and you will see the real issue in stderr logs.
  console.error("KaTeX require failed:", e && e.message ? e.message : e);
  process.stdout.write(`<span style="color:#b00">[Math error]</span>`);
  process.exit(0);
}

const mode = process.argv[2] || "display";
const displayMode = mode === "display";

// Read full stdin as LaTeX
let input = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", (chunk) => (input += chunk));
process.stdin.on("end", () => {
  const latex = (input || "").trim();

  try {
    const html = katex.renderToString(latex, {
      displayMode,
      throwOnError: false,
      strict: "ignore",
      output: "svg",
    });

    process.stdout.write(html);
  } catch (e) {
    console.error("KaTeX render failed:", e && e.message ? e.message : e);
    process.stdout.write(`<span style="color:#b00">[Math error]</span>`);
  }
});

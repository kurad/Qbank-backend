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
    // IMPORTANT: output "svg" (DOMPDF-safe)
    const svg = katex.renderToString(latex, {
      displayMode,
      throwOnError: false,
      output: "svg",
      strict: "ignore",
    });

    process.stdout.write(svg);
  } catch (e) {
    // Return something harmless so PDF generation doesn't crash
    process.stdout.write(
      `<span style="color:#b00">[Math error]</span>`
    );
  }
});

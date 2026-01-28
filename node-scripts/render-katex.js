// node-scripts/render-katex.js
// Usage:
//   echo "c = \\pm\\sqrt{a^2 + b^2}" | node node-scripts/render-katex.js inline
//   echo "\\int_0^\\infty e^{-x^2} dx = \\frac{\\sqrt{\\pi}}{2}" | node node-scripts/render-katex.js display

const katex = require('katex');

function readStdin() {
  return new Promise((resolve) => {
    let data = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', chunk => (data += chunk));
    process.stdin.on('end', () => resolve(data));
  });
}

(async () => {
  const latex = (await readStdin()).trim();
  const modeArg = (process.argv[2] || 'display').toLowerCase();
  const displayMode = modeArg === 'display';

  try {
    const html = katex.renderToString(latex, {
      displayMode,
      throwOnError: false,     // do not crash on unknown commands
      output: 'htmlAndMathml', // best for DOMPDF/print & accessibility
      strict: 'warn',          // log issues but continue
      trust: false,            // avoid unsafe HTML
      macros: {},              // add your custom macros if needed
    });
    process.stdout.write(html);
  } catch (err) {
    // Fallback to escaped plaintext if KaTeX fails
    process.stdout.write(
      `<code style="color:#b00; font-family:monospace;">${latex.replace(/[&<>]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[s]))}</code>`
    );
    process.stderr.write(String(err));
    process.exitCode = 0;
  }
})();
``
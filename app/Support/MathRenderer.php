<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class MathRenderer
{
    protected static function runKatexSvg(string $latex, bool $display = true): string
    {
        $script = base_path('node-scripts/render-katex.js');
    $mode = $display ? 'display' : 'inline';

    // Clean LaTeX coming from HTML content
    $latex = html_entity_decode($latex, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $latex = strip_tags($latex);
    $latex = trim($latex);

    if ($latex === '') {
        return '';
    }

    $process = new \Symfony\Component\Process\Process(['node', $script, $mode]);
    $process->setInput($latex);
    $process->setTimeout(8);
    $process->run();

    $out = trim((string) $process->getOutput());
    $err = trim((string) $process->getErrorOutput());

    // If node failed at all (node missing, permission, katex missing, etc.)
    if (!$process->isSuccessful()) {
        \Log::error('KaTeX process failed', [
            'exit_code' => $process->getExitCode(),
            'stderr' => $err,
            'latex' => mb_substr($latex, 0, 500),
        ]);
        return '<span style="color:#b00">[Math error]</span>';
    }

    // Node ran but katex reported an error marker
    if (str_starts_with($out, '__KATEX_ERROR__')) {
        \Log::error('KaTeX render error marker', [
            'marker' => $out,
            'stderr' => $err,
            'latex' => mb_substr($latex, 0, 500),
        ]);
        return '<span style="color:#b00">[Math error]</span>';
    }

    // Still no SVG => log it
    if ($out === '' || !str_contains($out, '<svg')) {
        \Log::error('KaTeX returned empty/non-svg', [
            'stdout' => $out,
            'stderr' => $err,
            'latex' => mb_substr($latex, 0, 500),
        ]);
        return '<span style="color:#b00">[Math error]</span>';
    }

    // DOMPDF-safe: embed as <img>
    $b64 = base64_encode($out);

    if ($display) {
        return '<div class="math-block"><img alt="math" src="data:image/svg+xml;base64,' . $b64 . '" style="height:22px"></div>';
    }

    return '<span class="math-inline"><img alt="math" src="data:image/svg+xml;base64,' . $b64 . '" style="height:14px; vertical-align:middle"></span>';

    }

    protected static function svgToImg(string $svg, bool $display): string
    {
        $svg = trim($svg);

        // If the node script returned a span (error fallback), just return it.
        if ($svg === '' || stripos($svg, '<svg') === false) {
            return $svg;
        }

        // DOMPDF is happiest with base64 data URIs
        $b64 = base64_encode($svg);

        if ($display) {
            // Block-like math
            return '<div class="math-block"><img src="data:image/svg+xml;base64,' . $b64 . '" /></div>';
        }

        // Inline math
        return '<span class="math-inline"><img src="data:image/svg+xml;base64,' . $b64 . '" /></span>';
    }

    public static function render(string $latex, bool $display = true): string
    {
        $latex = trim($latex);
        if ($latex === '') return '';

        $key = 'katex_svg_' . md5(($display ? 'D' : 'I') . $latex);

        return Cache::remember($key, now()->addDays(7), function () use ($latex, $display) {
            $svg = self::runKatexSvg($latex, $display);
            return self::svgToImg($svg, $display);
        });
    }

    /**
     * Replace LaTeX delimiters with embedded SVG images.
     * Supports:
     *  - Display: $$...$$ or \[...\]
     *  - Inline:  $...$  or \(...\)
     */
    public static function processHtmlWithLatex(string $html): string
    {
        if ($html === '') return $html;

        // Guard: avoid double-processing if already rendered
        if (str_contains($html, 'data:image/svg+xml;base64,') || str_contains($html, 'math-inline') || str_contains($html, 'math-block')) {
            return $html;
        }

        // Display math: $$ ... $$ or \[ ... \]
        $html = preg_replace_callback('/\\$\\$(.+?)\\$\\$|\\\\\\[(.+?)\\\\\\]/s', function ($m) {
            $latex = $m[1] ?? $m[2] ?? '';
            return self::render($latex, true);
        }, $html);

        // Inline math: $ ... $ or \( ... \)
        // (This avoids $$..$$ because those were already handled above)
        $html = preg_replace_callback('/(?<!\\$)\\$(?!\\$)(.+?)(?<!\\$)\\$(?!\\$)|\\\\\\((.+?)\\\\\\)/s', function ($m) {
            $latex = $m[1] ?? $m[2] ?? '';
            return self::render($latex, false);
        }, $html);

        return $html;
    }
}

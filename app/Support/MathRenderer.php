<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class MathRenderer
{
    protected static function runKatexSvg_old(string $latex, bool $display = true): string
    {
        $script = base_path('node-scripts/render-katex.cjs');
        $mode   = $display ? 'display' : 'inline';

        $node = env('NODE_BIN') ?: '/usr/bin/node'; // fallback

        $process = new \Symfony\Component\Process\Process([$node, $script, $mode]);
        $process->setWorkingDirectory(base_path()); // important on shared hosting
        $process->setInput($latex);
        $process->setTimeout(10);

        // Optional: explicitly set PATH so child process can find libs
        $process->setEnv([
            'PATH' => dirname($node) . ':' . getenv('PATH'),
            'HOME' => getenv('HOME') ?: base_path(),
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            \Illuminate\Support\Facades\Log::error('KaTeX render error: ' . $process->getErrorOutput(), [
                'exit_code' => $process->getExitCode(),
                'node' => $node,
            ]);

            // return visible placeholder (so PDF still renders)
            return '<span style="color:#b00">[Math error]</span>';
        }

        return $process->getOutput();
    }

    protected static function runKatex(string $latex, bool $display = true): string
{
    $node = env('NODE_BIN') ?: config('services.node_bin');

    if (!$node || !is_file($node) || !is_executable($node)) {
        Log::error('KaTeX render error: NODE_BIN not set or invalid', [
            'NODE_BIN' => $node,
            'exists' => $node ? file_exists($node) : false,
            'exec' => $node ? is_executable($node) : false,
        ]);
        return '<span style="color:#b00">[Math error]</span>';
    }

    $script = base_path('node-scripts/render-katex.cjs'); // IMPORTANT
    $mode   = $display ? 'display' : 'inline';

    $process = new \Symfony\Component\Process\Process([$node, $script, $mode]);
    $process->setInput($latex);
    $process->setTimeout(10);

    $process->run();

    if (!$process->isSuccessful()) {
        Log::error('KaTeX render error: ' . $process->getErrorOutput(), [
            'latex' => mb_substr($latex, 0, 200),
        ]);
        return '<span style="color:#b00">[Math error]</span>';
    }

    return (string) $process->getOutput();
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
            $svg = self::runKatex($latex, $display);
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

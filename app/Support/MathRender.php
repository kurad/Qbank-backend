<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class MathRenderer
{
    protected static function runKatex(string $latex, bool $display = true): string
    {
        $script = base_path('node-scripts/render-katex.js');
        $mode = $display ? 'display' : 'inline';

        $process = new Process(['node', $script, $mode]);
        $process->setInput($latex);
        $process->setTimeout(5); // seconds

        $process->run();
        if (!$process->isSuccessful()) {
            Log::error('KaTeX render error: ' . $process->getErrorOutput());
        }

        return $process->getOutput();
    }

    public static function render(string $latex, bool $display = true): string
    {
        $key = 'katex_' . md5(($display ? 'D' : 'I') . $latex);
        return Cache::remember($key, now()->addDays(7), function () use ($latex, $display) {
            return self::runKatex($latex, $display);
        });
    }

    /**
     * Process HTML that contains LaTeX delimiters and replace with KaTeX markup.
     * Supports:
     *  - Display: $$...$$ or \[...\]
     *  - Inline:  $...$  or \(...\)
     */
    public static function processHtmlWithLatex(string $html): string
    {
        // Display math: $$ ... $$ or \[ ... \]
        $html = preg_replace_callback('/\\$\\$(.+?)\\$\\$|\\\\\\[(.+?)\\\\\\]/s', function ($m) {
            $latex = $m[1] ?? $m[2] ?? '';
            return self::render(trim($latex), true);
        }, $html);

        // Inline math: $ ... $ or \( ... \)
        $html = preg_replace_callback('/(?<!\\$)\\$(?!\\$)(.+?)(?<!\\$)\\$(?!\\$)|\\\\\\((.+?)\\\\\\)/s', function ($m) {
            $latex = $m[1] ?? $m[2] ?? '';
            return self::render(trim($latex), false);
        }, $html);

        return $html;
    }
}
<?php

namespace App\Services;

use App\Models\QuestionnaireImport;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/** Extract visual candidates for teacher confirmation; never guess their question mapping. */
class QuestionnaireImportMediaService
{
    private function directory(QuestionnaireImport $import): string
    {
        return 'questionnaire-media/' . $import->id . '-' . substr(hash('sha256', $import->file_path), 0, 16);
    }

    public function extract(QuestionnaireImport $import, string $absolutePath): void
    {
        $directory = $this->directory($import);
        if (Storage::disk('public')->files($directory)) return; // idempotent on reprocessing
        $ext = strtolower(pathinfo($import->original_name, PATHINFO_EXTENSION));
        if ($ext === 'docx') $this->extractDocx($absolutePath, $directory);
        elseif ($ext === 'pdf') $this->extractPdf($absolutePath, $directory);
        elseif (in_array($ext, ['jpg','jpeg','png','webp'], true)) {
            $bytes = @file_get_contents($absolutePath);
            if ($bytes !== false && strlen($bytes) <= 10 * 1024 * 1024) {
                Storage::disk('public')->put($directory . '/source.' . $ext, $bytes);
            }
        }
    }

    private function extractDocx(string $path, string $directory): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return;
        $count = 0;
        try {
            for ($i = 0; $i < $zip->numFiles && $count < 80; $i++) {
                $entry = $zip->getNameIndex($i);
                if (!preg_match('~^word/media/[^/]+\.(png|jpe?g|gif|webp)$~i', $entry)) continue;
                $bytes = $zip->getFromIndex($i);
                if (!$bytes || strlen($bytes) > 10 * 1024 * 1024 || !@getimagesizefromstring($bytes)) continue;
                $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                $count++;
                Storage::disk('public')->put($directory . '/docx-' . str_pad((string)$count, 3, '0', STR_PAD_LEFT) . '.' . $extension, $bytes);
            }
        } finally { $zip->close(); }
    }

    private function extractPdf(string $path, string $directory): void
    {
        // First keep original raster figures where available.
        $binary = trim((string) shell_exec('command -v pdfimages 2>/dev/null'));
        $tempDir = sys_get_temp_dir() . '/rh-pdf-media-' . Str::random(20);
        if (!@mkdir($tempDir, 0700)) return;
        $saved = 0;
        try {
            if ($binary && is_executable($binary)) {
                $cmd = escapeshellarg($binary) . ' -png ' . escapeshellarg($path) . ' ' . escapeshellarg($tempDir . '/figure') . ' 2>/dev/null';
                exec($cmd, $unused, $exit);
                if ($exit === 0) {
                    foreach (array_slice(glob($tempDir . '/figure-*.png') ?: [], 0, 80) as $i => $file) {
                        if (filesize($file) > 10 * 1024 * 1024 || !@getimagesize($file)) continue;
                        Storage::disk('public')->put($directory . '/pdf-' . str_pad((string)($i+1), 3, '0', STR_PAD_LEFT) . '.png', file_get_contents($file));
                        $saved++;
                    }
                }
            }
            // Vector drawings and scans may have no separately extractable raster figures.
            // Render a bounded set of reference pages for human selection/vision fallback.
            if ($saved === 0) {
                $renderer = trim((string) shell_exec('command -v pdftoppm 2>/dev/null'));
                if ($renderer && is_executable($renderer)) {
                    $cmd = escapeshellarg($renderer) . ' -f 1 -l 8 -scale-to 1600 -png ' . escapeshellarg($path) . ' ' . escapeshellarg($tempDir . '/page') . ' 2>/dev/null';
                    exec($cmd, $unused2, $exit2);
                    if ($exit2 === 0) {
                        foreach (array_slice(glob($tempDir . '/page-*.png') ?: [], 0, 8) as $i => $file) {
                            if (filesize($file) > 10 * 1024 * 1024) continue;
                            Storage::disk('public')->put($directory . '/page-' . str_pad((string)($i+1), 3, '0', STR_PAD_LEFT) . '.png', file_get_contents($file));
                        }
                    }
                }
            }
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $file) @unlink($file);
            @rmdir($tempDir);
        }
    }

    public function assets(QuestionnaireImport $import): array
    {
        $directory = $this->directory($import);
        return array_values(array_map(function ($path) {
            return ['path' => $path, 'url' => Storage::disk('public')->url($path), 'label' => basename($path)];
        }, array_filter(Storage::disk('public')->files($directory), fn ($path) =>
            (bool) preg_match('/\.(png|jpe?g|gif|webp)$/i', $path)
        )));
    }

    public function validAssetPath(QuestionnaireImport $import, string $path): bool
    {
        return str_starts_with($path, $this->directory($import) . '/') &&
            preg_match('/\.(png|jpe?g|gif|webp)$/i', $path) &&
            Storage::disk('public')->exists($path);
    }
}

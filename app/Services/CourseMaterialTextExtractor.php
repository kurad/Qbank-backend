<?php

namespace App\Services;

use App\Models\CourseMaterial;
use App\Models\CourseMaterialPage;
use App\Models\CourseMaterialVisual;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Log;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Process;

class CourseMaterialTextExtractor
{

    public function extract(CourseMaterial $material): array
    {
        // PDF processing can take several minutes for large curriculum PDFs.
        set_time_limit(0);
        ini_set('max_execution_time', '0');
        
        Log::info('PDF EXTRACTION STARTED', [
            'material_id' => $material->id,
            'file_name' => $material->file_name,
            'original_file_path' => $material->original_file_path,
        ]);

        $path = $this->resolveStoredPath(
            $material->original_file_path
        );

        \Log::info('PDF PATH RESOLVED', [
            'material_id' => $material->id,
            'path' => $path,
            'exists' => is_file($path),
            'size' => is_file($path) ? filesize($path) : null,
        ]);

        if (!is_file($path)) {
            throw new RuntimeException(
                'The stored course-material file could not be found.'
            );
        }

        if (!extension_loaded('imagick')) {
            throw new RuntimeException(
                'The PHP Imagick extension is required to process PDF pages.'
            );
        }

        \Log::info('IMAGICK AVAILABLE', [
            'material_id' => $material->id,
            'imagick' => extension_loaded('imagick'),
            'version' => (new \Imagick())->getVersion()['versionString'] ?? null,
        ]);

        /*
    |--------------------------------------------------------------------------
    | PDF TEXT PARSING
    |--------------------------------------------------------------------------
    */

        \Log::info('PDF PARSER STARTING', [
            'material_id' => $material->id,
        ]);

        $parser = new Parser();

        $pdf = $parser->parseFile($path);

        \Log::info('PDF PARSER FINISHED', [
            'material_id' => $material->id,
        ]);

        $pages = $pdf->getPages();

        \Log::info('PDF PAGES READ', [
            'material_id' => $material->id,
            'page_count' => count($pages),
        ]);

        $pageData = [];

        foreach ($pages as $index => $page) {
            $pageNumber = $index + 1;

            \Log::info('EXTRACTING PAGE TEXT', [
                'material_id' => $material->id,
                'page' => $pageNumber,
            ]);

            $text = trim(
                preg_replace(
                    '/\s+/u',
                    ' ',
                    $page->getText()
                ) ?? ''
            );

            $pageData[] = [
                'page_number' => $pageNumber,
                'text' => $text,
            ];
        }

        \Log::info('ALL PAGE TEXT EXTRACTED', [
            'material_id' => $material->id,
        ]);

        $completeText = collect($pageData)
            ->map(fn($page) => $page['text'])
            ->filter()
            ->implode("\n\n");

        $completeText = trim($completeText);

        if ($completeText === '') {
            throw new RuntimeException(
                'No readable text was found in the PDF.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | IMAGE RENDERING
    |--------------------------------------------------------------------------
    */

        \Log::info('STARTING PDF PAGE RENDERING', [
            'material_id' => $material->id,
            'page_count' => count($pages),
        ]);

        $renderedPages = $this->renderPages(
            $path,
            $material->id,
            count($pages)
        );

        \Log::info('PDF PAGE RENDERING FINISHED', [
            'material_id' => $material->id,
            'page_snapshot_count' => count($renderedPages),
        ]);

        /*
    |--------------------------------------------------------------------------
    | EMBEDDED VISUAL EXTRACTION
    |--------------------------------------------------------------------------
    |
    | Page snapshots are useful as a source reference, but they are not the
    | same thing as instructional visuals contained inside the PDF. Extract
    | raster image objects separately so diagrams, screenshots, photographs,
    | and other embedded visuals can later be retrieved by the Tutor.
    |
    */

        $visualExtractionBinary = trim((string) env('PDFIMAGES_BINARY', 'pdfimages'));
        $resolvedPdfImagesBinary = $this->resolveCommandPath($visualExtractionBinary);
        $visualExtractionAvailable = $resolvedPdfImagesBinary !== null;

        // Always prepare the visuals directory. This makes it obvious whether
        // extraction ran but found zero images, versus never running at all.
        $visualStorageDirectory = 'course-material-assets/' . $material->id . '/visuals';

        Storage::disk('local')->deleteDirectory($visualStorageDirectory);
        Storage::disk('local')->makeDirectory($visualStorageDirectory);

        \Log::info('PDF VISUAL STORAGE PREPARED', [
            'material_id' => $material->id,
            'relative_path' => $visualStorageDirectory,
            'absolute_path' => $this->localDiskPath($visualStorageDirectory),
            'pdfimages_configured' => $visualExtractionBinary,
            'pdfimages_resolved' => $resolvedPdfImagesBinary,
            'platform' => PHP_OS_FAMILY,
        ]);

        $extractedVisuals = $visualExtractionAvailable
            ? $this->extractEmbeddedVisuals(
                $path,
                $material->id,
                count($pages),
                $resolvedPdfImagesBinary
            )
            : [];

        if (!$visualExtractionAvailable) {
            \Log::warning('PDF EMBEDDED VISUAL EXTRACTION UNAVAILABLE', [
                'material_id' => $material->id,
                'binary' => $visualExtractionBinary,
                'platform' => PHP_OS_FAMILY,
                'PATH' => getenv('PATH') ?: null,
            ]);
        }

        $extractedVisualCount = collect($extractedVisuals)
            ->sum(fn(array $items) => count($items));

        \Log::info('PDF EMBEDDED VISUAL EXTRACTION FINISHED', [
            'material_id' => $material->id,
            'extracted_visual_count' => $extractedVisualCount,
        ]);

        /*
    |--------------------------------------------------------------------------
    | SAVE PAGE DATA
    |--------------------------------------------------------------------------
    */

        DB::transaction(function () use (
            $material,
            $pageData,
            $renderedPages,
            $extractedVisuals
        ) {
            \Log::info('SAVING PDF PAGE RECORDS', [
                'material_id' => $material->id,
                'page_count' => count($pageData),
            ]);

            $material->pages()
                ->each(function ($page) {
                    $page->visuals()->delete();
                    $page->delete();
                });

            foreach ($pageData as $page) {
                $pageModel = CourseMaterialPage::create([
                    'course_material_id' => $material->id,
                    'page_number' => $page['page_number'],
                    'text' => $page['text'] ?: null,
                    'metadata' => [
                        'source' => 'pdf',
                    ],
                ]);

                if (isset($renderedPages[$page['page_number']])) {
                    $rendered = $renderedPages[$page['page_number']];

                    CourseMaterialVisual::create([
                        'course_material_id' =>
                        $material->id,

                        'course_material_page_id' =>
                        $pageModel->id,

                        'type' => 'page_snapshot',

                        'file_path' =>
                        $rendered['path'],

                        'file_name' =>
                        $rendered['file_name'],

                        'mime_type' =>
                        'image/png',

                        'caption' =>
                        'PDF page ' .
                            $page['page_number'],

                        'metadata' => [
                            'page_number' =>
                            $page['page_number'],

                            'source' =>
                            'pdf-render',
                        ],

                        'sort_order' => 1,
                    ]);
                }

                foreach (($extractedVisuals[$page['page_number']] ?? []) as $index => $visual) {
                    CourseMaterialVisual::create([
                        'course_material_id' => $material->id,
                        'course_material_page_id' => $pageModel->id,
                        'type' => 'embedded_image',
                        'file_path' => $visual['path'],
                        'file_name' => $visual['file_name'],
                        'mime_type' => 'image/png',
                        'caption' => 'Visual extracted from PDF page ' . $page['page_number'],
                        'metadata' => [
                            'page_number' => $page['page_number'],
                            'source' => 'pdfimages',
                            'width' => $visual['width'],
                            'height' => $visual['height'],
                            'sha256' => $visual['sha256'],
                        ],
                        'sort_order' => $index + 2,
                    ]);
                }
            }

            \Log::info('PDF PAGE RECORDS SAVED', [
                'material_id' => $material->id,
            ]);
        });

        \Log::info('PDF EXTRACTION COMPLETED', [
            'material_id' => $material->id,
            'page_count' => count($pageData),
            'page_snapshot_count' => count($renderedPages),
            'visual_count' => $extractedVisualCount,
        ]);

        return [
            'text' => $completeText,
            'page_count' => count($pageData),
            'page_snapshot_count' => count($renderedPages),
            'visual_count' => $extractedVisualCount,
            'visual_extraction_engine' => 'pdfimages',
            'visual_extraction_available' => $visualExtractionAvailable,
            'pages' => $pageData,
        ];
    }

    /**
     * Extract raster image objects embedded in the PDF.
     *
     * This is deliberately separate from page rendering. Full-page PNGs are
     * stored under /pages; true embedded visuals are stored under /visuals.
     *
     * Poppler's pdfimages is used because Imagick renders an entire PDF page
     * and does not expose individual PDF image XObjects reliably.
     *
     * Vector-only diagrams are not image objects and therefore are not
     * extracted by this first-stage method. They can be handled later by a
     * visual-region detector operating on the rendered page snapshot.
     */
    protected function extractEmbeddedVisuals(
        string $pdfPath,
        int $materialId,
        int $pageCount,
        string $binary
    ): array {
        $results = [];

        $storageDirectory =
            'course-material-assets/' .
            $materialId .
            '/visuals';

        Storage::disk('local')->makeDirectory($storageDirectory);

        /*
        |------------------------------------------------------------------
        | Ask pdfimages what actually exists before extracting anything.
        |------------------------------------------------------------------
        |
        | This gives us a deterministic source of truth. If pdfimages -list
        | reports images but extraction later produces no files, the logs can
        | now show exactly which stage failed.
        */
        $listedImages = $this->listEmbeddedImages($pdfPath, $binary);

        \Log::info('PDFIMAGES LIST FINISHED', [
            'material_id' => $materialId,
            'listed_image_count' => count($listedImages),
            'listed_pages' => array_values(array_unique(array_column($listedImages, 'page'))),
            'images' => $listedImages,
        ]);

        if (empty($listedImages)) {
            return $results;
        }

        $workingRoot = storage_path(
            'app/tmp/course-material-visuals/' . $materialId
        );

        if (is_dir($workingRoot)) {
            $this->deleteDirectory($workingRoot);
        }

        if (!is_dir($workingRoot) && !mkdir($workingRoot, 0775, true) && !is_dir($workingRoot)) {
            \Log::warning('PDF EMBEDDED VISUAL EXTRACTION SKIPPED', [
                'material_id' => $materialId,
                'reason' => 'Could not create temporary extraction directory',
                'path' => $workingRoot,
            ]);

            return $results;
        }

        $minimumWidth = max(1, (int) env('COURSE_MATERIAL_VISUAL_MIN_WIDTH', 120));
        $minimumHeight = max(1, (int) env('COURSE_MATERIAL_VISUAL_MIN_HEIGHT', 80));
        $minimumArea = max(1, (int) env('COURSE_MATERIAL_VISUAL_MIN_AREA', 15000));
        $seenHashes = [];

        $pagesWithImages = array_values(array_unique(array_map(
            static fn(array $item) => (int) $item['page'],
            $listedImages
        )));
        sort($pagesWithImages, SORT_NUMERIC);

        try {
            foreach ($pagesWithImages as $page) {
                if ($page < 1 || $page > $pageCount) {
                    continue;
                }

                $pageWorkDirectory = $workingRoot . DIRECTORY_SEPARATOR . 'page-' . $page;

                if (!is_dir($pageWorkDirectory) && !mkdir($pageWorkDirectory, 0775, true) && !is_dir($pageWorkDirectory)) {
                    \Log::warning('PDFIMAGES PAGE WORK DIRECTORY FAILED', [
                        'material_id' => $materialId,
                        'page' => $page,
                        'path' => $pageWorkDirectory,
                    ]);
                    continue;
                }

                $prefix = $pageWorkDirectory . DIRECTORY_SEPARATOR . 'visual';

                $command = [
                    $binary,
                    '-f',
                    (string) $page,
                    '-l',
                    (string) $page,
                    '-png',
                    $pdfPath,
                    $prefix,
                ];

                \Log::info('PDFIMAGES PAGE EXTRACTION START', [
                    'material_id' => $materialId,
                    'page' => $page,
                    'command' => $command,
                    'work_directory' => $pageWorkDirectory,
                ]);

                [$exitCode, $commandOutput, $commandError] = $this->runProcess($command);

                if ($exitCode !== 0) {
                    \Log::warning('PDFIMAGES PAGE EXTRACTION FAILED', [
                        'material_id' => $materialId,
                        'page' => $page,
                        'exit_code' => $exitCode,
                        'stdout' => $commandOutput,
                        'stderr' => $commandError,
                        'binary' => $binary,
                        'pdf_path' => $pdfPath,
                        'prefix' => $prefix,
                    ]);
                    continue;
                }

                $allGeneratedFiles = glob($pageWorkDirectory . DIRECTORY_SEPARATOR . '*') ?: [];
                $files = glob($pageWorkDirectory . DIRECTORY_SEPARATOR . '*.png') ?: [];
                sort($files, SORT_NATURAL);

                \Log::info('PDFIMAGES PAGE EXTRACTION OUTPUT', [
                    'material_id' => $materialId,
                    'page' => $page,
                    'generated_files' => array_map('basename', $allGeneratedFiles),
                    'png_count' => count($files),
                    'stdout' => $commandOutput,
                    'stderr' => $commandError,
                ]);

                if (empty($files)) {
                    \Log::warning('PDFIMAGES RETURNED NO PNG FILES FOR LISTED PAGE', [
                        'material_id' => $materialId,
                        'page' => $page,
                        'listed_images_for_page' => array_values(array_filter(
                            $listedImages,
                            static fn(array $item) => (int) $item['page'] === $page
                        )),
                        'generated_files' => array_map('basename', $allGeneratedFiles),
                        'work_directory' => $pageWorkDirectory,
                    ]);
                    continue;
                }

                $storedIndex = 0;

                foreach ($files as $file) {
                    try {
                        $probe = new Imagick();
                        $probe->pingImage($file);
                        $width = (int) $probe->getImageWidth();
                        $height = (int) $probe->getImageHeight();
                        $probe->clear();
                        $probe->destroy();
                    } catch (\Throwable $e) {
                        \Log::warning('EXTRACTED PDF VISUAL COULD NOT BE INSPECTED', [
                            'material_id' => $materialId,
                            'page' => $page,
                            'file' => $file,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }

                    if (
                        $width < $minimumWidth ||
                        $height < $minimumHeight ||
                        ($width * $height) < $minimumArea
                    ) {
                        \Log::info('EXTRACTED PDF VISUAL FILTERED BY SIZE', [
                            'material_id' => $materialId,
                            'page' => $page,
                            'file' => basename($file),
                            'width' => $width,
                            'height' => $height,
                            'minimum_width' => $minimumWidth,
                            'minimum_height' => $minimumHeight,
                            'minimum_area' => $minimumArea,
                        ]);
                        continue;
                    }

                    $sha256 = hash_file('sha256', $file);

                    if (isset($seenHashes[$sha256])) {
                        \Log::info('EXTRACTED PDF VISUAL SKIPPED AS DUPLICATE', [
                            'material_id' => $materialId,
                            'page' => $page,
                            'file' => basename($file),
                            'sha256' => $sha256,
                        ]);
                        continue;
                    }

                    $seenHashes[$sha256] = true;
                    $storedIndex++;

                    $pageDirectory =
                        $storageDirectory .
                        '/page-' .
                        str_pad((string) $page, 4, '0', STR_PAD_LEFT);

                    Storage::disk('local')->makeDirectory($pageDirectory);

                    $fileName =
                        'visual-' .
                        str_pad((string) $storedIndex, 3, '0', STR_PAD_LEFT) .
                        '.png';

                    $relativePath = $pageDirectory . '/' . $fileName;
                    $bytes = file_get_contents($file);

                    if ($bytes === false) {
                        \Log::warning('EXTRACTED PDF VISUAL COULD NOT BE READ', [
                            'material_id' => $materialId,
                            'page' => $page,
                            'temporary_file' => $file,
                        ]);
                        continue;
                    }

                    try {
                        $written = Storage::disk('local')->put($relativePath, $bytes);
                        $existsAfterWrite = Storage::disk('local')->exists($relativePath);
                    } catch (\Throwable $e) {
                        \Log::error('EXTRACTED PDF VISUAL STORAGE EXCEPTION', [
                            'material_id' => $materialId,
                            'page' => $page,
                            'relative_path' => $relativePath,
                            'absolute_path' => $this->localDiskPath($relativePath),
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }

                    if (!$written || !$existsAfterWrite) {
                        \Log::error('EXTRACTED PDF VISUAL STORAGE FAILED', [
                            'material_id' => $materialId,
                            'page' => $page,
                            'relative_path' => $relativePath,
                            'absolute_path' => $this->localDiskPath($relativePath),
                            'written' => $written,
                            'exists_after_write' => $existsAfterWrite,
                        ]);
                        continue;
                    }

                    \Log::info('EXTRACTED PDF VISUAL STORED', [
                        'material_id' => $materialId,
                        'page' => $page,
                        'relative_path' => $relativePath,
                        'absolute_path' => $this->localDiskPath($relativePath),
                        'width' => $width,
                        'height' => $height,
                        'bytes' => strlen($bytes),
                    ]);

                    $results[$page][] = [
                        'path' => $relativePath,
                        'file_name' => $fileName,
                        'width' => $width,
                        'height' => $height,
                        'sha256' => $sha256,
                    ];
                }
            }
        } finally {
            if (is_dir($workingRoot)) {
                $this->deleteDirectory($workingRoot);
            }
        }

        return $results;
    }

    /**
     * Read pdfimages' own inventory so extraction is driven by the images that
     * Poppler actually reports, rather than scanning every page blindly.
     *
     * @return array<int,array{page:int,num:int,type:string,width:int,height:int}>
     */
    protected function listEmbeddedImages(string $pdfPath, string $binary): array
    {
        [$exitCode, $stdout, $stderr] = $this->runProcess([
            $binary,
            '-list',
            $pdfPath,
        ]);

        if ($exitCode !== 0) {
            \Log::warning('PDFIMAGES LIST FAILED', [
                'exit_code' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'binary' => $binary,
                'pdf_path' => $pdfPath,
            ]);

            return [];
        }

        $items = [];
        $lines = preg_split('/\R+/', $stdout) ?: [];

        foreach ($lines as $line) {
            if (!preg_match(
                '/^\s*(\d+)\s+(\d+)\s+(\S+)\s+(\d+)\s+(\d+)\s+/u',
                $line,
                $matches
            )) {
                continue;
            }

            $type = strtolower($matches[3]);

            // We only want actual raster image objects. Image masks and other
            // auxiliary PDF objects are not useful instructional visuals.
            if ($type !== 'image') {
                continue;
            }

            $items[] = [
                'page' => (int) $matches[1],
                'num' => (int) $matches[2],
                'type' => $type,
                'width' => (int) $matches[4],
                'height' => (int) $matches[5],
            ];
        }

        return $items;
    }

    /**
     * Resolve an executable in a cross-platform way.
     *
     * The old implementation used `command -v`, which is a Unix shell command
     * and therefore reported pdfimages as unavailable on Windows even when it
     * was installed and callable from PowerShell/CMD.
     */
    protected function resolveCommandPath(string $binary): ?string
    {
        $binary = trim($binary, " \t\n\r\0\x0B\"'");

        if ($binary === '') {
            return null;
        }

        // Explicit path supplied through PDFIMAGES_BINARY.
        if (
            str_contains($binary, '/') ||
            str_contains($binary, '\\') ||
            preg_match('/^[A-Za-z]:[\\\\\/]/', $binary)
        ) {
            return is_file($binary) ? $binary : null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // where.exe is the Windows equivalent of `command -v` / `which`.
            [$exitCode, $stdout] = $this->runProcess([
                'where.exe',
                $binary,
            ]);

            if ($exitCode !== 0) {
                return null;
            }

            $candidates = preg_split('/\R+/', trim($stdout)) ?: [];

            foreach ($candidates as $candidate) {
                $candidate = trim($candidate, " \t\n\r\0\x0B\"");

                if ($candidate !== '' && is_file($candidate)) {
                    return $candidate;
                }
            }

            return null;
        }

        [$exitCode, $stdout] = $this->runProcess([
            'sh',
            '-lc',
            'command -v ' . escapeshellarg($binary),
        ]);

        if ($exitCode !== 0) {
            return null;
        }

        $resolved = trim($stdout);

        return $resolved !== '' && is_file($resolved)
            ? $resolved
            : null;
    }

    /**
     * Run an external command using argument arrays so Windows paths containing
     * spaces do not have to pass through fragile shell quoting.
     *
     * @return array{0:int,1:string,2:string}
     */
    protected function runProcess(array $command): array
    {
        if (class_exists(Process::class)) {
            try {
                $process = new Process($command);
                $process->setTimeout(120);
                $process->run();

                return [
                    $process->getExitCode() ?? 1,
                    $process->getOutput(),
                    $process->getErrorOutput(),
                ];
            } catch (\Throwable $e) {
                return [1, '', $e->getMessage()];
            }
        }

        // Very old/minimal installations may not have symfony/process. Keep a
        // fallback that still quotes each argument independently.
        if (!function_exists('exec')) {
            return [1, '', 'Neither symfony/process nor PHP exec() is available.'];
        }

        $shellCommand = implode(
            ' ',
            array_map(
                static fn($part) => escapeshellarg((string) $part),
                $command
            )
        );

        $lines = [];
        $exitCode = 0;
        exec($shellCommand . ' 2>&1', $lines, $exitCode);

        return [$exitCode, implode("\n", $lines), ''];
    }

    /**
     * Return the physical path used by the local disk for diagnostic logging.
     */
    protected function localDiskPath(string $relativePath): ?string
    {
        try {
            return Storage::disk('local')->path($relativePath);
        } catch (\Throwable) {
            $root = config('filesystems.disks.local.root');

            if (!is_string($root) || trim($root) === '') {
                return null;
            }

            return rtrim($root, '/\\') . DIRECTORY_SEPARATOR .
                ltrim($relativePath, '/\\');
        }
    }

    /**
     * Recursively delete a temporary directory.
     */
    protected function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    /**
     * Render PDF pages as PNG images.
     *
     * Returns:
     *
     * [
     *     1 => [
     *         'path' => '...',
     *         'file_name' => '...'
     *     ]
     * ]
     */
    protected function renderPages(
        string $pdfPath,
        int $materialId,
        int $pageCount
    ): array {
        $results = [];

        $directory =
            'course-material-assets/' .
            $materialId .
            '/pages';

        /*
        |--------------------------------------------------------------------------
        | Remove previously generated page images
        |--------------------------------------------------------------------------
        */

        $existingFiles = Storage::disk('local')
            ->files($directory);

        if (!empty($existingFiles)) {
            Storage::disk('local')
                ->delete($existingFiles);
        }

        /*
        |--------------------------------------------------------------------------
        | Render each page
        |--------------------------------------------------------------------------
        */

        for ($page = 1; $page <= $pageCount; $page++) {

            \Log::info('RENDERING PDF PAGE START', [
                'material_id' => $materialId,
                'page' => $page,
                'page_count' => $pageCount,
            ]);

            $image = new Imagick();

            try {
                $image->setResolution(150, 150);

                \Log::info('IMAGICK READING PAGE', [
                    'material_id' => $materialId,
                    'page' => $page,
                ]);

                $image->readImage(
                    $pdfPath . '[' . ($page - 1) . ']'
                );

                \Log::info('IMAGICK READ PAGE SUCCESS', [
                    'material_id' => $materialId,
                    'page' => $page,
                ]);

                $image->setImageFormat('png');
                $image->setImageBackgroundColor('white');

                if (method_exists($image, 'setImageAlphaChannel')) {
                    $image->setImageAlphaChannel(
                        Imagick::ALPHACHANNEL_REMOVE
                    );
                }

                $image->stripImage();

                $fileName =
                    'page-' .
                    str_pad(
                        (string) $page,
                        4,
                        '0',
                        STR_PAD_LEFT
                    ) .
                    '.png';

                $relativePath =
                    $directory . '/' . $fileName;

                Storage::disk('local')->put(
                    $relativePath,
                    $image->getImageBlob()
                );

                \Log::info('PDF PAGE SAVED', [
                    'material_id' => $materialId,
                    'page' => $page,
                    'path' => $relativePath,
                ]);

                $results[$page] = [
                    'path' => $relativePath,
                    'file_name' => $fileName,
                ];
            } finally {
                $image->clear();
                $image->destroy();

                \Log::info('RENDERING PDF PAGE FINISHED', [
                    'material_id' => $materialId,
                    'page' => $page,
                ]);
            }
        }

        return $results;
    }

    /**
     * Resolve the stored course material path.
     */
    private function resolveStoredPath(
        string $relativePath
    ): string {
        $configuredRoot =
            config(
                'filesystems.disks.local.root',
                storage_path('app')
            );

        $configuredPath =
            rtrim(
                $configuredRoot,
                DIRECTORY_SEPARATOR
            )
            . DIRECTORY_SEPARATOR
            . ltrim(
                $relativePath,
                DIRECTORY_SEPARATOR
            );

        if (is_file($configuredPath)) {
            return $configuredPath;
        }

        $privatePath =
            storage_path(
                'app/private/' .
                    ltrim(
                        $relativePath,
                        DIRECTORY_SEPARATOR
                    )
            );

        if (is_file($privatePath)) {
            return $privatePath;
        }

        throw new RuntimeException(
            'The stored course-material file could not be found.'
        );
    }
}

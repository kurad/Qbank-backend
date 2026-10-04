<?php

namespace App\Jobs;

use App\Models\CourseMaterial;
use App\Services\CourseMaterialTextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessCourseMaterialJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * PDF processing can take some time because
     * every page may be rendered through Imagick.
     */
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public int $courseMaterialId
    ) {}

    public function handle(
        CourseMaterialTextExtractor $textExtractor
    ): void {
        $material = CourseMaterial::find($this->courseMaterialId);

        if (!$material) {
            return;
        }

        $material->update([
            'processing_status' => 'processing',
            'processing_stage' => 'extracting',
            'processing_progress' => 10,
            'processing_error' => null,
            'processing_started_at' => now(),
            'processing_completed_at' => null,

            'extraction_status' => 'processing',
            'visual_extraction_status' => 'processing',

            'extraction_error' => null,
            'visual_extraction_error' => null,
        ]);

        try {

            $result = $textExtractor->extract($material->fresh());

            $extractedText = trim($result['text'] ?? '');

            if ($extractedText === '') {
                throw new \RuntimeException(
                    'No readable text was found in the PDF.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Extraction successful
            |--------------------------------------------------------------------------
            */

            $visualCount = (int) ($result['visual_count'] ?? 0);
            $visualExtractionAvailable = (bool) ($result['visual_extraction_available'] ?? false);

            $visualStatus = $visualExtractionAvailable
                ? ($visualCount > 0 ? 'ready' : 'empty')
                : 'unavailable';

            $material->update([
                'extracted_text' => $extractedText,

                'extraction_status' => 'ready',
                'visual_extraction_status' => $visualStatus,

                'extracted_at' => now(),
                'visual_extracted_at' => now(),

                'extraction_error' => null,
                'visual_extraction_error' => $visualStatus === 'unavailable'
                    ? 'The PDF visual extractor is not available on this server.'
                    : null,

                'processing_status' => 'processing',
                'processing_stage' => 'analyzing',
                'processing_progress' => 55,
                'processing_error' => null,

                'status' => 'draft',
            ]);
        } catch (Throwable $e) {
            $material->update([
                'processing_status' => 'failed',
                'processing_stage' => 'failed',
                'processing_progress' => 0,
                'processing_error' => $e->getMessage(),

                'extraction_status' => 'failed',
                'visual_extraction_status' => 'failed',

                'extraction_error' => $e->getMessage(),
                'visual_extraction_error' => $e->getMessage(),

                'processing_completed_at' => now(),
                'status' => 'draft',
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        CourseMaterial::whereKey($this->courseMaterialId)
            ->update([
                'processing_status' => 'failed',
                'processing_stage' => 'failed',
                'processing_progress' => 0,
                'processing_error' => $exception->getMessage(),
                'processing_completed_at' => now(),
                'status' => 'draft',
            ]);
    }
}
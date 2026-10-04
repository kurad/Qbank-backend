<?php

namespace App\Jobs;

use App\Models\CourseMaterial;
use App\Services\CurriculumAnalyzerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AnalyzeCourseMaterialJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * AI requests may take some time.
     */
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public int $courseMaterialId,
        public bool $forceFreshAnalysis = false
    ) {}

    public function handle(
        CurriculumAnalyzerService $analyzer
    ): void {
        $material = CourseMaterial::find($this->courseMaterialId);

        if (!$material) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Make sure extraction succeeded
        |--------------------------------------------------------------------------
        */

        if (
            $material->extraction_status !== 'ready' ||
            trim((string) $material->extracted_text) === ''
        ) {
            throw new \RuntimeException(
                'Course material extraction is not ready for curriculum analysis.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Mark AI analysis as processing
        |--------------------------------------------------------------------------
        */

        $material->update([
            'processing_status' => 'processing',
            'processing_stage' => 'analyzing',
            'processing_progress' => 60,
            'processing_error' => null,
        ]);

        try {
            /*
            |--------------------------------------------------------------------------
            | Run existing curriculum analyzer
            |--------------------------------------------------------------------------
            */

            $instruction = $this->forceFreshAnalysis
                ? 'Re-analyze this course material from the current extracted source content. Create a fresh curriculum proposal from the source material rather than returning an earlier ready analysis.'
                : null;

            $analysis = $analyzer->analyze(
                $material->fresh(),
                (int) $material->teacher_id,
                $this->resolveSchoolId($material),
                $instruction,
                null
            );

            /*
            |--------------------------------------------------------------------------
            | Analysis completed
            |--------------------------------------------------------------------------
            */

            $material->update([
                'processing_status' => 'ready',
                'processing_stage' => 'ready',
                'processing_progress' => 100,
                'processing_error' => null,
                'processing_completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $material->update([
                'processing_status' => 'failed',
                'processing_stage' => 'failed',
                'processing_progress' => 0,
                'processing_error' => $e->getMessage(),
                'processing_completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Resolve the school through the Teaching Area.
     *
     * This keeps the job independent from the authenticated
     * HTTP request because queue workers have no Auth::user().
     */
    protected function resolveSchoolId(
        CourseMaterial $material
    ): ?int {
        $material->loadMissing(
            'unit.gradeSubject'
        );

        return $material->unit?->gradeSubject?->school_id !== null
            ? (int) $material->unit->gradeSubject->school_id
            : null;
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
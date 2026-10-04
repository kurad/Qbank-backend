<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeCourseMaterialJob;
use App\Jobs\ProcessCourseMaterialJob;
use Illuminate\Support\Facades\Bus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCourseMaterialRequest;
use App\Models\CourseMaterial;
use App\Models\CourseMaterialVisual;
use App\Models\Unit;
use App\Services\CourseMaterialTextExtractor;
use App\Services\KnowledgeChunkingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CourseMaterialController extends Controller
{
    public function __construct(
        protected CourseMaterialTextExtractor $textExtractor,
        protected KnowledgeChunkingService $chunkingService
    ) {}

    /**
     * List course materials.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = CourseMaterial::query()
            ->with([
                'teacher:id,name,email',
                'subject:id,name',
                'unit:id,name,grade_subject_id',
                'topic:id,topic_name,unit_id',
                'curriculumAnalyses',
            ])
            ->withCount([
                'pages as page_count',
                'visuals as visual_count' => function ($q) {
                    $q->where('type', '!=', 'page_snapshot');
                },
                'visuals as page_snapshot_count' => function ($q) {
                    $q->where('type', 'page_snapshot');
                },
            ]);

        /*
        |--------------------------------------------------------------------------
        | Teacher
        |--------------------------------------------------------------------------
        */

        if ($user->role === 'teacher') {
            $query->whereHas('unit.gradeSubject', function ($q) use ($user) {
                $q->where('school_id', $user->school_id)
                    ->where('teacher_id', $user->id);
            });
        } elseif ($user->role === 'admin') {
            $query->whereHas('unit.gradeSubject', function ($q) use ($user) {
                $q->where('school_id', $user->school_id);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Student
        |--------------------------------------------------------------------------
        */ elseif ($user->role === 'student') {
            $query->where('status', 'approved');
        }

        /*
        |--------------------------------------------------------------------------
        | Admin
        |--------------------------------------------------------------------------
        */ elseif ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Filters
        |--------------------------------------------------------------------------
        */

        if ($request->filled('unit_id')) {
            $query->where(
                'unit_id',
                $request->integer('unit_id')
            );
        }

        if ($request->filled('topic_id')) {
            $query->where(
                'topic_id',
                $request->integer('topic_id')
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->input('status')
            );
        }

        if ($request->filled('scope')) {
            $query->where(
                'scope',
                $request->input('scope')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        return response()->json(
            $query
                ->latest('id')
                ->paginate(
                    $request->integer('per_page', 20)
                )
        );
    }

    /**
     * Store a new course material.
     */
    public function store(StoreCourseMaterialRequest $request)
    {
        $user = Auth::user();

        $unit = Unit::with('gradeSubject')->findOrFail($request->integer('unit_id'));
        $this->ownsUnit($unit);
        $gradeSubject = $unit->gradeSubject;

        if (!$gradeSubject) {
            return response()->json([
                'message' =>
                'The selected unit is not associated with a teaching area.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | PDF only
        |--------------------------------------------------------------------------
        */

        $file = $request->file('material_file');

        if (!$file) {
            return response()->json([
                'message' => 'A PDF file is required.',
            ], 422);
        }

        if (
            strtolower(
                $file->getClientOriginalExtension()
            ) !== 'pdf'
        ) {
            return response()->json([
                'message' =>
                'Only PDF course materials are supported.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Determine topic
        |--------------------------------------------------------------------------
        */

        $topicId = null;

        if (
            $request->input('scope') === 'topic'
        ) {
            $topicId = $request->integer('topic_id');

            if (!$topicId) {
                return response()->json([
                    'message' =>
                    'A topic is required for topic-level material.',
                ], 422);
            }

            $topic = $unit->topics()
                ->whereKey($topicId)
                ->first();

            if (!$topic) {
                return response()->json([
                    'message' =>
                    'The selected topic does not belong to the selected unit.',
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Store file
        |--------------------------------------------------------------------------
        */

        $hash = hash_file(
            'sha256',
            $file->getRealPath()
        );

        $duplicate = CourseMaterial::query()
            ->where('teacher_id', $user->id)
            ->where('unit_id', $unit->id)
            ->where('file_hash', $hash)
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' =>
                'This course material has already been uploaded.',
                'data' => $duplicate,
            ], 422);
        }

        $path = $file->store(
            'course-materials',
            'local'
        );

        /*
        |--------------------------------------------------------------------------
        | Create material
        |--------------------------------------------------------------------------
        */

        $material = CourseMaterial::create([
            'teacher_id' => $user->id,

            'subject_id' => $gradeSubject->subject_id,

            'unit_id' => $unit->id,

            'topic_id' => $topicId,

            'scope' => $request->input('scope'),

            'title' => $request->input('title'),

            'description' => $request->input('description'),

            'original_file_path' => $path,

            'file_name' => $file->getClientOriginalName(),

            'file_type' => 'application/pdf',

            'file_hash' => $hash,

            'status' => 'draft',

            'extraction_status' => 'pending',

            'visual_extraction_status' => 'pending',

            'processing_status' => 'queued',

            'processing_stage' => 'queued',

            'processing_progress' => 0,

            'processing_error' => null,
        ]);

        /*
|--------------------------------------------------------------------------
| Start background processing
|--------------------------------------------------------------------------
*/

        $jobs = [
            new ProcessCourseMaterialJob($material->id),
        ];

        if ($material->scope === 'unit') {
            $jobs[] = new AnalyzeCourseMaterialJob($material->id);
        }

        Bus::chain($jobs)->dispatch();

        return response()->json([
            'message' =>
            'Course material uploaded successfully. Processing has started.',

            'data' =>
            $material->fresh()->load([
                'teacher',
                'subject',
                'unit',
                'topic',
            ]),
        ], 201);
    }
    /**
     * Re-run extraction and curriculum analysis using the already stored PDF.
     *
     * The original upload is preserved. A fresh curriculum-analysis version is
     * generated for unit-level material, while previously approved curriculum
     * remains untouched until the teacher explicitly approves the new proposal.
     */
    public function reanalyze(CourseMaterial $courseMaterial)
    {
        $this->ownsMaterial($courseMaterial);

        if (in_array($courseMaterial->processing_status, ['queued', 'processing'], true)) {
            return response()->json([
                'message' => 'This course material is already being processed.',
            ], 409);
        }

        if (
            strtolower(pathinfo($courseMaterial->file_name, PATHINFO_EXTENSION)) !== 'pdf'
        ) {
            return response()->json([
                'message' => 'Only PDF course materials can be re-analyzed.',
            ], 422);
        }

        if (
            !$courseMaterial->original_file_path ||
            !Storage::disk('local')->exists($courseMaterial->original_file_path)
        ) {
            return response()->json([
                'message' => 'The original PDF file could not be found in storage.',
            ], 422);
        }

        $courseMaterial->update([
            'processing_status' => 'queued',
            'processing_stage' => 'queued',
            'processing_progress' => 0,
            'processing_error' => null,
            'processing_started_at' => null,
            'processing_completed_at' => null,

            'extraction_status' => 'pending',
            'visual_extraction_status' => 'pending',
            'extraction_error' => null,
            'visual_extraction_error' => null,

            // A re-analysis produces a proposal that must be reviewed again.
            'status' => 'draft',
        ]);

        $jobs = [
            new ProcessCourseMaterialJob($courseMaterial->id),
        ];

        if ($courseMaterial->scope === 'unit') {
            $jobs[] = new AnalyzeCourseMaterialJob(
                $courseMaterial->id,
                true
            );
        }

        Bus::chain($jobs)->dispatch();

        return response()->json([
            'message' => 'Re-analysis has started using the existing PDF.',
            'data' => $courseMaterial->fresh(),
        ], 202);
    }

    /**
     * Return background processing status for a course material.
     */
    public function processingStatus(
        CourseMaterial $courseMaterial
    ) {
        $this->ownsMaterial($courseMaterial);

        $courseMaterial->load(['curriculumAnalyses' => function ($query) {$query->latest('id');},
        ]);

        $latestAnalysis = $courseMaterial->curriculumAnalyses->first();

        return response()->json([
            'data' => [
                'id' => $courseMaterial->id,
                'processing_status' => $courseMaterial->processing_status,
                'processing_stage' => $courseMaterial->processing_stage,
                'processing_progress' => (int) $courseMaterial->processing_progress,
                'processing_error' => $courseMaterial->processing_error,
                'extraction_status' => $courseMaterial->extraction_status,
                'visual_extraction_status' => $courseMaterial->visual_extraction_status,
                'curriculum_analysis_id' => $latestAnalysis?->id,
                'curriculum_analysis_status' => $latestAnalysis?->status,
                'processing_started_at' => $courseMaterial->processing_started_at,
                'processing_completed_at' => $courseMaterial->processing_completed_at,
            ],
        ]);
    }

    /**
     * Extract PDF text, pages and visual snapshots.
     */
    public function extract(CourseMaterial $courseMaterial)
    {
        $this->ownsMaterial($courseMaterial);

        /*
        |--------------------------------------------------------------------------
        | Only PDFs
        |--------------------------------------------------------------------------
        */

        if (
            strtolower(
                pathinfo(
                    $courseMaterial->file_name,
                    PATHINFO_EXTENSION
                )
            ) !== 'pdf'
        ) {
            return response()->json([
                'message' =>
                'Only PDF course materials are supported.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Processing state
        |--------------------------------------------------------------------------
        */

        $courseMaterial->update([
            'extraction_status' =>
            'processing',

            'visual_extraction_status' =>
            'processing',

            'extraction_error' =>
            null,

            'visual_extraction_error' =>
            null,
        ]);

        try {
            /*
            |--------------------------------------------------------------------------
            | Extract
            |--------------------------------------------------------------------------
            */

            $result = $this->textExtractor->extract(
                $courseMaterial->fresh()
            );

            $extractedText =
                trim(
                    $result['text'] ?? ''
                );

            if ($extractedText === '') {
                throw new RuntimeException(
                    'No readable text was found in the PDF.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Save extraction state
            |--------------------------------------------------------------------------
            */

            $courseMaterial->update([
                'extracted_text' =>
                $extractedText,

                'extraction_status' =>
                'ready',

                'visual_extraction_status' =>
                (($result['visual_extraction_available'] ?? false)
                    ? (((int) ($result['visual_count'] ?? 0)) > 0 ? 'ready' : 'empty')
                    : 'unavailable'),

                'extracted_at' =>
                now(),

                'visual_extracted_at' =>
                now(),

                'extraction_error' =>
                null,

                'visual_extraction_error' =>
                null,

                'status' =>
                'draft',
            ]);

            return response()->json([
                'message' =>
                'Course material extracted successfully.',

                'data' =>
                $courseMaterial
                    ->fresh()
                    ->load([
                        'pages.visuals',
                    ]),

                'summary' => [
                    'page_count' =>
                    $result['page_count'] ?? 0,

                    'visual_count' =>
                    $result['visual_count'] ?? 0,

                    'text_length' =>
                    mb_strlen($extractedText),
                ],
            ]);
        } catch (\Throwable $e) {
            /*
            |--------------------------------------------------------------------------
            | Mark extraction as failed
            |--------------------------------------------------------------------------
            */

            $courseMaterial->update([
                'extraction_status' =>
                'failed',

                'visual_extraction_status' =>
                'failed',

                'extraction_error' =>
                $e->getMessage(),

                'visual_extraction_error' =>
                $e->getMessage(),

                'status' =>
                'draft',
            ]);

            return response()->json([
                'message' =>
                'Course material extraction failed.',

                'error' =>
                $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Analyze course material as curriculum.
     *
     * Curriculum analysis itself is handled by
     * CurriculumAnalyzerService / CurriculumAnalysisController.
     */
    public function analyzeContent(
        CourseMaterial $courseMaterial
    ) {
        $this->ownsMaterial($courseMaterial);

        if (
            $courseMaterial->extraction_status !== 'ready'
        ) {
            return response()->json([
                'message' =>
                'The course material must be successfully extracted before analysis.',
            ], 422);
        }

        return response()->json([
            'message' =>
            'The course material is ready for curriculum analysis.',

            'data' =>
            $courseMaterial->load([
                'pages.visuals',
            ]),
        ]);
    }

    /**
     * Approve material and create knowledge chunks.
     */
    public function approve(
        CourseMaterial $courseMaterial
    ) {
        $this->ownsMaterial($courseMaterial);

        /*
        |--------------------------------------------------------------------------
        | Validate PDF
        |--------------------------------------------------------------------------
        */

        if (
            strtolower(
                pathinfo(
                    $courseMaterial->file_name,
                    PATHINFO_EXTENSION
                )
            ) !== 'pdf'
        ) {
            return response()->json([
                'message' =>
                'Only PDF course materials are supported.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Ensure extraction exists
        |--------------------------------------------------------------------------
        */

        if (
            $courseMaterial->extraction_status !== 'ready' ||
            $courseMaterial->visual_extraction_status !== 'ready'
        ) {
            return response()->json([
                'message' =>
                'The course material must be successfully extracted before approval.',
            ], 422);
        }

        if (
            !$courseMaterial->extracted_text ||
            trim($courseMaterial->extracted_text) === ''
        ) {
            return response()->json([
                'message' =>
                'The course material does not contain extracted text.',
            ], 422);
        }

        try {
            /*
            |--------------------------------------------------------------------------
            | Create knowledge chunks
            |--------------------------------------------------------------------------
            |
            | Step 5 will upgrade this service so chunks become
            | page-aware and can reference visuals.
            |
            */

            $chunkCount =
                $this->chunkingService->process(
                    $courseMaterial->fresh()
                );

            /*
            |--------------------------------------------------------------------------
            | Approve material
            |--------------------------------------------------------------------------
            */

            $courseMaterial->update([
                'status' =>
                'approved',

                'approved_by' =>
                Auth::id(),

                'approved_at' =>
                now(),

                'extraction_error' =>
                null,

                'visual_extraction_error' =>
                null,
            ]);

            return response()->json([
                'message' =>
                'Course material approved successfully.',

                'data' =>
                $courseMaterial
                    ->fresh()
                    ->load([
                        'pages.visuals',
                        'knowledgeChunks',
                    ]),

                'chunk_count' =>
                is_numeric($chunkCount)
                    ? $chunkCount
                    : null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' =>
                'Course material approval failed.',

                'error' =>
                $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete course material.
     */
    public function destroy(
        CourseMaterial $courseMaterial
    ) {
        $this->ownsMaterial($courseMaterial);

        /*
        |--------------------------------------------------------------------------
        | Delete original file
        |--------------------------------------------------------------------------
        */

        if (
            $courseMaterial->original_file_path &&
            Storage::disk('local')->exists(
                $courseMaterial->original_file_path
            )
        ) {
            Storage::disk('local')->delete(
                $courseMaterial->original_file_path
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Delete derived visual assets
        |--------------------------------------------------------------------------
        */

        $assetDirectory =
            'course-material-assets/' .
            $courseMaterial->id;

        if (
            Storage::disk('local')->exists(
                $assetDirectory
            )
        ) {
            Storage::disk('local')->deleteDirectory(
                $assetDirectory
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Delete database record
        |--------------------------------------------------------------------------
        |
        | Pages and visuals should cascade from CourseMaterial.
        |
        */

        $courseMaterial->delete();

        return response()->json([
            'message' =>
            'Course material deleted successfully.',
        ]);
    }

    /**
     * Show extracted pages and visuals.
     */
    public function pages(
        CourseMaterial $courseMaterial
    ) {
        $this->ownsMaterial($courseMaterial);

        return response()->json([
            'data' =>
            $courseMaterial
                ->load([
                    'pages.visuals',
                ])
                ->pages,
        ]);
    }

    /**
     * Show a specific visual.
     */
    public function visual(
        CourseMaterial $courseMaterial,
        CourseMaterialVisual $visual
    ) {
        $this->ownsMaterial($courseMaterial);

        if (
            (int) $visual->course_material_id !==
            (int) $courseMaterial->id
        ) {
            return response()->json([
                'message' =>
                'The visual does not belong to this course material.',
            ], 404);
        }

        return response()->json([
            'data' => $visual,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Verify that the authenticated teacher owns the Unit
     * through the Teaching Area.
     */
    private function ownsUnit(Unit $unit): void
    {
        $user = Auth::user();

        $unit->loadMissing('gradeSubject');

        $gradeSubject = $unit->gradeSubject;

        abort_unless(
            (
                $gradeSubject &&
                (int) $gradeSubject->school_id === (int) $user->school_id &&
                (
                    $user->role === 'admin' ||
                    (
                        $user->role === 'teacher' &&
                        (int) $gradeSubject->teacher_id === (int) $user->id
                    )
                )
            ),
            403,
            'You are not authorized to access this teaching area.'
        );
    }

    /**
     * Verify ownership through:
     *
     * CourseMaterial
     *     ↓
     * Unit
     *     ↓
     * GradeSubject
     *     ↓
     * Teacher + School
     */
    private function ownsMaterial(
        CourseMaterial $courseMaterial
    ): void {
        $user = Auth::user();

        $courseMaterial->loadMissing(
            'unit.gradeSubject'
        );

        $unit =
            $courseMaterial->unit;

        $gradeSubject =
            $unit?->gradeSubject;

        abort_unless(
            $gradeSubject &&
                (int) $gradeSubject->school_id === (int) $user->school_id &&
                (
                    $user->role === 'admin' ||
                    (
                        $user->role === 'teacher' &&
                        (int) $gradeSubject->teacher_id === (int) $user->id
                    )
                ),
            403,
            'You are not authorized to access this course material.'
        );
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CourseMaterial;
use App\Models\CourseMaterialVisual;
use App\Models\LearningObjective;
use App\Services\CourseMaterialDiagnosticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CourseMaterialDiagnosticsController extends Controller
{
    public function __construct(
        protected CourseMaterialDiagnosticsService $diagnostics
    ) {}

    public function inspect(Request $request, CourseMaterial $courseMaterial)
    {
        $this->authorizeMaterial($courseMaterial);

        $request->validate([
            'search' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json([
            'data' => $this->diagnostics->inspect(
                $courseMaterial,
                $request->string('search')->toString(),
                $request->integer('limit', 80)
            ),
        ]);
    }

    public function testRetrieval(Request $request, CourseMaterial $courseMaterial)
    {
        $this->authorizeMaterial($courseMaterial);

        $data = $request->validate([
            'query' => ['required', 'string', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json([
            'data' => $this->diagnostics->testRetrieval(
                $courseMaterial,
                $data['query'],
                (int) ($data['limit'] ?? 8)
            ),
        ]);
    }

    public function objectiveSupport(
        CourseMaterial $courseMaterial,
        LearningObjective $learningObjective
    ) {
        $this->authorizeMaterial($courseMaterial);

        $courseMaterial->loadMissing('unit.gradeSubject');
        $learningObjective->loadMissing('topic.unit.gradeSubject');

        abort_unless(
            (int) $learningObjective->topic?->unit?->grade_subject_id
                === (int) $courseMaterial->unit?->grade_subject_id,
            422,
            'The selected objective does not belong to the same teaching area as this material.'
        );

        return response()->json([
            'data' => $this->diagnostics->objectiveSupport(
                $courseMaterial,
                $learningObjective
            ),
        ]);
    }

    public function visualContent(
        CourseMaterial $courseMaterial,
        CourseMaterialVisual $visual
    ) {
        $this->authorizeMaterial($courseMaterial);

        abort_unless(
            (int) $visual->course_material_id === (int) $courseMaterial->id,
            404,
            'The visual does not belong to this course material.'
        );

        abort_unless(
            $visual->file_path
                && Storage::disk('local')->exists($visual->file_path),
            404,
            'The extracted visual file could not be found.'
        );

        $path = Storage::disk('local')->path($visual->file_path);
        $mime = $visual->mime_type ?: 'application/octet-stream';

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . ($visual->file_name ?: "visual-{$visual->id}") . '"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    protected function authorizeMaterial(CourseMaterial $courseMaterial): void
    {
        $user = Auth::user();

        $courseMaterial->loadMissing('unit.gradeSubject');
        $gradeSubject = $courseMaterial->unit?->gradeSubject;

        abort_unless(
            $user
                && $gradeSubject
                && (int) $gradeSubject->school_id === (int) $user->school_id
                && (
                    $user->role === 'admin'
                    || (
                        $user->role === 'teacher'
                        && (int) $gradeSubject->teacher_id === (int) $user->id
                    )
                ),
            403,
            'You are not authorized to inspect this course material.'
        );
    }
}

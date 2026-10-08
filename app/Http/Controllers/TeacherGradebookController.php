<?php

namespace App\Http\Controllers;

use App\Models\StudentAnswer;
use App\Models\User;
use App\Services\TeacherGradebookService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherGradebookController extends Controller
{
    public function __construct(
        protected TeacherGradebookService $gradebook
    ) {
    }

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->gradebook->overview(
                $request->user(),
                $this->filters($request)
            ),
        ]);
    }

    public function student(Request $request, User $student)
    {
        return response()->json([
            'data' => $this->gradebook->studentProfile(
                $request->user(),
                $student,
                $this->filters($request)
            ),
        ]);
    }

    public function reviewQueue(Request $request)
    {
        return response()->json([
            'data' => $this->gradebook->manualReviewQueue(
                $request->user(),
                $this->filters($request)
            ),
        ]);
    }

    public function reviewAnswer(Request $request, StudentAnswer $answer)
    {
        $validated = $request->validate([
            'points' => ['required', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:3000'],
        ]);

        return response()->json([
            'message' => 'Response graded successfully.',
            'data' => $this->gradebook->reviewAnswer(
                $request->user(),
                $answer,
                (float) $validated['points'],
                $validated['feedback'] ?? null
            ),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $export = $this->gradebook->exportRows(
            $request->user(),
            $this->filters($request)
        );

        $filename = 'revisionhub-gradebook-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($export) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $export['headings']);

            foreach ($export['rows'] as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function filters(Request $request): array
    {
        return $request->validate([
            'group_id' => ['nullable', 'integer'],
            'grade_subject_id' => ['nullable', 'integer'],
            'assessment_type' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
    }
}

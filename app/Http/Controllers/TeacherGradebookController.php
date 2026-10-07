<?php

namespace App\Http\Controllers;

use App\Services\TeacherGradebookService;
use Illuminate\Http\Request;

class TeacherGradebookController extends Controller
{
    public function __construct(
        protected TeacherGradebookService $gradebook
    ) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'group_id' => ['nullable', 'integer'],
            'grade_subject_id' => ['nullable', 'integer'],
            'assessment_type' => ['nullable', 'in:quiz,exam,homework,practice'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $this->gradebook->overview(
                $request->user(),
                $filters
            ),
        ]);
    }

    public function export(Request $request)
    {
        $filters = $request->validate([
            'group_id' => ['nullable', 'integer'],
            'grade_subject_id' => ['nullable', 'integer'],
            'assessment_type' => ['nullable', 'in:quiz,exam,homework,practice'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $export = $this->gradebook->exportRows(
            $request->user(),
            $filters
        );

        $filename = 'revisionhub-gradebook-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($export) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM improves Excel compatibility.
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
}

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


    public function formativeReview(Request $request)
    {
        return response()->json([
            'data' => $this->gradebook->formativeReview(
                $request->user(),
                $this->filters($request)
            ),
        ]);
    }

    /** Assessment-wide question management; never tied to a student submission. */
    public function assessmentQuestions(
        Request $request,
        \App\Models\Assessment $assessment
    ) {
        $user = $request->user();
        abort_unless($user && in_array($user->role, ['teacher', 'admin'], true), 403);
        abort_unless($this->gradebook->canManageAssessment($user, $assessment), 403,
            'You cannot manage this assessment.');

        // Fixed question set for assigned assessments; include personalized
        // questions found in actual student attempts for formative assessments.
        $questionIds = \Illuminate\Support\Facades\DB::table('assessment_questions')
            ->where('assessment_id', $assessment->id)->pluck('question_id');
        $attemptIds = \App\Models\StudentAssessment::query()
            ->where('assessment_id', $assessment->id)->select('id');
        $fromAttempts = \Illuminate\Support\Facades\DB::table('student_answers')
            ->whereIn('student_assessment_id', $attemptIds)
            ->distinct()->pluck('question_id');
        $ids = $questionIds->merge($fromAttempts)->map(fn ($id) => (int) $id)
            ->unique()->values()->all();
        $records = \Illuminate\Support\Facades\DB::table('questions')
            ->whereIn('id', $ids)->get(['id', 'question', 'question_type', 'marks']);
        $excluded = \Illuminate\Support\Facades\DB::table('assessment_question_exclusions')
            ->where('assessment_id', $assessment->id)->get()->keyBy('question_id');
        return response()->json(['data' => [
            'assessment_id' => $assessment->id,
            'questions' => $records->map(function ($question) use ($excluded) {
                $setting = $excluded->get($question->id);
                return [
                    'id' => (int) $question->id,
                    'question' => $question->question,
                    'question_type' => $question->question_type,
                    'marks' => (float) $question->marks,
                    'excluded' => (bool) ($setting->is_excluded ?? false),
                    'reason' => $setting->reason ?? null,
                ];
            })->values(),
            'affected_attempts' => \App\Models\StudentAssessment::query()
                ->where('assessment_id', $assessment->id)->count(),
        ]]);
    }

    public function setQuestionExclusion(
        Request $request,
        \App\Models\Assessment $assessment,
        int $questionId,
        \App\Services\AssessmentQuestionExclusionService $exclusions
    ) {
        $data = $request->validate([
            'excluded' => ['required', 'boolean'],
            'reason' => ['required_if:excluded,true', 'nullable', 'string', 'min:5', 'max:1000'],
        ]);
        $user = $request->user();
        abort_unless($user && in_array($user->role, ['teacher', 'admin'], true), 403);
        $allowed = $this->gradebook->canManageAssessment($user, $assessment);
        abort_unless($allowed, 403, 'You cannot change grading for this assessment.');
        return response()->json(['data' => $exclusions->setExcluded(
            $user, $assessment, $questionId, (bool) $data['excluded'], $data['reason'] ?? null
        )]);
    }

    public function reviewFormativeAnswer(Request $request, StudentAnswer $answer)
    {
        $validated = $request->validate([
            'points' => ['required', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:3000'],
        ]);

        return response()->json([
            'message' => 'Formative response reviewed successfully.',
            'data' => $this->gradebook->reviewFormativeAnswer(
                $request->user(),
                $answer,
                (float) $validated['points'],
                $validated['feedback'] ?? null
            ),
        ]);
    }

    public function reviewAssignedAnswer(Request $request, StudentAnswer $answer)
    {
        $validated = $request->validate([
            'points' => ['required', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:3000'],
        ]);
        return response()->json([
            'message' => 'Assigned assessment response reviewed.',
            'data' => $this->gradebook->reviewAssignedAnswer($request->user(), $answer, (float) $validated['points'], $validated['feedback'] ?? null),
        ]);
    }

    public function publishAssignedResults(Request $request, \App\Models\StudentAssessment $attempt)
    {
        return response()->json([
            'message' => 'Final results published.',
            'data' => $this->gradebook->publishAssignedResults($request->user(), $attempt),
        ]);
    }

    public function assignedPublicationStatus(Request $request, \App\Models\Assessment $assessment)
    {
        $validated = $request->validate(['group_id' => ['nullable', 'integer', 'min:1']]);
        return response()->json(['data' => $this->gradebook->assignedPublicationStatus(
            $request->user(), $assessment, isset($validated['group_id']) ? (int) $validated['group_id'] : null
        )]);
    }

    public function publishAllAssignedResults(Request $request, \App\Models\Assessment $assessment)
    {
        $validated = $request->validate(['group_id' => ['nullable', 'integer', 'min:1']]);
        return response()->json(['message' => 'Reviewed results published.', 'data' => $this->gradebook->publishAllAssignedResults(
            $request->user(), $assessment, isset($validated['group_id']) ? (int) $validated['group_id'] : null
        )]);
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
            'learning_period_id' => ['nullable', 'integer', 'exists:learning_periods,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use Illuminate\Http\Request;
use App\Models\StudentAnswer;
use App\Models\StudentAssessment;
use App\Models\LearnerFormativeAttempt;
use Illuminate\Support\Facades\DB;
use App\Services\LearningPeriodFormativeService;

class StudentAssessmentController extends Controller
{
    public function startAssessment(
        $assessmentId,
        LearningPeriodFormativeService $formativeService
    )
    {
        $studentId = auth()->id();
        $assessment = Assessment::findOrFail($assessmentId);

        $formativeService->assertStudentReadyForAssessment(
            auth()->user(),
            $assessment
        );
        $student = auth()->user();
        abort_unless($student && $student->role === 'student', 403);
        $existing = StudentAssessment::where('student_id', $studentId)
            ->where('assessment_id', $assessmentId)->first();
        $isOwnPractice = $assessment->type === 'practice'
            && (int) $assessment->creator_id === (int) $studentId;
        $isGroupAssignment = $assessment->groups()
            ->whereIn('groups.id', $student->groups()->pluck('groups.id'))->exists();
        abort_unless($existing || $isOwnPractice || $isGroupAssignment, 403,
            'This assessment is not assigned to you.');
        if ($existing && in_array($existing->status, ['completed', 'graded', 'under_review'], true)) {
            return response()->json([
                'message' => 'Assessment already submitted.',
                'student_assessment' => $existing,
            ], 409);
        }
        $studentAssessment = StudentAssessment::firstOrCreate(
            ['student_id' => $studentId, 'assessment_id' => $assessmentId],
            ['assigned_by' => $assessment->creator_id, 'assigned_at' => now(),
             'status' => 'in_progress']
        );
        if (!in_array($studentAssessment->status, ['in_progress'], true)) {
            $studentAssessment->update(['status' => 'in_progress']);
        }
        return response()->json([
            'message' => 'Assessment started successfully',
            'student_assessment' => $studentAssessment,
        ]);
    }
    /**
     * Drafts are not marked or submitted answers. They belong to exactly one
     * authenticated student attempt, and cannot be changed after submission.
     */
    public function getDraft($assessmentId)
    {
        $attempt = StudentAssessment::where('student_id', auth()->id())
            ->where('assessment_id', $assessmentId)->firstOrFail();
        abort_unless($attempt->status === 'in_progress', 409, 'Assessment is no longer in progress.');

        $draft = DB::table('student_assessment_drafts')
            ->where('student_assessment_id', $attempt->id)->first();
        return response()->json([
            'answers' => $draft ? json_decode($draft->answers, true) : new \stdClass(),
            'page_index' => $draft ? (int) $draft->page_index : 0,
            'saved_at' => $draft->updated_at ?? null,
        ]);
    }

    public function saveDraft(Request $request, $assessmentId)
    {
        $validated = $request->validate([
            'answers' => 'required|array',
            'page_index' => 'required|integer|min:0',
        ]);

        $attempt = StudentAssessment::where('student_id', auth()->id())
            ->where('assessment_id', $assessmentId)->firstOrFail();
        abort_unless($attempt->status === 'in_progress', 409, 'Assessment is no longer in progress.');
        abort_if(count($validated['answers']) > 500, 422, 'Too many draft answers.');

        // Accept only question IDs actually belonging to this assessment.
        // The question payload is otherwise kept as-is, including text, arrays
        // for multiple choice and matching, and empty values for cleared answers.
        $assessment = $attempt->assessment;
        $assessmentQuestionIds = $assessment->questions()->with('question.subQuestions')->get()
            ->flatMap(function ($aq) {
                $question = $aq->question;
                if (!$question) return [];
                return collect([$question->id])->concat($question->subQuestions->pluck('id'));
            })->map(fn ($id) => (string) $id)->unique()->all();
        $allowed = array_fill_keys($assessmentQuestionIds, true);
        foreach ($validated['answers'] as $questionId => $answer) {
            abort_unless(ctype_digit((string) $questionId) && isset($allowed[(string) $questionId]),
                422, 'Draft includes a question not in this assessment.');
            abort_unless(is_string($answer) || is_array($answer) || $answer === null
                || is_numeric($answer) || is_bool($answer), 422, 'Invalid answer format.');
        }
        $timestamp = now();
        DB::table('student_assessment_drafts')->updateOrInsert(
            ['student_assessment_id' => $attempt->id],
            [
                'answers' => json_encode($validated['answers'], JSON_UNESCAPED_UNICODE),
                'page_index' => $validated['page_index'],
                'updated_at' => $timestamp,
                // updateOrInsert sets this on both paths; acceptable for draft history.
                'created_at' => $timestamp,
            ]
        );
        return response()->json(['saved' => true, 'saved_at' => $timestamp->toIso8601String()]);
    }

    public function saveAnswer(Request $request)
    {
        $validated = $request->validate([
            'student_assessment_id' => 'required|exists:student_assessments,id',
            'question_id' => 'required|integer',
            'answer' => 'required|string',
        ]);

        $studentAssessment = StudentAssessment::findOrFail($validated['student_assessment_id']);

        // SECURITY: only owner & only in progress
        abort_if(
            $studentAssessment->student_id !== auth()->id() ||
                $studentAssessment->status !== 'in_progress',
            403
        );

        $answer = StudentAnswer::updateOrCreate(
            [
                'student_assessment_id' => $validated['student_assessment_id'],
                'question_id' => $validated['question_id'],
            ],
            [
                'answer' => $validated['answer'],
                'is_correct' => null, // auto-grader sets later
                'points_earned' => null,
                'submitted_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Answer saved successfully',
            'answer' => $answer,
        ]);
    }
    public function submit($id)
    {
        $assessment = StudentAssessment::with('studentAnswers')->findOrFail($id);

        abort_if(
            $assessment->student_id !== auth()->id() ||
            $assessment->status !== 'in_progress',
            403
        );
        $needsReview = $assessment->studentAnswers()
            ->whereNull('points_earned')
            ->exists();

        $assessment->update([
            'status' => $needsReview ? 'under_review' : 'graded',
            'completed_at' => now(),
            'score' => $needsReview ? null : $assessment->studentAnswers()->sum('points_earned'),
        ]);
        return response()->json([
            'status' => $assessment->status,
        ]);
    }
    public function reviewAnswer(Request $request, $answerId)
    {
        $validated = $request->validate([
            'is_correct' => 'required|boolean',
            'points' => 'required|integer|min:0',
        ]);
        $answer = StudentAnswer::with('studentAssessment.assessment')->findOrFail($answerId);

        // SECURITY: only assessment creator
        abort_if(
            $answer->studentAssessment->assessment->creator_id !== auth()->id(),
            403
        );
        $answer->update([
            'is_correct' => $validated['is_correct'],
            'points_earned' => $validated['points'],
        ]);

        return response()->json([
            'message' => 'Answer reviewed',
            'answer' => $answer,
        ]);
    }
    public function finalize($studentAssessmentId)
    {
        $sa = StudentAssessment::with(['studentAnswers', 'assessment'])->findOrFail($studentAssessmentId);

        abort_if(
            $sa->assessment->creator_id !== auth()->id(),
            403
        );
        $answers = $sa->studentAnswers;
        $pending = $answers->contains(fn ($a) => $a->points_earned === null);
        $sa->update([
            'score' => $pending ? null : $answers->sum('points_earned'),
            'status' => $pending ? 'under_review' : 'graded'
        ]);

        return response()->json([
            'message' => 'Assessment graded successfully',
            'score' => $sa->score,
        ]);
    }
    /**
     * Student assessment history: teacher assignments (individual and group),
     * personalized formative assessments and independent practice.
     */
    public function assessmentHub(Request $request)
    {
        $student = $request->user();
        abort_unless($student && $student->role === 'student', 403);

        $relations = [
            'creator:id,name',
            'learningPeriod:id,title,group_id,grade_subject_id',
            'learningPeriod.gradeSubject:id,subject_id,grade_level_id',
            'learningPeriod.gradeSubject.subject:id,name',
            'learningPeriod.gradeSubject.gradeLevel:id,grade_name',
            'units:id,name,grade_subject_id',
            'units.gradeSubject:id,subject_id,grade_level_id',
            'units.gradeSubject.subject:id,name',
            'units.gradeSubject.gradeLevel:id,grade_name',
            'topics:id,topic_name,grade_subject_id,unit_id',
            'topics.gradeSubject:id,subject_id,grade_level_id',
            'topics.gradeSubject.subject:id,name',
            'topics.gradeSubject.gradeLevel:id,grade_name',
            'groups:id,group_name,class_code',
        ];

        // Existing student attempts and direct assignments, including practice.
        $items = StudentAssessment::query()
            ->where('student_id', $student->id)
            ->whereHas('assessment')
            ->with(['assessment' => function ($query) use ($relations) {
                $query->with($relations);
            }])
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get();

        $attempts = LearnerFormativeAttempt::query()
            ->where('student_id', $student->id)
            ->whereNotNull('student_assessment_id')
            ->with(['learningPeriod:id,title,group_id,grade_subject_id',
                    'learningPeriod.group:id,group_name,class_code'])
            ->orderByDesc('id')
            ->get()
            ->unique('student_assessment_id')
            ->keyBy('student_assessment_id');

        $format = function ($assessment, $item = null) use ($attempts) {
            $attempt = $item ? $attempts->get($item->id) : null;
            $gradeSubject = $assessment->learningPeriod?->gradeSubject
                ?: $assessment->units->first()?->gradeSubject
                ?: $assessment->topics->first()?->gradeSubject;

            return [
                'student_assessment_id' => $item?->id,
                'assessment_id' => $assessment->id,
                'title' => $assessment->title ?: 'Assessment',
                'type' => $assessment->type ?: 'assessment',
                'status' => $item?->status ?: 'pending',
                // Display state; the persisted status remains unchanged.
                'lifecycle_status' => match ($item?->status) {
                    'in_progress' => 'started',
                    'under_review' => 'submitted',
                    'graded' => 'results_ready',
                    'completed' => 'submitted',
                    default => 'not_started',
                },
                'results_available' => $item && in_array($item->status, ['completed', 'graded', 'under_review'], true),
                'final_result_pending' => $item?->status === 'under_review',
                'assigned_at' => $item?->assigned_at?->toIso8601String()
                    ?? $assessment->created_at?->toIso8601String(),
                'completed_at' => $item?->completed_at?->toIso8601String(),
                'score' => $item?->status === 'under_review' ? null : $item?->score,
                'max_score' => $item?->max_score,
                'teacher' => $assessment->type === 'practice' ? null : $assessment->creator?->name,
                'subject' => $gradeSubject?->subject?->name,
                'grade' => $gradeSubject?->gradeLevel?->grade_name,
                'groups' => $assessment->groups->map(fn ($group) => [
                    'id' => $group->id,
                    'name' => $group->group_name,
                    'class_code' => $group->class_code,
                ])->values()->all(),
                'formative_attempt' => $attempt ? [
                    'id' => $attempt->id,
                    'status' => $attempt->status,
                    'progress' => $attempt->progress,
                    'stage' => $attempt->stage,
                    'message' => $attempt->message,
                    'question_count' => $attempt->question_count,
                    'error_message' => $attempt->error_message,
                    'learning_period_id' => $attempt->learning_period_id,
                    'learning_period' => $attempt->learningPeriod?->title,
                    'group_id' => $attempt->learningPeriod?->group_id,
                    'group_name' => $attempt->learningPeriod?->group?->group_name,
                ] : null,
            ];
        };

        $data = $items->map(fn ($item) => $format($item->assessment, $item));
        $known = $items->pluck('assessment_id')->map(fn ($id) => (int) $id)->all();

        // Group assignments aren't inserted into student_assessments until
        // a student starts them, so include published group assignments too.
        $groupIds = $student->groups()->pluck('groups.id');
        if ($groupIds->isNotEmpty()) {
            $groupAssessments = Assessment::query()
                ->where('status', 'published')
                ->where('type', '!=', 'practice')
                ->where('creator_id', '!=', $student->id)
                ->whereHas('groups', fn ($query) => $query->whereIn('groups.id', $groupIds))
                ->when(count($known) > 0, fn ($query) => $query->whereNotIn('id', $known))
                ->with($relations)->get();
            foreach ($groupAssessments as $assessment) {
                $data->push($format($assessment));
                $known[] = (int) $assessment->id;
            }
        }

        // Older self-created practice assessments may not have an attempt row.
        $ownPractice = Assessment::query()
            ->where('type', 'practice')
            ->where('creator_id', $student->id)
            ->when(count($known) > 0, fn ($query) => $query->whereNotIn('id', $known))
            ->with($relations)->get();
        foreach ($ownPractice as $assessment) {
            $data->push($format($assessment));
        }

        $data = $data->sortByDesc('assigned_at')->values();
        $required = $data->where('type', '!=', 'practice');
        $finished = fn ($item) => in_array($item['status'],
            ['completed', 'graded', 'under_review'], true);

        return response()->json([
            'data' => $data,
            'summary' => [
                'total' => $data->count(),
                'formative' => $required->where('type', 'formative')->count(),
                'assigned' => $required->where('type', '!=', 'formative')->count(),
                'practice' => $data->where('type', 'practice')->count(),
                'pending' => $required->reject($finished)->count(),
                'completed' => $data->filter($finished)->count(),
            ],
        ]);
    }

    public function myAssessments()
{
    return StudentAssessment::with('assessment')
        ->where('student_id', auth()->id())
        ->orderBy('created_at', 'desc')
        ->get();
}
public function show($id)
{
    $assessment = StudentAssessment::with(['studentAnswers', 'assessment'])
        ->where('id', $id)
        ->where('student_id', auth()->id())
        ->firstOrFail();

    return response()->json($assessment);
}

}

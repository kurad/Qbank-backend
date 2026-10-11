<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\LearningPeriod;
use App\Models\Question;
use App\Models\PracticeAttempt;
use App\Models\PracticeAttemptAnswer;
use App\Models\Topic;
use App\Services\TutorContextService;
use Illuminate\Http\Request;

class StudentPracticeController extends Controller
{
    public function __construct(
        protected TutorContextService $context
    ) {
    }

    /**
     * Return only practice content that belongs to classes the
     * authenticated learner is enrolled in.
     *
     * Student practice context is:
     *
     * Class
     * -> Published Learning Period
     * -> Scheduled Topic
     * -> Approved assessment-eligible questions
     *
     * We intentionally do not use users.grade_level_id.
     */
    public function context(Request $request)
    {
        $student = $request->user();

        abort_unless(
            $student && $student->role === 'student',
            403,
            'Only students can access practice.'
        );

        $groups = $this->context
            ->studentGroups($student);

        $today = now()->toDateString();

        $payload = $groups
            ->map(function ($group) use ($student, $today) {
                $model = Group::with([
                    'gradeSubject.subject:id,name',
                    'gradeSubject.gradeLevel:id,grade_name',
                ])->find($group->id);

                if (!$model) {
                    return null;
                }

                /*
                 * Practice may include current and previously-started
                 * published learning periods, but not future periods.
                 */
                $periods = $this->context
                    ->learningPeriodsForStudent(
                        $student,
                        $model,
                        false
                    )
                    ->filter(
                        fn (LearningPeriod $period) =>
                            $period->start_date
                                ->toDateString()
                            <= $today
                    )
                    ->values();

                $topicIds = $periods
                    ->flatMap(
                        fn (LearningPeriod $period) =>
                            $period->topics
                                ->pluck('topic_id')
                    )
                    ->filter()
                    ->unique()
                    ->values();

                $questionCounts = Question::query()
                    ->whereIn(
                        'topic_id',
                        $topicIds
                    )
                    ->whereNull(
                        'parent_question_id'
                    )
                    ->where(
                        'status',
                        'approved'
                    )
                    ->where(
                        'is_assessment_eligible',
                        true
                    )
                    ->selectRaw(
                        'topic_id, COUNT(*) as aggregate'
                    )
                    ->groupBy('topic_id')
                    ->pluck(
                        'aggregate',
                        'topic_id'
                    );

                $periodPayload = $periods
                    ->map(function (
                        LearningPeriod $period
                    ) use (
                        $questionCounts,
                        $today
                    ) {
                        $topics = $period
                            ->topics
                            ->map(function ($item) use (
                                $questionCounts
                            ) {
                                return [
                                    'id' =>
                                        $item->id,

                                    'unit_id' =>
                                        $item->unit_id,

                                    'unit_name' =>
                                        $item->unit?->name,

                                    'topic_id' =>
                                        $item->topic_id,

                                    'topic_name' =>
                                        $item
                                            ->topic
                                            ?->topic_name,

                                    'display_order' =>
                                        $item
                                            ->display_order,

                                    'question_count' =>
                                        (int) (
                                            $questionCounts[
                                                $item->topic_id
                                            ]
                                            ?? 0
                                        ),
                                ];
                            })
                            /*
                             * Do not show empty topics as available
                             * practice choices.
                             */
                            ->filter(
                                fn ($item) =>
                                    $item[
                                        'question_count'
                                    ] > 0
                            )
                            ->values();

                        return [
                            'id' =>
                                $period->id,

                            'title' =>
                                $period->title,

                            'description' =>
                                $period
                                    ->description,

                            'start_date' =>
                                $period
                                    ->start_date
                                    ?->toDateString(),

                            'end_date' =>
                                $period
                                    ->end_date
                                    ?->toDateString(),

                            'learning_state' =>
                                $period
                                    ->end_date
                                    ->toDateString()
                                < $today
                                    ? 'previous'
                                    : 'current',

                            'subject' =>
                                $period
                                    ->gradeSubject
                                    ?->subject
                                    ?->name,

                            'grade' =>
                                $period
                                    ->gradeSubject
                                    ?->gradeLevel
                                    ?->grade_name,

                            'grade_subject_id' =>
                                $period
                                    ->grade_subject_id,

                            'topics' =>
                                $topics,

                            'question_count' =>
                                $topics->sum(
                                    'question_count'
                                ),
                        ];
                    })
                    ->filter(
                        fn ($period) =>
                            $period[
                                'question_count'
                            ] > 0
                    )
                    ->values();

                if ($periodPayload->isEmpty()) {
                    return [
                        'id' =>
                            $model->id,

                        'group_name' =>
                            $model->group_name,

                        'class_code' =>
                            $model->class_code,

                        'academic_year' =>
                            $model->academic_year,

                        'subject' =>
                            $model
                                ->gradeSubject
                                ?->subject
                                ?->name,

                        'grade' =>
                            $model
                                ->gradeSubject
                                ?->gradeLevel
                                ?->grade_name,

                        'grade_subject_id' =>
                            $model
                                ->grade_subject_id,

                        'learning_periods' =>
                            [],

                        'question_count' =>
                            0,
                    ];
                }

                return [
                    'id' =>
                        $model->id,

                    'group_name' =>
                        $model->group_name,

                    'class_code' =>
                        $model->class_code,

                    'academic_year' =>
                        $model->academic_year,

                    'subject' =>
                        $model
                            ->gradeSubject
                            ?->subject
                            ?->name,

                    'grade' =>
                        $model
                            ->gradeSubject
                            ?->gradeLevel
                            ?->grade_name,

                    'grade_subject_id' =>
                        $model
                            ->grade_subject_id,

                    'learning_periods' =>
                        $periodPayload,

                    'question_count' =>
                        $periodPayload->sum(
                            'question_count'
                        ),
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'data' => $payload,
        ]);
    }

    /**
     * Return approved questions only after verifying that:
     * - learner belongs to the class;
     * - learning period belongs to that class;
     * - topic is explicitly scheduled in that learning period.
     */
    public function questions(
        Request $request,
        Topic $topic
    ) {
        $student = $request->user();

        abort_unless(
            $student && $student->role === 'student',
            403,
            'Only students can access practice.'
        );

        $validated = $request->validate([
            'group_id' => [
                'required',
                'integer',
                'exists:groups,id',
            ],

            'learning_period_id' => [
                'required',
                'integer',
                'exists:learning_periods,id',
            ],
        ]);

        $group = Group::findOrFail(
            $validated['group_id']
        );

        $period = LearningPeriod::with([
            'topics',
        ])->findOrFail(
            $validated[
                'learning_period_id'
            ]
        );

        /*
         * Reuse the Tutor context access checks rather than
         * trusting IDs supplied by the browser.
         */
        $allowedPeriods =
            $this->context
                ->learningPeriodsForStudent(
                    $student,
                    $group,
                    false
                );

        abort_unless(
            $allowedPeriods->contains(
                'id',
                $period->id
            ),
            403,
            'This learning period is not available to you.'
        );

        abort_unless(
            $period->start_date
                ->toDateString()
            <= now()->toDateString(),
            403,
            'This learning period has not started yet.'
        );

        abort_unless(
            $period
                ->topics
                ->contains(
                    fn ($item) =>
                        (int) $item->topic_id
                        === (int) $topic->id
                ),
            403,
            'This topic is not scheduled in the selected learning period.'
        );

        $questions = Question::query()
            ->where(
                'topic_id',
                $topic->id
            )
            ->whereNull(
                'parent_question_id'
            )
            ->where(
                'status',
                'approved'
            )
            ->where(
                'is_assessment_eligible',
                true
            )
            ->with([
                'learningObjective:id,topic_id,code,objective',
                'subQuestions.learningObjective:id,topic_id,code,objective',
            ])
            ->inRandomOrder()
            ->get();

        return response()->json([
            'data' =>
                $questions
                    ->values(),
            'meta' => [
                'group_id' =>
                    $group->id,

                'learning_period_id' =>
                    $period->id,

                'topic_id' =>
                    $topic->id,

                'question_count' =>
                    $questions->count(),
            ],
        ]);
    }

    /**
     * Start a persistent practice attempt.
     *
     * Practice scores are learning analytics, not formal grades.
     */
    public function startAttempt(Request $request)
    {
        $student = $request->user();

        abort_unless(
            $student && $student->role === 'student',
            403,
            'Only students can access practice.'
        );

        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'learning_period_id' => ['required', 'integer', 'exists:learning_periods,id'],
            'topic_id' => ['required', 'integer', 'exists:topics,id'],
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer', 'exists:questions,id'],
            'mode' => ['nullable', 'in:full,retry_incorrect'],
            'parent_attempt_id' => ['nullable', 'integer', 'exists:practice_attempts,id'],
        ]);

        $group = Group::findOrFail($validated['group_id']);
        $period = LearningPeriod::with('topics')
            ->findOrFail($validated['learning_period_id']);
        $topic = Topic::findOrFail($validated['topic_id']);

        $allowedPeriods = $this->context->learningPeriodsForStudent(
            $student,
            $group,
            false
        );

        abort_unless(
            $allowedPeriods->contains('id', $period->id),
            403,
            'This learning period is not available to you.'
        );

        abort_unless(
            $period->topics->contains(
                fn ($item) =>
                    (int) $item->topic_id === (int) $topic->id
            ),
            403,
            'This topic is not scheduled in the selected learning period.'
        );

        $questions = Question::query()
            ->whereIn('id', $validated['question_ids'])
            ->where('topic_id', $topic->id)
            ->whereNull('parent_question_id')
            ->where('status', 'approved')
            ->where('is_assessment_eligible', true)
            ->get();

        abort_unless(
            $questions->count() === count(array_unique($validated['question_ids'])),
            422,
            'One or more practice questions are no longer available.'
        );

        $attempt = PracticeAttempt::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'learning_period_id' => $period->id,
            'topic_id' => $topic->id,
            'parent_attempt_id' => $validated['parent_attempt_id'] ?? null,
            'mode' => $validated['mode'] ?? 'full',
            'status' => 'in_progress',
            'total_questions' => $questions->count(),
            'total_marks' => $questions->sum(
                fn ($question) => (float) ($question->marks ?? 1)
            ),
            'started_at' => now(),
        ]);

        return response()->json([
            'data' => $this->attemptSummary($attempt),
        ]);
    }

    /**
     * Save one learner response.
     *
     * The client sends the same practice evaluation already displayed
     * to the learner. This is advisory practice analytics, not a formal
     * assessment grade.
     */
    public function saveAttemptAnswer(
        Request $request,
        PracticeAttempt $practiceAttempt
    ) {
        abort_unless(
            (int) $practiceAttempt->student_id === (int) $request->user()->id,
            403
        );

        abort_unless(
            $practiceAttempt->status === 'in_progress',
            409,
            'This practice attempt is already closed.'
        );

        $validated = $request->validate([
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'answer' => ['nullable'],
            'is_correct' => ['nullable', 'boolean'],
            'review_only' => ['nullable', 'boolean'],
            'marks_awarded' => ['nullable', 'numeric', 'min:0'],
        ]);

        $question = Question::findOrFail($validated['question_id']);

        abort_unless(
            (int) $question->topic_id === (int) $practiceAttempt->topic_id,
            422,
            'This question does not belong to the practice topic.'
        );

        $marks = (float) ($question->marks ?? 1);
        $awarded = min(
            $marks,
            max(0, (float) ($validated['marks_awarded'] ?? 0))
        );

        $answer = PracticeAttemptAnswer::updateOrCreate(
            [
                'practice_attempt_id' => $practiceAttempt->id,
                'question_id' => $question->id,
            ],
            [
                'answer' => [
                    'value' => $validated['answer'] ?? null,
                ],
                'is_correct' => $validated['is_correct'] ?? null,
                'review_only' => (bool) ($validated['review_only'] ?? false),
                'marks_awarded' => $awarded,
                'answered_at' => now(),
            ]
        );

        $this->refreshAttempt($practiceAttempt);

        return response()->json([
            'data' => [
                'id' => $answer->id,
            ],
        ]);
    }

    public function completeAttempt(
        Request $request,
        PracticeAttempt $practiceAttempt
    ) {
        abort_unless(
            (int) $practiceAttempt->student_id === (int) $request->user()->id,
            403
        );

        $this->refreshAttempt($practiceAttempt);

        $practiceAttempt->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return response()->json([
            'data' => $this->attemptSummary(
                $practiceAttempt->fresh()
            ),
        ]);
    }

    public function history(Request $request)
    {
        $attempts = PracticeAttempt::query()
            ->with([
                'group:id,group_name',
                'learningPeriod:id,title',
                'topic:id,topic_name',
            ])
            ->where('student_id', $request->user()->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->limit(50)
            ->get()
            ->map(
                fn ($attempt) =>
                    $this->attemptSummary($attempt, true)
            );

        return response()->json([
            'data' => $attempts,
        ]);
    }

    public function reviewAttempt(
        Request $request,
        PracticeAttempt $practiceAttempt
    ) {
        abort_unless(
            (int) $practiceAttempt->student_id === (int) $request->user()->id,
            403
        );

        $practiceAttempt->load([
            'group:id,group_name',
            'learningPeriod:id,title',
            'topic:id,topic_name',
            'answers.question',
        ]);

        return response()->json([
            'data' => [
                'attempt' => $this->attemptSummary(
                    $practiceAttempt,
                    true
                ),
                'answers' => $practiceAttempt
                    ->answers
                    ->map(fn ($answer) => [
                        'id' => $answer->id,
                        'question_id' => $answer->question_id,
                        'question' => $answer->question,
                        'answer' => $answer->answer,
                        'is_correct' => $answer->is_correct,
                        'review_only' => $answer->review_only,
                        'marks_awarded' => (float) $answer->marks_awarded,
                    ])
                    ->values(),
            ],
        ]);
    }

    protected function refreshAttempt(
        PracticeAttempt $attempt
    ): void {
        $answers = $attempt->answers()->get();

        $attempt->update([
            'correct_count' => $answers
                ->where('review_only', false)
                ->where('is_correct', true)
                ->count(),
            'earned_marks' => $answers
                ->sum(fn ($answer) => (float) $answer->marks_awarded),
        ]);
    }

    protected function attemptSummary(
        PracticeAttempt $attempt,
        bool $includeContext = false
    ): array {
        $incorrect = $attempt
            ->answers()
            ->where('review_only', false)
            ->where('is_correct', false)
            ->count();

        $gradable = $attempt
            ->answers()
            ->where('review_only', false)
            ->count();

        $summary = [
            'id' => $attempt->id,
            'parent_attempt_id' => $attempt->parent_attempt_id,
            'mode' => $attempt->mode,
            'status' => $attempt->status,
            'total_questions' => $attempt->total_questions,
            'correct_count' => $attempt->correct_count,
            'incorrect_count' => $incorrect,
            'total_marks' => (float) $attempt->total_marks,
            'earned_marks' => (float) $attempt->earned_marks,
            'score_percent' => $gradable > 0
                ? round(($attempt->correct_count / $gradable) * 100, 1)
                : null,
            'started_at' => optional($attempt->started_at)?->toIso8601String(),
            'completed_at' => optional($attempt->completed_at)?->toIso8601String(),
        ];

        if ($includeContext) {
            $summary += [
                'group_id' => $attempt->group_id,
                'group_name' => $attempt->group?->group_name,
                'learning_period_id' => $attempt->learning_period_id,
                'learning_period' => $attempt->learningPeriod?->title,
                'topic_id' => $attempt->topic_id,
                'topic' => $attempt->topic?->topic_name,
            ];
        }

        return $summary;
    }

}

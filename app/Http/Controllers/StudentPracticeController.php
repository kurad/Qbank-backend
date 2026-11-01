<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\Group;
use App\Models\LearningPeriod;
use App\Models\Question;
use App\Models\PracticeAttempt;
use App\Models\PracticeAttemptAnswer;
use App\Models\StudentAssessment;
use App\Models\StudentQuestionHistory;
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
     * Build the Independent Practice catalogue directly from the learner's
     * school question bank. Class membership and learning periods do not
     * restrict independent practice.
     */
    public function context(Request $request)
    {
        $student = $request->user();

        abort_unless(
            $student && $student->role === 'student',
            403,
            'Only students can access practice.'
        );

        abort_unless(
            $student->school_id,
            422,
            'Your account is not linked to a school yet.'
        );

        /*
         * Independent Practice is intentionally broader than Tutor and
         * formal assessment delivery. Learners may practise any APPROVED
         * question in their own school's question bank, regardless of class,
         * learning period, or is_assessment_eligible.
         *
         * We count answerable leaf questions. Parent stems are excluded from
         * the count; their sub-questions are counted individually.
         */
        $questions = Question::query()
            ->where('status', 'approved')
            ->where('question_type', '!=', 'parent')
            ->whereDoesntHave('subQuestions')
            ->whereHas('topic.gradeSubject', function ($query) use ($student) {
                $query->where('school_id', $student->school_id);
            })
            ->with([
                'topic:id,grade_subject_id,unit_id,topic_name',
                'topic.unit:id,grade_subject_id,name',
                'topic.gradeSubject:id,school_id,grade_level_id,subject_id',
                'topic.gradeSubject.gradeLevel:id,grade_name',
                'topic.gradeSubject.subject:id,name',
            ])
            ->get([
                'id',
                'topic_id',
            ]);

        $grades = [];

        foreach ($questions as $question) {
            $topic = $question->topic;
            $gradeSubject = $topic?->gradeSubject;
            $gradeLevel = $gradeSubject?->gradeLevel;
            $subject = $gradeSubject?->subject;

            if (!$topic || !$gradeSubject || !$gradeLevel || !$subject) {
                continue;
            }

            $gradeKey = (string) $gradeLevel->id;
            $subjectKey = (string) $gradeSubject->id;
            $unitKey = $topic->unit_id
                ? (string) $topic->unit_id
                : 'general';
            $topicKey = (string) $topic->id;

            if (!isset($grades[$gradeKey])) {
                $grades[$gradeKey] = [
                    'id' => $gradeLevel->id,
                    'grade_name' => $gradeLevel->grade_name,
                    'question_count' => 0,
                    'subjects' => [],
                ];
            }

            if (!isset($grades[$gradeKey]['subjects'][$subjectKey])) {
                $grades[$gradeKey]['subjects'][$subjectKey] = [
                    'id' => $gradeSubject->id,
                    'subject_id' => $subject->id,
                    'name' => $subject->name,
                    'question_count' => 0,
                    'units' => [],
                ];
            }

            if (!isset($grades[$gradeKey]['subjects'][$subjectKey]['units'][$unitKey])) {
                $grades[$gradeKey]['subjects'][$subjectKey]['units'][$unitKey] = [
                    'id' => $topic->unit_id,
                    'key' => $unitKey,
                    'name' => $topic->unit?->name ?? 'General topics',
                    'question_count' => 0,
                    'topics' => [],
                ];
            }

            if (!isset($grades[$gradeKey]['subjects'][$subjectKey]['units'][$unitKey]['topics'][$topicKey])) {
                $grades[$gradeKey]['subjects'][$subjectKey]['units'][$unitKey]['topics'][$topicKey] = [
                    'id' => $topic->id,
                    'topic_name' => $topic->topic_name,
                    'question_count' => 0,
                ];
            }

            $grades[$gradeKey]['question_count']++;
            $grades[$gradeKey]['subjects'][$subjectKey]['question_count']++;
            $grades[$gradeKey]['subjects'][$subjectKey]['units'][$unitKey]['question_count']++;
            $grades[$gradeKey]['subjects'][$subjectKey]['units'][$unitKey]['topics'][$topicKey]['question_count']++;
        }

        $payload = collect($grades)
            ->map(function ($grade) {
                $grade['subjects'] = collect($grade['subjects'])
                    ->map(function ($subject) {
                        $subject['units'] = collect($subject['units'])
                            ->map(function ($unit) {
                                $unit['topics'] = collect($unit['topics'])
                                    ->values()
                                    ->sortBy('topic_name', SORT_NATURAL | SORT_FLAG_CASE)
                                    ->values();

                                return $unit;
                            })
                            ->values()
                            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                            ->values();

                        return $subject;
                    })
                    ->values()
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values();

                return $grade;
            })
            ->values()
            ->sortBy('grade_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json([
            'data' => $payload,
            'meta' => [
                'school_id' => $student->school_id,
                'question_count' => $questions->count(),
            ],
        ]);
    }

    /**
     * Create a normal RevisionHub practice assessment from any approved
     * question-bank topic in the learner's own school.
     *
     * This is independent practice: class membership and learning periods
     * are intentionally not required.
     */
    public function prepare(Request $request)
    {
        $student = $request->user();

        abort_unless(
            $student && $student->role === 'student',
            403,
            'Only students can start practice.'
        );

        abort_unless(
            $student->school_id,
            422,
            'Your account is not linked to a school yet.'
        );

        $validated = $request->validate([
            'topic_id' => ['required', 'integer', 'exists:topics,id'],
            'mode' => ['required', 'in:all,custom,unpracticed'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $topic = Topic::query()
            ->whereKey($validated['topic_id'])
            ->whereHas('gradeSubject', function ($query) use ($student) {
                $query->where('school_id', $student->school_id);
            })
            ->with([
                'gradeSubject.gradeLevel:id,grade_name',
                'gradeSubject.subject:id,name',
                'unit:id,name',
            ])
            ->firstOrFail();

        $baseQuery = Question::query()
            ->where('topic_id', $topic->id)
            ->where('status', 'approved')
            ->where('question_type', '!=', 'parent')
            ->whereDoesntHave('subQuestions');

        $availableCount = (clone $baseQuery)->count();

        if ($availableCount === 0) {
            return response()->json([
                'message' => 'No approved practice questions are available for this topic yet.',
            ], 404);
        }

        $mode = $validated['mode'];
        $limit = $validated['limit'] ?? null;

        if ($mode === 'custom' && !$limit) {
            return response()->json([
                'message' => 'Choose how many questions you want to practise.',
            ], 422);
        }

        if ($mode === 'unpracticed') {
            if (!$limit) {
                $limit = min(10, $availableCount);
            }

            $practicedIds = StudentQuestionHistory::query()
                ->where('student_id', $student->id)
                ->pluck('question_id');

            if ($practicedIds->isNotEmpty()) {
                $baseQuery->whereNotIn('id', $practicedIds);
            }
        }

        $questions = match ($mode) {
            'all' => $baseQuery->inRandomOrder()->get(),
            default => $baseQuery
                ->inRandomOrder()
                ->take(min((int) $limit, 100))
                ->get(),
        };

        if ($questions->isEmpty()) {
            return response()->json([
                'message' => $mode === 'unpracticed'
                    ? 'You have already practised all available questions in this topic.'
                    : 'No approved practice questions are available for this topic.',
            ], 404);
        }

        $subjectName = $topic->gradeSubject?->subject?->name ?? 'Subject';
        $topicName = $topic->topic_name ?? 'Topic';

        $practiceAssessment = Assessment::create([
            'type' => 'practice',
            'title' => "Practice: {$subjectName} - {$topicName}",
            'creator_id' => $student->id,
            'question_count' => $questions->count(),
            'due_date' => now()->addDay(),
            'is_timed' => false,
            'time_limit' => null,
        ]);

        $practiceAssessment->topics()->attach($topic->id);

        foreach ($questions->values() as $order => $question) {
            AssessmentQuestion::create([
                'assessment_id' => $practiceAssessment->id,
                'question_id' => $question->id,
                'order' => $order + 1,
            ]);
        }

        $studentAssessment = StudentAssessment::create([
            'student_id' => $student->id,
            'assessment_id' => $practiceAssessment->id,
            'started_at' => now(),
        ]);

        return response()->json([
            'message' => 'Practice prepared successfully.',
            'assessment' => $practiceAssessment,
            'student_assessment' => $studentAssessment,
            'meta' => [
                'available_questions' => $availableCount,
                'selected_questions' => $questions->count(),
                'mode' => $mode,
            ],
        ], 201);
    }

    /**
     * Return approved answerable questions for a topic in the learner's
     * school. Independent Practice does not require class or period IDs.
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

        abort_unless(
            $student->school_id,
            422,
            'Your account is not linked to a school yet.'
        );

        $allowed = Topic::query()
            ->whereKey($topic->id)
            ->whereHas('gradeSubject', function ($query) use ($student) {
                $query->where('school_id', $student->school_id);
            })
            ->exists();

        abort_unless(
            $allowed,
            403,
            'This topic is not available in your school question bank.'
        );

        $questions = Question::query()
            ->where('topic_id', $topic->id)
            ->where('status', 'approved')
            ->where('question_type', '!=', 'parent')
            ->whereDoesntHave('subQuestions')
            ->with([
                'learningObjective:id,topic_id,code,objective',
                'parent:id,topic_id,question,question_type,options,is_math',
            ])
            ->inRandomOrder()
            ->get();

        return response()->json([
            'data' => $questions->values(),
            'meta' => [
                'topic_id' => $topic->id,
                'question_count' => $questions->count(),
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

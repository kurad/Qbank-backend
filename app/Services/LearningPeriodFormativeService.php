<?php

namespace App\Services;

use App\Jobs\PrepareLearningPeriodFormativeAssessment;
use App\Models\Assessment;
use App\Models\KnowledgeChunk;
use App\Models\LearningPeriod;
use App\Models\Question;
use App\Models\StudentAssessment;
use App\Models\User;
use App\Services\AI\AIGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LearningPeriodFormativeService
{
    private const QUESTIONS_PER_OBJECTIVE = 3;

    private const MAX_QUESTIONS = 20;

    private const AUTO_GRADABLE_TYPES = [
        'mcq',
        'true_false',
        'fill_blank',
        'short_answer',
        'matching',
    ];

    public function __construct(
        protected AIGateway $ai,
        protected StudentTopicLearningService $topicLearning
    ) {
    }

    /**
     * Get the mandatory formative state for one learner and period.
     * If the learner has covered all scheduled topics, the shared
     * period assessment is created (once) and assigned to every
     * learner enrolled in the class.
     */
    public function statusForStudent(
        User $student,
        LearningPeriod $period
    ): array {
        $period->loadMissing([
            'group.students:id,name,email',
            'gradeSubject.subject:id,name',
            'topics.topic.learningObjectives',
            'topics.unit',
        ]);

        $ready = $this->studentCoveredPeriod(
            $student,
            $period
        );

        $assessment = Assessment::query()
            ->where('learning_period_id', $period->id)
            ->where('purpose', 'period_formative')
            ->first();

        if ($ready && !$assessment) {
            /*
             * Fallback trigger. Normally this job is dispatched immediately
             * when the learner completes the final topic. If that event was
             * missed, queue it here without blocking this request.
             */
            PrepareLearningPeriodFormativeAssessment::dispatch(
                $period->id
            )->afterResponse();

            return [
                'required' => true,
                'ready' => true,
                'status' => 'preparing',
                'message' =>
                    'Your required formative assessment is being prepared automatically.',
            ];
        }

        if (!$assessment) {
            return [
                'required' => true,
                'ready' => false,
                'status' => 'locked',
                'message' =>
                    'Complete all topics in this learning period before the formative assessment opens.',
            ];
        }

        // The assessment is shared by the class, but every learner has
        // an individual mandatory StudentAssessment row.
        $studentAssessment = StudentAssessment::firstOrCreate(
            [
                'student_id' => $student->id,
                'assessment_id' => $assessment->id,
            ],
            [
                'assigned_by' => $period->created_by,
                'assigned_at' => now(),
                'status' => 'pending',
            ]
        );

        $percentage = null;
        if (
            in_array($studentAssessment->status, ['completed', 'graded'], true)
            && (float) $studentAssessment->max_score > 0
        ) {
            $percentage = round(
                ((float) $studentAssessment->score /
                    (float) $studentAssessment->max_score) * 100,
                1
            );
        }

        return [
            'required' => true,
            'ready' => $ready,
            'status' => $ready
                ? $studentAssessment->status
                : 'locked',
            'assessment_id' => $assessment->id,
            'student_assessment_id' => $studentAssessment->id,
            'title' => $assessment->title,
            'question_count' => (int) $assessment->question_count,
            'score' => $studentAssessment->score,
            'max_score' => $studentAssessment->max_score,
            'percentage' => $percentage,
            'completed_at' => optional(
                $studentAssessment->completed_at
            )?->toIso8601String(),
            'message' => $ready
                ? 'This formative assessment is required to complete the learning period.'
                : 'Complete all topics in this learning period before the formative assessment opens.',
        ];
    }

    public function ensureForPeriod(
        LearningPeriod $period
    ): Assessment {
        $existing = Assessment::query()
            ->where('learning_period_id', $period->id)
            ->where('purpose', 'period_formative')
            ->first();

        if ($existing) {
            $this->assignToAllLearners($period, $existing);
            return $existing;
        }

        $period->loadMissing([
            'group.students:id,name,email',
            'gradeSubject.subject:id,name',
            'gradeSubject.gradeLevel:id,grade_name',
            'topics.topic.learningObjectives',
            'topics.unit',
        ]);

        if ($period->topics->isEmpty()) {
            throw new RuntimeException(
                'The learning period has no topics to assess.'
            );
        }

        $questionSet = collect();
        $generatedQuestionIds = [];
        $objectivePlan = $this->objectiveQuestionPlan($period);

        if ($objectivePlan->isEmpty()) {
            throw new RuntimeException(
                'No active learning objectives are available for this learning period.'
            );
        }

        foreach ($objectivePlan as $plan) {
            $assignment = $plan['assignment'];
            $topic = $plan['topic'];
            $objective = $plan['objective'];
            $required = (int) $plan['question_count'];

            if ($required <= 0) {
                continue;
            }

            $objectiveQuestions = Question::query()
                ->where('topic_id', $topic->id)
                ->where('learning_objective_id', $objective->id)
                ->whereNull('parent_question_id')
                ->where('status', 'approved')
                ->where('is_assessment_eligible', true)
                ->whereIn('question_type', self::AUTO_GRADABLE_TYPES)
                ->with('learningObjective:id,topic_id,code,objective,status')
                ->get();

            $selected = $this->selectObjectiveQuestions(
                $objectiveQuestions,
                $required
            );

            $missing = max(0, $required - $selected->count());

            if ($missing > 0) {
                $generated = $this->generateGroundedQuestionsForObjective(
                    $period,
                    $assignment,
                    $objective,
                    $missing,
                    $selected->count()
                );

                foreach ($generated as $question) {
                    $selected->push($question);
                    $generatedQuestionIds[] = $question->id;
                }
            }

            if ($selected->count() < $required) {
                throw new RuntimeException(
                    'Not enough grounded auto-gradable questions could be prepared for learning objective: ' .
                    ($objective->code ?: $objective->id) . ' - ' .
                    ($objective->objective ?? $objective->description ?? '')
                );
            }

            $questionSet = $questionSet->concat($selected->take($required));
        }

        if ($questionSet->isEmpty()) {
            throw new RuntimeException(
                'No questions could be prepared for this learning period.'
            );
        }

        try {
            return DB::transaction(function () use (
                $period,
                $questionSet
            ) {
                // Re-check inside the transaction in case two learners
                // completed the period at the same moment.
                $assessment = Assessment::query()
                    ->where('learning_period_id', $period->id)
                    ->where('purpose', 'period_formative')
                    ->first();

                if ($assessment) {
                    $this->assignToAllLearners($period, $assessment);
                    return $assessment;
                }

                $subject = $period
                    ->gradeSubject
                    ?->subject
                    ?->name;

                $assessment = Assessment::create([
                    'type' => 'formative',
                    'title' => trim(
                        'Formative Check: ' . $period->title
                    ),
                    'creator_id' => $period->created_by,
                    'learning_period_id' => $period->id,
                    'is_system_generated' => true,
                    'is_mandatory' => true,
                    'purpose' => 'period_formative',
                    'question_count' => $questionSet->count(),
                    'delivery_mode' => 'online',
                    'is_timed' => false,
                    'instructions' =>
                        'This required formative assessment checks your understanding across all topics covered in this learning period. Answer every question.',
                    'question_blueprint' => [
                        'source' => 'automatic_period_formative',
                        'questions_per_objective' => self::QUESTIONS_PER_OBJECTIVE,
                        'max_questions' => self::MAX_QUESTIONS,
                        'learning_period_id' => $period->id,
                        'subject' => $subject,
                    ],
                    'status' => 'published',
                ]);

                $assessment->groups()->syncWithoutDetaching([
                    $period->group_id,
                ]);

                $assessment->topics()->syncWithoutDetaching(
                    $period->topics->pluck('topic_id')->all()
                );

                $assessment->units()->syncWithoutDetaching(
                    $period->topics->pluck('unit_id')->filter()->unique()->all()
                );

                $objectiveIds = $period->topics
                    ->flatMap(
                        fn ($item) =>
                            $item->topic?->learningObjectives
                                ?->where('status', 'active')
                                ?->pluck('id')
                            ?? collect()
                    )
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if (!empty($objectiveIds)) {
                    $assessment->learningObjectives()
                        ->syncWithoutDetaching($objectiveIds);
                }

                foreach ($questionSet->values() as $index => $question) {
                    $assessment->assessmentQuestions()->create([
                        'question_id' => $question->id,
                        'order' => $index + 1,
                    ]);
                }

                $this->assignToAllLearners($period, $assessment);

                return $assessment->fresh();
            });
        } catch (Throwable $e) {
            // Temporary AI mastery questions are intentionally not part of
            // the Question Bank. Clean them up if assessment creation fails.
            if (!empty($generatedQuestionIds)) {
                Question::query()
                    ->whereIn('id', $generatedQuestionIds)
                    ->where('source', 'ai_mastery')
                    ->delete();
            }

            throw $e;
        }
    }

    public function assertStudentReadyForAssessment(
        User $student,
        Assessment $assessment
    ): void {
        if (
            $assessment->type !== 'formative'
            || $assessment->purpose !== 'period_formative'
        ) {
            return;
        }

        $period = $assessment->learningPeriod;

        abort_unless(
            $period
            && $this->studentCoveredPeriod($student, $period),
            403,
            'Complete all topics in this learning period before starting the required formative assessment.'
        );
    }

    public function studentCoveredPeriod(
        User $student,
        LearningPeriod $period
    ): bool {
        return (bool) (
            $this->topicLearning
                ->periodSummary($student, $period)['coverage_complete']
            ?? false
        );
    }

    protected function assignToAllLearners(
        LearningPeriod $period,
        Assessment $assessment
    ): void {
        $period->loadMissing('group.students:id');

        foreach ($period->group?->students ?? collect() as $student) {
            StudentAssessment::firstOrCreate(
                [
                    'student_id' => $student->id,
                    'assessment_id' => $assessment->id,
                ],
                [
                    'assigned_by' => $period->created_by,
                    'assigned_at' => now(),
                    'status' => 'pending',
                ]
            );
        }
    }

    protected function objectiveQuestionPlan(LearningPeriod $period): Collection
    {
        $items = $period->topics
            ->flatMap(function ($assignment) {
                $topic = $assignment->topic;
                if (!$topic) {
                    return collect();
                }

                return ($topic->learningObjectives ?? collect())
                    ->where('status', 'active')
                    ->sortBy('order')
                    ->map(fn ($objective) => [
                        'assignment' => $assignment,
                        'topic' => $topic,
                        'objective' => $objective,
                        'question_count' => 0,
                    ]);
            })
            ->unique(fn ($item) => $item['objective']->id)
            ->values();

        if ($items->isEmpty()) {
            return collect();
        }

        $remaining = min(
            self::MAX_QUESTIONS,
            $items->count() * self::QUESTIONS_PER_OBJECTIVE
        );

        for ($round = 0; $round < self::QUESTIONS_PER_OBJECTIVE && $remaining > 0; $round++) {
            foreach ($items as $index => $item) {
                if ($remaining <= 0) {
                    break;
                }

                $items[$index]['question_count']++;
                $remaining--;
            }
        }

        return $items;
    }

    protected function selectObjectiveQuestions(
        Collection $questions,
        int $limit
    ): Collection {
        if ($questions->isEmpty() || $limit <= 0) {
            return collect();
        }

        $selected = collect();

        // Existing question-bank records do not persist an explicit Bloom
        // level yet, so maximize difficulty-level variation as the closest
        // available signal of cognitive variety.
        foreach (
            $questions
                ->shuffle()
                ->groupBy(fn ($question) => strtolower(trim((string) ($question->difficulty_level ?: 'unspecified'))))
            as $group
        ) {
            if ($selected->count() >= $limit) {
                break;
            }

            $selected->push($group->first());
        }

        if ($selected->count() < $limit) {
            $selected = $selected->concat(
                $questions
                    ->whereNotIn('id', $selected->pluck('id'))
                    ->shuffle()
                    ->take($limit - $selected->count())
            );
        }

        return $selected->take($limit)->values();
    }

    protected function generateGroundedQuestionsForObjective(
        LearningPeriod $period,
        $assignment,
        $objective,
        int $count,
        int $existingCount = 0
    ): Collection {
        $topic = $assignment->topic;
        $materialText = KnowledgeChunk::query()
            ->where('topic_id', $topic->id)
            ->whereNotNull('content')
            ->orderBy('chunk_index')
            ->limit(8)
            ->pluck('content')
            ->implode("\n\n");

        $objectiveText = trim(
            ($objective->code ? $objective->code . ': ' : '') .
            ($objective->objective ?? $objective->description ?? '')
        );

        if (
            trim($materialText) === ''
            && trim($objectiveText) === ''
        ) {
            throw new RuntimeException(
                'Insufficient curriculum grounding to generate questions for topic: ' .
                ($topic->topic_name ?? $topic->id)
            );
        }

        $subject = $period->gradeSubject?->subject?->name ?? 'Subject';
        $unit = $assignment->unit?->name ?? '';
        $topicName = $topic->topic_name ?? 'Topic';

        $prompt = <<<PROMPT
Create exactly {$count} additional formative assessment questions for this learner, all targeting the SINGLE learning objective below.

SUBJECT: {$subject}
UNIT: {$unit}
TOPIC: {$topicName}

TARGET LEARNING OBJECTIVE:
{$objectiveText}

TEACHER/CURRICULUM MATERIAL:
{$materialText}

STRICT RULES:
- Use ONLY facts supported by the learning objectives and curriculum material above.
- Do not introduce outside facts, even if you know them.
- These questions are for an automatically generated mandatory formative check.
- Questions must be objectively auto-gradable.
- Allowed types: mcq, true_false, fill_blank, short_answer.
- Vary cognitive demand across the objective. Across the complete set for this objective, aim for this progression where appropriate: Remember/Understand -> Apply -> Analyze/Evaluate.
- {$existingCount} question(s) for this objective have already been selected from the question bank; make these additional questions complement them rather than repeat them.
- Do not force a high Bloom level when the objective itself does not support it; remain faithful to the objective and supplied material.
- For MCQ provide exactly four plausible options and one unambiguous correct answer.
- correct_answer must always be an array of accepted answer strings.
- Use 1 mark per question.
- Give a short explanation grounded in the supplied material.
PROMPT;

        $schema = [
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'question_type' => ['type' => 'string'],
                            'difficulty_level' => ['type' => 'string'],
                            'options' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'correct_answer' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'explanation' => ['type' => 'string'],
                        ],
                        'required' => [
                            'question',
                            'question_type',
                            'correct_answer',
                            'explanation',
                        ],
                    ],
                ],
            ],
            'required' => ['questions'],
        ];

        $response = $this->ai->json(
            [
                [
                    'role' => 'system',
                    'content' =>
                        'You create grounded formative questions from supplied curriculum evidence only.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            $schema,
            [
                'temperature' => 0.35,
                'max_tokens' => 5000,
            ]
        );

        $items = collect($response['questions'] ?? [])
            ->take($count);

        if ($items->count() < $count) {
            throw new RuntimeException(
                'AI did not return enough grounded questions for topic: ' .
                $topicName
            );
        }

        return $items->map(function ($item) use (
            $topic,
            $objective,
            $period
        ) {
            $type = strtolower(
                trim($item['question_type'] ?? 'mcq')
            );

            if (!in_array($type, [
                'mcq',
                'true_false',
                'fill_blank',
                'short_answer',
            ], true)) {
                $type = 'mcq';
            }

            $options = $item['options'] ?? [];
            $correct = $item['correct_answer'] ?? [];

            if (!is_array($correct)) {
                $correct = [$correct];
            }

            if ($type === 'true_false') {
                $options = ['True', 'False'];
            }

            if ($type !== 'mcq' && $type !== 'true_false') {
                $options = [];
            }

            return Question::create([
                'topic_id' => $topic->id,
                'learning_objective_id' => $objective?->id,
                'question_type' => $type,
                'question' => trim((string) ($item['question'] ?? '')),
                'options' => array_values($options),
                'correct_answer' => array_values($correct),
                'marks' => 1,
                'difficulty_level' =>
                    $item['difficulty_level'] ?? 'medium',
                'is_math' => false,
                'is_chemistry' => false,
                'multiple_answers' => false,
                'is_required' => true,
                'explanation' => $item['explanation'] ?? null,
                'created_by' => $period->created_by,
                'source' => 'ai_mastery',
                // Keep automatic formative-only questions out of the
                // permanent Question Bank workflow.
                'status' => 'draft',
                'is_assessment_eligible' => false,
            ]);
        })->values();
    }
}

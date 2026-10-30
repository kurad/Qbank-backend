<?php

namespace App\Services;

use App\Jobs\PrepareLearnerFormativeAttempt;
use App\Models\Assessment;
use App\Models\LearnerFormativeAttempt;
use App\Models\LearnerFormativeQuestion;
use App\Models\LearningPeriod;
use App\Models\KnowledgeChunk;
use App\Models\Question;
use App\Models\StudentAssessment;
use App\Models\TutorSession;
use App\Models\TutorSessionObjective;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\AI\AIGateway;
use RuntimeException;

class LearnerFormativeService
{
    public function __construct(
        protected StudentTopicLearningService $topicLearning,
        protected AIGateway $ai
    ) {}

    private const QUESTIONS_PER_OBJECTIVE = 3;

    private const MAX_QUESTIONS = 20;

    private const AUTO_GRADABLE_TYPES = [
        'mcq',
        'true_false',
        'fill_blank',
        'short_answer',
        'matching',
    ];

    public function statusForStudent(User $student, LearningPeriod $period): array
    {
        $eligible = $this->studentCoveredPeriod($student, $period);

        if (!$eligible) {
            return [
                'required' => true,
                'eligible' => false,
                'ready' => false,
                'status' => 'locked',
                'progress' => 0,
                'message' => 'Complete all topics in this learning period before beginning the assessment.',
            ];
        }

        $attempt = LearnerFormativeAttempt::query()
            ->where('student_id', $student->id)
            ->where('learning_period_id', $period->id)
            ->first();

        if (!$attempt) {
            return [
                'required' => true,
                'eligible' => true,
                'ready' => false,
                'status' => 'eligible',
                'progress' => 0,
                'message' => 'You have covered all topics. Your next step is the assessment.',
            ];
        }

        $this->syncCompletionFromStudentAssessment($attempt);
        $attempt->refresh();

        $studentAssessment = $attempt->studentAssessment;
        $percentage = null;

        if (
            $studentAssessment
            && in_array($studentAssessment->status, ['completed', 'graded'], true)
            && (float) $studentAssessment->max_score > 0
        ) {
            $percentage = round(
                ((float) $studentAssessment->score / (float) $studentAssessment->max_score) * 100,
                1
            );
        }

        return [
            'required' => true,
            'eligible' => true,
            'ready' => in_array($attempt->status, ['ready', 'in_progress', 'completed'], true),
            'status' => $attempt->status,
            'attempt_id' => $attempt->id,
            'assessment_id' => $attempt->assessment_id,
            'student_assessment_id' => $attempt->student_assessment_id,
            'progress' => (int) $attempt->progress,
            'stage' => $attempt->stage,
            'question_count' => (int) $attempt->question_count,
            'score' => $studentAssessment?->score,
            'max_score' => $studentAssessment?->max_score,
            'percentage' => $percentage,
            'message' => $attempt->message,
            'error' => $attempt->status === 'failed' ? $attempt->error_message : null,
        ];
    }

    public function beginPreparation(User $student, LearningPeriod $period): LearnerFormativeAttempt
    {
        if (!$this->studentCoveredPeriod($student, $period)) {
            abort(403, 'Complete all topics in this learning period before beginning the assessment.');
        }

        $attempt = LearnerFormativeAttempt::firstOrCreate(
            [
                'student_id' => $student->id,
                'learning_period_id' => $period->id,
            ],
            [
                'status' => 'eligible',
                'progress' => 0,
                'stage' => 'eligible',
                'message' => 'Ready to prepare your assessment.',
                'seed' => hash('sha256', $student->id . ':' . $period->id . ':' . Str::uuid()),
            ]
        );

        if (in_array($attempt->status, ['ready', 'in_progress', 'completed'], true)) {
            return $attempt;
        }

        $attempt->update([
            'status' => 'preparing',
            'progress' => 5,
            'stage' => 'starting',
            'message' => 'Starting your assessment preparation...',
            'error_message' => null,
        ]);

        PrepareLearnerFormativeAttempt::dispatch($attempt->id)->afterResponse();

        return $attempt->fresh();
    }

    public function prepareAttempt(LearnerFormativeAttempt $attempt): void
    {
        $attempt->loadMissing(['student', 'learningPeriod']);

        $student = $attempt->student;
        $period = $attempt->learningPeriod;

        if (!$student || !$period) {
            throw new RuntimeException('Learner assessment context is missing.');
        }

        if (!$this->studentCoveredPeriod($student, $period)) {
            throw new RuntimeException('The learner has not yet completed all topics in this learning period.');
        }

        $period->loadMissing([
            'group.students:id',
            'gradeSubject.subject:id,name',
            'topics.topic.learningObjectives',
            'topics.unit',
        ]);

        if ($period->topics->isEmpty()) {
            throw new RuntimeException('The learning period has no topics to assess.');
        }

        $this->setProgress($attempt, 15, 'checking_pool', 'Reviewing available questions...');

        $selected = collect();
        $shortages = [];

        $objectivePlan = $this->objectiveQuestionPlan($period, $student);

        if ($objectivePlan->isEmpty()) {
            throw new RuntimeException('No supported learning goals are available for this learning period.');
        }

        foreach ($objectivePlan as $plan) {
            $objective = $plan['objective'];
            $topic = $plan['topic'];
            $required = (int) $plan['question_count'];

            if ($required <= 0) {
                continue;
            }

            $pool = Question::query()
                ->where('topic_id', $topic->id)
                ->where('learning_objective_id', $objective->id)
                ->whereNull('parent_question_id')
                ->where('status', 'approved')
                ->where('is_assessment_eligible', true)
                ->whereIn('question_type', self::AUTO_GRADABLE_TYPES)
                ->with('learningObjective:id,topic_id,code,objective,status')
                ->get();

            $objectiveSelected = $this->selectObjectiveQuestions(
                $pool,
                $required,
                $attempt->seed . ':objective:' . $objective->id
            );

            // If the permanent Question Bank does not yet contain enough
            // approved questions for this learning goal, generate only the
            // missing questions from the approved curriculum material. These
            // temporary questions are attached directly to this learner's
            // formative attempt and stay outside the permanent Question Bank.
            $missing = max(0, $required - $objectiveSelected->count());

            if ($missing > 0) {
                $generated = $this->generateGroundedQuestionsForObjective(
                    $attempt,
                    $period,
                    $plan['assignment'] ?? null,
                    $objective,
                    $missing,
                    $objectiveSelected->count()
                );

                $objectiveSelected = $objectiveSelected->concat($generated)->values();
            }

            if ($objectiveSelected->count() < $required) {
                $shortages[] = [
                    'objective' => $objective->code ?: ('Objective ' . $objective->id),
                    'topic' => $topic->topic_name ?? ('Topic ' . $topic->id),
                    'available' => $objectiveSelected->count(),
                    'required' => $required,
                ];
            }

            $selected = $selected->concat($objectiveSelected->take($required));
        }

        if (!empty($shortages)) {
            $details = collect($shortages)
                ->map(fn ($item) => $item['topic'] . ' / ' . $item['objective'] . ' (' . $item['available'] . '/' . $item['required'] . ')')
                ->implode(', ');

            throw new RuntimeException(
                'There are not enough approved assessment questions for: ' . $details . '. Add more approved questions mapped to those learning goals and try again.'
            );
        }

        if ($selected->isEmpty()) {
            throw new RuntimeException('No approved assessment questions are available for this learning period.');
        }

        $this->setProgress($attempt, 55, 'building_form', 'Building your unique question set...');

        $ordered = $this->deterministicOrder(
            $selected->unique('id')->values(),
            $attempt->seed . ':form-order'
        );

        DB::transaction(function () use ($attempt, $period, $student, $ordered) {
            $container = $this->ensureContainerAssessment($period, $ordered->count());

            $studentAssessment = StudentAssessment::firstOrCreate(
                [
                    'student_id' => $student->id,
                    'assessment_id' => $container->id,
                ],
                [
                    'assigned_by' => $period->created_by,
                    'assigned_at' => now(),
                    'status' => 'pending',
                ]
            );

            LearnerFormativeQuestion::where(
                'learner_formative_attempt_id',
                $attempt->id
            )->delete();

            foreach ($ordered as $index => $question) {
                LearnerFormativeQuestion::create([
                    'learner_formative_attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                    'display_order' => $index + 1,
                    'option_order' => $this->buildOptionOrder(
                        $question,
                        $attempt->seed . ':options:' . $question->id
                    ),
                ]);
            }

            $attempt->update([
                'assessment_id' => $container->id,
                'student_assessment_id' => $studentAssessment->id,
                'question_count' => $ordered->count(),
            ]);
        });

        $this->setProgress($attempt, 90, 'finalizing', 'Finalizing your assessment...');

        $attempt->update([
            'status' => 'ready',
            'progress' => 100,
            'stage' => 'ready',
            'message' => 'Your assessment is ready.',
            'prepared_at' => now(),
            'error_message' => null,
        ]);
    }

    public function questionsForAttempt(User $student, LearnerFormativeAttempt $attempt): array
    {
        abort_unless((int) $attempt->student_id === (int) $student->id, 403);
        abort_unless(in_array($attempt->status, ['ready', 'in_progress'], true), 409, 'Your assessment is not ready yet.');

        $attempt->loadMissing([
            'assessment',
            'learningPeriod.gradeSubject.subject',
            'questions.question',
        ]);

        if (!$attempt->assessment) {
            throw new RuntimeException('Assessment container is missing.');
        }

        if ($attempt->status === 'ready') {
            $attempt->update([
                'status' => 'in_progress',
                'started_at' => $attempt->started_at ?? now(),
                'message' => 'Assessment in progress.',
            ]);
        }

        $questions = $attempt->questions
            ->sortBy('display_order')
            ->map(function (LearnerFormativeQuestion $item) {
                $question = $item->question;
                if (!$question) {
                    return null;
                }

                $data = $question->toArray();
                $data['assessment_order'] = $item->display_order;
                $data['options'] = $this->reorderOptions(
                    $question->options,
                    $item->option_order
                );

                return [
                    'order' => $item->display_order,
                    'question' => $data,
                ];
            })
            ->filter()
            ->values();

        $assessment = $attempt->assessment->toArray();
        $assessment['questions'] = $questions->all();
        $assessment['student_assessment_id'] = $attempt->student_assessment_id;
        $assessment['question_count'] = $questions->count();

        return [
            'assessment' => $assessment,
            'attempt_id' => $attempt->id,
            'subject' => $attempt->learningPeriod?->gradeSubject?->subject?->name ?? 'General',
            'topic' => null,
            'question_count' => $questions->count(),
        ];
    }

    public function studentCoveredPeriod(User $student, LearningPeriod $period): bool
    {
        return (bool) ($this->topicLearning->periodSummary($student, $period)['coverage_complete'] ?? false);
    }

    protected function ensureContainerAssessment(LearningPeriod $period, int $questionCount): Assessment
    {
        // Serialize container creation when several learners begin at once.
        LearningPeriod::query()
            ->whereKey($period->id)
            ->lockForUpdate()
            ->first();

        $assessment = Assessment::firstOrCreate(
            [
                'learning_period_id' => $period->id,
                'purpose' => 'learner_personalized_formative',
            ],
            [
                'type' => 'formative',
                'title' => 'Personalized Formative: ' . $period->title,
                'creator_id' => $period->created_by,
                'is_system_generated' => true,
                'is_mandatory' => true,
                'question_count' => $questionCount,
                'delivery_mode' => 'online',
                'is_timed' => false,
                'instructions' => 'This is your personalized formative assessment for the learning period.',
                'question_blueprint' => [
                    'source' => 'learner_personalized_formative',
                    'questions_per_objective' => self::QUESTIONS_PER_OBJECTIVE,
                    'max_questions' => self::MAX_QUESTIONS,
                    'learning_period_id' => $period->id,
                    'personalized_question_order' => true,
                    'personalized_option_order' => true,
                ],
                'status' => 'published',
            ]
        );

        if ((int) $assessment->question_count !== $questionCount) {
            $assessment->update(['question_count' => $questionCount]);
        }

        $assessment->groups()->syncWithoutDetaching([$period->group_id]);
        $assessment->topics()->syncWithoutDetaching(
            $period->topics->pluck('topic_id')->filter()->unique()->all()
        );
        $assessment->units()->syncWithoutDetaching(
            $period->topics->pluck('unit_id')->filter()->unique()->all()
        );

        return $assessment;
    }

    protected function objectiveQuestionPlan(LearningPeriod $period, User $student): Collection
    {
        $materialGapObjectiveIds = TutorSessionObjective::query()
            ->where('status', 'material_gap')
            ->whereHas('session', function ($query) use ($student, $period) {
                $query
                    ->where('student_id', $student->id)
                    ->where('learning_period_id', $period->id)
                    ->where('session_mode', 'learning');
            })
            ->pluck('learning_objective_id')
            ->unique()
            ->values();

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
                        'objective' => $objective,
                        'topic' => $topic,
                        'question_count' => 0,
                    ]);
            })
            ->reject(fn ($item) =>
                $materialGapObjectiveIds->contains((int) $item['objective']->id)
            )
            ->unique(fn ($item) => $item['objective']->id)
            ->values();

        if ($items->isEmpty()) {
            return collect();
        }

        $remaining = min(
            self::MAX_QUESTIONS,
            $items->count() * self::QUESTIONS_PER_OBJECTIVE
        );

        // Fair round-robin allocation: every objective gets one before any
        // objective gets a second, and so on, until the 20-question cap.
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

    protected function selectObjectiveQuestions(Collection $pool, int $limit, string $seed): Collection
    {
        if ($pool->isEmpty() || $limit <= 0) {
            return collect();
        }

        $ordered = $this->deterministicOrder($pool, $seed);
        $selected = collect();

        // Existing questions do not currently persist an explicit Bloom level.
        // Maximize difficulty-level variation as the closest available signal.
        foreach ($ordered->groupBy(fn ($q) => strtolower(trim((string) ($q->difficulty_level ?: 'unspecified')))) as $group) {
            if ($selected->count() >= $limit) {
                break;
            }
            $selected->push($group->first());
        }

        if ($selected->count() < $limit) {
            $selected = $selected->concat(
                $ordered
                    ->whereNotIn('id', $selected->pluck('id'))
                    ->take($limit - $selected->count())
            );
        }

        return $selected->take($limit)->values();
    }

    protected function deterministicOrder(Collection $items, string $seed): Collection
    {
        return $items
            ->sortBy(fn ($item) => hash('sha256', $seed . ':' . $item->id))
            ->values();
    }

    protected function buildOptionOrder(Question $question, string $seed): ?array
    {
        $options = $question->options;

        if ($question->question_type === 'mcq' && is_array($options)) {
            return collect(array_keys($options))
                ->sortBy(fn ($index) => hash('sha256', $seed . ':' . $index))
                ->values()
                ->all();
        }

        if (
            $question->question_type === 'matching'
            && is_array($options)
            && is_array($options['right'] ?? null)
        ) {
            return collect(array_keys($options['right']))
                ->sortBy(fn ($index) => hash('sha256', $seed . ':right:' . $index))
                ->values()
                ->all();
        }

        return null;
    }

    protected function reorderOptions($options, ?array $order)
    {
        if (!$order || !is_array($options)) {
            return $options;
        }

        if (array_key_exists('right', $options) && is_array($options['right'])) {
            $right = $options['right'];
            $options['right'] = collect($order)
                ->map(fn ($index) => $right[$index] ?? null)
                ->filter(fn ($value) => $value !== null)
                ->values()
                ->all();
            return $options;
        }

        return collect($order)
            ->map(fn ($index) => $options[$index] ?? null)
            ->filter(fn ($value) => $value !== null)
            ->values()
            ->all();
    }

    protected function syncCompletionFromStudentAssessment(LearnerFormativeAttempt $attempt): void
    {
        $studentAssessment = $attempt->studentAssessment;
        if (!$studentAssessment) {
            return;
        }

        if (in_array($studentAssessment->status, ['completed', 'graded'], true) && $attempt->status !== 'completed') {
            $attempt->update([
                'status' => 'completed',
                'progress' => 100,
                'stage' => 'completed',
                'message' => 'Assessment completed.',
                'completed_at' => $studentAssessment->completed_at ?? now(),
            ]);
        }
    }

    protected function setProgress(
        LearnerFormativeAttempt $attempt,
        int $progress,
        string $stage,
        string $message
    ): void {
        $attempt->update([
            'status' => 'preparing',
            'progress' => max(0, min(100, $progress)),
            'stage' => $stage,
            'message' => $message,
        ]);
    }
    protected function generateGroundedQuestionsForObjective(
        LearnerFormativeAttempt $attempt,
        LearningPeriod $period,
        $assignment,
        $objective,
        int $count,
        int $existingCount = 0
    ): Collection {
        $topic = $assignment?->topic;

        if (!$topic) {
            throw new RuntimeException('Assessment topic context is missing.');
        }

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

        if (trim($materialText) === '' && trim($objectiveText) === '') {
            throw new RuntimeException(
                'Insufficient approved curriculum material to prepare assessment questions for topic: ' .
                ($topic->topic_name ?? $topic->id)
            );
        }

        $subject = $period->gradeSubject?->subject?->name ?? 'Subject';
        $unit = $assignment?->unit?->name ?? '';
        $topicName = $topic->topic_name ?? 'Topic';

        $prompt = <<<PROMPT
Create exactly {$count} additional formative assessment questions for one learner, all targeting the SINGLE learning objective below.

SUBJECT: {$subject}
UNIT: {$unit}
TOPIC: {$topicName}

TARGET LEARNING OBJECTIVE:
{$objectiveText}

APPROVED TEACHER/CURRICULUM MATERIAL:
{$materialText}

STRICT RULES:
- Use ONLY facts supported by the learning objective and approved curriculum material above.
- Do not introduce outside facts.
- Questions must be objectively auto-gradable.
- Allowed types: mcq, true_false, fill_blank, short_answer.
- {$existingCount} question(s) for this objective are already available; make the additional questions different but equivalent in scope.
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
                    'content' => 'You create grounded formative questions from supplied curriculum evidence only.',
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
                '_usage_feature' => 'Learner Formative Assessment Generation',
                '_usage_user_id' => $attempt->student_id,
                '_usage_school_id' => $attempt->student?->school_id,
                '_usage_role' => 'student',
                '_usage_learning_period_id' => $period->id,
                '_usage_subject_id' => $period->gradeSubject?->subject_id,
                '_usage_unit_id' => $assignment?->unit_id,
                '_usage_topic_id' => $topic->id,
                '_usage_learning_objective_id' => $objective->id,
            ]
        );

        $items = collect($response['questions'] ?? [])->take($count);

        if ($items->count() < $count) {
            throw new RuntimeException(
                'AI did not return enough grounded questions for topic: ' . $topicName
            );
        }

        return $items->map(function ($item) use ($topic, $objective, $period) {
            $type = strtolower(trim($item['question_type'] ?? 'mcq'));

            if (!in_array($type, ['mcq', 'true_false', 'fill_blank', 'short_answer'], true)) {
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
                'difficulty_level' => $item['difficulty_level'] ?? 'medium',
                'is_math' => false,
                'is_chemistry' => false,
                'multiple_answers' => false,
                'is_required' => true,
                'explanation' => $item['explanation'] ?? null,
                'created_by' => $period->created_by,
                'source' => 'ai_mastery',
                'status' => 'draft',
                'is_assessment_eligible' => false,
            ]);
        })->values();
    }

}

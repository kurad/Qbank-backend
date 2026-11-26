<?php

namespace App\Services;

use App\Exceptions\FormativePreparationException;
use App\Exceptions\FormativeTimeBudgetExceeded;
use App\Jobs\PrepareLearnerFormativeAttempt;
use App\Models\Assessment;
use App\Models\LearnerFormativeAttempt;
use App\Models\LearnerFormativeQuestion;
use App\Models\LearningPeriod;
use App\Models\Question;
use App\Models\StudentAssessment;
use App\Models\TutorSession;
use App\Models\TutorSessionObjective;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class LearnerFormativeService
{
    public function __construct(
        protected StudentTopicLearningService $topicLearning,
        protected FormativeQuestionGenerator $generator
    ) {}

    private const QUESTIONS_PER_OBJECTIVE = 10;

    private const MAX_QUESTIONS = 20;

    private const AUTO_GRADABLE_TYPES = [
        'mcq',
        'true_false',
        'fill_blank',
        'short_answer',
        'matching',
    ];

    /**
     * A preparing attempt whose job has not reported progress for this long
     * is assumed to have lost its worker and is picked up again.
     */
    private const STALE_PREPARING_MINUTES = 6;

    /** Smallest share of the intended questions an assessment may be built with. */
    private const MIN_FILL_RATIO = 0.5;

    public function statusForStudent(User $student, LearningPeriod $period): array
    {
        if (!$period->require_formative_assessment) {
            return [
                'required' => false, 'eligible' => false, 'ready' => false,
                'status' => 'not_required', 'progress' => 0,
                'message' => 'No formative assessment has been assigned for this learning period.',
            ];
        }

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

        // An attempt that is already being built / taken / finished does not
        // depend on the objective plan; skipping it keeps the 2-second status
        // polling cheap and stops a later material change from locking out a
        // learner who already has their questions.
        $inFlight = $attempt && in_array(
            $attempt->status,
            ['preparing', 'ready', 'in_progress', 'completed'],
            true
        );

        if (!$inFlight) {
            // A covered period is not automatically assessable. A learner can
            // legitimately finish a topic with one or more material gaps. Build
            // the same objective plan used by preparation before showing the
            // Begin Assessment action so the UI never advertises an assessment
            // that has no fair, supported learning goals to assess.
            $period->loadMissing([
                'topics.topic.learningObjectives',
                'topics.unit',
            ]);

            if ($this->objectiveQuestionPlan($period, $student)->isEmpty()) {
                return [
                    'required' => true,
                    'eligible' => false,
                    'ready' => false,
                    'status' => 'material_gap',
                    'progress' => 0,
                    'message' => 'Your assessment is not available yet because the approved learning material does not currently support any assessable learning goals. Your teacher needs to review the material first.',
                ];
            }
        }

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

        if ($attempt->status === 'preparing') {
            $this->resumeIfStale($attempt);
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
            // error_message only ever holds text written for the student.
            'error' => $attempt->status === 'failed' ? $attempt->error_message : null,
        ];
    }

    public function beginPreparation(User $student, LearningPeriod $period): LearnerFormativeAttempt
    {
        abort_unless($period->require_formative_assessment, 403,
            'Your teacher has not assigned a formative assessment for this learning period.');

        if (!$this->studentCoveredPeriod($student, $period)) {
            abort(403, 'Complete all topics in this learning period before beginning the assessment.');
        }

        $period->loadMissing([
            'topics.topic.learningObjectives',
            'topics.unit',
        ]);

        if ($this->objectiveQuestionPlan($period, $student)->isEmpty()) {
            abort(409, 'The assessment cannot be prepared yet because the approved learning material does not currently support any assessable learning goals.');
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

        // Already being prepared: do not reset its progress or queue it twice.
        if ($attempt->status === 'preparing') {
            $this->resumeIfStale($attempt);

            return $attempt->fresh();
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

    /**
     * Begin preparing in the background as soon as a learner has covered the
     * whole period, so the assessment is usually ready when they arrive.
     * Never throws: not being eligible yet is an expected outcome.
     */
    public function warmPreparation(User $student, int $learningPeriodId): void
    {
        try {
            $period = LearningPeriod::query()->find($learningPeriodId);

            if (!$period || !$period->require_formative_assessment) {
                return;
            }

            $this->beginPreparation($student, $period);
        } catch (HttpExceptionInterface $e) {
            // Not eligible / nothing assessable yet.
        } catch (Throwable $e) {
            Log::warning('Could not start formative preparation.', [
                'student_id' => $student->id,
                'learning_period_id' => $learningPeriodId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * If a "preparing" attempt has made no progress for several minutes its
     * worker died (deploy, crash, timeout). Queue it again. The claim is an
     * atomic UPDATE so concurrent polls queue it at most once.
     */
    protected function resumeIfStale(LearnerFormativeAttempt $attempt): void
    {
        $cutoff = now()->subMinutes(self::STALE_PREPARING_MINUTES);

        $claimed = LearnerFormativeAttempt::query()
            ->whereKey($attempt->id)
            ->where('status', 'preparing')
            ->where('updated_at', '<', $cutoff)
            ->update([
                'message' => 'Resuming your assessment preparation...',
                'updated_at' => now(),
            ]);

        if ($claimed) {
            PrepareLearnerFormativeAttempt::dispatch($attempt->id)->afterResponse();
        }
    }

    /**
     * @param int|null $deadline unix time after which no NEW objective is started
     *                           (the job then releases itself; stored questions are reused)
     */
    public function prepareAttempt(LearnerFormativeAttempt $attempt, ?int $deadline = null): void
    {
        $attempt->loadMissing(['student', 'learningPeriod']);

        $student = $attempt->student;
        $period = $attempt->learningPeriod;

        if (!$student || !$period) {
            throw new FormativePreparationException(
                'Your assessment could not be found. Please tell your teacher.'
            );
        }

        if (!$period->require_formative_assessment) {
            return;
        }

        if (!$this->studentCoveredPeriod($student, $period)) {
            throw new FormativePreparationException(
                'Complete all topics in this learning period before beginning the assessment.'
            );
        }

        $period->loadMissing([
            'group.students:id',
            'gradeSubject.subject:id,name',
            'topics.topic.learningObjectives',
            'topics.unit',
        ]);

        if ($period->topics->isEmpty()) {
            throw new FormativePreparationException(
                'This learning period has no topics to assess. Please tell your teacher.'
            );
        }

        $this->setProgress($attempt, 15, 'checking_pool', 'Reviewing available questions...');

        $objectivePlan = $this->objectiveQuestionPlan($period, $student)
            ->filter(fn ($plan) => (int) $plan['question_count'] > 0)
            ->values();

        if ($objectivePlan->isEmpty()) {
            throw new FormativePreparationException(
                'The approved learning material does not currently support any assessable learning goals. Your teacher needs to review the material first.'
            );
        }

        $intended = (int) $objectivePlan->sum('question_count');
        $selected = collect();
        $leftovers = [];
        $shortfalls = [];
        $total = $objectivePlan->count();

        foreach ($objectivePlan as $position => $plan) {
            if ($deadline !== null && time() > $deadline) {
                throw new FormativeTimeBudgetExceeded();
            }

            $objective = $plan['objective'];
            $topic = $plan['topic'];
            $required = (int) $plan['question_count'];
            $seed = $attempt->seed . ':objective:' . $objective->id;

            $this->setProgress(
                $attempt,
                15 + (int) floor(40 * $position / max(1, $total)),
                'checking_pool',
                'Preparing questions for "' . ($topic->topic_name ?? 'your topic') . '"...'
            );

            $bank = Question::query()
                ->where('topic_id', $topic->id)
                ->where('learning_objective_id', $objective->id)
                ->whereNull('parent_question_id')
                ->where('status', 'approved')
                ->where('is_assessment_eligible', true)
                ->whereIn('question_type', self::AUTO_GRADABLE_TYPES)
                ->with('learningObjective:id,topic_id,code,objective,status')
                ->get();

            $chosen = $this->selectObjectiveQuestions($bank, $required, $seed);
            $pool = collect();
            $missing = $required - $chosen->count();

            // Questions generated earlier for this objective (by this or any
            // other learner's preparation) are reused before asking the AI.
            if ($missing > 0) {
                $pool = $this->generator->reusablePool($objective)
                    ->reject(fn ($q) => $chosen->contains('id', $q->id))
                    ->values();

                $chosen = $chosen
                    ->concat($this->selectObjectiveQuestions($pool, $missing, $seed . ':pool'))
                    ->values();

                $missing = $required - $chosen->count();
            }

            if ($missing > 0) {
                $this->setProgress(
                    $attempt,
                    15 + (int) floor(40 * ($position + 0.5) / max(1, $total)),
                    'generating',
                    'Writing questions for "' . ($topic->topic_name ?? 'your topic') . '"...'
                );

                try {
                    $generated = $this->generator->generate(
                        $period,
                        $topic,
                        (string) ($plan['assignment']->unit?->name ?? ''),
                        $objective,
                        $missing,
                        $chosen->pluck('id')->all(),
                        $bank->concat($pool)->pluck('question')->all(),
                        [
                            '_usage_user_id' => $attempt->student_id,
                            '_usage_school_id' => $student->school_id ?? null,
                            '_usage_role' => 'student',
                            '_usage_learning_period_id' => $period->id,
                            '_usage_subject_id' => $period->gradeSubject?->subject_id,
                            '_usage_unit_id' => $plan['assignment']->unit_id ?? null,
                            '_usage_topic_id' => $topic->id,
                            '_usage_learning_objective_id' => $objective->id,
                        ]
                    );

                    $chosen = $chosen->concat($generated)->values();
                } catch (FormativePreparationException $e) {
                    // No approved material for this goal (or storage problem).
                    // A storage problem would hit every goal, so only an
                    // ungrounded goal is skipped; anything else is re-thrown.
                    if (!$this->generator->hasEvidence($objective, $topic)) {
                        Log::warning('Formative objective skipped: no approved material.', [
                            'learning_objective_id' => $objective->id,
                            'attempt_id' => $attempt->id,
                        ]);
                    } else {
                        throw $e;
                    }
                }
            }

            $chosen = $chosen->take($required)->values();

            if ($chosen->count() < $required) {
                $shortfalls[] = ($objective->code ?: 'Objective ' . $objective->id)
                    . ' (' . $chosen->count() . '/' . $required . ')';
            }

            $selected = $selected->concat($chosen);
            $leftovers[] = $bank->concat($pool)
                ->reject(fn ($q) => $chosen->contains('id', $q->id))
                ->values();
        }

        $selected = $selected->unique('id')->values();

        // Cover a shortfall from other goals' unused approved questions
        // (no AI involved), taken round-robin so no goal dominates.
        if ($selected->count() < $intended) {
            $selected = $this->backfill($selected, $leftovers, $intended, $attempt->seed);
        }

        if ($selected->isEmpty()) {
            throw new FormativePreparationException(
                'No assessment questions could be prepared from the approved material yet. Your teacher needs to review the material first.'
            );
        }

        if ($selected->count() < (int) ceil($intended * self::MIN_FILL_RATIO)) {
            // Likely transient (the AI answered badly or was overloaded):
            // let the job retry; progress made so far is reused.
            throw new RuntimeException(
                'Only ' . $selected->count() . ' of ' . $intended . ' questions could be prepared. Short: ' .
                implode(', ', $shortfalls)
            );
        }

        if ($selected->count() < $intended) {
            Log::warning('Formative assessment built with fewer questions than planned.', [
                'attempt_id' => $attempt->id,
                'planned' => $intended,
                'built' => $selected->count(),
                'short' => $shortfalls,
            ]);
        }

        $this->setProgress($attempt, 60, 'building_form', 'Building your unique question set...');

        $ordered = $this->deterministicOrder(
            $selected,
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

    /**
     * @param Collection<int, Collection> $leftovers per-objective unused questions
     */
    protected function backfill(Collection $selected, array $leftovers, int $target, string $seed): Collection
    {
        $queues = array_map(
            fn (Collection $pool) => $this->deterministicOrder($pool, $seed . ':backfill')->all(),
            $leftovers
        );

        $taken = $selected->pluck('id')->all();

        while (count($taken) < $target) {
            $progressed = false;

            foreach ($queues as $i => $queue) {
                if (count($taken) >= $target) {
                    break;
                }

                while ($queue) {
                    $candidate = array_shift($queue);

                    if (!in_array($candidate->id, $taken, true)) {
                        $selected->push($candidate);
                        $taken[] = $candidate->id;
                        $progressed = true;
                        break;
                    }
                }

                $queues[$i] = $queue;
            }

            if (!$progressed) {
                break;
            }
        }

        return $selected->values();
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

        // A question can be deleted after the assessment was prepared (a
        // teacher cleaning the bank). Drop such links so the learner is never
        // asked to answer - or blocked from submitting - a question that no
        // longer exists.
        $orphanLinkIds = $attempt->questions
            ->filter(fn (LearnerFormativeQuestion $item) => !$item->question)
            ->pluck('id');

        if ($orphanLinkIds->isNotEmpty()) {
            LearnerFormativeQuestion::query()->whereIn('id', $orphanLinkIds)->delete();
            $attempt->unsetRelation('questions');
            $attempt->load('questions.question');
            $attempt->update(['question_count' => $attempt->questions->count()]);
        }

        if ($attempt->questions->isEmpty()) {
            // Nothing left to ask: send the learner back to prepare it again.
            $attempt->update([
                'status' => 'failed',
                'progress' => 0,
                'stage' => 'failed',
                'message' => 'Your assessment needs to be prepared again. Press Try again.',
                'error_message' => 'Your assessment needs to be prepared again. Press Try again.',
            ]);

            abort(409, 'Your assessment needs to be prepared again. Please go back to your learning period and press Try again.');
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
        /*
         * Only the learner's LATEST Tutor result for each learning objective
         * may suppress that objective from the formative assessment.
         *
         * Previously we selected every historical `material_gap` row. That
         * meant one interrupted/old Tutor session could poison the objective
         * forever, even after a later session successfully taught it.
         */
        $latestObjectiveRowIds = TutorSessionObjective::query()
            ->selectRaw('MAX(tutor_session_objectives.id)')
            ->join(
                'tutor_sessions',
                'tutor_sessions.id',
                '=',
                'tutor_session_objectives.tutor_session_id'
            )
            ->where('tutor_sessions.student_id', $student->id)
            ->where('tutor_sessions.learning_period_id', $period->id)
            ->where('tutor_sessions.session_mode', 'learning')
            ->whereNotNull('tutor_session_objectives.learning_objective_id')
            ->groupBy('tutor_session_objectives.learning_objective_id');

        $materialGapObjectiveIds = TutorSessionObjective::query()
            ->whereIn('id', $latestObjectiveRowIds)
            ->where('status', 'material_gap')
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

        // Target up to 10 questions per objective, subject to the hard
        // 20-question assessment cap. Allocation remains fair: every
        // objective receives one before any objective receives a second,
        // continuing round-robin until the target/cap is reached.
        //
        // Do not mutate nested array values through Collection array access
        // (e.g. $items[$index]['question_count']++). Laravel Collections use
        // overloaded array access, so that indirect modification is ignored.
        for ($round = 0; $round < self::QUESTIONS_PER_OBJECTIVE && $remaining > 0; $round++) {
            foreach ($items->keys() as $index) {
                if ($remaining <= 0) {
                    break;
                }

                $item = $items->get($index);
                $item['question_count'] = (int) ($item['question_count'] ?? 0) + 1;
                $items->put($index, $item);
                $remaining--;
            }
        }

        return $items->values();
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
}

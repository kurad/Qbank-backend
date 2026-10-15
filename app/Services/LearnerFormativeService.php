<?php

namespace App\Services;

use App\Jobs\PrepareLearnerFormativeAttempt;
use App\Models\Assessment;
use App\Models\LearnerFormativeAttempt;
use App\Models\LearnerFormativeQuestion;
use App\Models\LearningPeriod;
use App\Models\Question;
use App\Models\StudentAssessment;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LearnerFormativeService
{
    private const QUESTIONS_PER_TOPIC = 3;

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

        foreach ($period->topics as $assignment) {
            $topic = $assignment->topic;
            if (!$topic) {
                continue;
            }

            $pool = Question::query()
                ->where('topic_id', $topic->id)
                ->whereNull('parent_question_id')
                ->where('status', 'approved')
                ->where('is_assessment_eligible', true)
                ->whereIn('question_type', self::AUTO_GRADABLE_TYPES)
                ->with('learningObjective:id,topic_id,code,objective,status')
                ->get();

            // If the old shared formative exists, its questions are already in the
            // same Question table and are included above only when approved/eligible.
            // The personalized layer itself does not depend on the old implementation.
            $topicSelected = $this->selectEquivalentQuestions(
                $pool,
                self::QUESTIONS_PER_TOPIC,
                $attempt->seed . ':topic:' . $topic->id
            );

            if ($topicSelected->count() < self::QUESTIONS_PER_TOPIC) {
                $shortages[] = [
                    'topic' => $topic->topic_name ?? ('Topic ' . $topic->id),
                    'available' => $topicSelected->count(),
                    'required' => self::QUESTIONS_PER_TOPIC,
                ];
            }

            $selected = $selected->concat($topicSelected);
        }

        if (!empty($shortages)) {
            $details = collect($shortages)
                ->map(fn ($item) => $item['topic'] . ' (' . $item['available'] . '/' . $item['required'] . ')')
                ->implode(', ');

            throw new RuntimeException(
                'There are not enough approved assessment questions for: ' . $details . '. Add more approved questions to the question bank and try again.'
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
        $period->loadMissing('topics:id,learning_period_id,topic_id');

        $topicIds = $period->topics
            ->pluck('topic_id')
            ->filter()
            ->unique()
            ->values();

        if ($topicIds->isEmpty()) {
            return false;
        }

        $covered = TutorSession::query()
            ->where('student_id', $student->id)
            ->where('learning_period_id', $period->id)
            ->where('status', 'completed')
            ->whereIn('topic_id', $topicIds)
            ->pluck('topic_id')
            ->unique();

        return $topicIds->diff($covered)->isEmpty();
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
                    'questions_per_topic' => self::QUESTIONS_PER_TOPIC,
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

    protected function selectEquivalentQuestions(Collection $pool, int $limit, string $seed): Collection
    {
        if ($pool->isEmpty()) {
            return collect();
        }

        $ordered = $this->deterministicOrder($pool, $seed);
        $selected = collect();

        // First pass: maximize learning-objective coverage.
        foreach ($ordered->groupBy(fn ($q) => $q->learning_objective_id ?: 'unmapped') as $group) {
            if ($selected->count() >= $limit) {
                break;
            }
            $selected->push($group->first());
        }

        // Second pass: maximize cognitive-level coverage using difficulty_level.
        if ($selected->count() < $limit) {
            $remaining = $ordered->whereNotIn('id', $selected->pluck('id'));
            foreach ($remaining->groupBy(fn ($q) => $q->difficulty_level ?: 'unspecified') as $group) {
                if ($selected->count() >= $limit) {
                    break;
                }
                $selected->push($group->first());
            }
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

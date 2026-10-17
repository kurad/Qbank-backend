<?php

namespace App\Services;

use App\Jobs\PrepareLearningPeriodFormativeAssessment;
use App\Models\LearningPeriod;
use App\Models\TutorSession;
use App\Models\TutorSessionObjective;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TutorSessionService
{
    public function __construct(
        protected TutorContextService $context,
        protected TutorAIService $ai,
        protected TutorDifficultyService $difficulty,
        protected StudentTopicLearningService $topicLearning
    ) {}

    public function create(User $student, array $data): TutorSession
    {
        /*
         * Repair stale active rows left by older deployments before deciding
         * whether a topic already has an active Tutor session.
         */
        $this->reconcileCompletedSessionsForStudent($student);

        $resolved = $this->context->resolveSessionContext($student, $data);
        $objectives = $resolved['objectives'] ?? collect();

        if ($objectives->isEmpty()) {
            throw ValidationException::withMessages([
                'topic_id' => 'The selected topic does not have any active learning objectives.',
            ]);
        }

        // Do not create a duplicate active session for the same assigned topic.
        $existing = TutorSession::query()
            ->where('student_id', $student->id)
            ->where('group_id', $resolved['group']->id)
            ->where('learning_period_id', $resolved['learningPeriod']->id)
            ->where('topic_id', $resolved['topic']->id)
            ->where('status', 'active')
            ->latest('last_activity_at')
            ->first();

        if ($existing) {
            return $this->freshSession($existing);
        }

        $session = DB::transaction(function () use ($student, $resolved, $objectives) {
            $now = now();

            $session = TutorSession::create([
                'student_id' => $student->id,
                'group_id' => $resolved['group']->id,
                'learning_period_id' => $resolved['learningPeriod']->id,
                'grade_subject_id' => $resolved['gradeSubject']->id,
                'subject_id' => $resolved['gradeSubject']->subject_id,
                'unit_id' => $resolved['unit']->id,
                'topic_id' => $resolved['topic']->id,
                'learning_objective_id' => null,
                'title' => $resolved['topic']->topic_name,
                'status' => 'active',
                'started_at' => $now,
                'last_activity_at' => $now,
            ]);

            foreach ($objectives->sortBy('order')->values() as $index => $objective) {
                $isFirst = $index === 0;

                $session->objectives()->create([
                    'learning_objective_id' => $objective->id,
                    'objective_order' => $index + 1,
                    'status' => $isFirst ? 'in_progress' : 'not_started',
                    'started_at' => $isFirst ? $now : null,
                    'completed_at' => null,
                    'attempts' => 0,
                    'correct_attempts' => 0,
                    'notes' => null,
                ]);
            }

            return $session;
        });

        $this->topicLearning->markStarted($student, $session);

        $opening = $this->ai->opening($session);

        $session->messages()->create([
            'role' => 'assistant',
            'message_type' => 'opening',
            'content' => $opening,
        ]);

        $session->update(['last_activity_at' => now()]);

        return $this->freshSession($session);
    }

    public function currentObjective(TutorSession $session): ?TutorSessionObjective
    {
        $session->loadMissing('objectives.learningObjective');

        return $session->objectives
            ->sortBy('objective_order')
            ->first(fn ($objective) => !in_array(
                strtolower((string) $objective->status),
                ['completed', 'mastered', 'needs_review'],
                true
            ));
    }

    public function nextObjective(
        TutorSession $session,
        TutorSessionObjective $current
    ): ?TutorSessionObjective {
        $session->loadMissing('objectives.learningObjective');

        return $session->objectives
            ->sortBy('objective_order')
            ->first(fn ($objective) =>
                (int) $objective->objective_order > (int) $current->objective_order
                && !in_array(strtolower((string) $objective->status), ['completed', 'mastered', 'needs_review'], true)
            );
    }

    public function processResponseEvaluation(
        TutorSession $session,
        array $evaluation
    ): array {
        return DB::transaction(function () use ($session, $evaluation) {
            $currentId = $this->currentObjective($session)?->id;

            if (!$currentId) {
                return [
                    'objective' => null,
                    'next_objective' => null,
                    'objective_completed' => false,
                    'session_completed' => true,
                    'attempt_number' => 0,
                    'correct_attempts' => 0,
                    'teaching_action' => 'session_complete',
                ];
            }

            /** @var TutorSessionObjective $current */
            $current = TutorSessionObjective::query()
                ->with('learningObjective')
                ->lockForUpdate()
                ->findOrFail($currentId);

            $classification = strtolower((string) ($evaluation['classification'] ?? 'unclear'));
            $score = max(0, min(100, (int) ($evaluation['understanding_score'] ?? 0)));

            $student = $session->student()->first();
            if ($student && $session->learning_period_id && $session->topic_id) {
                $this->topicLearning->recordEvaluation($student, $session, $score);
            }

            // A mastery-confirming response must satisfy BOTH conditions.
            $qualifyingCorrect = $classification === 'correct' && $score >= 80;

            $current->attempts = (int) $current->attempts + 1;

            if ($qualifyingCorrect) {
                $current->correct_attempts = (int) $current->correct_attempts + 1;
            }

            $current->notes = json_encode([
                'classification' => $classification,
                'understanding_score' => $score,
                'feedback' => $evaluation['feedback'] ?? null,
                'evidence' => $evaluation['evidence'] ?? null,
                'misconception' => $evaluation['misconception'] ?? null,
            ], JSON_UNESCAPED_UNICODE);

            $objectiveCompleted = $qualifyingCorrect
                && (int) $current->correct_attempts >= 2;

            if (!$objectiveCompleted) {
                $current->save();

                $freshCurrent = $current->fresh()->load('learningObjective');
                $difficulty = $this->difficulty->profile($session, $freshCurrent);

                /*
                 * Do not trap a learner indefinitely on one objective.
                 * After five consecutive unsuccessful formal checks we keep
                 * the evidence, mark the objective for teacher review, and
                 * continue to the next objective. This is not mastery.
                 */
                if (!empty($difficulty['max_persistence_reached'])) {
                    $current->status = 'needs_review';
                    $current->completed_at = now();
                    $current->notes = json_encode([
                        'classification' => $classification,
                        'understanding_score' => $score,
                        'feedback' => $evaluation['feedback'] ?? null,
                        'evidence' => $evaluation['evidence'] ?? null,
                        'misconception' => $evaluation['misconception'] ?? null,
                        'needs_review' => true,
                        'review_reason' => $difficulty['teacher_attention_reason']
                            ?? 'Persistent difficulty on this learning objective.',
                    ], JSON_UNESCAPED_UNICODE);
                    $current->save();

                    $freshSession = $session->fresh()->load('objectives.learningObjective');
                    $next = $this->nextObjective($freshSession, $current);

                    if (!$next) {
                        return [
                            'objective' => $current->fresh()->load('learningObjective'),
                            'next_objective' => null,
                            'objective_completed' => false,
                            'objective_resolved' => true,
                            'objective_status' => 'needs_review',
                            'session_completed' => true,
                            'attempt_number' => (int) $current->attempts,
                            'correct_attempts' => (int) $current->correct_attempts,
                            'teaching_action' => 'complete_topic_with_review',
                            'learning_state' => 'completed_with_review',
                            'checkpoint_ready' => false,
                            'teacher_attention' => true,
                            'teacher_attention_reason' => $difficulty['teacher_attention_reason']
                                ?? 'This objective needs teacher follow-up.',
                            'struggle_level' => (int) ($difficulty['struggle_level'] ?? 4),
                            'repeated_misconception' => $difficulty['repeated_misconception'] ?? null,
                        ];
                    }

                    $next = TutorSessionObjective::query()
                        ->lockForUpdate()
                        ->findOrFail($next->id);
                    $next->status = 'in_progress';
                    $next->started_at = $next->started_at ?? now();
                    $next->save();

                    return [
                        'objective' => $current->fresh()->load('learningObjective'),
                        'next_objective' => $next->fresh()->load('learningObjective'),
                        'objective_completed' => false,
                        'objective_resolved' => true,
                        'objective_status' => 'needs_review',
                        'session_completed' => false,
                        'attempt_number' => (int) $current->attempts,
                        'correct_attempts' => (int) $current->correct_attempts,
                        'teaching_action' => 'advance_with_review',
                        'learning_state' => 'teaching',
                        'checkpoint_ready' => true,
                        'teacher_attention' => true,
                        'teacher_attention_reason' => $difficulty['teacher_attention_reason']
                            ?? 'This objective needs teacher follow-up.',
                        'struggle_level' => (int) ($difficulty['struggle_level'] ?? 4),
                        'repeated_misconception' => $difficulty['repeated_misconception'] ?? null,
                    ];
                }

                return [
                    'objective' => $freshCurrent,
                    'next_objective' => null,
                    'objective_completed' => false,
                    'objective_resolved' => false,
                    'objective_status' => 'in_progress',
                    'session_completed' => false,
                    'attempt_number' => (int) $current->attempts,
                    'correct_attempts' => (int) $current->correct_attempts,
                    'teaching_action' => $this->teachingAction(
                        $classification,
                        (int) ($difficulty['consecutive_unsuccessful'] ?? 0),
                        false,
                        false
                    ),
                    ...$difficulty,
                ];
            }

            $current->status = 'completed';
            $current->completed_at = now();
            $current->save();

            $freshSession = $session->fresh()->load('objectives.learningObjective');
            $next = $this->nextObjective($freshSession, $current);

            if (!$next) {
                return [
                    'objective' => $current->fresh()->load('learningObjective'),
                    'next_objective' => null,
                    'objective_completed' => true,
                    'objective_resolved' => true,
                    'objective_status' => 'completed',
                    'session_completed' => true,
                    'attempt_number' => (int) $current->attempts,
                    'correct_attempts' => (int) $current->correct_attempts,
                    'teaching_action' => 'complete_topic',
                    'learning_state' => 'completed',
                    'checkpoint_ready' => false,
                    'teacher_attention' => false,
                    'struggle_level' => 0,
                ];
            }

            $next = TutorSessionObjective::query()
                ->lockForUpdate()
                ->findOrFail($next->id);

            $next->status = 'in_progress';
            $next->started_at = $next->started_at ?? now();
            $next->save();

            return [
                'objective' => $current->fresh()->load('learningObjective'),
                'next_objective' => $next->fresh()->load('learningObjective'),
                'objective_completed' => true,
                'objective_resolved' => true,
                'objective_status' => 'completed',
                'session_completed' => false,
                'attempt_number' => (int) $current->attempts,
                'correct_attempts' => (int) $current->correct_attempts,
                'teaching_action' => 'advance_objective',
                'learning_state' => 'teaching',
                'checkpoint_ready' => true,
                'teacher_attention' => false,
                'struggle_level' => 0,
            ];
        });
    }

    protected function teachingAction(
        string $classification,
        int $unsuccessfulAttempts,
        bool $objectiveCompleted,
        bool $sessionCompleted
    ): string {
        if ($sessionCompleted) {
            return 'complete_topic';
        }

        if ($objectiveCompleted) {
            return 'advance_objective';
        }

        return match ($classification) {
            'correct' => 'confirm_and_probe',
            'partially_correct' => $unsuccessfulAttempts >= 4
                ? 'guided_scaffold'
                : ($unsuccessfulAttempts >= 2 ? 'reteach_partial' : 'clarify_missing_piece'),
            'incorrect' => $unsuccessfulAttempts >= 5
                ? 'guided_scaffold'
                : ($unsuccessfulAttempts >= 3
                    ? 'reteach_with_example'
                    : ($unsuccessfulAttempts >= 2 ? 'reteach_differently' : 'correct_and_retry')),
            'unclear' => $unsuccessfulAttempts >= 3 ? 'simplify_question' : 'clarify_response',
            default => 'clarify_response',
        };
    }

    public function finish(
        User $student,
        TutorSession $session,
        string $status = 'abandoned'
    ): TutorSession {
        abort_unless((int) $session->student_id === (int) $student->id, 403);
        abort_unless(in_array($status, ['completed', 'abandoned'], true), 422);

        if ($status === 'completed') {
            $hasUnfinished = $session->objectives()
                ->whereNotIn('status', ['completed', 'mastered', 'needs_review'])
                ->exists();

            abort_if(
                $hasUnfinished,
                422,
                'This Tutor session cannot be marked completed until all learning objectives are mastered.'
            );
        }

        $session->update([
            'status' => $status,
            'ended_at' => now(),
            'last_activity_at' => now(),
        ]);

        if ($status === 'completed' && $session->learning_period_id && $session->topic_id) {
            $hasReview = $session->objectives()->where('status', 'needs_review')->exists();
            if ($hasReview) {
                $this->topicLearning->markNeedsReview($student, $session);
            } else {
                $this->topicLearning->markMastered($student, $session);
            }
        }

        /*
         * As soon as the learner completes the final assigned topic in a
         * learning period, queue preparation of the shared mandatory
         * formative assessment. The request does not wait for AI.
         */
        if (
            $status === 'completed'
            && $session->learning_period_id
            && $this->studentCoveredLearningPeriod(
                $student,
                (int) $session->learning_period_id
            )
        ) {
            PrepareLearningPeriodFormativeAssessment::dispatch(
                (int) $session->learning_period_id
            )->afterResponse();
        }

        return $this->freshSession($session);
    }

    /**
     * Repair legacy Tutor sessions whose objectives are already fully
     * resolved but whose session row still says active.
     */
    public function reconcileCompletedSessionsForStudent(
        User $student
    ): void {
        TutorSession::query()
            ->where(
                'student_id',
                $student->id
            )
            ->where(
                'status',
                'active'
            )
            ->whereDoesntHave(
                'objectives',
                fn ($query) =>
                    $query->whereNotIn(
                        'status',
                        [
                            'completed',
                            'mastered',
                            'needs_review',
                        ]
                    )
            )
            ->get()
            ->each(
                fn (TutorSession $session) =>
                    $this->reconcileCompletionState(
                        $student,
                        $session
                    )
            );
    }

    public function reconcileCompletionState(
        User $student,
        TutorSession $session
    ): TutorSession {
        abort_unless(
            (int) $session->student_id ===
            (int) $student->id,
            403
        );

        if ($session->status !== 'active') {
            return $session;
        }

        $hasUnfinished =
            $session
                ->objectives()
                ->whereNotIn(
                    'status',
                    [
                        'completed',
                        'mastered',
                        'needs_review',
                    ]
                )
                ->exists();

        if ($hasUnfinished) {
            return $session;
        }

        $session->update([
            'status' => 'completed',
            'ended_at' =>
                $session->ended_at ?? now(),
            'last_activity_at' =>
                $session->last_activity_at ?? now(),
        ]);

        if ($session->learning_period_id && $session->topic_id) {
            $hasReview = $session->objectives()->where('status', 'needs_review')->exists();
            if ($hasReview) {
                $this->topicLearning->markNeedsReview($student, $session);
            } else {
                $this->topicLearning->markMastered($student, $session);
            }
        }

        if (
            $session->learning_period_id &&
            $this->studentCoveredLearningPeriod(
                $student,
                (int) $session->learning_period_id
            )
        ) {
            PrepareLearningPeriodFormativeAssessment::dispatch(
                (int) $session->learning_period_id
            )->afterResponse();
        }

        return $session->fresh();
    }

    public function moveOnWithReview(
        User $student,
        TutorSession $session,
        ?string $reason = null
    ): TutorSession {
        abort_unless((int) $session->student_id === (int) $student->id, 403);
        abort_unless($session->status === 'active', 409, 'This Tutor session is no longer active.');

        $current = $this->currentObjective($session);
        abort_unless($current, 422, 'There is no current objective to move on from.');

        $profile = $this->difficulty->profile($session, $current);
        abort_unless(
            !empty($profile['move_on_available']),
            422,
            'Continue learning for a little longer before choosing to move on.'
        );

        DB::transaction(function () use ($student, $session, $reason) {
            $session->objectives()
                ->whereNotIn('status', ['completed', 'mastered', 'needs_review'])
                ->update([
                    'status' => 'needs_review',
                    'completed_at' => now(),
                ]);

            $session->update([
                'status' => 'completed',
                'ended_at' => now(),
                'last_activity_at' => now(),
            ]);

            $this->topicLearning->markNeedsReview($student, $session, $reason);
        });

        return $this->freshSession($session);
    }

    protected function studentCoveredLearningPeriod(
        User $student,
        int $learningPeriodId
    ): bool {
        $period = LearningPeriod::with([
            'topics:id,learning_period_id,topic_id',
        ])->find($learningPeriodId);

        if (!$period || $period->topics->isEmpty()) {
            return false;
        }

        $assignedTopicIds = $period
            ->topics
            ->pluck('topic_id')
            ->filter()
            ->unique()
            ->values();

        if ($assignedTopicIds->isEmpty()) {
            return false;
        }

        $completedTopicIds = TutorSession::query()
            ->where(
                'student_id',
                $student->id
            )
            ->where(
                'learning_period_id',
                $period->id
            )
            ->where(
                'status',
                'completed'
            )
            ->whereIn(
                'topic_id',
                $assignedTopicIds
            )
            ->pluck('topic_id')
            ->unique();

        return $assignedTopicIds
            ->diff($completedTopicIds)
            ->isEmpty();
    }

    public function freshSession(TutorSession $session): TutorSession
    {
        return $session->fresh()->load([
            'group:id,group_name,class_code',
            'learningPeriod:id,group_id,grade_subject_id,title,description,start_date,end_date,status',
            'gradeSubject.subject:id,name',
            'gradeSubject.gradeLevel:id,grade_name',
            'unit:id,name',
            'topic:id,topic_name',
            'objectives.learningObjective:id,topic_id,code,objective,description,order,status',
            'messages:id,tutor_session_id,role,message_type,content,created_at',
        ]);
    }
}
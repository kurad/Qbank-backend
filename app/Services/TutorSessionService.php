<?php

namespace App\Services;

use App\Jobs\PrepareLearningPeriodFormativeAssessment;
use App\Models\LearningPeriod;
use App\Models\TutorSession;
use App\Models\TutorSessionObjective;
use App\Models\StudentTopicLearningState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TutorSessionService
{
    public function __construct(
        protected TutorContextService $context,
        protected TutorAIService $ai,
        protected TutorDifficultyService $difficulty,
        protected StudentTopicLearningService $topicLearning,
        protected TutorSupportingSourceService $supportingSources
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

        /*
         * A mastered topic is already complete for this learning period.
         * Do not create a fresh normal Tutor session that resets the UI to
         * objective 1. Review sessions for `needs_review` topics remain
         * allowed until we introduce a dedicated review-session type.
         */
        $requestedMode = strtolower(
            (string) ($data['session_mode'] ?? 'learning')
        );

        $sessionMode = in_array(
            $requestedMode,
            ['learning', 'review', 'remediation'],
            true
        )
            ? $requestedMode
            : 'learning';

        $targetObjectiveId = $sessionMode === 'remediation'
            ? (int) ($data['learning_objective_id'] ?? 0)
            : 0;

        if ($sessionMode === 'remediation') {
            if ($targetObjectiveId <= 0) {
                throw ValidationException::withMessages([
                    'learning_objective_id' =>
                        'A teacher-directed review requires a learning objective.',
                ]);
            }

            $targetObjective = $objectives->first(
                fn ($objective) =>
                    (int) $objective->id === $targetObjectiveId
            );

            if (!$targetObjective) {
                throw ValidationException::withMessages([
                    'learning_objective_id' =>
                        'The selected learning objective does not belong to this topic.',
                ]);
            }

            /*
             * A remediation session deliberately contains only the teacher-
             * targeted objective. This prevents the Tutor from restarting the
             * whole topic and makes the new mastery evidence attributable to
             * exactly the unresolved learning goal.
             */
            $objectives = collect([$targetObjective]);
        }

        $topicState = StudentTopicLearningState::query()
            ->where('student_id', $student->id)
            ->where('learning_period_id', $resolved['learningPeriod']->id)
            ->where('topic_id', $resolved['topic']->id)
            ->first();

        if ($topicState?->mastery_status === 'mastered' && $sessionMode !== 'review') {
            throw ValidationException::withMessages([
                'topic_id' => 'This topic is already mastered for the current learning period.',
            ]);
        }

        /*
         * Keep normal learning sequential within the SAME learning period.
         *
         * A learner who has already started another topic must resolve it
         * before opening a new one. "Resolve" means the topic becomes covered
         * either through mastery or through the existing move-on/needs-review
         * pathway. Review sessions remain exempt, and learning in a different
         * period/subject is unaffected.
         */
        if ($sessionMode === 'learning') {
            $unfinishedState = StudentTopicLearningState::query()
                ->with('topic:id,topic_name')
                ->where('student_id', $student->id)
                ->where(
                    'learning_period_id',
                    $resolved['learningPeriod']->id
                )
                ->where('progression_status', 'in_progress')
                ->where(
                    'topic_id',
                    '!=',
                    $resolved['topic']->id
                )
                ->latest('updated_at')
                ->first();

            if ($unfinishedState) {
                $unfinishedName =
                    $unfinishedState->topic?->topic_name
                    ?: 'your unfinished topic';

                throw ValidationException::withMessages([
                    'topic_id' =>
                        "Please finish or move on from \"{$unfinishedName}\" before starting another topic in this learning period.",
                ]);
            }
        }

        // Do not create a duplicate active session for the same assigned topic.
        $existing = TutorSession::query()
            ->where('student_id', $student->id)
            ->where('group_id', $resolved['group']->id)
            ->where('learning_period_id', $resolved['learningPeriod']->id)
            ->where('topic_id', $resolved['topic']->id)
            ->where('status', 'active')
            ->where('session_mode', $sessionMode)
            ->when(
                $sessionMode === 'remediation',
                fn ($query) => $query->where(
                    'learning_objective_id',
                    $targetObjectiveId
                )
            )
            ->latest('last_activity_at')
            ->first();

        if ($existing) {
            return $this->freshSession($existing);
        }

        $session = DB::transaction(function () use (
            $student,
            $resolved,
            $objectives,
            $sessionMode,
            $targetObjectiveId
        ) {
            $now = now();

            $session = TutorSession::create([
                'student_id' => $student->id,
                'group_id' => $resolved['group']->id,
                'learning_period_id' => $resolved['learningPeriod']->id,
                'grade_subject_id' => $resolved['gradeSubject']->id,
                'subject_id' => $resolved['gradeSubject']->subject_id,
                'unit_id' => $resolved['unit']->id,
                'topic_id' => $resolved['topic']->id,
                'learning_objective_id' =>
                    $sessionMode === 'remediation'
                        ? $targetObjectiveId
                        : null,
                'title' => $resolved['topic']->topic_name,
                'status' => 'active',
                'session_mode' => $sessionMode,
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
                    'support_status' => 'unknown',
                    'support_reason' => null,
                ]);
            }

            return $session;
        });

        if ($sessionMode === 'learning') {
            $this->topicLearning->markStarted($student, $session);
        }

        $preparedObjective = $this->prepareCurrentObjectiveForLearning($session);

        if ($preparedObjective) {
            $opening = $this->ai->opening($session->fresh());

            $session->messages()->create([
                'role' => 'assistant',
                'message_type' => 'opening',
                'content' => $opening,
            ]);
        } else {
            // Keep the session open. No supported learning goal is not evidence
            // that the learner completed the topic. A teacher can add sources
            // and the learner can retry without losing this session.
            $session->messages()->create([
                'role' => 'assistant',
                'message_type' => 'material_gap',
                'content' => 'This lesson needs a little more preparation from your teacher before we can continue. Your progress is saved, and you can return when the lesson is ready.',
            ]);
        }

        $session->update(['last_activity_at' => now()]);

        return $this->freshSession($session);
    }

    /** A material gap is NOT completed learning and must never imply mastery. */
    public function hasMaterialGaps(TutorSession $session): bool
    {
        return $session->objectives()->where('status', 'material_gap')->exists();
    }

    public function currentObjective(TutorSession $session): ?TutorSessionObjective
    {
        $session->loadMissing('objectives.learningObjective');

        // A newly resolved material gap must not interrupt an objective that
        // the student is already learning. Finish that objective first.
        $active = $session->objectives
            ->sortBy('objective_order')
            ->first(fn ($objective) => strtolower((string) $objective->status) === 'in_progress');
        if ($active) return $active;

        return $session->objectives
            ->sortBy('objective_order')
            ->first(fn ($objective) => !in_array(
                strtolower((string) $objective->status),
                ['completed', 'mastered', 'needs_review', 'material_gap'],
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
                && !in_array(strtolower((string) $objective->status), ['completed', 'mastered', 'needs_review', 'material_gap'], true)
            );
    }

    /**
     * Ensure the active learning goal is supported by approved teacher
     * material before teaching or assessment begins.
     *
     * Unsupported goals are recorded as `material_gap` and skipped. This is
     * deliberately separate from `needs_review`: a material gap is a content
     * coverage issue, not evidence that the learner struggled.
     */
    public function prepareCurrentObjectiveForLearning(
        TutorSession $session,
        bool $retryMaterialGaps = false
    ): ?TutorSessionObjective {
        $session = $session->fresh()->load('objectives.learningObjective');

        $maxPasses = max(1, $session->objectives->count() + 1);

        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $current = $this->currentObjective($session);

            if (!$current && $retryMaterialGaps) {
                // Explicit retry after the teacher updated sources. Only one
                // previously unteachable goal is rechecked per request.
                $gap = $session->objectives->sortBy('objective_order')
                    ->first(fn ($item) => $item->status === 'material_gap');
                if ($gap) {
                    $gap->status = 'not_started';
                    $gap->completed_at = null;
                    $gap->support_status = 'unknown';
                    $gap->support_reason = null;
                    $gap->save();
                    $session = $session->fresh()->load('objectives.learningObjective');
                    $current = $this->currentObjective($session);
                    $retryMaterialGaps = false;
                }
            }
            if (!$current) {
                return null;
            }

            $supportStatus = strtolower((string) ($current->support_status ?? 'unknown'));
            $support = [
                'status' => $supportStatus,
                'reason' => $current->support_reason,
                'missing_focus' => [],
            ];

            if ($supportStatus === 'unknown') {
                $support = $this->ai->assessObjectiveMaterialSupport(
                    $session,
                    $current
                );

                $current->support_status = $support['status'] ?? 'supported';
                $current->support_reason = $support['reason'] ?? null;
                $current->save();

                $supportStatus = strtolower((string) $current->support_status);
            }

            if (in_array($supportStatus, ['supported', 'supporting_source'], true)) {
                if ($current->status === 'not_started') {
                    $current->status = 'in_progress';
                    $current->started_at = $current->started_at ?? now();
                    $current->save();
                }

                return $current->fresh()->load('learningObjective');
            }

            /*
             * Primary material is either PARTIAL or UNSUPPORTED.
             * Both states mean the full objective cannot yet be taught and
             * assessed fairly from the primary extraction alone. Before
             * declaring a gap, consult only teacher-approved supporting
             * sources and ask them to fill the verified missing focus.
             */
            $supporting = $this->supportingSources->resolve(
                $session,
                $current,
                [
                    'status' => $supportStatus,
                    'reason' => $current->support_reason,
                    'missing_focus' => $support['missing_focus'] ?? [],
                ]
            );

            if (($supporting['status'] ?? null) === 'supported') {
                $current->support_status = 'supporting_source';
                $current->support_reason =
                    $supporting['reason']
                    ?? 'Teacher-provided supporting sources are being used.';
                $current->supporting_source_ids =
                    $supporting['source_ids'] ?? [];
                $current->supporting_source_started_at =
                    $current->supporting_source_started_at ?? now();

                if ($current->status === 'not_started') {
                    $current->status = 'in_progress';
                    $current->started_at = $current->started_at ?? now();
                }

                $current->save();

                // Source selection is orchestration, not a lesson turn. Keep
                // the audited source IDs/reason on the objective and allow
                // the next teacher-style response to teach immediately.

                return $current->fresh()->load('learningObjective');
            }

            $current->status = 'material_gap';
            $current->completed_at = null;
            $current->save();

            // Do not emit an assistant bubble for each uncovered goal.
            // The status and support_reason remain available for teachers;
            // supported objectives continue without distracting the learner.

            $session = $session->fresh()->load('objectives.learningObjective');
            $next = $this->nextObjective($session, $current);

            if ($next) {
                $next->status = 'in_progress';
                $next->started_at = $next->started_at ?? now();
                $next->save();

                $session = $session->fresh()->load('objectives.learningObjective');
                continue;
            }

            return null;
        }

        return $this->currentObjective($session);
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
                    'session_completed' => !$this->hasMaterialGaps($session),
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

            /*
             * A focused recovery check is created only after the learner has
             * already demonstrated strong partial understanding on the same
             * objective. If that small recovery check is then answered
             * correctly, the two pieces of evidence together are sufficient:
             *
             *   strong partial checkpoint + correct targeted recovery
             *
             * This avoids forcing the learner to rewrite an entire multi-part
             * response merely to repair one missing concept.
             */
            $recoveryConfirmed =
                $qualifyingCorrect
                && !empty($evaluation['recovery_confirmed']);

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
                'recovery_confirmed' => $recoveryConfirmed,
            ], JSON_UNESCAPED_UNICODE);

            $objectiveCompleted =
                $qualifyingCorrect
                && (
                    (int) $current->correct_attempts >= 2
                    || (
                        $recoveryConfirmed
                        && (int) $current->attempts >= 2
                    )
                );

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
                            'session_completed' => !$this->hasMaterialGaps($session),
                            'attempt_number' => (int) $current->attempts,
                            'correct_attempts' => (int) $current->correct_attempts,
                            'teaching_action' => $this->hasMaterialGaps($session) ? 'await_source_review' : 'complete_topic_with_review',
                            'learning_state' => $this->hasMaterialGaps($session) ? 'awaiting_material' : 'completed_with_review',
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

            if (
                strtolower((string) ($current->support_status ?? ''))
                === 'supporting_source'
            ) {
                $current->supporting_source_mastered_at = now();
            }

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
                    'session_completed' => !$this->hasMaterialGaps($session),
                    'attempt_number' => (int) $current->attempts,
                    'correct_attempts' => (int) $current->correct_attempts,
                    'teaching_action' => $this->hasMaterialGaps($session) ? 'await_source_review' : 'complete_topic',
                    'learning_state' => $this->hasMaterialGaps($session) ? 'awaiting_material' : 'completed',
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
                ->whereNotIn('status', ['completed', 'mastered', 'needs_review', 'material_gap'])
                ->exists();

            abort_if(
                $hasUnfinished,
                422,
                'This Tutor session cannot be marked completed until all learning objectives are mastered.'
            );
            abort_if($this->hasMaterialGaps($session), 422, 'Some learning goals are awaiting verified source coverage. The topic cannot be marked completed yet.');
        }

        $session->update([
            'status' => $status,
            'ended_at' => now(),
            'last_activity_at' => now(),
        ]);

        if ($status === 'completed') {
            return $this->synchronizeCompletedTopicState($student, $session);
        }

        return $this->freshSession($session);
    }

    /**
     * Keep Tutor-session completion and the learner's topic-learning state in
     * sync. This is safe to call for an already-completed session and therefore
     * also repairs sessions completed by older checkpoint flows.
     */
    public function synchronizeCompletedTopicState(
        User $student,
        TutorSession $session
    ): TutorSession {
        abort_unless((int) $session->student_id === (int) $student->id, 403);

        if ($session->status !== 'completed') {
            return $this->freshSession($session);
        }
        // Never synchronize a prematurely completed gap session as mastered.
        if ($this->hasMaterialGaps($session)) {
            return $this->freshSession($session);
        }

        $wasCovered = false;

        if (
            ($session->session_mode ?? 'learning') === 'remediation'
            && $session->learning_period_id
            && $session->topic_id
            && $session->learning_objective_id
        ) {
            $existingState = StudentTopicLearningState::query()
                ->where('student_id', $student->id)
                ->where('learning_period_id', $session->learning_period_id)
                ->where('topic_id', $session->topic_id)
                ->first();

            $wasCovered =
                $existingState?->progression_status === 'covered';

            /*
             * The original learning sessions remain immutable evidence.
             * Instead of rewriting their needs_review objective rows, compare
             * those unresolved objective IDs with successfully completed
             * teacher-directed remediation sessions.
             */
            $originalNeedsReviewIds = TutorSessionObjective::query()
                ->where('status', 'needs_review')
                ->whereHas('session', function ($query) use ($student, $session) {
                    $query
                        ->where('student_id', $student->id)
                        ->where(
                            'learning_period_id',
                            $session->learning_period_id
                        )
                        ->where('topic_id', $session->topic_id)
                        ->where(function ($modeQuery) {
                            $modeQuery
                                ->whereNull('session_mode')
                                ->orWhere('session_mode', 'learning');
                        });
                })
                ->pluck('learning_objective_id')
                ->filter()
                ->unique()
                ->values();

            $successfulRemediationIds = TutorSession::query()
                ->where('student_id', $student->id)
                ->where(
                    'learning_period_id',
                    $session->learning_period_id
                )
                ->where('topic_id', $session->topic_id)
                ->where('session_mode', 'remediation')
                ->where('status', 'completed')
                ->whereIn(
                    'learning_objective_id',
                    $originalNeedsReviewIds
                )
                ->whereHas('objectives', function ($query) {
                    $query->whereIn(
                        'status',
                        ['completed', 'mastered']
                    );
                })
                ->pluck('learning_objective_id')
                ->filter()
                ->unique()
                ->values();

            $remainingReviewIds = $originalNeedsReviewIds
                ->diff($successfulRemediationIds)
                ->values();

            $hasMaterialGap = TutorSessionObjective::query()
                ->where('status', 'material_gap')
                ->whereHas('session', function ($query) use ($student, $session) {
                    $query
                        ->where('student_id', $student->id)
                        ->where(
                            'learning_period_id',
                            $session->learning_period_id
                        )
                        ->where('topic_id', $session->topic_id)
                        ->where(function ($modeQuery) {
                            $modeQuery
                                ->whereNull('session_mode')
                                ->orWhere('session_mode', 'learning');
                        });
                })
                ->exists();

            if (
                $remainingReviewIds->isEmpty()
                && !$hasMaterialGap
            ) {
                $this->topicLearning->markMastered(
                    $student,
                    $session
                );
            }

            return $this->freshSession($session);
        }

        if ($session->learning_period_id && $session->topic_id) {
            $existingState = \App\Models\StudentTopicLearningState::query()
                ->where('student_id', $student->id)
                ->where('learning_period_id', $session->learning_period_id)
                ->where('topic_id', $session->topic_id)
                ->first();

            $wasCovered = $existingState?->progression_status === 'covered';

            $hasReview = $session->objectives()
                ->whereIn('status', ['needs_review', 'material_gap'])
                ->exists();

            $hasMaterialGap = $session->objectives()
                ->where('status', 'material_gap')
                ->exists();

            if ($hasReview) {
                $this->topicLearning->markNeedsReview(
                    $student,
                    $session,
                    $hasMaterialGap
                        ? 'One or more learning goals could not be taught because the approved material did not provide enough information.'
                        : null
                );
            } else {
                $this->topicLearning->markMastered($student, $session);
            }
        }

        /*
         * Queue the period formative only when this call newly covers the
         * topic. This prevents repeated Finish-topic clicks from dispatching
         * duplicate preparation jobs.
         */
        if (
            !$wasCovered
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
        /*
         * Older/start-again flows could create a new active Tutor session
         * after the topic had already been mastered. Such a session must not
         * become the learner's current topic or inflate objective counts.
         */
        $masteredStates = StudentTopicLearningState::query()
            ->where('student_id', $student->id)
            ->where('progression_status', 'covered')
            ->where('mastery_status', 'mastered')
            ->get(['learning_period_id', 'topic_id']);

        foreach ($masteredStates as $state) {
            TutorSession::query()
                ->where('student_id', $student->id)
                ->where('learning_period_id', $state->learning_period_id)
                ->where('topic_id', $state->topic_id)
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('session_mode')
                        ->orWhere('session_mode', 'learning');
                })
                ->update([
                    'status' => 'abandoned',
                    'ended_at' => now(),
                    'last_activity_at' => now(),
                ]);
        }

        TutorSession::query()
            ->where(
                'student_id',
                $student->id
            )
            ->where(
                'status',
                'active'
            )
            // Never reconcile a partially-created/legacy session that has no
            // objective rows. An empty objective set is not evidence of mastery.
            ->whereHas('objectives')
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

        // A session with no objectives can be left behind by an interrupted
        // or older start flow. Never convert that incomplete initialization
        // into a completed/mastered topic.
        if (!$session->objectives()->exists()) {
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

        // Reconciliation is a repair path, not a shortcut to mastery. Require
        // evidence that the learner actually interacted with the Tutor before
        // allowing an already-resolved objective set to complete the session.
        $hasLearnerActivity =
            $session->messages()->where('role', 'user')->exists()
            || $session->objectives()->where('attempts', '>', 0)->exists();

        if (!$hasLearnerActivity) {
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
                ->whereNotIn('status', ['completed', 'mastered', 'needs_review', 'material_gap'])
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
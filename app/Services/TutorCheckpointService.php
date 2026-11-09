<?php

namespace App\Services;

use App\Models\Question;
use App\Models\TutorMessage;
use App\Models\TutorResponseEvaluation;
use App\Models\TutorSession;
use App\Models\TutorSessionObjective;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TutorCheckpointService
{
    protected const MAX_CHECKPOINT_ATTEMPTS = 3;
    protected const TYPES = [
        'mcq',
        'true_false',
        'matching',
        'fill_blank',
        'short_answer',
    ];

    public function __construct(
        protected TutorAIService $ai,
        protected TutorSessionService $sessions,
        protected TutorDifficultyService $difficulty
    ) {}

    public function generate(
        TutorSession $session,
        bool $forceFollowUp = false
    ): TutorMessage
    {
        $this->ensureActive($session);

        $objective = $this->sessions->prepareCurrentObjectiveForLearning($session);

        if (!$objective) {
            throw ValidationException::withMessages([
                'session' => 'There is no supported learning goal available for a learning check.',
            ]);
        }

        $difficulty = $this->difficulty->profile($session, $objective);

        /*
         * Normal learner-triggered checks respect the support-turn gate.
         * An automatic follow-up immediately after feedback may bypass that
         * gate so the learner does not have to click "Check again".
         *
         * This does NOT bypass mastery rules: the new checkpoint is still
         * evaluated normally and the objective still requires its configured
         * evidence before it can be resolved.
         */
        if (
            !$forceFollowUp
            && !($difficulty['checkpoint_ready'] ?? true)
        ) {
            throw ValidationException::withMessages([
                'checkpoint' => 'Spend a little more time working through the explanation with your Tutor before trying another learning check.',
            ]);
        }

        $pending = $this->pendingCheckpoint($session, $objective);

        if ($pending) {
            return $pending;
        }

        // Let the learning objective determine the assessment approach.
        // The planner chooses assessment mode, response depth, question type,
        // and interaction strategy before the question is generated.
        $plan = $this->ai->planCheckpoint($session);

        /*
         * Mix teacher-approved Question Bank checks with AI-generated checks.
         *
         * We alternate where possible so the Tutor does not become either:
         * - purely generative; or
         * - a static question-bank player.
         *
         * Question Bank candidates must be approved, assessment-eligible,
         * linked to THIS exact learning objective, supported by the Tutor UI,
         * and not previously used in this session.
         */
        $checkpoint = $this->questionBankCheckpoint(
            $session,
            $objective,
            $plan
        );

        if (!$checkpoint) {
            // Four focused chunks are normally enough for one checkpoint.
            $knowledge = $this->ai->teachingContext(
                $session,
                $objective->learningObjective?->objective ?? '',
                4
            );

            $checkpoint = $this->ai->generateCheckpoint(
                $session,
                $plan,
                $knowledge
            );

            $checkpoint['source'] = 'ai_generated';
        }

        $message = $session->messages()->create([
            'role' => 'assistant',
            'message_type' => 'checkpoint',
            'content' => $checkpoint['question'],
            'metadata' => [
                'checkpoint' => [
                    ...$checkpoint,
                    'status' => 'pending',
                    'generated_at' => now()->toISOString(),
                ],
            ],
        ]);

        $session->update([
            'last_activity_at' => now(),
        ]);

        return $message;
    }

    /**
     * Return the currently unanswered checkpoint for the current objective.
     */
    public function pendingCheckpoint(
        TutorSession $session,
        ?TutorSessionObjective $objective = null
    ): ?TutorMessage {
        $objective ??= $this->sessions->currentObjective($session);

        if (!$objective) {
            return null;
        }

        return $session
            ->messages()
            ->where('role', 'assistant')
            ->where('message_type', 'checkpoint')
            ->whereNotNull('metadata')
            ->latest('id')
            ->get()
            ->first(function ($message) use ($objective) {
                $checkpoint = $message->metadata['checkpoint'] ?? null;

                if (!is_array($checkpoint)) {
                    return false;
                }

                return
                    (int) ($checkpoint['objective_id'] ?? 0)
                        === (int) $objective->learning_objective_id
                    && in_array(
                        ($checkpoint['status'] ?? 'pending'),
                        ['pending', 'retry_ready'],
                        true
                    );
            });
    }

    /**
     * Postpone a pending checkpoint without recording an attempt.
     *
     * A skipped checkpoint remains outstanding mastery evidence and
     * will be returned before the topic/session is allowed to finish.
     */
    public function skip(
        TutorSession $session,
        TutorMessage $checkpointMessage
    ): TutorMessage {
        $this->ensureActive($session);
        $this->validateCheckpointOwnership($session, $checkpointMessage);

        return DB::transaction(function () use ($session, $checkpointMessage) {
            $locked = TutorMessage::query()
                ->whereKey($checkpointMessage->id)
                ->lockForUpdate()
                ->firstOrFail();

            $metadata = $locked->metadata ?? [];
            $checkpoint = $metadata['checkpoint'] ?? null;

            if (!is_array($checkpoint)) {
                throw ValidationException::withMessages([
                    'checkpoint' => 'Checkpoint information is missing.',
                ]);
            }

            if (($checkpoint['status'] ?? 'pending') !== 'pending') {
                throw ValidationException::withMessages([
                    'checkpoint' => 'Only a pending checkpoint can be skipped.',
                ]);
            }

            $metadata['checkpoint']['status'] = 'skipped';
            $metadata['checkpoint']['skipped_at'] = now()->toISOString();

            $locked->update([
                'metadata' => $metadata,
            ]);

            $session->update([
                'last_activity_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * All skipped checkpoints that still require completion.
     */
    public function skippedCheckpoints(TutorSession $session)
    {
        return $session
            ->messages()
            ->where('role', 'assistant')
            ->where('message_type', 'checkpoint')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->get()
            ->filter(function (TutorMessage $message) {
                $checkpoint = $message->metadata['checkpoint'] ?? null;

                return is_array($checkpoint)
                    && ($checkpoint['status'] ?? null) === 'skipped';
            })
            ->values();
    }

    public function outstandingCount(TutorSession $session): int
    {
        return $this->skippedCheckpoints($session)->count();
    }

    /**
     * Return a skipped check to the student.
     *
     * Reopening the stored check avoids an AI generation request, making
     * the final review fast. A future enhancement can replace it with a
     * fresh equivalent question if desired.
     */
    public function reopenOldestSkipped(
        TutorSession $session
    ): ?TutorMessage {
        $skipped = $this->skippedCheckpoints($session)->first();

        if (!$skipped) {
            return null;
        }

        return DB::transaction(function () use ($session, $skipped) {
            $locked = TutorMessage::query()
                ->whereKey($skipped->id)
                ->lockForUpdate()
                ->firstOrFail();

            $metadata = $locked->metadata ?? [];
            $checkpoint = $metadata['checkpoint'] ?? null;

            if (
                !is_array($checkpoint)
                || ($checkpoint['status'] ?? null) !== 'skipped'
            ) {
                return null;
            }

            $metadata['checkpoint']['status'] = 'pending';
            $metadata['checkpoint']['returned_at'] = now()->toISOString();

            $locked->update([
                'metadata' => $metadata,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Submit a checkpoint answer.
     *
     * Performance rules:
     * - MCQ / True-False / Fill Blank / Matching are evaluated locally.
     * - Short answer uses one AI evaluation.
     * - We do NOT make a second AI call merely to rewrite feedback.
     * - AI work happens before the DB transaction so database locks are
     *   not held while waiting for the model.
     */
    public function answer(
        TutorSession $session,
        TutorMessage $checkpointMessage,
        mixed $studentAnswer,
        int $studentId,
        array $aiAttachments = [],
        array $storedAttachments = []
    ): array {
        $this->ensureActive($session);
        $this->validateCheckpointOwnership($session, $checkpointMessage);

        $checkpoint = $checkpointMessage->metadata['checkpoint'] ?? null;

        if (!is_array($checkpoint)) {
            throw ValidationException::withMessages([
                'checkpoint' => 'Checkpoint information is missing.',
            ]);
        }

        if (!in_array(($checkpoint['status'] ?? 'pending'), ['pending', 'retry_ready'], true)) {
            throw ValidationException::withMessages([
                'checkpoint' => 'This checkpoint has already been answered.',
            ]);
        }

        $objective = $session->objectives()
            ->where('learning_objective_id', $checkpoint['objective_id'] ?? 0)
            ->with('learningObjective')
            ->first();

        if (!$objective) {
            throw ValidationException::withMessages([
                'checkpoint' => 'The checkpoint learning objective could not be found.',
            ]);
        }

        $currentObjective = $this->sessions->currentObjective($session);

        if (!$currentObjective || (int) $currentObjective->id !== (int) $objective->id) {
            throw ValidationException::withMessages([
                'checkpoint' => 'This checkpoint is no longer active.',
            ]);
        }

        // AI evaluation (short answer only) happens before any database lock.
        $evaluation = $this->evaluateCheckpoint(
            $session,
            $checkpoint,
            $studentAnswer,
            $aiAttachments
        );

        /*
         * Strong partial understanding should not force the learner to repeat
         * an entire broad checkpoint. When there is one identifiable missing
         * concept, prepare a focused recovery check instead.
         */
        $recoveryCheckpoint = null;
        $classification = strtolower(
            (string) ($evaluation['classification'] ?? 'unclear')
        );
        $understandingScore = (int) (
            $evaluation['understanding_score'] ?? 0
        );
        $hasFocusedGap = trim(
            (string) ($evaluation['misconception'] ?? '')
        ) !== '';

        if (
            empty($checkpoint['recovery_mode'])
            && $classification === 'partially_correct'
            && $understandingScore >= 65
            && $hasFocusedGap
        ) {
            try {
                $recoveryCheckpoint =
                    $this->ai->generateTargetedRecoveryCheckpoint(
                        $session,
                        $checkpoint,
                        $evaluation,
                        $this->answerAsText(
                            $checkpoint,
                            $studentAnswer
                        )
                    );
            } catch (\Throwable $e) {
                \Log::warning(
                    'Tutor targeted recovery generation failed; falling back to normal retry.',
                    [
                        'session_id' => $session->id,
                        'checkpoint_message_id' => $checkpointMessage->id,
                        'message' => $e->getMessage(),
                    ]
                );
            }
        }

        /*
         * A correct answer to an already-focused recovery check completes the
         * missing evidence when combined with the strong partial response that
         * caused that recovery check to be generated.
         */
        if (
            !empty($checkpoint['recovery_mode'])
            && ($evaluation['classification'] ?? null) === 'correct'
            && (int) ($evaluation['understanding_score'] ?? 0) >= 80
        ) {
            $evaluation['recovery_confirmed'] = true;
        }

        $stored = DB::transaction(function () use (
            $session,
            $checkpointMessage,
            $checkpoint,
            $objective,
            $studentAnswer,
            $studentId,
            $evaluation,
            $recoveryCheckpoint,
            $storedAttachments
        ) {
            $lockedCheckpointMessage = TutorMessage::query()
                ->whereKey($checkpointMessage->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedCheckpointMessage) {
                throw ValidationException::withMessages([
                    'checkpoint' => 'The checkpoint could not be found.',
                ]);
            }

            $lockedMetadata = $lockedCheckpointMessage->metadata ?? [];
            $lockedCheckpoint = $lockedMetadata['checkpoint'] ?? null;

            if (!is_array($lockedCheckpoint)) {
                throw ValidationException::withMessages([
                    'checkpoint' => 'Checkpoint information is missing.',
                ]);
            }

            if (!in_array(($lockedCheckpoint['status'] ?? 'pending'), ['pending', 'retry_ready'], true)) {
                throw ValidationException::withMessages([
                    'checkpoint' => 'This checkpoint has already been answered.',
                ]);
            }

            $previousAttempts = is_array($lockedCheckpoint['attempts'] ?? null)
                ? $lockedCheckpoint['attempts']
                : [];
            $checkpointAttemptNumber = count($previousAttempts) + 1;

            $answerText = $this->answerAsText(
                $checkpoint,
                $studentAnswer
            );

            if (
                trim($answerText) === ''
                && !empty($storedAttachments)
            ) {
                $answerText = 'Submitted an image answer.';
            }

            $studentMessage = $session->messages()->create([
                'role' => 'user',
                'message_type' => 'checkpoint_answer',
                'content' => $answerText,
                'metadata' => [
                    'checkpoint_answer' => [
                        'checkpoint_message_id' => $lockedCheckpointMessage->id,
                        'type' => $checkpoint['type'],
                        'answer' => $studentAnswer,
                        'attempt_number' => $checkpointAttemptNumber,
                    ],
                    'attachments' => $storedAttachments,
                ],
            ]);

            TutorResponseEvaluation::create([
                'tutor_session_id' => $session->id,
                'tutor_session_objective_id' => $objective->id,
                'student_id' => $studentId,
                'student_response' => $studentMessage->content,
                'classification' => $evaluation['classification'],
                'correct' => $evaluation['correct'],
                'understanding_score' => $evaluation['understanding_score'],
                'feedback' => $evaluation['feedback'] ?? null,
                'evidence' => $evaluation['evidence'] ?? null,
                'misconception' => $evaluation['misconception'] ?? null,
            ]);

            $progress = $this->sessions->processResponseEvaluation(
                $session,
                $evaluation
            );

            $objectiveResolved = (bool) (
                $progress['objective_resolved']
                ?? $progress['objective_completed']
                ?? false
            );
            $sessionCompleted = (bool) ($progress['session_completed'] ?? false);
            $qualifyingCorrect = (bool) ($evaluation['correct'] ?? false);
            $retryAllowed = !$qualifyingCorrect
                && !$objectiveResolved
                && !$sessionCompleted
                && $checkpointAttemptNumber < self::MAX_CHECKPOINT_ATTEMPTS;

            $attemptRecord = [
                'attempt_number' => $checkpointAttemptNumber,
                'answer' => $studentAnswer,
                'classification' => $evaluation['classification'] ?? 'unclear',
                'correct' => $qualifyingCorrect,
                'understanding_score' => (int) ($evaluation['understanding_score'] ?? 0),
                'feedback' => $evaluation['feedback'] ?? null,
                'misconception' => $evaluation['misconception'] ?? null,
                'recovery_confirmed' =>
                    !empty($evaluation['recovery_confirmed']),
                'attachments' => collect($storedAttachments)
                    ->values()
                    ->map(
                        fn ($attachment, $index) => [
                            'index' => $index,
                            'original_name' =>
                                $attachment['original_name']
                                ?? 'Image answer',
                            'mime_type' =>
                                $attachment['mime_type']
                                ?? 'image/*',
                            'size' =>
                                (int) ($attachment['size'] ?? 0),
                            'url' =>
                                "/api/tutor/messages/{$studentMessage->id}/attachments/{$index}",
                        ]
                    )
                    ->all(),
                'submitted_at' => now()->toISOString(),
            ];

            $lockedMetadata['checkpoint']['attempts'] = [
                ...$previousAttempts,
                $attemptRecord,
            ];
            $lockedMetadata['checkpoint']['attempt_count'] = $checkpointAttemptNumber;
            $lockedMetadata['checkpoint']['max_attempts'] = self::MAX_CHECKPOINT_ATTEMPTS;
            $lockedMetadata['checkpoint']['retry_allowed'] = $retryAllowed;
            $lockedMetadata['checkpoint']['last_student_answer'] =
                $studentAnswer;

            $lockedMetadata['checkpoint']['last_answer_attachments'] =
                $attemptRecord['attachments'] ?? [];

            $lockedMetadata['checkpoint']['result'] = [
                'classification' => $evaluation['classification'],
                'correct' => $qualifyingCorrect,
                'understanding_score' => $evaluation['understanding_score'],
            ];

            if ($retryAllowed) {
                /*
                 * For strong partial understanding, convert the open retry
                 * into a small recovery check. Preserve the full attempt
                 * history and original question for audit/review.
                 */
                if (is_array($recoveryCheckpoint)) {
                    $lockedMetadata['checkpoint']['original_question'] =
                        $lockedMetadata['checkpoint']['original_question']
                        ?? ($lockedCheckpoint['question'] ?? null);

                    foreach ([
                        'question',
                        'type',
                        'options',
                        'correct_answer',
                        'accepted_answers',
                        'expected_answer',
                        'evaluation_criteria',
                        'assessment_mode',
                        'response_depth',
                        'strategy',
                        'recovery_mode',
                        'recovery_focus',
                        'recovery_of_question',
                    ] as $key) {
                        if (array_key_exists($key, $recoveryCheckpoint)) {
                            $lockedMetadata['checkpoint'][$key] =
                                $recoveryCheckpoint[$key];
                        }
                    }

                    $lockedMetadata['checkpoint']['recovery_generated_at'] =
                        now()->toISOString();
                }

                $lockedMetadata['checkpoint']['status'] = 'retry_ready';
                $lockedMetadata['checkpoint']['retry_ready_at'] = now()->toISOString();
                unset($lockedMetadata['checkpoint']['answered_at']);
                unset($lockedMetadata['checkpoint']['student_answer']);
            } else {
                $lockedMetadata['checkpoint']['status'] = 'answered';
                $lockedMetadata['checkpoint']['answered_at'] = now()->toISOString();
                $lockedMetadata['checkpoint']['student_answer'] = $studentAnswer;
            }

            $lockedCheckpointMessage->update(['metadata' => $lockedMetadata]);

            $updates = ['last_activity_at' => now()];

            if (!empty($progress['session_completed'])) {
                $updates['status'] = 'completed';
                $updates['ended_at'] = now();
            }

            $session->update($updates);

            return [
                'checkpoint_message' => $lockedCheckpointMessage->fresh(),
                'student_message' => $studentMessage,
                'progress' => $progress,
                'checkpoint_attempt_number' => $checkpointAttemptNumber,
                'retry_allowed' => $retryAllowed,
                'max_checkpoint_attempts' => self::MAX_CHECKPOINT_ATTEMPTS,
            ];
        });

        /*
         * The final checkpoint can complete the TutorSession inside the
         * transaction above. Synchronize the learner's topic-learning state
         * immediately so student and teacher progress views agree without
         * waiting for another navigation or repair pass.
         */
        if (!empty($stored['progress']['session_completed'])) {
            $student = $session->student()->first();

            if ($student) {
                $this->sessions->synchronizeCompletedTopicState(
                    $student,
                    $session->fresh()
                );
            }
        }

        /*
         * Correct answers and the first lightweight correction can use local
         * feedback. Repeated difficulty invokes the teaching model so the
         * response changes strategy instead of merely restating the answer.
         * This AI call is intentionally outside the transaction.
         */
        $struggleLevel = (int) ($stored['progress']['struggle_level'] ?? 0);
        $classification = strtolower((string) ($evaluation['classification'] ?? 'unclear'));
        $retryAllowed = (bool) ($stored['retry_allowed'] ?? false);
        $needsAdaptiveTeaching = !($stored['progress']['objective_resolved'] ?? false)
            && in_array($classification, ['incorrect', 'partially_correct', 'unclear'], true)
            && ($retryAllowed || $struggleLevel >= 2);

        $feedback = $needsAdaptiveTeaching
            ? $this->ai->respondToEvaluation(
                $session->fresh(),
                $stored['student_message']->content,
                $evaluation,
                [
                    ...$stored['progress'],
                    'checkpoint_retry_allowed' => $retryAllowed,
                    'checkpoint_attempt_number' => $stored['checkpoint_attempt_number'] ?? null,
                    'max_checkpoint_attempts' => $stored['max_checkpoint_attempts'] ?? self::MAX_CHECKPOINT_ATTEMPTS,
                    'targeted_recovery' =>
                        is_array($recoveryCheckpoint),
                ]
            )
            : $this->buildFeedback($checkpoint, $evaluation, $stored['progress']);

        if ($retryAllowed) {
            $feedback = trim($feedback)
                . "\n\n"
                . (
                    is_array($recoveryCheckpoint)
                        ? 'You are close. I have narrowed the next check to just the one idea that still needs strengthening, so you do not need to repeat everything you already explained correctly.'
                        : 'Take a moment with the feedback above. You can ask for another explanation or example before trying again when you feel ready.'
                );
        }

        $messageType = !empty($stored['progress']['objective_resolved'])
            ? 'objective_transition'
            : ($needsAdaptiveTeaching ? 'adaptive_support' : 'feedback');

        $answeredObjective = $stored['progress']['objective'] ?? null;
        $nextObjective = $stored['progress']['next_objective'] ?? null;

        $feedbackMessage = $session->messages()->create([
            'role' => 'assistant',
            'message_type' => $messageType,
            'content' => $feedback,
            'metadata' => [
                'pedagogy' => [
                    'teaching_action' => $stored['progress']['teaching_action'] ?? null,
                    'struggle_level' => $struggleLevel,
                    'teacher_attention' => (bool) ($stored['progress']['teacher_attention'] ?? false),
                ],
                'objective_transition' => !empty($stored['progress']['objective_resolved'])
                    ? [
                        'completed_objective_id' => $answeredObjective?->id,
                        'completed_objective_order' => $answeredObjective?->objective_order,
                        'completed_objective_title' => $answeredObjective?->learningObjective?->objective,
                        'completed_status' => $stored['progress']['objective_status'] ?? null,
                        'next_objective_id' => $nextObjective?->id,
                        'next_objective_order' => $nextObjective?->objective_order,
                        'next_objective_title' => $nextObjective?->learningObjective?->objective,
                        'session_completed' => (bool) ($stored['progress']['session_completed'] ?? false),
                    ]
                    : null,
            ],
        ]);

        $session->update(['last_activity_at' => now()]);

        if ($retryAllowed) {
            $latestCheckpoint = $stored['checkpoint_message']->fresh();
            $metadata = $latestCheckpoint->metadata ?? [];
            if (is_array($metadata['checkpoint'] ?? null)) {
                $metadata['checkpoint']['retry_feedback'] = $feedback;
                $metadata['checkpoint']['retry_feedback_message_id'] = $feedbackMessage->id;
                $latestCheckpoint->update(['metadata' => $metadata]);
                $stored['checkpoint_message'] = $latestCheckpoint->fresh();
            }
        }

        return [
            ...$stored,
            'feedback_message' => $feedbackMessage,
            'evaluation' => $evaluation,
            'retry_allowed' => $retryAllowed,
        ];
    }

    /**
     * First demonstration: use varied recognition/application checks.
     * After one qualifying correct answer: use short answer for stronger
     * verification.
     *
     * Note: this chooses TYPE only. The decision about WHEN another
     * checkpoint should happen belongs to the continuation flow.
     */
    protected function evaluateCheckpoint(
        TutorSession $session,
        array $checkpoint,
        mixed $studentAnswer,
        array $attachments = []
    ): array {
        return match ($checkpoint['type'] ?? null) {
            'mcq' =>
                $this->evaluateMcq(
                    $checkpoint,
                    $studentAnswer
                ),

            'true_false' =>
                $this->evaluateTrueFalse(
                    $checkpoint,
                    $studentAnswer
                ),

            'fill_blank' =>
                $this->evaluateFillBlank(
                    $checkpoint,
                    $studentAnswer
                ),

            'matching' =>
                $this->evaluateMatching(
                    $checkpoint,
                    $studentAnswer
                ),

            'short_answer' =>
                $this->evaluateShortAnswer(
                    $session,
                    $checkpoint,
                    $studentAnswer,
                    $attachments
                ),

            default =>
                throw ValidationException::withMessages([
                    'checkpoint' => 'Unsupported checkpoint type.',
                ]),
        };
    }

    protected function evaluateMcq(
        array $checkpoint,
        mixed $answer
    ): array {
        $given = strtoupper(trim((string) $answer));

        $expected = strtoupper(
            trim((string) ($checkpoint['correct_answer'] ?? ''))
        );

        $correct =
            $given !== ''
            && $expected !== ''
            && hash_equals($expected, $given);

        return $this->binaryEvaluation(
            $correct,
            $correct
                ? 'Correct.'
                : 'That option is not correct.',
            $checkpoint
        );
    }

    protected function evaluateTrueFalse(
        array $checkpoint,
        mixed $answer
    ): array {
        $given = mb_strtolower(trim((string) $answer));

        $expected = mb_strtolower(
            trim((string) ($checkpoint['correct_answer'] ?? ''))
        );

        $correct =
            $given !== ''
            && $expected !== ''
            && hash_equals($expected, $given);

        return $this->binaryEvaluation(
            $correct,
            $correct
                ? 'Correct.'
                : 'That classification is not correct.',
            $checkpoint
        );
    }

    protected function evaluateFillBlank(
        array $checkpoint,
        mixed $answer
    ): array {
        $given = $this->normalizeTextAnswer((string) $answer);

        $accepted = collect(
            $checkpoint['accepted_answers'] ?? []
        )
            ->map(
                fn($value) =>
                    $this->normalizeTextAnswer((string) $value)
            )
            ->filter()
            ->values();

        $correct =
            $given !== ''
            && $accepted->contains($given);

        return $this->binaryEvaluation(
            $correct,
            $correct
                ? 'Correct.'
                : 'That answer does not match the expected concept.',
            $checkpoint
        );
    }

    protected function evaluateMatching(
        array $checkpoint,
        mixed $answer
    ): array {
        if (!is_array($answer)) {
            throw ValidationException::withMessages([
                'answer' =>
                    'Matching answers must be submitted as pairs.',
            ]);
        }

        $expected = $checkpoint['correct_answer'] ?? [];

        if (!is_array($expected) || empty($expected)) {
            throw ValidationException::withMessages([
                'checkpoint' =>
                    'The matching answer key is missing.',
            ]);
        }

        $total = count($expected);
        $correctCount = 0;

        foreach ($expected as $left => $right) {
            $given =
                isset($answer[$left])
                    ? (string) $answer[$left]
                    : null;

            if (
                $given !== null
                && hash_equals((string) $right, $given)
            ) {
                $correctCount++;
            }
        }

        $score =
            $total > 0
                ? (int) round(
                    ($correctCount / $total) * 100
                )
                : 0;

        if ($score >= 80) {
            $classification = 'correct';
        } elseif ($score >= 40) {
            $classification = 'partially_correct';
        } else {
            $classification = 'incorrect';
        }

        return [
            'classification' => $classification,
            'correct' => $classification === 'correct',
            'understanding_score' => $score,
            'feedback' =>
                "{$correctCount} of {$total} matches were correct.",
            'evidence' =>
                "Correct matches: {$correctCount}/{$total}.",
            'misconception' =>
                $classification === 'correct'
                    ? null
                    : 'One or more relationships were matched incorrectly.',
            'checkpoint_explanation' =>
                $checkpoint['explanation'] ?? null,
        ];
    }

    /**
     * Short answer is the only checkpoint type that needs semantic AI
     * evaluation. Retrieve context once and make one evaluator call.
     *
     * We intentionally do not call respondToEvaluation afterwards.
     */
    protected function evaluateShortAnswer(
        TutorSession $session,
        array $checkpoint,
        mixed $answer,
        array $attachments = []
    ): array {
        $answer = trim((string) $answer);

        if ($answer === '' && empty($attachments)) {
            throw ValidationException::withMessages([
                'answer' =>
                    'Write an answer or attach an image of your work.',
            ]);
        }

        /*
         * Do not let punctuation or conjunctions turn an otherwise identical
         * short answer into a wrong answer. This is especially important for
         * list/name questions such as:
         *
         *   expected: "set operations and operations developed for relational databases"
         *   learner:  "set operations, operations developed for relational databases"
         *
         * Both responses demonstrate exactly the same knowledge. When the
         * normalized learner response and expected response differ only by
         * separators/conjunctions, accept the answer deterministically before
         * invoking the semantic AI evaluator.
         */
        $expectedAnswer = trim((string) ($checkpoint['expected_answer'] ?? ''));

        if (
            $expectedAnswer !== ''
            && $this->equivalentShortAnswerFormatting($answer, $expectedAnswer)
        ) {
            return [
                'classification' => 'correct',
                'correct' => true,
                'understanding_score' => 100,
                'feedback' => 'Correct. Your answer identifies the required idea(s), even though you expressed them using different punctuation or linking words.',
                'evidence' => 'The learner response contains the same required content as the expected understanding.',
                'misconception' => null,
                'checkpoint_explanation' => $checkpoint['explanation'] ?? null,
            ];
        }

        $criteria = collect($checkpoint['evaluation_criteria'] ?? [])
            ->filter()
            ->map(fn($item, $index) => ($index + 1) . '. ' . $item)
            ->implode("\n");

        if ($criteria === '') {
            $criteria = 'Demonstrate the important understanding required by the question.';
        }

        $evaluationInput =
            "ASSESSMENT MODE:\n"
            . ($checkpoint['assessment_mode'] ?? 'conceptual')
            . "\n\n"
            . "EXPECTED RESPONSE DEPTH:\n"
            . ($checkpoint['response_depth'] ?? 'short')
            . "\n\n"
            . "QUESTION STRATEGY:\n"
            . ($checkpoint['strategy'] ?? 'explain_reasoning')
            . "\n\n"
            . "CHECKPOINT QUESTION:\n"
            . ($checkpoint['question'] ?? '')
            . "\n\n"
            . "EXPECTED UNDERSTANDING:\n"
            . ($checkpoint['expected_answer'] ?? '')
            . "\n\n"
            . "EVALUATION CRITERIA:\n"
            . $criteria
            . "\n\n"
            . "STUDENT ANSWER:\n"
            . $answer;

        $knowledge = $this->ai->teachingContext(
            $session,
            $evaluationInput,
            4
        );

        /*
         * Keep compatibility with the TutorAIService currently used by
         * the project. This is one AI evaluation call.
         */
        if (!empty($attachments)) {
            return $this->ai->evaluateCheckpointAnswerWithAttachments(
                $session,
                $checkpoint,
                $answer,
                $attachments,
                $knowledge
            );
        }

        return $this->ai->evaluateCheckpointAnswer(
            $session,
            $checkpoint,
            $answer,
            $knowledge
        );
    }

    /**
     * Compare short answers while ignoring formatting-only differences.
     *
     * We intentionally keep this conservative: it removes punctuation and
     * standalone joining words (and/&), but does not remove subject-specific
     * content words. The semantic AI evaluator still handles true paraphrases.
     */
    protected function equivalentShortAnswerFormatting(
        string $learnerAnswer,
        string $expectedAnswer
    ): bool {
        $normalize = static function (string $value): string {
            $value = mb_strtolower(trim($value));
            $value = str_replace('&', ' and ', $value);

            // Treat common list separators as whitespace.
            $value = preg_replace('/[\p{P}\p{S}]+/u', ' ', $value) ?? $value;

            // A conjunction must never be a correctness requirement for a
            // list of concepts. Remove only standalone joining words.
            $value = preg_replace('/\b(?:and)\b/u', ' ', $value) ?? $value;
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

            return trim($value);
        };

        $learner = $normalize($learnerAnswer);
        $expected = $normalize($expectedAnswer);

        return $learner !== '' && hash_equals($expected, $learner);
    }

    protected function binaryEvaluation(
        bool $correct,
        string $feedback,
        array $checkpoint
    ): array {
        return [
            'classification' =>
                $correct ? 'correct' : 'incorrect',
            'correct' => $correct,
            'understanding_score' =>
                $correct ? 100 : 0,
            'feedback' => $feedback,
            'evidence' =>
                $correct
                    ? 'The submitted answer matches the expected answer.'
                    : 'The submitted answer does not match the expected answer.',
            'misconception' =>
                $correct
                    ? null
                    : 'The checkpoint response does not yet demonstrate the expected understanding.',
            'checkpoint_explanation' =>
                $checkpoint['explanation'] ?? null,
        ];
    }

    /**
     * Build useful feedback without another model request.
     */
    protected function buildFeedback(
        array $checkpoint,
        array $evaluation,
        array $progress
    ): string {
        $parts = [];

        $classification =
            $evaluation['classification'] ?? 'unclear';

        $objectiveCompleted = !empty($progress['objective_completed']);
        $sessionCompleted = !empty($progress['session_completed']);
        $needsReview = ($progress['objective_status'] ?? null) === 'needs_review';

        // Once an objective is resolved, keep the learner-facing feedback concise.
        // The completion boundary in the conversation carries the transition; do not
        // expose evaluator-style commentary such as "the checkpoint asks...".
        if ($objectiveCompleted && !$needsReview) {
            $parts[] = $sessionCompleted
                ? 'Well done. You have completed this learning session.'
                : 'Well done. You have completed this objective.';
        } else {
            if ($classification === 'correct') {
                $parts[] = 'Correct.';
            } elseif ($classification === 'partially_correct') {
                $parts[] = 'You have part of the idea, but it is not complete yet.';
            } elseif ($classification === 'incorrect') {
                $parts[] = 'Not quite yet.';
            } else {
                $parts[] = 'Your answer needs a little more clarification.';
            }

            $evaluationFeedback = trim(
                (string) ($evaluation['feedback'] ?? '')
            );

            if (
                $evaluationFeedback !== ''
                && !in_array(
                    mb_strtolower(rtrim($evaluationFeedback, '.')),
                    [
                        'correct',
                        'that option is not correct',
                        'that classification is not correct',
                    ],
                    true
                )
            ) {
                $parts[] = $evaluationFeedback;
            }

            $explanation = trim(
                (string) (
                    $evaluation['checkpoint_explanation']
                    ?? $checkpoint['explanation']
                    ?? ''
                )
            );

            if ($explanation !== '') {
                $parts[] = $explanation;
            }
        }

        if ($needsReview) {
            $parts[] = $sessionCompleted
                ? 'We will mark this objective for review with your teacher instead of keeping you on it. You have reached the end of this learning session.'
                : 'We will mark this objective for review with your teacher and move on, rather than keeping you on the same concept. You can return to it later with extra support.';
        } elseif ($sessionCompleted || $objectiveCompleted) {
            // Completion wording has already been added above.
        } elseif ($classification === 'correct') {
            $correctAttempts = (int) ($progress['correct_attempts'] ?? 0);

            $parts[] = $correctAttempts === 1
                ? 'Good progress. One more successful learning check is needed to complete this objective.'
                : 'Good progress. Keep working with the Tutor, then check your understanding again when you are ready.';
        } else {
            $parts[] =
                'Review the explanation and continue learning when you are ready.';
        }

        return collect($parts)
            ->map(fn($part) => trim((string) $part))
            ->filter()
            ->unique()
            ->implode("\n\n");
    }

    protected function questionBankCheckpoint(
        TutorSession $session,
        TutorSessionObjective $objective,
        array $plan
    ): ?array {
        $previous = $session
            ->messages()
            ->where('message_type', 'checkpoint')
            ->get()
            ->map(
                fn ($message) =>
                    $message->metadata['checkpoint']
                    ?? null
            )
            ->filter(
                fn ($checkpoint) =>
                    is_array($checkpoint)
                    && (int) (
                        $checkpoint['objective_id']
                        ?? 0
                    )
                    === (int) $objective->learning_objective_id
            )
            ->values();

        /*
         * Alternate sources where possible. First check may come from the
         * teacher Question Bank; the next one is generated; then bank again.
         */
        if ($previous->count() % 2 === 1) {
            return null;
        }

        $usedBankIds = $previous
            ->pluck('bank_question_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $supportedTypes = [
            'mcq',
            'true_false',
            'fill_blank',
            'short_answer',
            'open_ended',
        ];

        $questions = Question::query()
            ->where('topic_id', $session->topic_id)
            ->where(
                'learning_objective_id',
                $objective->learning_objective_id
            )
            ->whereNull('parent_question_id')
            ->where('status', 'approved')
            ->where('is_assessment_eligible', true)
            ->whereIn('question_type', $supportedTypes)
            ->whereNull('question_image')
            ->whereNull('correct_answer_image')
            ->where(function ($query) {
                $query
                    ->whereNull('stimulus')
                    ->orWhereJsonLength('stimulus', 0);
            })
            ->when(
                !empty($usedBankIds),
                fn ($query) =>
                    $query->whereNotIn('id', $usedBankIds)
            )
            ->latest('updated_at')
            ->limit(12)
            ->get();

        if ($questions->isEmpty()) {
            return null;
        }

        $candidates = $questions
            ->map(fn (Question $question) => [
                'id' => $question->id,
                'type' => $question->question_type,
                'difficulty' => $question->difficulty_level,
                'question' => $question->question,
            ])
            ->all();

        $selectedId = $this->ai->selectQuestionBankQuestion(
            $session,
            $plan,
            $candidates
        );

        if (!$selectedId) {
            return null;
        }

        $selected = $questions->first(
            fn (Question $question) =>
                (int) $question->id
                === (int) $selectedId
        );

        return $selected
            ? $this->checkpointFromQuestion(
                $selected,
                $objective,
                $plan
            )
            : null;
    }

    protected function checkpointFromQuestion(
        Question $question,
        TutorSessionObjective $objective,
        array $plan
    ): ?array {
        $type = match (
            strtolower((string) $question->question_type)
        ) {
            'open_ended' => 'short_answer',
            default =>
                strtolower((string) $question->question_type),
        };

        $checkpoint = [
            'type' => $type,
            'objective_id' =>
                $objective->learning_objective_id,
            'question' =>
                trim((string) $question->question),
            'difficulty' =>
                $question->difficulty_level
                ?: ($plan['difficulty'] ?? 'understanding'),
            'assessment_mode' =>
                $plan['assessment_mode']
                ?? 'conceptual',
            'response_depth' =>
                $plan['response_depth']
                ?? 'short',
            'strategy' =>
                $plan['strategy']
                ?? 'question_bank',
            'source' => 'question_bank',
            'bank_question_id' => $question->id,
            'explanation' => $question->explanation,
        ];

        if ($checkpoint['question'] === '') {
            return null;
        }

        $answer = $question->correct_answer;

        switch ($type) {
            case 'mcq':
                $options = collect(
                    is_array($question->options)
                        ? $question->options
                        : []
                )
                    ->values()
                    ->map(function ($option, $index) {
                        if (is_array($option)) {
                            return [
                                'id' =>
                                    strtoupper(
                                        (string) (
                                            $option['id']
                                            ?? chr(65 + $index)
                                        )
                                    ),
                                'text' =>
                                    trim(
                                        (string) (
                                            $option['text']
                                            ?? $option['value']
                                            ?? ''
                                        )
                                    ),
                            ];
                        }

                        return [
                            'id' => chr(65 + $index),
                            'text' => trim((string) $option),
                        ];
                    })
                    ->filter(
                        fn ($option) =>
                            $option['text'] !== ''
                    )
                    ->values();

                if ($options->count() < 2) {
                    return null;
                }

                $rawCorrect = is_array($answer)
                    ? collect($answer)->flatten()->first()
                    : $answer;

                $rawCorrect = trim((string) $rawCorrect);

                $correctOption = $options->first(
                    function ($option) use ($rawCorrect) {
                        return
                            strcasecmp(
                                (string) $option['id'],
                                $rawCorrect
                            ) === 0
                            || strcasecmp(
                                (string) $option['text'],
                                $rawCorrect
                            ) === 0;
                    }
                );

                if (!$correctOption) {
                    return null;
                }

                $checkpoint['options'] =
                    $options->all();
                $checkpoint['correct_answer'] =
                    $correctOption['id'];
                break;

            case 'true_false':
                $rawCorrect = is_array($answer)
                    ? collect($answer)->flatten()->first()
                    : $answer;

                $rawCorrect = strtolower(
                    trim((string) $rawCorrect)
                );

                if (!in_array(
                    $rawCorrect,
                    ['true', 'false'],
                    true
                )) {
                    return null;
                }

                $checkpoint['options'] = [
                    ['id' => 'true', 'text' => 'True'],
                    ['id' => 'false', 'text' => 'False'],
                ];
                $checkpoint['correct_answer'] =
                    $rawCorrect;
                break;

            case 'fill_blank':
                $accepted = collect(
                    is_array($answer) ? $answer : [$answer]
                )
                    ->flatten()
                    ->map(
                        fn ($value) =>
                            trim((string) $value)
                    )
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if (empty($accepted)) {
                    return null;
                }

                $checkpoint['accepted_answers'] =
                    $accepted;
                $checkpoint['expected_answer'] =
                    implode(' / ', $accepted);
                break;

            case 'short_answer':
                $expected = collect(
                    is_array($answer) ? $answer : [$answer]
                )
                    ->flatten()
                    ->map(
                        fn ($value) =>
                            trim((string) $value)
                    )
                    ->filter()
                    ->implode('; ');

                if ($expected === '') {
                    $expected = trim(
                        (string) $question->explanation
                    );
                }

                if ($expected === '') {
                    return null;
                }

                $checkpoint['expected_answer'] =
                    $expected;
                $checkpoint['evaluation_criteria'] = [
                    'Demonstrate the understanding required by the approved Question Bank item.',
                ];
                break;

            default:
                return null;
        }

        return $checkpoint;
    }

    public function publicCheckpoint(
        TutorMessage $message
    ): array {
        $checkpoint =
            $message->metadata['checkpoint'] ?? [];

        $public = [
            'id' => $message->id,
            'type' => $checkpoint['type'] ?? null,
            'objective_id' =>
                $checkpoint['objective_id'] ?? null,
            'question' =>
                $checkpoint['question']
                ?? $message->content,
            'difficulty' =>
                $checkpoint['difficulty'] ?? null,
            'assessment_mode' =>
                $checkpoint['assessment_mode'] ?? null,
            'response_depth' =>
                $checkpoint['response_depth'] ?? null,
            'strategy' =>
                $checkpoint['strategy'] ?? null,
            'source' =>
                $checkpoint['source'] ?? 'ai_generated',
            'bank_question_id' =>
                $checkpoint['bank_question_id'] ?? null,
            'status' =>
                $checkpoint['status'] ?? 'pending',
            'created_at' => $message->created_at,
            'skipped_at' => $checkpoint['skipped_at'] ?? null,
            'attempt_count' => (int) ($checkpoint['attempt_count'] ?? 0),
            'max_attempts' => (int) ($checkpoint['max_attempts'] ?? self::MAX_CHECKPOINT_ATTEMPTS),
            'retry_allowed' => (bool) ($checkpoint['retry_allowed'] ?? false),
            'retry_feedback' => $checkpoint['retry_feedback'] ?? null,
            'previous_answer' =>
                $checkpoint['last_student_answer'] ?? null,
            'answer_attachments' =>
                $checkpoint['last_answer_attachments'] ?? [],
        ];

        switch ($checkpoint['type'] ?? null) {
            case 'mcq':
            case 'true_false':
                $public['options'] =
                    $checkpoint['options'] ?? [];
                break;

            case 'matching':
                $public['left_items'] =
                    $checkpoint['left_items'] ?? [];
                $public['right_items'] =
                    $checkpoint['right_items'] ?? [];
                break;

            case 'fill_blank':
            case 'short_answer':
                break;
        }

        if (($checkpoint['status'] ?? null) === 'answered') {
            $public['student_answer'] =
                $checkpoint['student_answer'] ?? null;

            $result = $checkpoint['result'] ?? [];

            $public['result'] = [
                'correct' =>
                    (bool) ($result['correct'] ?? false),
            ];
        }

        return $public;
    }

    protected function answerAsText(
        array $checkpoint,
        mixed $answer
    ): string {
        $type = $checkpoint['type'] ?? null;

        if ($type === 'matching') {
            if (!is_array($answer)) {
                return '';
            }

            return collect($answer)
                ->map(
                    fn($right, $left) =>
                        "{$left} → {$right}"
                )
                ->implode(', ');
        }

        if (is_scalar($answer)) {
            return trim((string) $answer);
        }

        return json_encode(
            $answer,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
        );
    }

    protected function normalizeTextAnswer(
        string $value
    ): string {
        $value = Str::lower(trim($value));
        $value = $this->stripOuterMathDelimiters($value);

        // MathLive returns clean LaTeX without $...$ wrappers, while AI-
        // generated accepted answers may include those wrappers. Normalize
        // harmless LaTeX presentation differences before exact comparison.
        $looksMathematical = preg_match(
            '/[\\^_=+\-*\/<>]|\d/u',
            $value
        ) === 1;

        if ($looksMathematical) {
            $value = str_replace(
                ['\\left', '\\right', '\\,', '\\;', '\\!', '\\ '],
                '',
                $value
            );

            $value = preg_replace('/\s+/u', '', $value);
        } else {
            $value = preg_replace('/\s+/u', ' ', $value);
        }

        $value = preg_replace(
            '/[.!?]+$/u',
            '',
            $value
        );

        return trim($value);
    }

    protected function stripOuterMathDelimiters(
        string $value
    ): string {
        $value = trim($value);

        if (
            str_starts_with($value, '$$')
            && str_ends_with($value, '$$')
            && mb_strlen($value) >= 4
        ) {
            return trim(mb_substr($value, 2, mb_strlen($value) - 4));
        }

        if (
            str_starts_with($value, '\\[')
            && str_ends_with($value, '\\]')
            && mb_strlen($value) >= 4
        ) {
            return trim(mb_substr($value, 2, mb_strlen($value) - 4));
        }

        if (
            str_starts_with($value, '\\(')
            && str_ends_with($value, '\\)')
            && mb_strlen($value) >= 4
        ) {
            return trim(mb_substr($value, 2, mb_strlen($value) - 4));
        }

        if (
            str_starts_with($value, '$')
            && str_ends_with($value, '$')
            && mb_strlen($value) >= 2
        ) {
            return trim(mb_substr($value, 1, mb_strlen($value) - 2));
        }

        return $value;
    }

    protected function validateCheckpointOwnership(
        TutorSession $session,
        TutorMessage $checkpointMessage
    ): void {
        if (
            (int) $checkpointMessage->tutor_session_id
            !== (int) $session->id
        ) {
            throw ValidationException::withMessages([
                'checkpoint' =>
                    'This checkpoint does not belong to this session.',
            ]);
        }

        if (
            $checkpointMessage->role !== 'assistant'
            || $checkpointMessage->message_type !== 'checkpoint'
        ) {
            throw ValidationException::withMessages([
                'checkpoint' => 'The selected message is not a checkpoint.',
            ]);
        }
    }

    protected function ensureActive(
        TutorSession $session
    ): void {
        if ($session->status !== 'active') {
            throw ValidationException::withMessages([
                'session' =>
                    'This Tutor session is no longer active.',
            ]);
        }
    }
}

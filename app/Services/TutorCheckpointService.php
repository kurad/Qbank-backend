<?php

namespace App\Services;

use App\Models\TutorMessage;
use App\Models\TutorResponseEvaluation;
use App\Models\TutorSession;
use App\Models\TutorSessionObjective;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TutorCheckpointService
{
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

    public function generate(TutorSession $session): TutorMessage
    {
        $this->ensureActive($session);

        $objective = $this->sessions->currentObjective($session);

        if (!$objective) {
            throw ValidationException::withMessages([
                'session' => 'There is no active learning objective.',
            ]);
        }

        $difficulty = $this->difficulty->profile($session, $objective);

        if (!($difficulty['checkpoint_ready'] ?? true)) {
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

        // Four focused chunks are normally enough for one checkpoint.
        // This keeps the generation grounded without overloading the prompt.
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
                    && ($checkpoint['status'] ?? 'pending') === 'pending';
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
        int $studentId
    ): array {
        $this->ensureActive($session);
        $this->validateCheckpointOwnership($session, $checkpointMessage);

        $checkpoint = $checkpointMessage->metadata['checkpoint'] ?? null;

        if (!is_array($checkpoint)) {
            throw ValidationException::withMessages([
                'checkpoint' => 'Checkpoint information is missing.',
            ]);
        }

        if (($checkpoint['status'] ?? 'pending') !== 'pending') {
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
            $studentAnswer
        );

        $stored = DB::transaction(function () use (
            $session,
            $checkpointMessage,
            $checkpoint,
            $objective,
            $studentAnswer,
            $studentId,
            $evaluation
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

            if (($lockedCheckpoint['status'] ?? 'pending') !== 'pending') {
                throw ValidationException::withMessages([
                    'checkpoint' => 'This checkpoint has already been answered.',
                ]);
            }

            $studentMessage = $session->messages()->create([
                'role' => 'user',
                'message_type' => 'checkpoint_answer',
                'content' => $this->answerAsText($checkpoint, $studentAnswer),
                'metadata' => [
                    'checkpoint_answer' => [
                        'checkpoint_message_id' => $lockedCheckpointMessage->id,
                        'type' => $checkpoint['type'],
                        'answer' => $studentAnswer,
                    ],
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

            $lockedMetadata['checkpoint']['status'] = 'answered';
            $lockedMetadata['checkpoint']['answered_at'] = now()->toISOString();
            $lockedMetadata['checkpoint']['student_answer'] = $studentAnswer;
            $lockedMetadata['checkpoint']['result'] = [
                'classification' => $evaluation['classification'],
                'correct' => $evaluation['correct'],
                'understanding_score' => $evaluation['understanding_score'],
            ];

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
            ];
        });

        /*
         * Correct answers and the first lightweight correction can use local
         * feedback. Repeated difficulty invokes the teaching model so the
         * response changes strategy instead of merely restating the answer.
         * This AI call is intentionally outside the transaction.
         */
        $struggleLevel = (int) ($stored['progress']['struggle_level'] ?? 0);
        $classification = strtolower((string) ($evaluation['classification'] ?? 'unclear'));
        $needsAdaptiveTeaching = !($stored['progress']['objective_resolved'] ?? false)
            && in_array($classification, ['incorrect', 'partially_correct', 'unclear'], true)
            && $struggleLevel >= 2;

        $feedback = $needsAdaptiveTeaching
            ? $this->ai->respondToEvaluation(
                $session->fresh(),
                $stored['student_message']->content,
                $evaluation,
                $stored['progress']
            )
            : $this->buildFeedback($checkpoint, $evaluation, $stored['progress']);

        $messageType = !empty($stored['progress']['objective_resolved'])
            ? 'objective_transition'
            : ($needsAdaptiveTeaching ? 'adaptive_support' : 'feedback');

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
            ],
        ]);

        $session->update(['last_activity_at' => now()]);

        return [
            ...$stored,
            'feedback_message' => $feedbackMessage,
            'evaluation' => $evaluation,
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
        mixed $studentAnswer
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
                    $studentAnswer
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
        mixed $answer
    ): array {
        $answer = trim((string) $answer);

        if ($answer === '') {
            throw ValidationException::withMessages([
                'answer' => 'Please enter an answer.',
            ]);
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
        return $this->ai->evaluateResponse(
            $session,
            $evaluationInput,
            $knowledge
        );
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

        if (($progress['objective_status'] ?? null) === 'needs_review') {
            $parts[] = !empty($progress['session_completed'])
                ? 'We will mark this objective for review with your teacher instead of keeping you on it. You have reached the end of this learning session.'
                : 'We will mark this objective for review with your teacher and move on, rather than keeping you on the same concept. You can return to it later with extra support.';
        } elseif (!empty($progress['session_completed'])) {
            $parts[] =
                'You have completed this learning session.';
        } elseif (!empty($progress['objective_completed'])) {
            $parts[] =
                'You have demonstrated the required understanding for this objective. Continue when you are ready for the next objective.';
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
            'status' =>
                $checkpoint['status'] ?? 'pending',
            'created_at' => $message->created_at,
            'skipped_at' => $checkpoint['skipped_at'] ?? null,
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

        $value = preg_replace(
            '/\s+/u',
            ' ',
            $value
        );

        $value = preg_replace(
            '/[.!?]+$/u',
            '',
            $value
        );

        return trim($value);
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

<?php

namespace App\Services;

use App\Models\TutorSession;
use App\Models\TutorSessionObjective;
use Illuminate\Support\Collection;

class TutorDifficultyService
{
    /**
     * Build a lightweight pedagogical profile for the current objective.
     *
     * This is deliberately derived from existing evidence instead of being a
     * second mastery system. Formal checkpoint evaluations remain the source
     * of truth; this profile only decides how much support to provide and
     * whether the teacher should be made aware of persistent difficulty.
     */
    public function profile(
        TutorSession $session,
        TutorSessionObjective $objective
    ): array {
        $evaluations = $objective->responseEvaluations()
            ->orderBy('id')
            ->get([
                'id',
                'classification',
                'understanding_score',
                'misconception',
                'created_at',
            ]);

        if ($evaluations->isEmpty()) {
            return $this->firstCheckProfile($session, $objective);
        }

        $latest = $evaluations->last();
        $consecutiveUnsuccessful = $this->consecutiveUnsuccessful($evaluations);
        $recent = $evaluations->take(-5);
        $averageScore = (int) round($recent->avg('understanding_score') ?? 0);
        $repeatedMisconception = $this->repeatedMisconception($recent);

        $latestClassification = strtolower((string) $latest->classification);
        $latestQualifyingCorrect = $latestClassification === 'correct'
            && (int) $latest->understanding_score >= 80;

        $struggleLevel = $this->struggleLevel(
            $consecutiveUnsuccessful,
            $averageScore,
            $repeatedMisconception,
            $latestQualifyingCorrect
        );

        $teacherAttention = $struggleLevel >= 3;
        // After three unsuccessful formal checks, the learner may choose to
        // continue practising or move to the next topic. This is deliberately
        // not an automatic mastery/completion decision.
        $moveOnAvailable = $consecutiveUnsuccessful >= 3;
        $maxPersistenceReached = false; // retained for backward-compatible API shape
        $requiredSupportTurns = match (true) {
            $latestQualifyingCorrect => 0,
            $struggleLevel >= 3 => 2,
            $struggleLevel >= 1 => 1,
            default => 0,
        };

        $supportTurns = $this->supportTurnsSince(
            $session,
            $latest->created_at
        );

        return [
            'struggle_level' => $struggleLevel,
            'consecutive_unsuccessful' => $consecutiveUnsuccessful,
            'recent_average_score' => $averageScore,
            'repeated_misconception' => $repeatedMisconception,
            'teacher_attention' => $teacherAttention,
            'max_persistence_reached' => $maxPersistenceReached,
            'move_on_available' => $moveOnAvailable,
            'teacher_attention_reason' => $teacherAttention
                ? $this->teacherAttentionReason(
                    $consecutiveUnsuccessful,
                    $averageScore,
                    $repeatedMisconception
                )
                : null,
            'support_turns_since_check' => $supportTurns,
            'required_support_turns' => $requiredSupportTurns,
            'checkpoint_ready' => $supportTurns >= $requiredSupportTurns,
            'remaining_learning_turns' => max($requiredSupportTurns - $supportTurns, 0),
            'readiness_percent' => $requiredSupportTurns > 0
                ? min(100, (int) round(($supportTurns / $requiredSupportTurns) * 100))
                : 100,
            'readiness_message' => $supportTurns >= $requiredSupportTurns
                ? 'You are ready to check your understanding.'
                : 'Work through the Tutor feedback and guided support before trying another understanding check.',
            'learning_state' => $moveOnAvailable && !$latestQualifyingCorrect
                ? 'choice_available'
                : ($latestQualifyingCorrect
                ? 'ready_for_checkpoint'
                : ($supportTurns >= $requiredSupportTurns
                    ? 'ready_for_checkpoint'
                    : 'support_learning')),
        ];
    }

    /**
     * Readiness for the FIRST formal understanding check.
     *
     * A new objective is deliberately not checkpoint-ready immediately. The
     * learner must first take part in the live teaching conversation. We use
     * existing Tutor messages as durable evidence, so readiness survives a
     * page refresh and updates immediately after every learner turn.
     */
    protected function firstCheckProfile(
        TutorSession $session,
        TutorSessionObjective $objective
    ): array {
        $evidence = $this->learningEvidence($session, $objective);

        // A first formal check should come only after a real instructional
        // sequence: opening/diagnostic, substantive teaching, and guided
        // practice. Three learner/tutor exchanges is the minimum evidence that
        // those phases had room to occur. This is still evidence-based rather
        // than time-gated and remains adaptive to the learner's responses.
        $requiredLearningTurns = 3;
        $learningTurns = (int) ($evidence['learner_turns'] ?? 0);
        $teachingTurns = (int) ($evidence['tutor_turns'] ?? 0);

        $checkpointReady =
            $learningTurns >= $requiredLearningTurns
            && $teachingTurns >= $requiredLearningTurns;

        $remaining = max($requiredLearningTurns - min($learningTurns, $teachingTurns), 0);
        $progress = min(100, (int) round(
            (min($learningTurns, $teachingTurns) / $requiredLearningTurns) * 100
        ));

        return [
            'struggle_level' => 0,
            'consecutive_unsuccessful' => 0,
            'recent_average_score' => null,
            'repeated_misconception' => null,
            'teacher_attention' => false,
            'teacher_attention_reason' => null,
            'max_persistence_reached' => false,
            'move_on_available' => false,
            'support_turns_since_check' => $learningTurns,
            'required_support_turns' => $requiredLearningTurns,
            'learning_turns' => $learningTurns,
            'tutor_teaching_turns' => $teachingTurns,
            'required_learning_turns' => $requiredLearningTurns,
            'remaining_learning_turns' => $remaining,
            'readiness_percent' => $progress,
            'checkpoint_ready' => $checkpointReady,
            'readiness_message' => $checkpointReady
                ? 'You are ready to check your understanding.'
                : $this->firstCheckReadinessMessage($remaining),
            'learning_state' => $checkpointReady
                ? 'ready_for_checkpoint'
                : 'support_learning',
        ];
    }

    /**
     * Count learner/tutor teaching turns for the CURRENT objective only.
     * Checkpoints and their answers are intentionally excluded.
     */
    protected function learningEvidence(
        TutorSession $session,
        TutorSessionObjective $objective
    ): array {
        $startedAt = $objective->started_at;

        // Prefer the persisted introduction for this specific session
        // objective. It is the clearest boundary between objectives.
        $intro = $session->messages()
            ->where('role', 'assistant')
            ->where('message_type', 'objective_intro')
            ->where('created_at', '>=', $startedAt ?: $session->created_at)
            ->latest('id')
            ->get()
            ->first(function ($message) use ($objective) {
                return (int) ($message->metadata['objective_id'] ?? 0)
                    === (int) $objective->id;
            });

        $boundary = $intro?->created_at ?: $startedAt ?: $session->created_at;

        $learnerTurns = $session->messages()
            ->where('role', 'user')
            ->where('message_type', 'text')
            ->where('created_at', '>', $boundary)
            ->get()
            ->filter(function ($message) {
                $text = trim((string) $message->content);

                if ($text === '') {
                    return false;
                }

                // Asking to start the assessment is not itself learning
                // evidence. Ordinary questions, explanations and attempts are.
                return !preg_match(
                    '/^(check( my)? understanding|test me|give me (a )?(test|quiz)|start (the )?(check|quiz|test))\b/i',
                    $text
                );
            })
            ->count();

        $tutorTurns = $session->messages()
            ->where('role', 'assistant')
            ->where('message_type', 'text')
            ->where('created_at', '>', $boundary)
            ->count();

        return [
            'learner_turns' => $learnerTurns,
            'tutor_turns' => $tutorTurns,
        ];
    }

    protected function firstCheckReadinessMessage(int $remaining): string
    {
        if ($remaining <= 1) {
            return 'Complete one more guided practice interaction with your Tutor before checking your understanding.';
        }

        if ($remaining === 2) {
            return 'Your Tutor is teaching the core ideas now. Work through the explanation and guided practice before the formal understanding check.';
        }

        return 'Begin with the Tutor introduction and diagnostic, then work through the detailed teaching and guided practice before checking your understanding.';
    }

    protected function consecutiveUnsuccessful(Collection $evaluations): int
    {
        $count = 0;

        foreach ($evaluations->reverse() as $evaluation) {
            $qualifyingCorrect = strtolower((string) $evaluation->classification) === 'correct'
                && (int) $evaluation->understanding_score >= 80;

            if ($qualifyingCorrect) {
                break;
            }

            $count++;
        }

        return $count;
    }

    protected function repeatedMisconception(Collection $evaluations): ?string
    {
        $items = $evaluations
            ->pluck('misconception')
            ->filter(fn ($value) => trim((string) $value) !== '')
            ->map(fn ($value) => trim((string) $value));

        if ($items->count() < 2) {
            return null;
        }

        // Evaluator wording may vary, so exact repetition is a strong signal,
        // while a recent non-empty misconception remains available elsewhere.
        $grouped = $items->groupBy(fn ($value) => mb_strtolower($value));
        $repeated = $grouped->first(fn ($group) => $group->count() >= 2);

        return $repeated?->last();
    }

    protected function struggleLevel(
        int $consecutiveUnsuccessful,
        int $averageScore,
        ?string $repeatedMisconception,
        bool $latestQualifyingCorrect
    ): int {
        if ($latestQualifyingCorrect || $consecutiveUnsuccessful === 0) {
            return 0;
        }

        if ($consecutiveUnsuccessful >= 4) {
            return 4;
        }

        if (
            $consecutiveUnsuccessful >= 3
            || ($consecutiveUnsuccessful >= 2 && $averageScore <= 35)
            || $repeatedMisconception
        ) {
            return 3;
        }

        if ($consecutiveUnsuccessful >= 2) {
            return 2;
        }

        return 1;
    }

    protected function supportTurnsSince(
        TutorSession $session,
        $evaluationCreatedAt
    ): int {
        if (!$evaluationCreatedAt) {
            return 0;
        }

        return $session->messages()
            ->where('role', 'user')
            ->where('message_type', 'text')
            ->where('created_at', '>', $evaluationCreatedAt)
            ->count();
    }

    protected function teacherAttentionReason(
        int $consecutiveUnsuccessful,
        int $averageScore,
        ?string $repeatedMisconception
    ): string {
        if ($repeatedMisconception) {
            return 'A misconception has persisted across formal learning checks.';
        }

        if ($consecutiveUnsuccessful >= 5) {
            return 'The student has made several unsuccessful attempts on the current objective.';
        }

        if ($averageScore <= 35) {
            return 'Recent formal checks show persistently low demonstrated understanding.';
        }

        return 'The student has struggled repeatedly with the current learning objective.';
    }
}

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
            return $this->emptyProfile();
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
        $maxPersistenceReached = $consecutiveUnsuccessful >= 5;
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
            'learning_state' => $maxPersistenceReached
                ? 'needs_review'
                : ($latestQualifyingCorrect
                ? 'ready_for_checkpoint'
                : ($supportTurns >= $requiredSupportTurns
                    ? 'ready_for_checkpoint'
                    : 'support_learning')),
        ];
    }

    public function emptyProfile(): array
    {
        return [
            'struggle_level' => 0,
            'consecutive_unsuccessful' => 0,
            'recent_average_score' => null,
            'repeated_misconception' => null,
            'teacher_attention' => false,
            'teacher_attention_reason' => null,
            'max_persistence_reached' => false,
            'support_turns_since_check' => 0,
            'required_support_turns' => 0,
            'checkpoint_ready' => true,
            'learning_state' => 'ready_for_checkpoint',
        ];
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

        if ($consecutiveUnsuccessful >= 5) {
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

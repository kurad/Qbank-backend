<?php

namespace App\Services;

use App\Models\TutorSession;
use App\Models\TutorSessionObjective;

/**
 * Selects the NEXT teaching move, not the student's mastery status.
 *
 * Decisions use explicit learner requests and already-recorded formal
 * assessment evidence. Chat replies are not graded or promoted to mastery.
 */
class TutorTeachingDecisionService
{
    public function plan(
        TutorSession $session,
        TutorSessionObjective $objective,
        string $learnerMessage,
        array $turnFocus = []
    ): array {
        $boundary = $objective->started_at ?: $session->created_at;
        $teachingTurns = $session->messages()
            ->where('role', 'user')
            ->where('message_type', 'text')
            ->where('created_at', '>=', $boundary)
            ->count();

        $evidence = $objective->responseEvaluations()
            ->latest('id')
            ->limit(4)
            ->get(['classification', 'understanding_score', 'misconception']);

        $latest = $evidence->first();
        $recentStruggle = $evidence->take(2)->filter(function ($evaluation) {
            return in_array(
                strtolower((string) $evaluation->classification),
                ['incorrect', 'partially_correct', 'unclear'],
                true
            );
        })->count();
        $repeatedStruggle = $recentStruggle >= 2;
        $recentCorrect = $latest
            && strtolower((string) $latest->classification) === 'correct'
            && (int) $latest->understanding_score >= 80;
        $misconception = trim((string) ($latest?->misconception ?? ''));

        // Explicit learner requests are used as teaching preferences, not as
        // evidence of correctness. Do not infer mastery from "I understand".
        $message = mb_strtolower(trim($learnerMessage));
        $wantsSimpler = (bool) preg_match(
            "/\\b(?:don't understand|do not understand|don't get|do not get|confused|lost|stuck|too hard|i don't know|i do not know|not sure|help me|explain again|repeat that)\\b/u",
            $message
        );
        $wantsExample = (bool) preg_match(
            '/\b(?:example|show me|demonstrate|walk me through|worked solution)\b/u',
            $message
        );
        $wantsContinue = (bool) preg_match(
            '/\b(?:move on|next step|continue|go ahead|already know)\b/u',
            $message
        );
        $needsRedirect = !empty($turnFocus['redirect_needed']);
        $focus = (string) ($turnFocus['focus'] ?? 'on_track');

        if ($needsRedirect && in_array($focus, ['off_topic', 'social'], true)) {
            return $this->decision('REFOCUS', 'Return gently to the current objective.', $teachingTurns, $misconception);
        }

        if ($wantsSimpler || $repeatedStruggle) {
            $reason = $wantsSimpler
                ? 'The learner explicitly requested clarification or showed uncertainty.'
                : 'Two recent formal checks showed unresolved difficulty.';
            return $this->decision('SCAFFOLD_AND_RETEACH', $reason, $teachingTurns, $misconception);
        }

        if ($wantsExample) {
            return $this->decision('WORKED_EXAMPLE', 'The learner requested a concrete demonstration.', $teachingTurns, $misconception);
        }

        if ($latest && !$recentCorrect && in_array(
            strtolower((string) $latest->classification),
            ['incorrect', 'partially_correct', 'unclear'],
            true
        )) {
            return $this->decision('TARGETED_RETEACH', 'The latest formal check identified an unresolved gap.', $teachingTurns, $misconception);
        }

        // A quick "move on" request can advance within this objective only;
        // it cannot complete the objective or bypass a formal check.
        if ($wantsContinue && $teachingTurns >= 1) {
            return $this->decision('NEXT_SUBSKILL', 'The learner requested forward movement within the objective.', $teachingTurns, $misconception);
        }

        if ($teachingTurns <= 1) {
            return $this->decision('CORE_TEACHING', 'Introduce and model the required learning.', $teachingTurns, $misconception);
        }

        if (!$recentCorrect && $teachingTurns <= 3) {
            return $this->decision('GUIDED_PRACTICE', 'Practise a supported subskill with feedback.', $teachingTurns, $misconception);
        }

        // Mere number of chat turns is NOT evidence that a learner is ready.
        // Consolidation must not claim mastery or unlock formal assessments.
        return $this->decision(
            $recentCorrect ? 'CONSOLIDATE' : 'GUIDED_PRACTICE',
            $recentCorrect
                ? 'Recent formal evidence is strong; consolidate remaining supported skills.'
                : 'No reliable evidence of readiness; continue useful guided practice.',
            $teachingTurns,
            $misconception
        );
    }

    private function decision(string $phase, string $reason, int $turns, string $misconception): array
    {
        return [
            'phase' => $phase,
            'reason' => $reason,
            'teaching_turns' => $turns,
            'misconception' => $misconception,
        ];
    }
}

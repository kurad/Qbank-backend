<?php

namespace App\Services;

use App\Models\LearningPeriod;
use App\Models\StudentTopicLearningState;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudentTopicLearningService
{
    public function stateFor(User $student, int $learningPeriodId, int $topicId): StudentTopicLearningState
    {
        return StudentTopicLearningState::firstOrCreate(
            [
                'student_id' => $student->id,
                'learning_period_id' => $learningPeriodId,
                'topic_id' => $topicId,
            ],
            [
                'progression_status' => 'not_started',
                'mastery_status' => 'unknown',
            ]
        );
    }

    public function markStarted(User $student, TutorSession $session): StudentTopicLearningState
    {
        if (!$session->learning_period_id || !$session->topic_id) {
            throw new \InvalidArgumentException('Tutor session is missing learning-period/topic context.');
        }

        $state = $this->stateFor($student, (int) $session->learning_period_id, (int) $session->topic_id);
        if ($state->progression_status !== 'covered') {
            $state->progression_status = 'in_progress';
        }
        if ($state->mastery_status === 'unknown') {
            $state->mastery_status = 'developing';
        }
        $state->save();

        return $state;
    }

    public function recordEvaluation(User $student, TutorSession $session, int $score): StudentTopicLearningState
    {
        $state = $this->stateFor($student, (int) $session->learning_period_id, (int) $session->topic_id);
        $state->progression_status = $state->progression_status === 'covered' ? 'covered' : 'in_progress';
        if ($state->mastery_status === 'unknown') {
            $state->mastery_status = 'developing';
        }
        $state->attempt_count = (int) $state->attempt_count + 1;
        $state->last_score = max(0, min(100, $score));
        $state->best_score = max((int) ($state->best_score ?? 0), $state->last_score);
        $state->last_attempt_at = now();
        $state->save();

        return $state;
    }

    public function markMastered(User $student, TutorSession $session): StudentTopicLearningState
    {
        $state = $this->stateFor($student, (int) $session->learning_period_id, (int) $session->topic_id);
        $state->progression_status = 'covered';
        $state->mastery_status = 'mastered';
        $state->move_on_chosen = false;
        $state->move_on_reason = null;
        $state->move_on_at = null;
        $state->mastered_at = now();
        $state->save();

        return $state;
    }

    public function markNeedsReview(
        User $student,
        TutorSession $session,
        ?string $reason = null
    ): StudentTopicLearningState {
        $state = $this->stateFor($student, (int) $session->learning_period_id, (int) $session->topic_id);
        $state->progression_status = 'covered';
        $state->mastery_status = 'needs_review';
        $state->move_on_chosen = true;
        $state->move_on_reason = $reason;
        $state->move_on_at = now();
        $state->save();

        return $state;
    }

    public function periodStates(User $student, LearningPeriod $period): Collection
    {
        $period->loadMissing('topics:id,learning_period_id,topic_id');
        $topicIds = $period->topics->pluck('topic_id')->filter()->unique()->values();

        $states = StudentTopicLearningState::query()
            ->where('student_id', $student->id)
            ->where('learning_period_id', $period->id)
            ->whereIn('topic_id', $topicIds)
            ->get()
            ->keyBy('topic_id');

        // Backward compatibility: existing completed Tutor sessions count as covered.
        $legacySessions = TutorSession::query()
            ->where('student_id', $student->id)
            ->where('learning_period_id', $period->id)
            ->where('status', 'completed')
            ->whereIn('topic_id', $topicIds)
            ->with('objectives:id,tutor_session_id,status')
            ->latest('id')
            ->get()
            ->unique('topic_id');

        foreach ($legacySessions as $session) {
            if ($states->has($session->topic_id)) {
                continue;
            }

            $hasReview = $session->objectives->contains(fn ($objective) => $objective->status === 'needs_review');
            $state = $this->stateFor($student, (int) $period->id, (int) $session->topic_id);
            $state->progression_status = 'covered';
            $state->mastery_status = $hasReview ? 'needs_review' : 'mastered';
            $state->mastered_at = $hasReview ? null : ($session->ended_at ?? $session->updated_at);
            $state->save();
            $states->put($session->topic_id, $state);
        }

        return $states;
    }

    public function periodSummary(User $student, LearningPeriod $period): array
    {
        $period->loadMissing('topics:id,learning_period_id,topic_id');
        $topicIds = $period->topics->pluck('topic_id')->filter()->unique()->values();
        $states = $this->periodStates($student, $period);

        $covered = $topicIds->filter(fn ($id) => $states->get($id)?->progression_status === 'covered')->count();
        $mastered = $topicIds->filter(fn ($id) => $states->get($id)?->mastery_status === 'mastered')->count();
        $needsReview = $topicIds->filter(fn ($id) => $states->get($id)?->mastery_status === 'needs_review')->count();

        return [
            'total_topics' => $topicIds->count(),
            'covered_topics' => $covered,
            'mastered_topics' => $mastered,
            'needs_review_topics' => $needsReview,
            'coverage_complete' => $topicIds->isNotEmpty() && $covered === $topicIds->count(),
        ];
    }
}

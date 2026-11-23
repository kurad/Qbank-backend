<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\StudentAssessment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssessmentQuestionExclusionService
{
    public function excludedIds(int $assessmentId): array
    {
        return DB::table('assessment_question_exclusions')
            ->where('assessment_id', $assessmentId)->where('is_excluded', true)
            ->pluck('question_id')->map(fn ($id) => (int) $id)->all();
    }

    public function recalculate(StudentAssessment $attempt): void
    {
        $excluded = $this->excludedIds((int) $attempt->assessment_id);
        $answers = $attempt->studentAnswers()->with('question:id,marks')->get();
        // The original maximum is a frozen baseline, never derived from
        // the previously discounted maximum (which would drift on reruns).
        if ($attempt->original_max_score === null) {
            $attempt->original_max_score = max(
                (float) ($attempt->max_score ?? 0),
                (float) $answers->sum(fn ($answer) => (float) ($answer->question?->marks ?? 0))
            );
        }
        $assessmentQuestionIds = DB::table('assessment_questions')
            ->where('assessment_id', $attempt->assessment_id)->pluck('question_id')->map(fn ($id) => (int) $id)->all();
        // Common assessments have a fixed list; personalized attempts may
        // differ, so use the actual answers when no fixed list exists.
        $answeredQuestionIds = $answers->pluck('question_id')->map(fn ($id) => (int) $id)->all();
        $applicableExcluded = array_intersect($excluded, array_unique(array_merge($assessmentQuestionIds, $answeredQuestionIds)));
        $removed = (float) DB::table('questions')->whereIn('id', $applicableExcluded)->sum('marks');
        $included = $answers->reject(fn ($answer) => in_array((int) $answer->question_id, $excluded, true));
        $pending = $included->contains(fn ($answer) => $answer->points_earned === null);
        $max = max(0, (float) $attempt->original_max_score - (float) $removed);
        $score = round((float) $included->sum('points_earned'), 2);
        $updates = ['max_score' => round($max, 2)];
        if (in_array($attempt->status, ['graded', 'completed'], true)) {
            $updates['score'] = $pending ? null : $score;
            if ($pending) $updates['status'] = 'under_review';
        } elseif ($attempt->status === 'under_review') {
            $updates['score'] = null;
            // Only formative reviews auto-release when all included answers
            // have marks. Assigned assessments require explicit publication.
            if (!$pending && $included->isNotEmpty() && $attempt->assessment?->type === 'formative') {
                $updates['status'] = 'graded';
                $updates['score'] = $score;
            }
        }
        $attempt->fill($updates)->save();
    }

    public function setExcluded(User $teacher, Assessment $assessment, int $questionId, bool $exclude, ?string $reason): array
    {
        return DB::transaction(function () use ($teacher, $assessment, $questionId, $exclude, $reason) {
            // Serialize concurrent changes for one assessment.
            Assessment::query()->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            abort_unless(DB::table('assessment_questions')
                ->where('assessment_id', $assessment->id)->where('question_id', $questionId)->exists()
                || DB::table('student_answers')->where('question_id', $questionId)
                    ->whereIn('student_assessment_id', StudentAssessment::query()->where('assessment_id', $assessment->id)->select('id'))->exists(),
                422, 'This question is not part of the assessment.');
            $existing = DB::table('assessment_question_exclusions')
                ->where('assessment_id', $assessment->id)->where('is_excluded', true)->pluck('question_id')->all();
            if ($exclude) {
                $available = DB::table('assessment_questions')->where('assessment_id', $assessment->id)->distinct()->pluck('question_id');
                if ($available->isEmpty()) {
                    $available = DB::table('student_answers')->whereIn('student_assessment_id',
                        StudentAssessment::query()->where('assessment_id', $assessment->id)->select('id'))->distinct()->pluck('question_id');
                }
                abort_if($available->every(fn ($id) => (int) $id === $questionId || in_array($id, $existing)),
                    422, 'At least one question must remain included in grading.');
            }
            $now = now();
            DB::table('assessment_question_exclusions')->updateOrInsert(
                ['assessment_id' => $assessment->id, 'question_id' => $questionId],
                ['is_excluded' => $exclude, 'reason' => $reason, 'changed_by' => $teacher->id, 'updated_at' => $now, 'created_at' => $now]
            );
            DB::table('assessment_question_exclusion_events')->insert([
                'assessment_id' => $assessment->id, 'question_id' => $questionId,
                'is_excluded' => $exclude, 'reason' => $reason, 'changed_by' => $teacher->id, 'created_at' => $now,
            ]);
            $attempts = StudentAssessment::query()->where('assessment_id', $assessment->id)
                ->lockForUpdate()->get();
            foreach ($attempts as $attempt) $this->recalculate($attempt);
            return ['assessment_id' => $assessment->id, 'question_id' => $questionId,
                'excluded' => $exclude, 'affected_attempts' => $attempts->count()];
        });
    }
}

<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\StudentAssessment;
use Illuminate\Support\Facades\DB;

/** Immutable per-student presentation order; canonical question/answer IDs never change. */
class AssessmentShuffleService
{
    public function ensureSnapshot(Assessment $assessment, StudentAssessment $attempt): void
    {
        DB::transaction(function () use ($assessment, $attempt) {
            $locked = StudentAssessment::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->question_order !== null && $locked->option_order !== null) return;

            $questions = $assessment->questions()->with('question.subQuestions')->get();
            $orderedIds = $questions->pluck('question_id')->map(fn ($id) => (int) $id)->all();
            // Treat multipart questions as one block. Never separate a parent from its parts.
            $groups = [];
            foreach ($questions as $aq) {
                $q = $aq->question;
                $key = $q ? (int) ($q->parent_question_id ?: $q->id) : (int) $aq->question_id;
                $groups[$key][] = (int) $aq->question_id;
            }
            $shuffledIds = $orderedIds;
            if ($assessment->shuffle_questions) {
                $keys = array_keys($groups);
                shuffle($keys);
                $shuffledIds = [];
                foreach ($keys as $key) array_push($shuffledIds, ...$groups[$key]);
            }

            $options = [];
            foreach ($questions as $aq) {
                $question = $aq->question;
                if (!$question) continue;
                foreach (collect([$question])->concat($question->subQuestions) as $q) {
                    if ($q->question_type !== 'mcq') continue;
                    $raw = $q->options;
                    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                    if (!is_array($decoded) || !array_is_list($decoded)) continue;
                    $indices = array_keys($decoded);
                    if ($assessment->shuffle_options) shuffle($indices);
                    $options[(string) $q->id] = $indices;
                }
            }
            $locked->question_order = $shuffledIds;
            $locked->option_order = $options;
            $locked->save();
            $attempt->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function orderQuestions($questions, StudentAssessment $attempt)
    {
        $orderedIds = $attempt->question_order ?: [];
        if (!$orderedIds) return $questions;
        $positions = array_flip(array_map('intval', $orderedIds));
        return $questions->sortBy(fn ($aq) => $positions[(int) $aq->question_id] ?? PHP_INT_MAX)->values();
    }

    public function orderOptions($options, int $questionId, StudentAssessment $attempt)
    {
        $indices = ($attempt->option_order ?: [])[(string) $questionId] ?? null;
        if (!$indices || !is_array($options) || !array_is_list($options)) return $options;
        if (count($indices) !== count($options)) return $options;
        if (count(array_unique($indices)) !== count($indices)) return $options;
        if (array_diff(range(0, count($options)-1), $indices)) return $options;
        return array_values(array_map(fn ($i) => $options[(int) $i], $indices));
    }
}

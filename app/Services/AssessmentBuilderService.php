<?php

namespace App\Services;

use App\Models\Question;
use App\Models\Assessment;
use App\Models\QuestionUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentBuilderService
{
    /**
     * Fetch questions under selected topics.
     */
    public function questionsForTopics(array $topicIds)
    {
        return Question::whereIn('topic_id', $topicIds)
            ->with('topic')
            ->get();
    }

    /**
     * Main method: Create an assessment, attach topics, attach questions, log usage.
     */
    public function createWithTopicsAndQuestions(array $assessmentData, array $topicIds, array $questionIds, int $creatorId)
    {
        // Expand any selected parent questions into their sub-questions
        $expandedQuestionIds = Question::whereIn('id', $questionIds)
            ->with('subQuestions')
            ->get()
            ->flatMap(function ($q) {
                // If this question has sub-questions, use their IDs instead of the parent ID
                if ($q->subQuestions && $q->subQuestions->count() > 0) {
                    return $q->subQuestions->pluck('id');
                }

                return [$q->id];
            })
            ->unique()
            ->values()
            ->all();

        // ---- STEP 1: Validate usage BEFORE creating an assessment ----
        $usage = $this->countQuestionUsesByCreator($creatorId, $expandedQuestionIds);

        // Hard-stop if any question has been used 3 or more times
        $blocked = array_keys(array_filter($usage, fn($count) => $count >= 3));

        if (!empty($blocked)) {
            throw ValidationException::withMessages([
                'message' => 'Some questions have already been used in 3 or more of your assessments.',
                'question_ids' => $blocked,
            ]);
        }

        // Build warnings for questions used exactly twice (this will be the 3rd time)
        $warnings = [];
        foreach ($usage as $qid => $count) {
            if ($count === 2) {
                $warnings[] = "Question ID {$qid} has already been used twice in your assessments.";
            }
        }

        // ---- STEP 2: Create assessment + attach data atomically ----
        $result = DB::transaction(function () use ($assessmentData, $topicIds, $expandedQuestionIds, $creatorId) {

            $now = now();

            // Create the assessment record
            $assessment = Assessment::create([
                ...$assessmentData,
                'creator_id' => $creatorId,
                'question_count' => count($expandedQuestionIds),
            ]);

            // Attach topics
            if (!empty($topicIds)) {
                $assessment->topics()->attach($topicIds);
            }

            // Attach question pivots + log usage
            foreach ($expandedQuestionIds as $index => $qid) {

                // Create entry in assessment_questions pivot
                $assessment->assessmentQuestions()->create([
                    'question_id' => $qid,
                    'order' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Log usage in question_usages table
                $this->logQuestionUsage($qid, $assessment->id, $now);
            }

            return $assessment->load('topics', 'assessmentQuestions.question');
        });

        return [
            'assessment' => $result,
            'warnings'   => $warnings,
        ];
    }

    /**
     * Count how many times each selected question has been used by this creator.
     */
    public function countQuestionUsesByCreator(int $creatorId, array $questionIds): array
    {
        return DB::table('assessment_questions')
            ->join('assessments', 'assessments.id', '=', 'assessment_questions.assessment_id')
            ->where('assessments.creator_id', $creatorId)
            ->whereIn('assessment_questions.question_id', $questionIds)
            ->select('assessment_questions.question_id', DB::raw('COUNT(*) as usage_count'))
            ->groupBy('assessment_questions.question_id')
            ->pluck('usage_count', 'question_id')
            ->toArray();
    }

    /**
     * Log usage in the question_usages table.
     */
    protected function logQuestionUsage(int $questionId, int $assessmentId, $timestamp): void
    {
        QuestionUsage::create([
            'question_id'   => $questionId,
            'assessment_id' => $assessmentId,
            'used_at'       => $timestamp,
        ]);
    }
}

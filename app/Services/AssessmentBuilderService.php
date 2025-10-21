<?php

namespace App\Services;

use App\Models\Question;
use App\Models\Assessment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class AssessmentBuilderService
{
    public function questionsForTopics(array $topicIds)
    {
        return Question::whereIn('topic_id', $topicIds)
            ->with('topic')
            ->get();
    }
    public function createWithTopicsAndQuestions(array $assessmentData, array $topicIds, array $questionIds, int $creatorId)
    {
        return DB::transaction(function () use ($assessmentData, $topicIds, $questionIds, $creatorId) {
            $assessment = Assessment::create(array_merge($assessmentData, [
                'creator_id' => $creatorId,
                'question_count' => count($questionIds),
            ]));

            // Attach topics pivot

            if(!empty($topicIds)) {
                $assessment->topics()->attach($topicIds);
            }

            // Attach questions with order
            foreach($questionIds as $idx => $qid){
                $assessment->assessmentQuestions()->create([
                    'question_id' => $qid,
                    'order' => $idx + 1,
                ]);
            }
            return $assessment->load('topics', 'assessmentQuestions.question');
        });
    }
}
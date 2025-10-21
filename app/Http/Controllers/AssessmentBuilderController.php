<?php

namespace App\Http\Controllers;

use App\Services\AssessmentBuilderService;
use Illuminate\Http\Request;

class AssessmentBuilderController extends Controller
{
    protected $svc;

    public function __construct(AssessmentBuilderService $svc)
    {
        $this->svc = $svc;
    }

    public function questionsByTopics(Request $request)
    {
        $validated = $request->validate([
            'topic_ids' => 'required|array|min:1',
            'topic_ids.*' => 'integer|exists:topics,id'
        ]);

        $questions = $this->svc->questionsForTopics($validated['topic_ids']);

        return response()->json($questions);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'grade_level_id' => 'required|integer|exists:grade_levels,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'topic_ids' => 'required|array|min:1',
            'topic_ids.*' => 'integer|exists:topics,id',
            'type' => 'required|in:quiz,exam,homework,practice',
            'delivery_mode' => 'required|in:online,offline',
            'question_ids' => 'required|array|min:1',
            'question_ids.*' => 'integer|exists:questions,id',
        ]);

        $assessmentData = [
            'title' => $validated['title'],
            'grade_level_id' => $validated['grade_level_id'],
            'subject_id' => $validated['subject_id'],
            'type' => $validated['type'],
            'delivery_mode' => $validated['delivery_mode'],
        ];

        $assessment = $this->svc->createWithTopicsAndQuestions(
            $assessmentData,
            $validated['topic_ids'],
            $validated['question_ids'],
            $request->user()->id
        );

        return response()->json([
            'status' => 'success',
            'assessment' => $assessment
        ], 201);
    }
}

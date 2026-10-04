<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Services\ModernAssessmentBuilderService;
use Illuminate\Http\Request;

class ModernAssessmentBuilderController extends Controller
{
    public function __construct(
        private ModernAssessmentBuilderService $service
    ) {
    }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'type' => [
                'nullable',
                'in:quiz,exam,homework,practice',
            ],

            'delivery_mode' => [
                'nullable',
                'in:online,offline',
            ],

            'is_timed' => [
                'nullable',
                'boolean',
            ],

            'time_limit' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'instructions' => [
                'nullable',
                'string',
            ],
        ]);

        $assessment = $this->service->createDraft(
            $request->user()->id,
            $validated
        );

        return response()->json([
            'message' =>
                'Assessment draft created successfully.',

            'data' =>
                $this->service->getForBuilder(
                    $assessment
                ),
        ], 201);
    }

    public function show(
        Assessment $assessment,
        Request $request
    ) {
        abort_unless(
            (int) $assessment->creator_id ===
                (int) $request->user()->id,
            403
        );

        return response()->json([
            'data' => $this->service->getForBuilder($assessment),
        ]);
    }
    public function update(
        Assessment $assessment,
        Request $request
    ) {
        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'type' => [
                'nullable',
                'in:quiz,exam,homework,practice',
            ],

            'delivery_mode' => [
                'nullable',
                'in:online,offline',
            ],

            'is_timed' => [
                'nullable',
                'boolean',
            ],

            'time_limit' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'instructions' => [
                'nullable',
                'string',
            ],
        ]);

        $assessment = $this->service->updateAssessment(
            $assessment,
            $request->user()->id,
            $validated
        );

        return response()->json([
            'message' =>
                'Assessment updated successfully.',

            'data' => $assessment,
        ]);
    }

    public function updateScope(
        Assessment $assessment,
        Request $request
    ) {
        $validated = $request->validate([
            'unit_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'unit_ids.*' => [
                'integer',
                'distinct',
                'exists:units,id',
            ],

            'topic_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'topic_ids.*' => [
                'integer',
                'distinct',
                'exists:topics,id',
            ],

            'learning_objective_ids' => [
                'nullable',
                'array',
            ],

            'learning_objective_ids.*' => [
                'integer',
                'distinct',
                'exists:learning_objectives,id',
            ],
        ]);

        $assessment = $this->service->setScope(
            $assessment,
            $request->user()->id,
            $validated['unit_ids'],
            $validated['topic_ids'],
            $validated['learning_objective_ids'] ?? []
        );

        return response()->json([
            'message' =>
                'Assessment curriculum scope updated successfully.',

            'data' => $assessment,
        ]);
    }

    public function updateBlueprint(
        Assessment $assessment,
        Request $request
    ) {
        $validated = $request->validate([
            'mode' => [
                'required',
                'in:manual,blueprint',
            ],

            'total_questions' => [
                'required',
                'integer',
                'min:1',
            ],

            'bloom_distribution' => [
                'nullable',
                'array',
            ],

            'bloom_distribution.remembering' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'bloom_distribution.understanding' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'bloom_distribution.applying' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'bloom_distribution.analyzing' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'bloom_distribution.evaluating' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'bloom_distribution.creating' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'type_distribution' => [
                'nullable',
                'array',
            ],

            'type_distribution.mcq' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'type_distribution.true_false' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'type_distribution.matching' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'type_distribution.short_answer' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'type_distribution.open_ended' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ]);

        $assessment = $this->service->updateBlueprint(
            $assessment,
            $request->user()->id,
            $validated
        );

        return response()->json([
            'message' =>
                'Assessment blueprint updated successfully.',

            'data' => $assessment,
        ]);
    }

    /**
     * Publish a completed assessment from the teacher builder.
     */
    public function publish(
        Assessment $assessment,
        Request $request
    ) {
        $assessment = $this->service->publishAssessment(
            $assessment,
            $request->user()->id
        );

        return response()->json([
            'message' =>
                'Assessment published successfully.',

            'data' =>
                $assessment,
        ]);
    }

    public function questionPool(Request $request)
    {
        $validated = $request->validate([
            'assessment_id' => [
                'nullable',
                'integer',
                'exists:assessments,id',
            ],

            'unit_ids' => [
                'nullable',
                'array',
            ],

            'unit_ids.*' => [
                'integer',
                'distinct',
                'exists:units,id',
            ],

            'topic_ids' => [
                'nullable',
                'array',
            ],

            'topic_ids.*' => [
                'integer',
                'distinct',
                'exists:topics,id',
            ],
            'learning_objective_ids' => [
                'nullable',
                'array',
            ],

            'learning_objective_ids.*' => [
                'integer',
                'distinct',
                'exists:learning_objectives,id',
            ],

            'objective_filter' => [
                'nullable',
                'in:all,assigned,none',
            ],

            'learning_objective_id' => [
                'nullable',
                'integer',
                'exists:learning_objectives,id',
            ],

            'question_type' => [
                'nullable',
                'in:mcq,true_false,short_answer,matching,parent,open_ended',
            ],

            'difficulty_level' => [
                'nullable',
                'in:remembering,understanding,applying,analyzing,evaluating,creating',
            ],

            'source' => [
                'nullable',
                'in:teacher,ai,import',
            ],

            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'min_marks' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'max_marks' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $questions = $this->service->questionPool(
            $request->user()->id,
            $validated
        );

        return response()->json($questions);
    }

    public function addQuestions(
        Assessment $assessment,
        Request $request
    ) {
        $validated = $request->validate([
            'question_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'question_ids.*' => [
                'integer',
                'distinct',
                'exists:questions,id',
            ],
        ]);

        return response()->json([
            'message' =>
                'Questions added successfully.',

            'data' =>
                $this->service->addQuestions(
                    $assessment,
                    $request->user()->id,
                    $validated['question_ids']
                ),
        ]);
    }

    /**
     * Remove question from assessment.
     */
    public function removeQuestion(
        Assessment $assessment,
        int $question,
        Request $request
    ) {
        return response()->json([
            'message' =>
                'Question removed successfully.',

            'data' =>
                $this->service->removeQuestion(
                    $assessment,
                    $request->user()->id,
                    $question
                ),
        ]);
    }

    /**
     * Reorder assessment questions.
     */
    public function reorderQuestions(
        Assessment $assessment,
        Request $request
    ) {
        $validated = $request->validate([
            'question_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'question_ids.*' => [
                'integer',
                'distinct',
            ],
        ]);

        return response()->json([
            'message' =>
                'Assessment question order updated successfully.',

            'data' =>
                $this->service->reorderQuestions(
                    $assessment,
                    $request->user()->id,
                    $validated['question_ids']
                ),
        ]);
    }
}
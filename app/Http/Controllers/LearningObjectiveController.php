<?php

namespace App\Http\Controllers;

use App\Models\LearningObjective;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningObjectiveController extends Controller
{
    /**
     * List learning objectives for a topic.
     */
    public function index(Topic $topic)
    {
        $this->authorize('view', $topic);

        $objectives = $topic->learningObjectives()
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Learning objectives retrieved successfully',
            'data' => $objectives,
        ]);
    }

    /**
     * Create a learning objective.
     */
    public function store(Request $request, Topic $topic)
    {
        $this->authorize('view', $topic);

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:100'],
            'objective' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $objective = DB::transaction(function () use (
            $validated,
            $topic,
            $request
        ) {
            return LearningObjective::create([
                'topic_id' => $topic->id,
                'code' => $validated['code'] ?? null,
                'objective' => $validated['objective'],
                'description' => $validated['description'] ?? null,
                'order' => $validated['order'] ?? 0,
                'status' => $validated['status'] ?? 'active',
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => 'Learning objective created successfully',
            'data' => $objective->load([
                'topic.gradeSubject.subject',
                'topic.gradeSubject.gradeLevel',
                'topic.unit',
            ]),
        ], 201);
    }

    /**
     * Show one learning objective.
     */
    public function show(LearningObjective $learningObjective)
    {
        $this->authorize('view', $learningObjective);

        return response()->json([
            'message' => 'Learning objective retrieved successfully',
            'data' => $learningObjective->load([
                'topic.gradeSubject.subject',
                'topic.gradeSubject.gradeLevel',
                'topic.unit',
            ]),
        ]);
    }

    /**
     * Update a learning objective.
     */
    public function update(
        Request $request,
        LearningObjective $learningObjective
    ) {
        $this->authorize('update', $learningObjective);

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:100'],
            'objective' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $learningObjective->update([
            'code' => $validated['code'] ?? null,
            'objective' => $validated['objective'],
            'description' => $validated['description'] ?? null,
            'order' => $validated['order'] ?? $learningObjective->order,
            'status' => $validated['status'] ?? $learningObjective->status,
        ]);

        return response()->json([
            'message' => 'Learning objective updated successfully',
            'data' => $learningObjective->fresh()->load([
                'topic.gradeSubject.subject',
                'topic.gradeSubject.gradeLevel',
                'topic.unit',
            ]),
        ]);
    }

    /**
     * Delete a learning objective.
     */
    public function destroy(LearningObjective $learningObjective)
    {
        $this->authorize('delete', $learningObjective);

        if ($learningObjective->questions()->exists()) {
            return response()->json([
                'message' =>
                    'Cannot delete a learning objective that has associated questions.',
            ], 422);
        }

        $learningObjective->delete();

        return response()->json([
            'message' => 'Learning objective deleted successfully',
        ]);
    }

}

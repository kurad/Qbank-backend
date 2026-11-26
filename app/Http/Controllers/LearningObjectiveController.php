<?php

namespace App\Http\Controllers;

use App\Exceptions\CurriculumException;
use App\Models\LearningObjective;
use App\Models\Topic;
use App\Services\ObjectiveContentSufficiencyService;
use App\Services\ObjectiveCoverageService;
use App\Support\TextTerms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningObjectiveController extends Controller
{
    /**
     * List learning objectives for a topic (archived ones are history, not
     * part of the live curriculum).
     */
    public function index(Topic $topic)
    {
        $this->authorize('view', $topic);

        $objectives = $topic->learningObjectives()
            ->where('status', '!=', 'archived')
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
     *
     * The approved teaching material for the unit must contain enough to
     * teach it; otherwise the objective is not created.
     */
    public function store(
        Request $request,
        Topic $topic,
        ObjectiveContentSufficiencyService $sufficiency,
        ObjectiveCoverageService $coverage
    ) {
        $this->authorize('view', $topic);

        if ($topic->status !== 'active') {
            return response()->json([
                'message' => 'Learning objectives can only be added to an active topic.',
            ], 422);
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:100'],
            'objective' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        try {
            $this->assertTeachable(
                $topic,
                $validated['objective'],
                $validated['description'] ?? null,
                $validated['code'] ?? null,
                $sufficiency,
                $coverage
            );
        } catch (CurriculumException $e) {
            return $this->curriculumError($e);
        }

        $objective = DB::transaction(function () use (
            $validated,
            $topic,
            $request
        ) {
            // New objectives go to the end unless an order was given.
            $nextOrder = ((int) LearningObjective::query()
                ->where('topic_id', $topic->id)
                ->max('order')) + 1;

            return LearningObjective::create([
                'topic_id' => $topic->id,
                'code' => $validated['code'] ?? null,
                'objective' => $validated['objective'],
                'description' => $validated['description'] ?? null,
                'order' => $validated['order'] ?? $nextOrder,
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
     *
     * Rewording an objective re-runs the content-sufficiency gate; changing
     * only its order, code or status does not.
     */
    public function update(
        Request $request,
        LearningObjective $learningObjective,
        ObjectiveContentSufficiencyService $sufficiency,
        ObjectiveCoverageService $coverage
    ) {
        $this->authorize('update', $learningObjective);

        $learningObjective->loadMissing('topic');

        // Archived objectives belong to a superseded curriculum version and
        // must not be edited or reactivated through this endpoint.
        if (
            $learningObjective->status === 'archived' ||
            $learningObjective->topic?->status === 'archived'
        ) {
            return response()->json([
                'message' => 'This learning objective belongs to an archived curriculum version and cannot be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:100'],
            'objective' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $reworded =
            TextTerms::normalise($learningObjective->objective)
                !== TextTerms::normalise($validated['objective'])
            || TextTerms::normalise((string) $learningObjective->description)
                !== TextTerms::normalise((string) ($validated['description'] ?? ''));

        if ($reworded) {
            try {
                $this->assertTeachable(
                    $learningObjective->topic,
                    $validated['objective'],
                    $validated['description'] ?? null,
                    $validated['code'] ?? null,
                    $sufficiency,
                    $coverage,
                    $learningObjective
                );
            } catch (CurriculumException $e) {
                return $this->curriculumError($e);
            }
        }

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
     * Save a new order for all of a topic's objectives in one transaction.
     *
     * Replaces swapping two "order" values with two parallel PUTs, which was
     * not atomic and did nothing when both values were equal (e.g. 0).
     */
    public function reorder(Request $request, Topic $topic)
    {
        $this->authorize('update', $topic);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $ids = array_map('intval', $data['ids']);

        $current = LearningObjective::query()
            ->where('topic_id', $topic->id)
            ->where('status', '!=', 'archived')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (
            count($ids) !== count($current) ||
            array_diff($ids, $current) ||
            array_diff($current, $ids)
        ) {
            return response()->json([
                'message' => "The list must contain exactly this topic's learning objectives.",
            ], 422);
        }

        DB::transaction(function () use ($ids, $topic) {
            foreach ($ids as $position => $id) {
                LearningObjective::query()
                    ->where('id', $id)
                    ->where('topic_id', $topic->id)
                    ->update(['order' => $position + 1]);
            }
        });

        return response()->json([
            'message' => 'Learning objective order saved successfully',
            'data' => LearningObjective::query()
                ->where('topic_id', $topic->id)
                ->where('status', '!=', 'archived')
                ->orderBy('order')
                ->orderBy('id')
                ->get(),
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

    /**
     * Refuse an objective the approved teaching material cannot teach.
     *
     * Evidence is gathered exactly as the coverage checker gathers it
     * (approved material chunks for the unit/topic, validated supporting
     * sources, approved teacher notes) so the two features agree.
     *
     * @throws CurriculumException
     */
    private function assertTeachable(
        Topic $topic,
        string $objectiveText,
        ?string $description,
        ?string $code,
        ObjectiveContentSufficiencyService $sufficiency,
        ObjectiveCoverageService $coverage,
        ?LearningObjective $existing = null
    ): void {
        $topic->loadMissing('gradeSubject');

        // An unsaved draft (or the existing row carrying the new wording).
        $draft = $existing ? $existing->replicate() : new LearningObjective();
        $draft->forceFill([
            'topic_id' => $topic->id,
            'code' => $code,
            'objective' => $objectiveText,
            'description' => $description,
        ]);

        if ($existing) {
            // replicate() drops the key; keep it so teacher notes/mappings
            // already attached to this objective count as evidence.
            $draft->id = $existing->id;
        }

        $draft->setRelation('topic', $topic);

        $evidence = trim($coverage->evidence($draft)['text']);

        if ($evidence === '') {
            throw new CurriculumException(
                'No approved teaching material in this unit covers this objective yet. ' .
                'Upload and approve material that teaches it first, then add the objective.'
            );
        }

        $verdict = $sufficiency->assessOne(
            (string) $topic->topic_name,
            $objectiveText,
            $description,
            $evidence
        );

        if (!$verdict['sufficient']) {
            throw (new CurriculumException(
                'The approved material does not contain enough content to teach this objective, so it was not saved.' .
                ($verdict['reason'] !== '' ? ' ' . $verdict['reason'] : '')
            ))->withContext([
                'insufficient' => [[
                    'objective' => $objectiveText,
                    'reason' => $verdict['reason'],
                    'missing' => $verdict['missing'],
                ]],
            ]);
        }
    }

    private function curriculumError(CurriculumException $e): JsonResponse
    {
        return response()->json(array_merge($e->context(), [
            'message' => $e->getMessage(),
            'error' => $e->getMessage(),
        ]), 422);
    }
}

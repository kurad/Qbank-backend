<?php

namespace App\Http\Controllers;

use App\Models\CourseMaterial;
use App\Models\LearningObjective;
use App\Models\Topic;
use App\Models\TutorSessionObjective;
use App\Services\KnowledgeChunkingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseMaterialSupplementController extends Controller
{
    private function authorizeMaterial(Request $request, CourseMaterial $material): void
    {
        $area = $material->unit?->gradeSubject;
        $user = $request->user();
        abort_unless($user && $area && (int) $area->school_id === (int) $user->school_id
            && ($user->role === 'admin' || ($user->role === 'teacher'
                && (int) $area->teacher_id === (int) $user->id)), 403);
    }

    public function show(Request $request, CourseMaterial $courseMaterial)
    {
        $this->authorizeMaterial($request, $courseMaterial);
        $topics = Topic::query()->where('unit_id', $courseMaterial->unit_id)
            ->where('grade_subject_id', $courseMaterial->unit->grade_subject_id)
            ->where('status', 'active')
            ->with(['learningObjectives' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('order')->get(['id', 'topic_name', 'unit_id', 'grade_subject_id', 'order', 'status']);
        // Some installations use published instead of active: don't hide valid official objectives.
        if ($topics->isEmpty()) {
            $topics = Topic::query()->where('unit_id', $courseMaterial->unit_id)
                ->where('grade_subject_id', $courseMaterial->unit->grade_subject_id)
                ->where('status', 'active')
                ->with(['learningObjectives' => fn ($q) => $q->where('status', 'active')])
                ->orderBy('order')->get(['id', 'topic_name', 'unit_id', 'grade_subject_id', 'order', 'status']);
        }
        return response()->json(['data' => [
            'material_id' => $courseMaterial->id,
            'purpose' => $courseMaterial->purpose ?: 'curriculum',
            'status' => $courseMaterial->status,
            'page_count' => $courseMaterial->pages()->count(),
            'selected_objective_ids' => $courseMaterial->supportedObjectives()->pluck('learning_objectives.id'),
            'topics' => $topics,
        ]]);
    }

    public function save(Request $request, CourseMaterial $courseMaterial)
    {
        $this->authorizeMaterial($request, $courseMaterial);
        abort_if($courseMaterial->status === 'approved', 422, 'Approved materials cannot be remapped.');
        $data = $request->validate([
            'objective_ids' => ['required', 'array', 'min:1'],
            'objective_ids.*' => ['required', 'integer', 'distinct', 'exists:learning_objectives,id'],
        ]);
        $ids = array_map('intval', $data['objective_ids']);
        $valid = LearningObjective::query()->whereIn('id', $ids)
            ->where('status', 'active')
            ->whereHas('topic', fn ($q) => $q->where('unit_id', $courseMaterial->unit_id)
                ->where('grade_subject_id', $courseMaterial->unit->grade_subject_id)
                ->where('status', 'active'))
            ->count();
        abort_unless($valid === count($ids), 422, 'All objectives must be published objectives in the material’s teaching unit.');
        DB::transaction(function () use ($courseMaterial, $request, $ids) {
            $courseMaterial->update(['purpose' => 'supplement']);
            $courseMaterial->supportedObjectives()->sync(
                collect($ids)->mapWithKeys(fn ($id) => [$id => ['mapped_by' => $request->user()->id]])->all()
            );
        });
        return response()->json(['message' => 'Supplement mappings saved; no curriculum was created.', 'data' => ['objective_ids' => $ids]]);
    }

    public function approve(Request $request, CourseMaterial $courseMaterial, KnowledgeChunkingService $chunks)
    {
        $this->authorizeMaterial($request, $courseMaterial);
        abort_unless($courseMaterial->purpose === 'supplement', 422, 'Choose Supplement existing curriculum and save its objective mappings first.');
        abort_unless($courseMaterial->extraction_status === 'ready'
            && trim((string) $courseMaterial->extracted_text) !== ''
            && $courseMaterial->pages()->exists(), 422, 'The PDF must have usable extracted pages.');
        abort_unless(in_array($courseMaterial->visual_extraction_status, ['ready', 'empty'], true),
            422, 'Visual extraction has not finished.');
        $ids = $courseMaterial->supportedObjectives()->pluck('learning_objectives.id')->map(fn ($id) => (int) $id)->all();
        abort_unless(count($ids) > 0, 422, 'Map at least one existing objective first.');

        // Idempotent approval: never remove the original material's chunks or edit topics.
        $count = $courseMaterial->knowledgeChunks()->where('status', 'ready')->count();
        if ($count === 0) {
            $count = $chunks->process($courseMaterial->fresh());
        }
        abort_unless($count > 0, 422, 'No searchable content could be created.');
        $courseMaterial->update([
            'status' => 'approved', 'approved_by' => $request->user()->id,
            'approved_at' => $courseMaterial->approved_at ?: now(),
        ]);

        // Do not change completed/mastered objectives. Recheck only pending zero-attempt gaps.
        $reopened = TutorSessionObjective::query()->whereIn('learning_objective_id', $ids)
            ->where('status', 'material_gap')->where('attempts', 0)
            ->whereHas('session', fn ($q) => $q->where('status', 'active')
                ->where('grade_subject_id', $courseMaterial->unit->grade_subject_id))
            ->update(['status' => 'not_started', 'support_status' => 'unknown',
                'support_reason' => null, 'supporting_source_ids' => null,
                'completed_at' => null, 'updated_at' => now()]);
        return response()->json(['message' => 'Supplement approved. No new topics or objectives were created.',
            'data' => ['knowledge_chunks' => $count, 'objectives' => $ids, 'gaps_reopened' => $reopened]]);
    }
}

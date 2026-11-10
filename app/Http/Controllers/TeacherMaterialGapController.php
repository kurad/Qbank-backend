<?php

namespace App\Http\Controllers;

use App\Models\LearningObjective;
use App\Models\TutorSessionObjective;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherMaterialGapController extends Controller
{
    private function authorizeObjective(Request $request, LearningObjective $objective): int
    {
        $gradeSubject = $objective->topic?->gradeSubject;
        abort_unless($request->user()?->role === 'teacher'
            && $gradeSubject
            && (int) $gradeSubject->teacher_id === (int) $request->user()->id
            && (int) $gradeSubject->school_id === (int) $request->user()->school_id, 403);
        return (int) $gradeSubject->id;
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->role === 'teacher', 403);
        $teacher = $request->user();
        $rows = TutorSessionObjective::query()
            ->with(['learningObjective.topic.unit', 'session:id,student_id,grade_subject_id,topic_id,status'])
            ->where('status', 'material_gap')
            ->whereHas('session', function ($query) use ($teacher) {
                $query->where('status', 'active')->whereHas('gradeSubject', fn ($q) =>
                    $q->where('teacher_id', $teacher->id)->where('school_id', $teacher->school_id));
            })->get()->groupBy('learning_objective_id');
        $notes = DB::table('objective_support_notes')
            ->whereIn('learning_objective_id', $rows->keys()->all())
            ->where('teacher_id', $teacher->id)
            ->get()->keyBy('learning_objective_id');
        $data = $rows->map(function ($items, $objectiveId) use ($notes) {
            $first = $items->first();
            return [
                'objective_id' => (int) $objectiveId,
                'objective' => $first->learningObjective?->objective,
                'topic_name' => $first->learningObjective?->topic?->topic_name,
                'unit_id' => $first->learningObjective?->topic?->unit_id,
                'grade_subject_id' => $first->session?->grade_subject_id,
                'reason' => $first->support_reason,
                'affected_students' => $items->pluck('session.student_id')->filter()->unique()->count(),
                'support_note' => $notes->get($objectiveId)?->content ?? '',
            ];
        })->values();
        return response()->json(['data' => $data]);
    }

    public function saveNote(Request $request, LearningObjective $objective)
    {
        $gradeSubjectId = $this->authorizeObjective($request, $objective);
        $data = $request->validate(['content' => ['required', 'string', 'min:40', 'max:30000']]);
        $reopened = DB::transaction(function () use ($request, $objective, $gradeSubjectId, $data) {
            DB::table('objective_support_notes')->updateOrInsert(
                ['learning_objective_id' => $objective->id, 'grade_subject_id' => $gradeSubjectId],
                ['teacher_id' => $request->user()->id, 'content' => trim($data['content']),
                 'created_at' => now(), 'updated_at' => now()]
            );
            return $this->reopenActiveGaps($objective, $gradeSubjectId);
        });
        return response()->json(['message' => 'Teaching content saved. The Tutor will verify it before teaching; no objective was marked mastered.', 'reopened' => $reopened]);
    }

    public function retry(Request $request, LearningObjective $objective)
    {
        $gradeSubjectId = $this->authorizeObjective($request, $objective);
        $reopened = DB::transaction(fn () => $this->reopenActiveGaps($objective, $gradeSubjectId));
        return response()->json(['message' => 'Pending objectives queued for fresh material verification.', 'reopened' => $reopened]);
    }

    private function reopenActiveGaps(LearningObjective $objective, int $gradeSubjectId): int
    {
        return TutorSessionObjective::query()
            ->where('learning_objective_id', $objective->id)
            ->where('status', 'material_gap')
            ->where('attempts', 0)
            ->whereHas('session', fn ($q) => $q->where('status', 'active')->where('grade_subject_id', $gradeSubjectId))
            ->update([
                'status' => 'not_started', 'completed_at' => null,
                'support_status' => 'unknown', 'support_reason' => null,
                'supporting_source_ids' => null, 'updated_at' => now(),
            ]);
    }
}

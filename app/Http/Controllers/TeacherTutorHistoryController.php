<?php

namespace App\Http\Controllers;

use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Http\Request;

class TeacherTutorHistoryController extends Controller
{
    private function baseQuery(User $actor)
    {
        abort_unless(in_array($actor->role, ['teacher', 'admin'], true), 403);
        // Teacher teaching assignments AND actual class enrollment must both match.
        // School admins can review only their school's teaching areas.
        $query = TutorSession::query()
            ->whereHas('gradeSubject', function ($q) use ($actor) {
                if ($actor->school_id) {
                    $q->where('school_id', $actor->school_id);
                } else {
                    abort_unless($actor->isSystemAdmin(), 403);
                }
                if ($actor->role === 'teacher') {
                    $q->where('teacher_id', $actor->id);
                }
            })
            ->whereHas('student', function ($q) use ($actor) {
                if ($actor->school_id) $q->where('school_id', $actor->school_id);
            })
            ->where(function ($q) {
                $q->whereHas('group.students', fn ($students) =>
                    $students->whereColumn('users.id', 'tutor_sessions.student_id')
                );
            });
        return $query;
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'student_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'grade_subject_id' => ['nullable', 'integer'],
            'learning_period_id' => ['nullable', 'integer'],
            'topic_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,completed,abandoned'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = $this->baseQuery($request->user());
        foreach (['student_id','group_id','grade_subject_id','learning_period_id','topic_id','status'] as $key) {
            if (isset($filters[$key])) $query->where($key, $filters[$key]);
        }
        if (!empty($filters['q'])) {
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']) . '%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)
                ->orWhereHas('student', fn ($s) => $s->where('name', 'like', $term))
                ->orWhereHas('topic', fn ($t) => $t->where('topic_name', 'like', $term)));
        }
        return response()->json(['data' => $query
            ->with(['student:id,name', 'group:id,group_name', 'gradeSubject.subject:id,name',
                'learningPeriod:id,title', 'topic:id,topic_name'])
            ->withCount('messages')->orderByDesc('last_activity_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)]);
    }

    public function show(Request $request, TutorSession $tutorSession)
    {
        abort_unless($this->baseQuery($request->user())->whereKey($tutorSession->id)->exists(), 403);
        $tutorSession->load([
            'student:id,name', 'group:id,group_name', 'gradeSubject.subject:id,name',
            'learningPeriod:id,title', 'topic:id,topic_name',
            'objectives.learningObjective:id,objective',
            'messages' => fn ($q) => $q->orderBy('id')->select('id','tutor_session_id','role','message_type','content','created_at'),
            'responseEvaluations' => fn ($q) => $q->orderBy('id')->select('id','tutor_session_id','classification','correct','understanding_score','feedback','misconception','student_response','created_at'),
        ]);
        // Exclude raw metadata: it may contain internal file paths or private payloads.
        return response()->json(['data' => $tutorSession]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\GradeSubject;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GradeSubjectController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewAny', GradeSubject::class);

        $query = GradeSubject::with(['subject', 'gradeLevel', 'teacher'])
            ->withCount(['units', 'groups'])
            ->where('school_id', $user->school_id);

        if ($user->role === 'teacher') {
            $query->where('teacher_id', $user->id);
        } elseif ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->integer('teacher_id'));
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->integer('subject_id'));
        }

        if ($request->filled('grade_level_id')) {
            $query->where('grade_level_id', $request->integer('grade_level_id'));
        }

        return response()->json([
            'data' => $query->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->authorize('create', GradeSubject::class);

        $validated = $request->validate([
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'grade_level_id' => ['required', 'integer', 'exists:grade_levels,id'],
            'teacher_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $subject = Subject::where('id', $validated['subject_id'])
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        if ($user->role === 'teacher') {
            $existing = GradeSubject::query()
                ->where('school_id', $user->school_id)
                ->where('grade_level_id', $validated['grade_level_id'])
                ->where('subject_id', $subject->id)
                ->where('teacher_id', $user->id)
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'This teaching area is already in your workspace.',
                    'data' => $existing->load(['subject', 'gradeLevel', 'teacher'])->loadCount(['units', 'groups']),
                ]);
            }

            // Reuse an unassigned school teaching area before creating a teacher-specific one.
            $gradeSubject = GradeSubject::query()
                ->where('school_id', $user->school_id)
                ->where('grade_level_id', $validated['grade_level_id'])
                ->where('subject_id', $subject->id)
                ->whereNull('teacher_id')
                ->first();

            if ($gradeSubject) {
                $gradeSubject->update(['teacher_id' => $user->id]);
            } else {
                $gradeSubject = GradeSubject::create([
                    'school_id' => $user->school_id,
                    'grade_level_id' => $validated['grade_level_id'],
                    'subject_id' => $subject->id,
                    'teacher_id' => $user->id,
                ]);
            }
        } else {
            $teacherId = $validated['teacher_id'] ?? null;

            if ($teacherId !== null) {
                $teacher = User::where('id', $teacherId)
                    ->where('school_id', $user->school_id)
                    ->where('role', 'teacher')
                    ->first();

                abort_unless($teacher, 422, 'The selected teacher does not belong to your school.');
            }

            $gradeSubject = GradeSubject::firstOrCreate([
                'school_id' => $user->school_id,
                'grade_level_id' => $validated['grade_level_id'],
                'subject_id' => $subject->id,
                'teacher_id' => $teacherId,
            ]);
        }

        return response()->json([
            'message' => 'Teaching area added to your workspace.',
            'data' => $gradeSubject->fresh()
                ->load(['subject', 'gradeLevel', 'teacher'])
                ->loadCount(['units', 'groups']),
        ], 201);
    }

    public function show(GradeSubject $gradeSubject)
    {
        $this->authorize('view', $gradeSubject);

        return response()->json([
            'data' => $gradeSubject->load([
                'school',
                'subject',
                'gradeLevel',
                'teacher',
                'units',
            ]),
        ]);
    }

    public function update(Request $request, GradeSubject $gradeSubject)
    {
        $this->authorize('update', $gradeSubject);

        $validated = $request->validate([
            'teacher_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($request->user()->role !== 'admin') {
            abort(403);
        }

        if ($validated['teacher_id'] !== null) {
            $teacher = User::where('id', $validated['teacher_id'])
                ->where('school_id', $request->user()->school_id)
                ->where('role', 'teacher')
                ->first();

            abort_unless($teacher, 422, 'The selected teacher does not belong to your school.');
        }

        $gradeSubject->update([
            'teacher_id' => $validated['teacher_id'],
        ]);

        return response()->json([
            'message' => 'Teaching area updated successfully.',
            'data' => $gradeSubject->fresh()->load(['subject', 'gradeLevel', 'teacher']),
        ]);
    }

    public function destroy(GradeSubject $gradeSubject)
    {
        $this->authorize('delete', $gradeSubject);

        $gradeSubject->delete();

        return response()->json([
            'message' => 'Teaching area deleted successfully.',
        ]);
    }
}

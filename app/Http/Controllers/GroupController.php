<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\GradeSubject;
use App\Models\Group;
use App\Models\StudentAssessment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Group::query()
            ->with([
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,grade_name',
            ])
            ->withCount('students');

        if ($user->role === 'admin') {
            $query->whereHas('creator', fn($q) => $q->where('school_id', $user->school_id));
        } else {
            $query->where('created_by', $user->id);
        }

        if ($request->filled('grade_subject_id')) {
            $query->where('grade_subject_id', $request->integer('grade_subject_id'));
        }

        if ($request->filled('academic_year')) {
            $query->where('academic_year', trim((string) $request->input('academic_year')));
        }

        return response()->json(
            $query
                ->orderByDesc('academic_year')
                ->orderBy('group_name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();

        abort_unless(in_array($user->role, ['teacher', 'admin'], true), 403);

        $validated = $request->validate([
            'group_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('groups')->where(
                    fn($q) => $q
                        ->where('created_by', $user->id)
                        ->where('academic_year', $request->input('academic_year'))
                ),
            ],
            'grade_subject_id' => ['required', 'integer', 'exists:grade_subjects,id'],
            'academic_year' => ['required', 'string', 'max:20', 'regex:/^\d{4}(?:-\d{4})?$/'],
        ], [
            'academic_year.regex' => 'Use an academic year such as 2026 or 2026-2027.',
        ]);

        $gradeSubject = GradeSubject::query()
            ->whereKey($validated['grade_subject_id'])
            ->where('school_id', $user->school_id)
            ->when($user->role === 'teacher', fn($q) => $q->where('teacher_id', $user->id))
            ->first();

        abort_unless($gradeSubject, 422, 'The selected teaching area is not available to your account.');

        $group = Group::create([
            'group_name' => $validated['group_name'],
            'grade_subject_id' => $gradeSubject->id,
            'academic_year' => $validated['academic_year'],
            'created_by' => $user->id,
            'class_code' => strtoupper(Str::random(8)),
        ]);

        return response()->json(
            $group->load(['gradeSubject.subject', 'gradeSubject.gradeLevel'])->loadCount('students'),
            201
        );
    }

    public function show(Request $request, $id)
    {
        $group = $this->manageableGroup($request, $id);

        return response()->json(
            $group->load([
                'students:id,name,email,school_id',
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,grade_name',
            ])
        );
    }

    public function update(Request $request, $id)
    {
        $group = $this->manageableGroup($request, $id);

        $validated = $request->validate([
            'group_name' => ['sometimes', 'string', 'max:255'],
            'grade_subject_id' => ['sometimes', 'integer', 'exists:grade_subjects,id'],
            'academic_year' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^\d{4}(?:-\d{4})?$/'],
        ], [
            'academic_year.regex' => 'Use an academic year such as 2026 or 2026-2027.',
        ]);

        if (array_key_exists('grade_subject_id', $validated)) {
            $gradeSubject = GradeSubject::query()
                ->whereKey($validated['grade_subject_id'])
                ->where('school_id', $request->user()->school_id)
                ->when(
                    $request->user()->role === 'teacher',
                    fn($q) => $q->where('teacher_id', $request->user()->id)
                )
                ->first();

            abort_unless($gradeSubject, 422, 'The selected teaching area is not available to your account.');
        }

        $nextName = $validated['group_name'] ?? $group->group_name;
        $nextAcademicYear = $validated['academic_year'] ?? $group->academic_year;

        $duplicate = Group::query()
            ->where('created_by', $group->created_by)
            ->where('group_name', $nextName)
            ->where('academic_year', $nextAcademicYear)
            ->whereKeyNot($group->id)
            ->exists();

        abort_if(
            $duplicate,
            422,
            'You already have a class with this name in the selected academic year.'
        );

        $group->update($validated);

        return response()->json(
            $group->fresh()->load(['gradeSubject.subject', 'gradeSubject.gradeLevel'])->loadCount('students')
        );
    }

    public function destroy(Request $request, $id)
    {
        $group = $this->manageableGroup($request, $id);
        $group->delete();

        return response()->json(['message' => 'Class deleted successfully.']);
    }

    public function eligibleStudents(Request $request, $id)
    {
        $group = $this->manageableGroup($request, $id);
        $group->loadMissing('gradeSubject');

        $query = User::query()
            ->where('role', 'student')
            ->where('school_id', $request->user()->school_id)
            ->whereDoesntHave('groups', fn($q) => $q->where('groups.id', $group->id));


        if ($request->filled('q')) {
            $search = trim((string) $request->input('q'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->limit(200)
                ->get(),
        ]);
    }

    public function addStudents(Request $request, $id)
    {
        $group = $this->manageableGroup($request, $id);
        $group->loadMissing('gradeSubject');

        $validated = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['required', 'integer', 'exists:users,id'],
        ]);

        $studentIds = collect($validated['student_ids'])->map(fn($id) => (int) $id)->unique()->values();

        $students = User::query()
            ->whereIn('id', $studentIds)
            ->where('role', 'student')
            ->where('school_id', $request->user()->school_id)
            ->get(['id']);

        abort_if(
            $students->count() !== $studentIds->count(),
            422,
            'Every selected user must be a student in your school.'
        );


        $group->students()->syncWithoutDetaching($studentIds->all());

        return response()->json(
            $group->fresh()->load([
                'students:id,name,email,school_id',
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,grade_name',
            ])
        );
    }

    public function removeStudent(Request $request, $id, $studentId)
    {
        $group = $this->manageableGroup($request, $id);
        $group->students()->detach((int) $studentId);

        return response()->json(
            $group->fresh()->load([
                'students:id,name,email,school_id',
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,grade_name',
            ])
        );
    }

    public function myGroups(Request $request)
    {
        $user = $request->user();

        $query = Group::with([
            'students:id,name,email',
            'creator:id,name,email',
            'gradeSubject.subject:id,name',
            'gradeSubject.gradeLevel:id,grade_name',
        ]);

        if ($user->role === 'student') {
            $query->whereHas('students', fn($q) => $q->where('users.id', $user->id));
        } elseif ($user->role !== 'admin') {
            $query->where('created_by', $user->id);
        } else {
            $query->whereHas('creator', fn($q) => $q->where('school_id', $user->school_id));
        }

        return response()->json($query->get());
    }

    public function joinClassByCode(Request $request)
    {
        $validated = $request->validate([
            'class_code' => ['required', 'string', 'exists:groups,class_code'],
        ]);

        $group = Group::with('gradeSubject')->where('class_code', $validated['class_code'])->firstOrFail();
        $user = $request->user();

        abort_unless($user->role === 'student', 403, 'Only students can join a class.');

        if ($group->gradeSubject?->school_id && (int) $group->gradeSubject->school_id !== (int) $user->school_id) {
            abort(403, 'This class belongs to another school.');
        }


        if ($group->students()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'You have already joined this class.'], 409);
        }

        $group->students()->attach($user->id);

        return response()->json([
            'message' => 'Successfully joined the class!',
            'group' => $group,
        ]);
    }

    public function assignments(Request $request, Group $group)
    {
        $user = $request->user();

        if ($user->role === 'student') {
            abort_if(
                ! $user->groups()->where('groups.id', $group->id)->exists(),
                403,
                'You are not a member of this class'
            );
        } else {
            if ($user->role !== 'admin' && (int) $group->created_by !== (int) $user->id) {
                abort(403, 'Not allowed to view assignments for this group');
            }
        }

        $assessments = $group->assessments()
            ->withCount('studentAssessments')
            ->latest('assessment_groups.created_at')
            ->get();

        return response()->json($assessments);
    }

    public function assignmentSubmissions(Request $request, Group $group, Assessment $assessment)
    {
        $user = $request->user();
        abort_unless(
            $user->role === 'admin' || (int) $group->created_by === (int) $user->id,
            403
        );

        abort_unless($group->assessments()->where('assessments.id', $assessment->id)->exists(), 404);

        $students = $group->students()->orderBy('name')->get();

        $result = $students->map(function ($student) use ($assessment, $group) {
            $studentAssessment = StudentAssessment::query()
                ->where('assessment_id', $assessment->id)
                ->where('student_id', $student->id)
                ->where(function ($q) use ($group) {
                    $q->where('group_id', $group->id)->orWhereNull('group_id');
                })
                ->with(['answers.question'])
                ->latest('id')
                ->first();

            return [
                'student' => $student,
                'student_assessment' => $studentAssessment,
            ];
        });

        return response()->json($result);
    }

    private function manageableGroup(Request $request, int|string $id): Group
    {
        $group = Group::with(['creator:id,school_id', 'gradeSubject'])->findOrFail($id);
        $user = $request->user();

        if ($user->role === 'admin') {
            $sameSchool = (int) optional($group->creator)->school_id === (int) $user->school_id
                || (int) optional($group->gradeSubject)->school_id === (int) $user->school_id;

            abort_unless($sameSchool, 403);
            return $group;
        }

        abort_unless(
            $user->role === 'teacher' && (int) $group->created_by === (int) $user->id,
            403
        );

        return $group;
    }
    public function studentShow(Request $request, $id)
    {
        $user = $request->user();

        abort_unless(
            $user && $user->role === 'student',
            403,
            'Only students can access this endpoint.'
        );

        $group = Group::query()
            ->with([
                'creator:id,name',
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,grade_name',
            ])
            ->whereKey($id)
            ->whereHas('students', function ($query) use ($user) {
                $query->where('users.id', $user->id);
            })
            ->first();

        if (!$group) {
            return response()->json([
                'message' => 'Class not found or you are not enrolled in this class.',
            ], 404);
        }

        return response()->json($group);
    }

    public function studentAssignments(Request $request, $id)
    {
        $user = $request->user();

        abort_unless(
            $user && $user->role === 'student',
            403,
            'Only students can access this endpoint.'
        );

        $group = Group::query()
            ->whereKey($id)
            ->whereHas('students', function ($query) use ($user) {
                $query->where('users.id', $user->id);
            })
            ->first();

        if (!$group) {
            return response()->json([
                'message' => 'Class not found or you are not enrolled in this class.',
            ], 404);
        }

        $assignments = Assessment::query()
            ->whereHas('groups', function ($query) use ($group) {
                $query->where('groups.id', $group->id);
            })
            ->with([
                'creator:id,name',
            ])
            ->with([
                'studentAssessments' => function ($query) use ($user) {
                    $query->where('student_id', $user->id);
                },
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($assessment) {
                $assessment->student_assessment =
                    $assessment->studentAssessments->first();

                unset($assessment->studentAssessments);

                return $assessment;
            });

        return response()->json($assignments);
    }
}

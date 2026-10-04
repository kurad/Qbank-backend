<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Models\GradeSubject;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    public function index()
    {
        $user = auth()->user();
abort_unless($user, 401, 'Unauthenticated.');
        $query = Subject::with('gradeLevels');

        if ($user->role !== 'admin') {
            $query->where('school_id', $user->school_id);
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function subjectsByGrade($gradeId)
    {
        $user = auth()->user();

        $query = GradeSubject::with([
            'subject',
            'gradeLevel',
            'topics.questions',
            'units',
        ])->where('grade_level_id', $gradeId);

        if ($user->role === 'admin') {
            $query->where('school_id', $user->school_id);
        } elseif ($user->role === 'teacher') {
            $query->where('school_id', $user->school_id)
                ->where('teacher_id', $user->id);
        } else {
            $query->whereRaw('1 = 0');
        }

        $gradeSubjects = $query->get();

        return response()->json(
            $gradeSubjects->map(function ($gs) {
                return [
                    'id' => $gs->subject->id,
                    'name' => $gs->subject->name,
                    'grade_subject_id' => $gs->id,
                    'grade_level_id' => $gs->gradeLevel->id,
                    'grade_level' => $gs->gradeLevel->grade_name,

                    'units_count' => $gs->units->count(),

                    'units' => $gs->units->map(function ($unit) {
                        return [
                            'id' => $unit->id,
                            'name' => $unit->name,
                            'description' => $unit->description,
                            'order' => $unit->order,
                            'status' => $unit->status,
                        ];
                    })->values(),

                    'topics_count' => $gs->topics->count(),

                    'questions_count' => $gs->topics->sum(
                        fn ($topic) => $topic->questions->count()
                    ),
                ];
            })
        );
    }

    public function createSubject(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'grade_levels' => 'required|array',
            'grade_levels.*' => 'integer|exists:grade_levels,id',
            'name' => 'required|string|max:255',
        ]);

        // Prevent duplicate subject names within the same school
        $existingSubject = Subject::where('school_id', $user->school_id)
            ->whereRaw('LOWER(name) = ?', [strtolower($validated['name'])])
            ->first();

        if ($existingSubject) {
            return response()->json([
                'message' => 'This subject already exists in your school.',
                'data' => $existingSubject,
            ], 409);
        }

        $subject = Subject::create([
            'school_id' => $user->school_id,
            'created_by' => $user->id,
            'name' => $validated['name'],
        ]);

        foreach ($validated['grade_levels'] as $gradeLevelId) {
            GradeSubject::firstOrCreate(
                [
                    'school_id' => $user->school_id,
                    'grade_level_id' => $gradeLevelId,
                    'subject_id' => $subject->id,
                ],
                [
                    // Admin-created teaching areas remain unassigned until an
                    // administrator explicitly assigns a teacher.
                    'teacher_id' => $user->role === 'teacher'
                        ? $user->id
                        : null,
                ]
            );
        }

        return response()->json([
            'message' => 'Subject created successfully.',
            'data' => $subject->load('gradeLevels'),
        ], 201);
    }

    public function searchSubjects(Request $request)
    {
        $user = auth()->user();
        $search = $request->input('search', '');

        $query = Subject::query()
            ->when($search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->with(['gradeSubjects.gradeLevel'])
            ->orderBy('name')
            ->limit(10);

        if ($user->role !== 'admin') {
            $query->where('school_id', $user->school_id);
        }

        $subjects = $query->get()->map(function ($subject) {
            $grades = $subject->gradeSubjects
                ->map(fn ($gs) => $gs->gradeLevel->grade_name ?? null)
                ->filter()
                ->unique()
                ->values();

            return [
                'id' => $subject->id,
                'name' => $subject->name,
                'grade_levels' => $grades->implode(', ') ?: 'All Levels',
            ];
        });

        return response()->json($subjects);
    }

    public function store(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $existing = Subject::where('school_id', $user->school_id)
            ->whereRaw('LOWER(name) = ?', [strtolower($validated['name'])])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'This subject already exists in your school.',
                'data' => $existing,
            ], 409);
        }

        $subject = Subject::create([
            'school_id' => $user->school_id,
            'created_by' => $user->id,
            'name' => $validated['name'],
        ]);

        return response()->json($subject, 201);
    }

    public function update(Request $request, $id)
    {
        $user = auth()->user();

        $subject = Subject::find($id);

        if (!$subject) {
            return response()->json([
                'error' => 'Subject not found',
            ], 404);
        }

        abort_unless(
            $user->role === 'admin' ||
            (
                (int) $subject->school_id === (int) $user->school_id &&
                (int) $subject->created_by === (int) $user->id
            ),
            403
        );

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'grade_levels' => 'required|array',
            'grade_levels.*' => 'integer|exists:grade_levels,id',
        ]);

        $duplicate = Subject::where('school_id', $subject->school_id)
            ->where('id', '!=', $subject->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($validated['name'])])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Another subject with this name already exists in your school.',
            ], 409);
        }

        $subject->update([
            'name' => $validated['name'],
        ]);

        /*
         * Important:
         * Do not use gradeLevels()->sync() here anymore because
         * GradeSubject is now teacher/school-specific.
         */
        foreach ($validated['grade_levels'] as $gradeLevelId) {
            $gradeSubject = GradeSubject::firstOrCreate(
                [
                    'school_id' => $subject->school_id,
                    'grade_level_id' => $gradeLevelId,
                    'subject_id' => $subject->id,
                ],
                [
                    // Do not infer Teaching Area ownership from created_by.
                    // Assignment is managed explicitly through GradeSubjectController.
                    'teacher_id' => null,
                ]
            );

            // A teacher editing a subject they created may retain/create their
            // own Teaching Area. Admin-created subjects remain unassigned.
            if ($user->role === 'teacher' && $gradeSubject->teacher_id === null) {
                $gradeSubject->update(['teacher_id' => $user->id]);
            }
        }

        return response()->json([
            'data' => $subject->load('gradeLevels'),
        ], 200);
    }
}
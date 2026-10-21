<?php

namespace App\Http\Controllers;

use App\Models\GradeSubject;
use App\Models\School;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SchoolController extends Controller
{
    private function requireSystemAdmin(Request $request): User
    {
        $user = $request->user();

        abort_unless(
            $user && method_exists($user, 'isSystemAdmin') && $user->isSystemAdmin(),
            403,
            'Only a System Admin can manage RevisionHub clients.'
        );

        return $user;
    }

    public function index(Request $request)
    {
        $this->requireSystemAdmin($request);

        $perPage = max(5, min((int) $request->input('per_page', 10), 100));
        $search = trim((string) $request->input('search', ''));

        $schools = School::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('school_name', 'like', "%{$search}%")
                        ->orWhere('school_code', 'like', "%{$search}%")
                        ->orWhere('district', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->withCount([
                'users as users_count',
                'teachers as teachers_count' => fn ($q) => $q->where('role', 'teacher'),
                'schoolAdmins as school_admins_count',
            ])
            ->with(['schoolAdmins' => function ($query) {
                $query->select('id', 'name', 'email', 'school_id', 'status', 'created_at')
                    ->orderByDesc('created_at');
            }])
            ->orderBy('school_name')
            ->paginate($perPage);

        return response()->json($schools);
    }

    public function show(Request $request, School $school)
    {
        $this->requireSystemAdmin($request);

        $schoolAdmins = User::query()
            ->where('role', 'admin')
            ->where('admin_scope', 'school')
            ->where('school_id', $school->id)
            ->orderByDesc('created_at')
            ->get([
                'id',
                'name',
                'email',
                'school_id',
                'status',
                'email_verified_at',
                'created_at',
            ]);

        $teachers = User::query()
            ->where('role', 'teacher')
            ->where('school_id', $school->id);

        $students = User::query()
            ->where('role', 'student')
            ->where('school_id', $school->id);

        $topics = Topic::query()
            ->whereHas('gradeSubject', function ($query) use ($school) {
                $query->where('school_id', $school->id);
            });

        return response()->json([
            'school' => $school,
            'summary' => [
                'school_admins' => $schoolAdmins->count(),
                'teachers' => (clone $teachers)->count(),
                'approved_teachers' => (clone $teachers)
                    ->where('teacher_approval_status', 'approved')
                    ->count(),
                'pending_teachers' => (clone $teachers)
                    ->where('teacher_approval_status', 'pending')
                    ->count(),
                'students' => (clone $students)->count(),
                'active_students' => (clone $students)
                    ->where('status', 'active')
                    ->count(),
                'subjects' => Subject::query()
                    ->where('school_id', $school->id)
                    ->count(),
                'teaching_areas' => GradeSubject::query()
                    ->where('school_id', $school->id)
                    ->count(),
                'topics' => (clone $topics)->count(),
                'active_topics' => (clone $topics)
                    ->where('status', 'active')
                    ->count(),
            ],
            'school_admins' => $schoolAdmins,
        ]);
    }

    public function store(Request $request)
    {
        $this->requireSystemAdmin($request);

        $validated = $request->validate([
            'school_name' => 'required|string|max:255',
            'district' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('school_logos', 'public');
            $validated['logo_path'] = $path;
        }

        $school = School::create($validated);

        return response()->json([
            'school' => $school,
            'school_code' => $school->school_code,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $this->requireSystemAdmin($request);

        $school = School::findOrFail($id);
        $validated = $request->validate([
            'school_name' => 'sometimes|required|string|max:255',
            'district' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('school_logos', 'public');
            $validated['logo_path'] = $path;
        }

        $school->update($validated);

        return response()->json([
            'school' => $school,
            'school_code' => $school->school_code,
        ]);
    }

    public function createSchoolAdmin(Request $request, School $school)
    {
        $systemAdmin = $this->requireSystemAdmin($request);

        $existingAdmin = User::query()
            ->where('role', 'admin')
            ->where('admin_scope', 'school')
            ->where('school_id', $school->id)
            ->first();

        if ($existingAdmin) {
            return response()->json([
                'message' => 'This school already has a School Admin.',
                'admin' => $existingAdmin->only([
                    'id', 'name', 'email', 'status', 'school_id', 'created_at',
                ]),
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $admin = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
            'role' => 'admin',
            'admin_scope' => 'school',
            'school_id' => $school->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        return response()->json([
            'message' => 'School Admin created successfully.',
            'admin' => $admin->only([
                'id', 'name', 'email', 'role', 'admin_scope', 'school_id', 'status', 'created_at',
            ]),
            'created_by' => $systemAdmin->id,
        ], 201);
    }
}

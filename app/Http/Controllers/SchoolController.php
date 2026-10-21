<?php

namespace App\Http\Controllers;

use App\Models\GradeSubject;
use App\Models\School;
use App\Models\SchoolAdminInvitation;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\Request;

class SchoolController extends Controller
{
    private function requireSystemAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user && method_exists($user, 'isSystemAdmin') && $user->isSystemAdmin(),
            403,
            'Only a System Admin can manage RevisionHub clients.'
        );
    }

    public function index(Request $request)
    {
        $this->requireSystemAdmin($request);

        $perPage = max(5, min(100, (int) $request->input('per_page', 10)));
        $search = trim((string) $request->input('search', ''));

        $query = School::query()->orderBy('school_name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('school_name', 'like', "%{$search}%")
                    ->orWhere('school_code', 'like', "%{$search}%")
                    ->orWhere('district', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $schools = $query->paginate($perPage);
        $schools->getCollection()->transform(function (School $school) {
            $admin = User::query()
                ->where('role', 'admin')
                ->where('admin_scope', 'school')
                ->where('school_id', $school->id)
                ->where('status', 'active')
                ->first(['id', 'name', 'email', 'status']);

            $pending = SchoolAdminInvitation::query()
                ->where('school_id', $school->id)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->latest()
                ->first(['id', 'name', 'email', 'status', 'expires_at']);

            $school->setAttribute('school_admin', $admin);
            $school->setAttribute('pending_admin_invitation', $pending);

            return $school;
        });

        return response()->json($schools);
    }

    public function show(Request $request, School $school)
    {
        $this->requireSystemAdmin($request);

        $schoolAdmin = User::query()
            ->where('role', 'admin')
            ->where('admin_scope', 'school')
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->first(['id', 'name', 'email', 'status', 'email_verified_at', 'created_at']);

        $pendingInvitation = SchoolAdminInvitation::query()
            ->where('school_id', $school->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        if ($pendingInvitation && $pendingInvitation->expires_at->isPast()) {
            $pendingInvitation->update(['status' => 'expired']);
            $pendingInvitation = null;
        }

        return response()->json([
            'school' => $school,
            'summary' => [
                'teachers' => User::query()->where('role', 'teacher')->where('school_id', $school->id)->count(),
                'students' => User::query()->where('role', 'student')->where('school_id', $school->id)->count(),
                'subjects' => Subject::query()->where('school_id', $school->id)->count(),
                'teaching_areas' => GradeSubject::query()->where('school_id', $school->id)->count(),
                'topics' => Topic::query()->whereHas('gradeSubject', fn ($q) => $q->where('school_id', $school->id))->count(),
            ],
            'school_admin' => $schoolAdmin,
            'pending_admin_invitation' => $pendingInvitation ? [
                'id' => $pendingInvitation->id,
                'name' => $pendingInvitation->name,
                'email' => $pendingInvitation->email,
                'status' => $pendingInvitation->status,
                'expires_at' => $pendingInvitation->expires_at,
                'created_at' => $pendingInvitation->created_at,
            ] : null,
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
}

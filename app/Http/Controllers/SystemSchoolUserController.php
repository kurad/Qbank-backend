<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemSchoolUserController extends Controller
{
    private function requireSystemAdmin(Request $request): void
    {
        abort_unless($request->user() instanceof User && $request->user()->isSystemAdmin(), 403, 'System Admin access required.');
    }

    public function schools(Request $request)
    {
        $this->requireSystemAdmin($request);
        return School::query()->select('id', 'school_name', 'status')->orderBy('school_name')->get();
    }

    public function index(Request $request, School $school)
    {
        $this->requireSystemAdmin($request);
        $data = $request->validate([
            'role' => ['nullable', 'in:teacher,student'],
            'status' => ['nullable', 'in:active,inactive'],
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $query = User::query()->where('school_id', $school->id)->whereIn('role', ['teacher', 'student']);
        if (!empty($data['role'])) $query->where('role', $data['role']);
        if (!empty($data['status'])) $query->where('status', $data['status']);
        if (!empty($data['search'])) {
            $term = trim($data['search']);
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%");
            });
        }
        return response()->json([
            'school' => $school->only('id', 'school_name', 'status'),
            'users' => $query->select('id', 'name', 'email', 'role', 'status', 'school_id', 'teacher_approval_status', 'created_at')
                ->orderBy('name')->paginate((int) ($data['per_page'] ?? 20)),
        ]);
    }

    public function updateStatus(Request $request, School $school, User $user)
    {
        $this->requireSystemAdmin($request);
        abort_unless((int) $user->school_id === (int) $school->id && in_array($user->role, ['teacher', 'student'], true), 404, 'User not found in this school.');
        $data = $request->validate(['status' => ['required', 'in:active,inactive']]);
        DB::transaction(function () use ($user, $data) {
            $user->update(['status' => $data['status']]);
            // Revoke active sessions immediately when disabling access.
            if ($data['status'] === 'inactive') $user->tokens()->delete();
        });
        return response()->json([
            'message' => $data['status'] === 'active' ? 'User access enabled.' : 'User access disabled and existing sessions revoked.',
            'user' => $user->only('id', 'name', 'email', 'role', 'school_id', 'status', 'teacher_approval_status'),
        ]);
    }
}

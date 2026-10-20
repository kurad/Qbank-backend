<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class SchoolStudentController extends Controller
{
    private function requireSchoolAdmin(Request $request): User
    {
        $admin = $request->user();

        abort_unless(
            $admin instanceof User && $admin->isSchoolAdmin(),
            403,
            'Only a School Admin can manage students.'
        );

        abort_unless($admin->school_id, 422, 'This School Admin is not attached to a school.');

        return $admin;
    }

    public function index(Request $request)
    {
        $admin = $this->requireSchoolAdmin($request);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = User::query()
            ->where('role', 'student')
            ->where('school_id', $admin->school_id)
            ->select([
                'id',
                'name',
                'email',
                'school_id',
                'status',
                'email_verified_at',
                'created_at',
            ]);

        if (!empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json(
            $query
                ->orderBy('name')
                ->paginate((int) ($validated['per_page'] ?? 15))
        );
    }

    public function updateStatus(Request $request, User $student)
    {
        $admin = $this->requireSchoolAdmin($request);

        abort_unless(
            $student->role === 'student'
                && (int) $student->school_id === (int) $admin->school_id,
            404,
            'Student not found in your school.'
        );

        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ]);

        $student->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Student status updated successfully.',
            'student' => $student->only([
                'id', 'name', 'email', 'school_id', 'status',
            ]),
        ]);
    }
}

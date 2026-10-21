<?php

namespace App\Http\Controllers;

use App\Models\GradeSubject;
use App\Models\School;
use App\Models\TeacherInvitation;
use App\Models\User;
use App\Notifications\TeacherInvitationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class TeacherAccessController extends Controller
{
    private function requireAdmin(Request $request): User
    {
        $admin = $request->user();
        abort_unless(
            $admin instanceof User && $admin->isSchoolAdmin(),
            403,
            'Teacher management belongs to a School Admin.'
        );
        abort_unless($admin->school_id, 422, 'This School Admin is not attached to a school.');

        return $admin;
    }

    private function isSystemAdmin(User $admin): bool
    {
        // Backward-compatible fallback while existing admins are being migrated.
        return $admin->admin_scope === 'system'
            || ($admin->admin_scope === null && !$admin->school_id);
    }

    private function isSchoolAdmin(User $admin): bool
    {
        return $admin->admin_scope === 'school'
            || ($admin->admin_scope === null && (bool) $admin->school_id);
    }

    private function managedSchoolId(Request $request, User $admin, bool $required = true): ?int
    {
        if ($this->isSchoolAdmin($admin)) {
            abort_unless($admin->school_id, 422, 'This school administrator is not attached to a school.');
            return (int) $admin->school_id;
        }

        $schoolId = $request->integer('school_id') ?: null;

        if ($required && !$schoolId) {
            abort(422, 'Select a school first.');
        }

        if ($schoolId) {
            abort_unless(School::whereKey($schoolId)->exists(), 422, 'The selected school does not exist.');
        }

        return $schoolId;
    }

    private function schoolAllowedForAdmin(User $admin, int $schoolId): bool
    {
        return $this->isSystemAdmin($admin)
            || ((int) $admin->school_id === (int) $schoolId);
    }

    private function resolveInvitation(string $token): TeacherInvitation
    {
        $invitation = TeacherInvitation::with(['school', 'previousSchool'])
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();

        if ($invitation->status === 'pending' && $invitation->expires_at->isPast()) {
            $invitation->update(['status' => 'expired']);
        }

        return $invitation->fresh(['school', 'previousSchool']);
    }

    public function context(Request $request)
    {
        $admin = $this->requireAdmin($request);
        $schoolId = $this->managedSchoolId($request, $admin, false);

        $payload = [
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'admin_scope' => $this->isSystemAdmin($admin) ? 'system' : 'school',
                'school_id' => $admin->school_id,
            ],
            'can_select_school' => false,
            'can_transfer_teachers' => false,
            'selected_school' => null,
            'summary' => [
                'teachers' => 0,
                'pending' => 0,
                'open_invitations' => 0,
                'students' => 0,
            ],
        ];

        if (!$schoolId) {
            return response()->json($payload);
        }

        $school = School::findOrFail($schoolId);
        $payload['selected_school'] = $school->only(['id', 'school_name', 'school_code']);
        $payload['summary'] = [
            'teachers' => User::query()
                ->where('role', 'teacher')
                ->where('school_id', $schoolId)
                ->where('teacher_approval_status', 'approved')
                ->count(),
            'pending' => User::query()
                ->where('role', 'teacher')
                ->where('school_id', $schoolId)
                ->where('teacher_approval_status', 'pending')
                ->count(),
            'open_invitations' => TeacherInvitation::query()
                ->where('school_id', $schoolId)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->count(),
            'students' => User::query()
                ->where('role', 'student')
                ->where('school_id', $schoolId)
                ->count(),
        ];

        return response()->json($payload);
    }

    public function teachers(Request $request)
    {
        $admin = $this->requireAdmin($request);
        $schoolId = $this->managedSchoolId($request, $admin);

        $teachers = User::query()
            ->with('school:id,school_name')
            ->withCount('teachingAreas')
            ->where('role', 'teacher')
            ->where('school_id', $schoolId)
            ->where('teacher_approval_status', 'approved')
            ->orderBy('name')
            ->get([
                'id', 'name', 'email', 'school_id', 'status', 'teacher_approval_status',
                'teacher_approved_at', 'email_verified_at', 'created_at',
            ]);

        return response()->json($teachers);
    }

    public function invite(Request $request)
    {
        $admin = $this->requireAdmin($request);

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'school_id' => 'nullable|integer|exists:schools,id',
        ]);

        // School Admins can only invite into their own school.
        // Ignore any school_id supplied by the browser.
        $schoolId = (int) $admin->school_id;

        $email = strtolower(trim($validated['email']));
        $school = School::findOrFail($schoolId);
        $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing && $existing->role !== 'teacher') {
            return response()->json([
                'message' => 'This email already belongs to a non-teacher Revision Hub account.',
            ], 422);
        }

        $isTransfer = $existing
            && $existing->role === 'teacher'
            && $existing->school_id
            && (int) $existing->school_id !== $schoolId;

        if ($existing
            && $existing->role === 'teacher'
            && (int) $existing->school_id === $schoolId
            && $existing->teacher_approval_status === 'approved') {
            return response()->json([
                'message' => 'This teacher already belongs to the selected school.',
            ], 422);
        }

        TeacherInvitation::where('email', $email)
            ->where('school_id', $schoolId)
            ->where('status', 'pending')
            ->update(['status' => 'revoked']);

        $plainToken = Str::random(64);
        $invitation = TeacherInvitation::create([
            'school_id' => $schoolId,
            'email' => $email,
            'token_hash' => hash('sha256', $plainToken),
            'invited_by' => $admin->id,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'previous_school_id' => $isTransfer ? $existing->school_id : null,
        ]);

        $invitation->load('school');
        Notification::route('mail', $email)->notify(new TeacherInvitationNotification($invitation, $plainToken));

        return response()->json([
            'message' => $isTransfer
                ? 'Teacher transfer invitation sent successfully.'
                : 'Teacher invitation sent successfully.',
            'invitation' => $this->invitationPayload($invitation),
            'is_transfer' => (bool) $invitation->previous_school_id,
        ], 201);
    }

    public function invitations(Request $request)
    {
        $admin = $this->requireAdmin($request);
        $schoolId = $this->managedSchoolId($request, $admin);

        $query = TeacherInvitation::with(['school:id,school_name', 'inviter:id,name'])
            ->where('school_id', $schoolId)
            ->latest();

        return response()->json($query->limit(100)->get()->map(fn ($i) => $this->invitationPayload($i)));
    }

    public function resend(Request $request, TeacherInvitation $teacherInvitation)
    {
        $admin = $this->requireAdmin($request);
        abort_unless(
            $this->schoolAllowedForAdmin($admin, (int) $teacherInvitation->school_id),
            403,
            'You cannot resend invitations for another school.'
        );

        if (in_array($teacherInvitation->status, ['accepted', 'revoked'], true)) {
            return response()->json([
                'message' => 'Accepted or revoked invitations cannot be resent.',
            ], 422);
        }

        $plainToken = Str::random(64);

        $teacherInvitation->update([
            'token_hash' => hash('sha256', $plainToken),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'accepted_by' => null,
            'accepted_at' => null,
            'transfer_confirmed' => false,
        ]);

        $teacherInvitation->load(['school', 'inviter']);

        Notification::route('mail', $teacherInvitation->email)
            ->notify(new TeacherInvitationNotification($teacherInvitation, $plainToken));

        return response()->json([
            'message' => 'Teacher invitation resent successfully.',
            'invitation' => $this->invitationPayload($teacherInvitation->fresh()),
        ]);
    }

    public function revoke(Request $request, TeacherInvitation $teacherInvitation)
    {
        $admin = $this->requireAdmin($request);
        abort_unless($this->schoolAllowedForAdmin($admin, (int) $teacherInvitation->school_id), 403);

        if ($teacherInvitation->status !== 'pending') {
            return response()->json(['message' => 'Only pending invitations can be revoked.'], 422);
        }

        $teacherInvitation->update(['status' => 'revoked']);
        return response()->json(['message' => 'Invitation revoked.']);
    }

    public function pendingRequests(Request $request)
    {
        $admin = $this->requireAdmin($request);
        $schoolId = $this->managedSchoolId($request, $admin);

        $query = User::with('school:id,school_name')
            ->where('role', 'teacher')
            ->where('school_id', $schoolId)
            ->where('teacher_approval_status', 'pending')
            ->orderBy('created_at');

        return response()->json($query->get([
            'id', 'name', 'email', 'school_id', 'status', 'teacher_approval_status', 'email_verified_at', 'created_at'
        ]));
    }

    public function approve(Request $request, User $user)
    {
        $admin = $this->requireAdmin($request);
        abort_unless($user->role === 'teacher', 422, 'This account is not a teacher account.');
        abort_unless($user->school_id && $this->schoolAllowedForAdmin($admin, (int) $user->school_id), 403);

        $user->update([
            'teacher_approval_status' => 'approved',
            'teacher_approved_at' => now(),
            'teacher_approved_by' => $admin->id,
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Teacher approved successfully.',
            'user' => $user->fresh('school'),
        ]);
    }

    public function reject(Request $request, User $user)
    {
        $admin = $this->requireAdmin($request);
        abort_unless($user->role === 'teacher', 422, 'This account is not a teacher account.');
        abort_unless($user->school_id && $this->schoolAllowedForAdmin($admin, (int) $user->school_id), 403);

        $user->update([
            'teacher_approval_status' => 'rejected',
            'teacher_approved_at' => null,
            'teacher_approved_by' => $admin->id,
            'status' => 'inactive',
        ]);

        return response()->json(['message' => 'Teacher registration rejected.']);
    }

    public function preview(string $token)
    {
        $invitation = $this->resolveInvitation($token);
        $existing = User::whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->first();

        return response()->json([
            'invitation' => $this->invitationPayload($invitation),
            'usable' => $invitation->isUsable(),
            'existing_account' => (bool) $existing,
            'existing_role' => $existing?->role,
            'requires_transfer' => (bool) ($existing?->role === 'teacher'
                && $existing->school_id
                && (int) $existing->school_id !== (int) $invitation->school_id),
            'current_school' => $existing?->school?->only(['id', 'school_name']),
        ]);
    }

    public function registerFromInvitation(Request $request, string $token)
    {
        $invitation = $this->resolveInvitation($token);
        if (!$invitation->isUsable()) {
            return response()->json(['message' => 'This teacher invitation is no longer valid.'], 410);
        }

        if (User::whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->exists()) {
            return response()->json([
                'message' => 'An account already exists for this email. Please sign in to accept the invitation.',
                'existing_account' => true,
            ], 409);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = DB::transaction(function () use ($validated, $invitation) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => strtolower($invitation->email),
                'password' => Hash::make($validated['password']),
                'role' => 'teacher',
                'school_id' => $invitation->school_id,
                'status' => 'active',
                'teacher_approval_status' => 'approved',
                'teacher_approved_at' => now(),
                'teacher_approved_by' => $invitation->invited_by,
                'email_verified_at' => now(),
            ]);

            $invitation->update([
                'status' => 'accepted',
                'accepted_by' => $user->id,
                'accepted_at' => now(),
            ]);

            return $user;
        });

        return response()->json([
            'message' => 'Teacher account created successfully. You can now sign in.',
            'email' => $user->email,
        ], 201);
    }

    public function acceptExisting(Request $request, string $token)
    {
        $user = $request->user();
        $invitation = $this->resolveInvitation($token);

        if (!$invitation->isUsable()) {
            return response()->json(['message' => 'This teacher invitation is no longer valid.'], 410);
        }
        abort_unless($user && strtolower($user->email) === strtolower($invitation->email), 403,
            'Sign in with the email address that received this invitation.');
        abort_unless($user->role === 'teacher', 422, 'Only a teacher account can accept this invitation.');

        $requiresTransfer = $user->school_id && (int) $user->school_id !== (int) $invitation->school_id;
        if ($requiresTransfer && !$request->boolean('confirm_transfer')) {
            return response()->json([
                'message' => 'Accepting this invitation will transfer your teacher account to the new school.',
                'requires_transfer_confirmation' => true,
                'current_school' => $user->school?->only(['id', 'school_name']),
                'new_school' => $invitation->school?->only(['id', 'school_name']),
            ], 409);
        }

        DB::transaction(function () use ($user, $invitation, $requiresTransfer) {
            $oldSchoolId = $user->school_id;

            if ($requiresTransfer && $oldSchoolId) {
                GradeSubject::query()
                    ->where('teacher_id', $user->id)
                    ->where('school_id', $oldSchoolId)
                    ->update(['teacher_id' => null]);
            }

            $user->update([
                'school_id' => $invitation->school_id,
                'status' => 'active',
                'teacher_approval_status' => 'approved',
                'teacher_approved_at' => now(),
                'teacher_approved_by' => $invitation->invited_by,
            ]);

            $invitation->update([
                'status' => 'accepted',
                'accepted_by' => $user->id,
                'accepted_at' => now(),
                'previous_school_id' => $requiresTransfer ? $oldSchoolId : $invitation->previous_school_id,
                'transfer_confirmed' => $requiresTransfer,
            ]);
        });

        return response()->json([
            'message' => $requiresTransfer
                ? 'Your teacher account has been transferred successfully.'
                : 'Teacher invitation accepted successfully.',
            'user' => $user->fresh('school'),
        ]);
    }

    private function invitationPayload(TeacherInvitation $invitation): array
    {
        $invitation->loadMissing(['school:id,school_name', 'inviter:id,name']);

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'status' => $invitation->status,
            'expires_at' => $invitation->expires_at,
            'accepted_at' => $invitation->accepted_at,
            'transfer_confirmed' => (bool) $invitation->transfer_confirmed,
            'previous_school_id' => $invitation->previous_school_id,
            'school' => $invitation->school?->only(['id', 'school_name']),
            'invited_by' => $invitation->inviter?->only(['id', 'name']),
        ];
    }
}

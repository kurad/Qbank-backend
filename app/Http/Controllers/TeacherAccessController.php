<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\TeacherInvitation;
use App\Models\GradeSubject;
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
        abort_unless($admin && $admin->role === 'admin', 403, 'Only an administrator can manage teacher access.');
        return $admin;
    }

    private function schoolAllowedForAdmin(User $admin, int $schoolId): bool
    {
        return !$admin->school_id || (int) $admin->school_id === (int) $schoolId;
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

    public function invite(Request $request)
    {
        $admin = $this->requireAdmin($request);

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'school_id' => 'nullable|integer|exists:schools,id',
        ]);

        $schoolId = $admin->school_id ?: ($validated['school_id'] ?? null);
        if (!$schoolId) {
            return response()->json(['message' => 'Select the school that is inviting this teacher.'], 422);
        }
        abort_unless($this->schoolAllowedForAdmin($admin, (int) $schoolId), 403, 'You cannot invite teachers to another school.');

        $email = strtolower(trim($validated['email']));
        $school = School::findOrFail($schoolId);
        $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing && $existing->role !== 'teacher') {
            return response()->json([
                'message' => 'This email already belongs to a non-teacher Revision Hub account.',
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
            'previous_school_id' => $existing?->school_id && (int) $existing->school_id !== (int) $schoolId
                ? $existing->school_id
                : null,
        ]);

        $invitation->load('school');
        Notification::route('mail', $email)->notify(new TeacherInvitationNotification($invitation, $plainToken));

        return response()->json([
            'message' => 'Teacher invitation sent successfully.',
            'invitation' => $this->invitationPayload($invitation),
            'is_transfer' => (bool) $invitation->previous_school_id,
        ], 201);
    }

    public function invitations(Request $request)
    {
        $admin = $this->requireAdmin($request);
        $query = TeacherInvitation::with(['school:id,school_name', 'inviter:id,name'])
            ->latest();

        if ($admin->school_id) {
            $query->where('school_id', $admin->school_id);
        }

        return response()->json($query->limit(100)->get()->map(fn ($i) => $this->invitationPayload($i)));
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
        $query = User::with('school:id,school_name')
            ->where('role', 'teacher')
            ->where('teacher_approval_status', 'pending')
            ->orderBy('created_at');

        if ($admin->school_id) {
            $query->where('school_id', $admin->school_id);
        }

        return response()->json($query->get([
            'id', 'name', 'email', 'school_id', 'status', 'teacher_approval_status', 'email_verified_at', 'created_at'
        ]));
    }

    public function approve(Request $request, User $user)
    {
        $admin = $this->requireAdmin($request);
        abort_unless($user->role === 'teacher', 422, 'This account is not a teacher account.');
        abort_unless($this->schoolAllowedForAdmin($admin, (int) $user->school_id), 403);

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
        abort_unless($this->schoolAllowedForAdmin($admin, (int) $user->school_id), 403);

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

            // A teacher belongs to one school at a time. When transferring,
            // release the old school's teaching-area assignments but keep
            // the teaching areas, classes, materials and assessments at that school.
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
            'school' => $invitation->school?->only(['id', 'school_name']),
            'invited_by' => $invitation->inviter?->only(['id', 'name']),
        ];
    }
}

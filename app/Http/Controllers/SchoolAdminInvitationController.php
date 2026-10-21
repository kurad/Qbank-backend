<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\SchoolAdminInvitation;
use App\Models\User;
use App\Notifications\SchoolAdminInvitationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class SchoolAdminInvitationController extends Controller
{
    private function requireSystemAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User && $user->isSystemAdmin(),
            403,
            'Only a System Admin can manage School Admin invitations.'
        );

        return $user;
    }

    private function resolveInvitation(string $token): SchoolAdminInvitation
    {
        $invitation = SchoolAdminInvitation::with(['school', 'inviter'])
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();

        if ($invitation->status === 'pending' && $invitation->expires_at->isPast()) {
            $invitation->update(['status' => 'expired']);
        }

        return $invitation->fresh(['school', 'inviter']);
    }

    private function activeSchoolAdmin(School $school): ?User
    {
        return User::query()
            ->where('role', 'admin')
            ->where('admin_scope', 'school')
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->first();
    }

    public function invite(Request $request, School $school)
    {
        $systemAdmin = $this->requireSystemAdmin($request);

        if ($existingAdmin = $this->activeSchoolAdmin($school)) {
            return response()->json([
                'message' => 'This school already has an active School Admin.',
                'admin' => $existingAdmin->only(['id', 'name', 'email', 'status']),
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));
        $existingUser = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existingUser?->isSystemAdmin()) {
            return response()->json([
                'message' => 'A System Admin account cannot be assigned as a School Admin.',
            ], 422);
        }

        SchoolAdminInvitation::query()
            ->where('school_id', $school->id)
            ->where('status', 'pending')
            ->update(['status' => 'revoked']);

        $plainToken = Str::random(64);
        $invitation = SchoolAdminInvitation::create([
            'school_id' => $school->id,
            'name' => trim($validated['name']),
            'email' => $email,
            'token_hash' => hash('sha256', $plainToken),
            'invited_by' => $systemAdmin->id,
            'status' => 'pending',
            'expires_at' => now()->addHours(72),
        ]);

        $invitation->load('school');
        Notification::route('mail', $email)
            ->notify(new SchoolAdminInvitationNotification($invitation, $plainToken));

        return response()->json([
            'message' => 'School Admin invitation sent successfully.',
            'invitation' => $this->payload($invitation),
        ], 201);
    }

    public function resend(Request $request, School $school, SchoolAdminInvitation $schoolAdminInvitation)
    {
        $this->requireSystemAdmin($request);
        abort_unless((int) $schoolAdminInvitation->school_id === (int) $school->id, 404);

        if ($this->activeSchoolAdmin($school)) {
            return response()->json(['message' => 'This school already has an active School Admin.'], 422);
        }

        if ($schoolAdminInvitation->status === 'accepted') {
            return response()->json(['message' => 'An accepted invitation cannot be resent.'], 422);
        }

        SchoolAdminInvitation::query()
            ->where('school_id', $school->id)
            ->where('status', 'pending')
            ->where('id', '!=', $schoolAdminInvitation->id)
            ->update(['status' => 'revoked']);

        $plainToken = Str::random(64);
        $schoolAdminInvitation->update([
            'token_hash' => hash('sha256', $plainToken),
            'status' => 'pending',
            'expires_at' => now()->addHours(72),
            'accepted_by' => null,
            'accepted_at' => null,
        ]);

        $schoolAdminInvitation->load('school');
        Notification::route('mail', $schoolAdminInvitation->email)
            ->notify(new SchoolAdminInvitationNotification($schoolAdminInvitation, $plainToken));

        return response()->json([
            'message' => 'School Admin invitation resent successfully.',
            'invitation' => $this->payload($schoolAdminInvitation),
        ]);
    }

    public function revoke(Request $request, School $school, SchoolAdminInvitation $schoolAdminInvitation)
    {
        $this->requireSystemAdmin($request);
        abort_unless((int) $schoolAdminInvitation->school_id === (int) $school->id, 404);

        if ($schoolAdminInvitation->status !== 'pending') {
            return response()->json(['message' => 'Only pending invitations can be cancelled.'], 422);
        }

        $schoolAdminInvitation->update(['status' => 'revoked']);

        return response()->json(['message' => 'School Admin invitation cancelled.']);
    }

    public function preview(string $token)
    {
        $invitation = $this->resolveInvitation($token);
        $existing = User::whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->first();

        return response()->json([
            'invitation' => $this->payload($invitation),
            'usable' => $invitation->isUsable(),
            'existing_account' => (bool) $existing,
            'existing_role' => $existing?->role,
            'current_school' => $existing?->school?->only(['id', 'school_name']),
            'requires_reassignment' => (bool) ($existing && (
                $existing->role !== 'admin'
                || $existing->admin_scope !== 'school'
                || (int) $existing->school_id !== (int) $invitation->school_id
            )),
        ]);
    }

    public function registerFromInvitation(Request $request, string $token)
    {
        $invitation = $this->resolveInvitation($token);

        if (!$invitation->isUsable()) {
            return response()->json(['message' => 'This School Admin invitation is no longer valid.'], 410);
        }

        if (User::whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->exists()) {
            return response()->json([
                'message' => 'An account already exists for this email. Please sign in to accept the invitation.',
                'existing_account' => true,
            ], 409);
        }

        if ($this->activeSchoolAdmin($invitation->school)) {
            return response()->json(['message' => 'This school already has an active School Admin.'], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($validated, $invitation) {
            $user = User::create([
                'name' => trim($validated['name']),
                'email' => strtolower($invitation->email),
                'password' => Hash::make($validated['password']),
                'role' => 'admin',
                'admin_scope' => 'school',
                'school_id' => $invitation->school_id,
                'status' => 'active',
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
            'message' => 'School Admin account created successfully. You can now sign in.',
            'email' => $user->email,
        ], 201);
    }

    public function acceptExisting(Request $request, string $token)
    {
        $user = $request->user();
        $invitation = $this->resolveInvitation($token);

        if (!$invitation->isUsable()) {
            return response()->json(['message' => 'This School Admin invitation is no longer valid.'], 410);
        }

        abort_unless($user && strtolower($user->email) === strtolower($invitation->email), 403,
            'Sign in with the email address that received this invitation.');
        abort_if($user->isSystemAdmin(), 422, 'A System Admin account cannot be converted to a School Admin.');

        $existingAdmin = $this->activeSchoolAdmin($invitation->school);
        if ($existingAdmin && $existingAdmin->id !== $user->id) {
            return response()->json(['message' => 'This school already has an active School Admin.'], 422);
        }

        $requiresReassignment = $user->role !== 'admin'
            || $user->admin_scope !== 'school'
            || (int) $user->school_id !== (int) $invitation->school_id;

        if ($requiresReassignment && !$request->boolean('confirm_reassignment')) {
            return response()->json([
                'message' => 'Accepting this invitation will change this account to a School Admin account for the invited school.',
                'requires_reassignment_confirmation' => true,
                'current_role' => $user->role,
                'current_school' => $user->school?->only(['id', 'school_name']),
                'new_school' => $invitation->school?->only(['id', 'school_name']),
            ], 409);
        }

        DB::transaction(function () use ($user, $invitation) {
            $user->update([
                'role' => 'admin',
                'admin_scope' => 'school',
                'school_id' => $invitation->school_id,
                'status' => 'active',
                'teacher_approval_status' => null,
                'teacher_approved_at' => null,
                'teacher_approved_by' => null,
                'email_verified_at' => $user->email_verified_at ?: now(),
            ]);

            $invitation->update([
                'status' => 'accepted',
                'accepted_by' => $user->id,
                'accepted_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'School Admin invitation accepted successfully.',
            'user' => $user->fresh('school'),
        ]);
    }

    private function payload(SchoolAdminInvitation $invitation): array
    {
        $invitation->loadMissing(['school:id,school_name,school_code', 'inviter:id,name']);

        return [
            'id' => $invitation->id,
            'name' => $invitation->name,
            'email' => $invitation->email,
            'status' => $invitation->status,
            'expires_at' => $invitation->expires_at,
            'accepted_at' => $invitation->accepted_at,
            'school' => $invitation->school?->only(['id', 'school_name', 'school_code']),
            'invited_by' => $invitation->inviter?->only(['id', 'name']),
        ];
    }
}

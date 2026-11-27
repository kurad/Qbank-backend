<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user's own profile. Used by every role (student, teacher,
 * admin): it only ever touches the authenticated user, never another id.
 */
class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['user' => $this->payload($request->user())]);
    }

    /**
     * Only safe, self-service fields. Email, role, school and status are not
     * editable here: email is the verified login identity, the others are
     * managed by administrators.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s.]{6,30}$/'],
        ], [
            'phone.regex' => 'Enter a valid phone number, for example +250 788 000 000.',
        ]);

        $user->forceFill([
            'name' => trim(strip_tags($data['name'])),
            'phone' => isset($data['phone']) && trim($data['phone']) !== '' ? trim($data['phone']) : null,
        ])->save();

        return response()->json([
            'message' => 'Your profile has been updated.',
            'user' => $this->payload($user->fresh()),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::min(8), 'confirmed'],
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Your current password is not correct.',
            ]);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Choose a password that is different from your current one.',
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Sign out every other device; keep the one being used right now.
        $current = $user->currentAccessToken();
        $currentId = $current && isset($current->id) ? $current->id : null;
        $user->tokens()
            ->when($currentId, fn ($query) => $query->where('id', '!=', $currentId))
            ->delete();

        return response()->json([
            'message' => 'Your password has been changed. Other devices were signed out.',
        ]);
    }

    protected function payload(User $user): array
    {
        $user->loadMissing('school');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'admin_scope' => $user->admin_scope,
            'school_id' => $user->school_id,
            'school_name' => $user->school?->school_name ?? $user->school?->name,
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }
}

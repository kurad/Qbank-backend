<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

/**
 * Forgotten-password flow for every role. Public routes.
 */
class PasswordResetController extends Controller
{
    private const GENERIC = 'If an account exists for that email, a password reset link has been sent. Check your inbox and spam folder.';

    public function sendLink(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->first();

        // The answer is identical whether or not the account exists, so this
        // cannot be used to discover which emails are registered.
        if ($user && $user->status === 'active') {
            try {
                Password::sendResetLink(['email' => $user->email]);
            } catch (Throwable $e) {
                Log::warning('Password reset email could not be sent.', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['message' => self::GENERIC]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', PasswordRule::min(8), 'confirmed'],
        ]);

        $status = Password::reset(
            [
                'email' => $data['email'],
                'password' => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $data['token'],
            ],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])
                    ->setRememberToken(Str::random(60));
                $user->save();

                // A reset must end every existing session.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This reset link is invalid or has expired. Please request a new one.',
            ], 422);
        }

        return response()->json([
            'message' => 'Your password has been reset. You can now sign in.',
        ]);
    }
}

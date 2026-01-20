<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\School;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    // Admin: Update user
    public function updateUser(Request $request, $id)
    {

        $user = User::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $id,
            'role' => 'sometimes|in:student,teacher,admin',
            'school_id' => 'sometimes|nullable|exists:schools,id',
            'status' => 'sometimes|in:active,inactive',
            'password' => 'sometimes|string|min:8',
        ]);
        if (isset($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        }
        $user->update($validated);
        return response()->json(['user' => $user]);
    }

    // Admin: Delete user
    public function deleteUser(Request $request, $id)
    {
        $admin = $request->user();
        if (!$admin || $admin->role !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $user = User::findOrFail($id);
        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }

    public function index()
    {
        $users = User::paginate(10);
        return response()->json($users);
    }
    // User registration
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:student,teacher',
            'school_code' => 'required_if:role,student|exists:schools,school_code',
        ]);

        $schoolId = null;
        if ($validated['role'] === 'student') {
            $school = School::where('school_code', $validated['school_code'])->first();
            $schoolId = $school->id;
        }
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'role' => $validated['role'],
            'school_id' => $schoolId,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;
        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    // User login
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!auth()->attempt($credentials)) {
            Log::warning('Failed login attempt for email: ' . $credentials['email']);
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = auth()->user();

        // Only allow users with active status to log in
        if ($user->status !== 'active') {
            Auth::logout();
            return response()->json(['message' => 'Your account is not active'], 403);
        }
        $ttlMinutes = 60;

        // Create a token with expiry time of 1 hour
        $plainTextToken = $user->createToken('auth_token')->plainTextToken;


        return response()->json([
            'user' => $user,
            'token' => $plainTextToken,
            'user_role' => $user->role,
            'expires_at' => now()->addMinutes($ttlMinutes)->toDateTimeString(),
        ]);
    }
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            $user = User::where('email', $googleUser->email)
                ->orWhere('google_id', $googleUser->id)
                ->first();
            if (!$user) {
                $user = User::create([
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'password' => Hash::make(Str::random(24)),
                    'role' => 'teacher',
                    'email_verified_at' => now(),
                    'google_id' => $googleUser->id,
                ]);
                //$user->profile()->create();
            } elseif (!$user->google_id) {
                $user->update(['google_id' => $googleUser->id]);
            }
            // Auth::login($user);
            $token = $user->createToken('auth_token')->plainTextToken;
            // $user->load('profile');
            return redirect(env('FRONTEND_URL') . '/login/callback?' . http_build_query([
                'token' => $token,
                'user' => $user,
                'user_role' => $user->role,
            ]));
        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL') . '/login?error=' . urlencode($e->getMessage()));
        }
    }
    public function getStudents()
    {
        $gradeLevelId = request('grade_level_id');
        $query = User::where('role', 'student');
        if ($gradeLevelId) {
            $query->where('grade_level_id', $gradeLevelId);
        }
        $students = $query->get();
        return response()->json($students);
    }
    public function logout(Request $request)
    {
        auth()->user()->tokens()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function refreshToken(Request $request)
    {
        $plainToken = $request->bearerToken();
        if (!$plainToken) {
            return response()->json(['message' => 'Authentication token is missing.'], 401);
        }

        $token = PersonalAccessToken::findToken($plainToken);
        if (!$token) {
            return response()->json(['message' => 'Invalid or Unknown token.'], 401);
        }

        $user = $token->tokenable;

        $ttlMinutes = 60;
        $gracePeriodMinutes = 30;

        $expiredAt = $token->created_at->copy()->addMinutes($ttlMinutes);

        // allow refresh until expiredAt + grace
        if (now()->greaterThan($expiredAt->copy()->addMinutes($gracePeriodMinutes))) {
            $token->delete(); // optional cleanup
            return response()->json([
                'message' => 'Token refresh period has expired. Please log in again.'
            ], 401);
        }

        // revoke old token
        $token->delete();

        // issue new token
        $newPlainToken = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Token refreshed successfully.',
            'token' => $newPlainToken,
            'user' => $user,
            'user_role' => $user->role,
            'expires_at' => now()->addMinutes($ttlMinutes)->toDateTimeString(),
        ]);
    }
}

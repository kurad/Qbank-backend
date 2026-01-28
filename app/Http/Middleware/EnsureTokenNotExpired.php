<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureTokenNotExpired
{
    public function handle(Request $request, Closure $next)
    {
        $plainToken = $request->bearerToken();
        if (!$plainToken) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = PersonalAccessToken::findToken($plainToken);
        if (!$token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $ttlMinutes = 60;

        $expiredAt = $token->created_at->copy()->addMinutes($ttlMinutes);

        if (now()->greaterThan($expiredAt)) {
            // Optional: delete the token once expired to keep table clean
            $token->delete();

            return response()->json([
                'message' => 'Token expired.',
                'code' => 'TOKEN_EXPIRED',
            ], 401);
        }

        return $next($request);
    }
}

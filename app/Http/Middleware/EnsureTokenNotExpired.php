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

        $user = $token->tokenable;
        if (!$user || $user->status !== 'active') {
            $token->delete();
            return response()->json(['message' => 'Account access is disabled.'], 403);
        }

        $ttlMinutes = 60;
        $gracePeriodMinutes = 30;

        $expiredAt = $token->created_at->copy()->addMinutes($ttlMinutes);

        if (now()->greaterThan($expiredAt)) {
            // Keep the token during the refresh grace period so the public
            // /refresh-token endpoint can exchange it for a fresh token.
            if (now()->greaterThan($expiredAt->copy()->addMinutes($gracePeriodMinutes))) {
                $token->delete();
            }

            return response()->json([
                'message' => 'Token expired.',
                'code' => 'TOKEN_EXPIRED',
                'expires_at' => $expiredAt->toIso8601String(),
            ], 401);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // For API requests, do not redirect to a web login route. Return null to produce 401 JSON.
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }
        return route('login');
    }
}

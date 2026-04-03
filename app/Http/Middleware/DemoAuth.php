<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * DEMO MODE — bypasses Sanctum authentication.
 * All requests are automatically authenticated as the first user in the DB.
 *
 * To restore real auth, revert routes/api.php:
 *   Change 'demo.auth'  →  'auth:sanctum'
 *   and remove this middleware from bootstrap/app.php
 */
class DemoAuth
{
    public function handle(Request $request, Closure $next)
    {
        $user = User::first();

        if ($user) {
            Auth::login($user);
        }

        return $next($request);
    }
}

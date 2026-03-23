<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
   public function register(Request $request): JsonResponse
{
    $validated = $request->validate([
        'name'     => 'required|string|max:255',
        'email'    => 'required|email|unique:users',
        'password' => 'required|min:8|confirmed',
    ]);

    $user = User::create([
        'name'     => $validated['name'],
        'email'    => $validated['email'],
        'password' => Hash::make($validated['password']),
    ]);

    return response()->json([
        'user'  => [
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'login_count'      => 0,
            'logins_remaining' => 5,
            'is_paid'          => false,
        ],
        'token' => $user->createToken('api-token')->plainTextToken,
    ], 201);
}

    public function login(Request $request): JsonResponse
{
    $request->validate([
        'email'    => 'required|email',
        'password' => 'required',
    ]);

    if (!Auth::attempt($request->only('email', 'password'))) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    $user = Auth::user();

    // Check login limit for free users (skip in development)
    if (app('env') !== 'local' && !$user->is_paid && $user->login_count >= 5) {
        return response()->json([
            'message' => 'Free trial expired. You have used all 5 free logins.',
            'trial_expired' => true,
        ], 403);
    }

    // Increment login count
    $user->increment('login_count');

    // Revoke old tokens
    $user->tokens()->delete();

    $loginsRemaining = $user->is_paid ? null : max(0, 5 - $user->login_count);

    return response()->json([
        'user'  => [
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'login_count'      => $user->login_count,
            'logins_remaining' => $loginsRemaining,
            'is_paid'          => $user->is_paid,
        ],
        'token' => $user->createToken('api-token')->plainTextToken,
    ]);
}
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }
}
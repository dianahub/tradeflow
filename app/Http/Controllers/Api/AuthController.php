<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

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

        $user->sendEmailVerificationNotification();

        return response()->json([
            'needs_verification' => true,
            'message' => 'Account created. Please check your email and click the verification link before signing in.',
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

        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'needs_verification' => true,
                'message' => 'Please verify your email before signing in. Check your inbox for the verification link.',
            ], 403);
        }

        // Check login limit for free users (skip in development)
        if (app('env') !== 'local' && !$user->is_paid && $user->login_count >= 5) {
            return response()->json([
                'message' => 'Free trial expired. You have used all 5 free logins.',
                'trial_expired' => true,
            ], 403);
        }

        $user->increment('login_count');
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

    public function verifyEmail(Request $request, $id, $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (!hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(400, 'Invalid verification link.');
        }

        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

        if ($user->hasVerifiedEmail()) {
            return redirect($frontendUrl . '?verified=already');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return redirect($frontendUrl . '?verified=1');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user || $user->hasVerifiedEmail()) {
            // Return success regardless to avoid email enumeration
            return response()->json(['message' => 'If that account exists and is unverified, a new link has been sent.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email resent. Please check your inbox.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        Password::sendResetLink($request->only('email'));

        // Always return success to avoid email enumeration
        return response()->json(['message' => 'If that email exists, a password reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'                 => 'required',
            'email'                 => 'required|email',
            'password'              => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset successfully. You can now sign in.']);
        }

        return response()->json(['message' => __($status)], 422);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }
}
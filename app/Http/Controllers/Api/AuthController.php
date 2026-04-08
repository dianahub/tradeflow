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
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'password'          => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ]);

        $user->tokens()->delete();

        return response()->json([
            'user'  => [
                'id'               => $user->id,
                'name'             => $user->name,
                'email'            => $user->email,
                'login_count'      => $user->login_count,
                'logins_remaining' => null,
                'is_paid'          => $user->is_paid,
                'is_admin'         => $user->is_admin,
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

        $user->increment('login_count');
        $user->tokens()->delete();

        $loginsRemaining = null;

        return response()->json([
            'user'  => [
                'id'               => $user->id,
                'name'             => $user->name,
                'email'            => $user->email,
                'login_count'      => $user->login_count,
                'logins_remaining' => $loginsRemaining,
                'is_paid'          => $user->is_paid,
                'is_admin'         => $user->is_admin,
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
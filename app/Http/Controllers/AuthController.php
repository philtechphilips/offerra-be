<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\GenericNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Events\Registered;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
            ]);

            $freePlan = \App\Models\Plan::where('price_usd', 0)->first();

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => User::ROLE_USER,
                'plan_id' => $freePlan?->id,
                'credits' => $freePlan?->credits ?? 0,
                'welcome_email_sent_at' => now(),
            ]);

            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\WelcomeMail($user));

            $user->notify(new GenericNotification(
                'Welcome to Offerra!',
                'We are excited to help you land your dream job. Start by uploading your CV.',
                'info',
                '/dashboard'
            ));

            event(new Registered($user));

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
            ]);
        }, 'AuthController@register');
    }

    public function login(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            // Lock the account after 5 *failed* attempts within 15 minutes,
            // keyed by the submitted email and the client's IP address.
            $throttleKey = 'login:' . strtolower($request->input('email')) . '|' . $request->ip();
            $maxAttempts = 5;
            $decaySeconds = 60 * 15;

            if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                throw ValidationException::withMessages([
                    'email' => ["Too many login attempts. Please try again in {$seconds} seconds."],
                ])->status(429);
            }

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                RateLimiter::hit($throttleKey, $decaySeconds);
                throw ValidationException::withMessages([
                    'email' => ['The provided credentials are incorrect.'],
                ]);
            }

            RateLimiter::clear($throttleKey);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
            ]);
        }, 'AuthController@login');
    }

    public function logout(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Successfully logged out'
            ]);
        }, 'AuthController@logout');
    }

    public function forgotPassword(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate(['email' => 'required|email']);

            // Attempt to send the reset link, but never reveal whether the email
            // exists in the system. This avoids account-enumeration leaks.
            \Illuminate\Support\Facades\Password::sendResetLink(
                $request->only('email')
            );

            return response()->json([
                'message' => "If we find an account with that email, we'll send a reset link shortly.",
            ]);
        }, 'AuthController@forgotPassword');
    }

    public function resetPassword(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate([
                'token' => 'required',
                'email' => 'required|email',
                'password' => 'required|min:8|confirmed',
            ]);

            $status = \Illuminate\Support\Facades\Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    $user->forceFill([
                        'password' => \Illuminate\Support\Facades\Hash::make($password)
                    ])->setRememberToken(\Illuminate\Support\Str::random(60));

                    $user->save();

                    event(new \Illuminate\Auth\Events\PasswordReset($user));
                }
            );

            return $status === \Illuminate\Support\Facades\Password::PASSWORD_RESET
                ? response()->json(['message' => __($status)])
                : response()->json(['message' => __($status)], 400);
        }, 'AuthController@resetPassword');
    }
}

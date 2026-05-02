<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\GenericNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => ['The provided credentials are incorrect.'],
                ]);
            }

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

            $status = \Illuminate\Support\Facades\Password::sendResetLink(
                $request->only('email')
            );

            return $status === \Illuminate\Support\Facades\Password::RESET_LINK_SENT
                ? response()->json(['message' => __($status)])
                : response()->json(['message' => __($status)], 400);
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

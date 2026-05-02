<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UserController extends Controller
{
    public function transactions(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $transactions = $request->user()->transactions()->with('plan')->latest()->get();
            return response()->json(['transactions' => $transactions]);
        }, 'UserController@transactions');
    }

    public function creditLogs(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $logs = $request->user()->creditLogs()->latest()->get();
            return response()->json(['logs' => $logs]);
        }, 'UserController@creditLogs');
    }

    public function updateSettings(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $user = $request->user();

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'professional_headline' => 'nullable|string|max:255',
                'ai_tone' => 'sometimes|required|string|in:Professional,Aggressive,Creative,Concise',
                'notifications_enabled' => 'sometimes|boolean',
            ]);

            $user->update($validated);

            return response()->json([
                'message' => 'Settings updated successfully',
                'user' => $user->fresh(['plan', 'googleAccount'])
            ]);
        }, 'UserController@updateSettings');
    }

    public function deleteAccount(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $user = $request->user();
            $user->delete();

            return response()->json(['message' => 'Account deleted successfully']);
        }, 'UserController@deleteAccount');
    }
}

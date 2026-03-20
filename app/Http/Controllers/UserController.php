<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function transactions(Request $request)
    {
        $transactions = $request->user()->transactions()->with('plan')->latest()->get();
        return response()->json(['transactions' => $transactions]);
    }

    public function creditLogs(Request $request)
    {
        $logs = $request->user()->creditLogs()->latest()->get();
        return response()->json(['logs' => $logs]);
    }
    public function updateSettings(Request $request)
    {
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
    }

    public function deleteAccount(Request $request)
    {
        $user = $request->user();
        
        // Final sanity check or reason collection can go here.
        $user->delete();

        return response()->json(['message' => 'Account deleted successfully']);
    }
}

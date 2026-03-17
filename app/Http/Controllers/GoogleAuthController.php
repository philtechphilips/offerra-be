<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Google\Client;
use Google\Service\Gmail;
use Google\Service\Oauth2;
use Illuminate\Support\Facades\Auth;
use App\Models\GoogleAccount;
use Illuminate\Support\Facades\Log;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        $client = new Client();
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect'));
        $client->addScope(Gmail::GMAIL_READONLY);
        $client->addScope(Oauth2::USERINFO_EMAIL);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');

        // Since we are using Sanctum (token-based), we need a way to identify the user
        // when they return from the Google redirect. We'll use the 'state' parameter.
        $client->setState(Auth::id());

        return response()->json([
            'url' => $client->createAuthUrl()
        ]);
    }

    public function callback(Request $request)
    {
        $code = $request->input('code');

        if (!$code) {
             Log::error('Google Auth Sync Callback: No code provided');
            return redirect(config('app.frontend_url') . '/dashboard/profile?error=no_code');
        }

        $client = new Client();
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect'));

        try {
            $token = $client->fetchAccessTokenWithAuthCode($code);

            if (isset($token['error'])) {
                Log::error('Google Token Fetch Error: ' . json_encode($token));
                return redirect(config('app.frontend_url') . '/dashboard/profile?error=token_failed');
            }

            $client->setAccessToken($token);

            $oauth2 = new Oauth2($client);
            $googleUser = $oauth2->userinfo->get();

            // Try to get user from state if Auth::user() is null (common in Sanctum/API redirects)
            $userId = $request->input('state');
            $user = Auth::user() ?: \App\Models\User::find($userId);

            if (!$user) {
                Log::error('Google Sync Callback: User not authenticated. State: ' . $userId);
                return redirect(config('app.frontend_url') . '/login?error=session_expired');
            }

            $user->googleAccount()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'email' => $googleUser->email,
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? $user->googleAccount?->refresh_token,
                    'token_expires_at' => now()->addSeconds($token['expires_in']),
                    'status' => 'connected',
                ]
            );

            return redirect(config('app.frontend_url') . '/dashboard/profile?success=google_connected');

        } catch (\Exception $e) {
            Log::error('Google Sync Callback Error: ' . $e->getMessage());
            return redirect(config('app.frontend_url') . '/dashboard/profile?error=exception');
        }
    }

    public function sync(Request $request)
    {
        $account = Auth::user()->googleAccount;
        if (!$account) {
            return response()->json(['error' => 'No Google account connected'], 404);
        }

        \App\Jobs\SyncGmailJob::dispatch($account);

        return response()->json([
            'message' => 'Sync started in background', 
            'last_synced_at' => $account->fresh()->last_synced_at
        ]);
    }
}

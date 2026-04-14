<?php

namespace App\Services;

use Google\Client;
use Google\Service\Gmail;
use App\Models\GoogleAccount;
use App\Models\JobApplication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GmailSyncService
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client();
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setRedirectUri(config('services.google.redirect'));
    }

    public function sync(GoogleAccount $account, $timeFilter = null)
    {
        $this->client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $account->token_expires_at ? $account->token_expires_at->diffInSeconds(now()) : 3600,
        ]);

        if ($this->client->isAccessTokenExpired()) {
            if ($account->refresh_token) {
                try {
                    $newToken = $this->client->fetchAccessTokenWithRefreshToken($account->refresh_token);
                    if (isset($newToken['error'])) {
                        Log::error('Gmail Sync: Refresh token failed for ' . $account->email . ': ' . json_encode($newToken));
                        
                        // Mark account as disconnected so frontend can prompt reconnect
                        if ($newToken['error'] === 'invalid_grant') {
                            $account->update(['status' => 'disconnected']);
                            Log::warning('Gmail Sync: Marked account ' . $account->email . ' as disconnected. User must reconnect.');
                        }
                        return;
                    }
                    $account->update([
                        'access_token' => $newToken['access_token'],
                        'token_expires_at' => now()->addSeconds($newToken['expires_in']),
                        'status' => 'connected',
                    ]);
                    $this->client->setAccessToken($newToken);
                } catch (\Exception $e) {
                    Log::error('Gmail Sync: Error refreshing token for ' . $account->email . ': ' . $e->getMessage());
                    return;
                }
            } else {
                Log::error('Gmail Sync: Token expired and no refresh token for ' . $account->email);
                return;
            }
        }

        $service = new Gmail($this->client);
        $userEmail = 'me';

        try {
            $q = '(application OR applied OR interview OR offer OR job OR vacancy OR remote OR developer OR software OR engineer OR engineering OR tech OR hire OR hiring OR recruit OR recruiting OR recruiter OR contract OR freelance)';
            if ($timeFilter) {
                $q .= " newer_than:{$timeFilter}";
            }

            // Fetch recent messages with keywords
            $messagesResponse = $service->users_messages->listUsersMessages($userEmail, [
                'maxResults' => 500,
                'q' => $q
            ]);

            $messagesList = $messagesResponse->getMessages();
            if (!$messagesList) {
                Log::info('Gmail Sync: No matching messages found for ' . $account->email);
                return;
            }

            foreach ($messagesList as $messageItem) {
                \App\Jobs\ProcessGmailMessageJob::dispatch($account, $messageItem->getId());
            }

            $account->update(['last_synced_at' => now()]);
        } catch (\Exception $e) {
             Log::error('Gmail Sync: Error fetching messages for ' . $account->email . ': ' . $e->getMessage());
             throw $e;
        }
    }

    public function processSingleMessage(GoogleAccount $account, string $messageId)
    {
        $this->client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $account->token_expires_at ? $account->token_expires_at->diffInSeconds(now()) : 3600,
        ]);

        $service = new Gmail($this->client);
        $msg = $service->users_messages->get('me', $messageId);
        $this->processMessage($account->user, $msg);
    }

    protected function processMessage($user, $message)
    {
        $payload = $message->getPayload();
        $headers = $payload->getHeaders();
        $subject = '';
        foreach ($headers as $header) {
            if ($header->getName() === 'Subject') {
                $subject = $header->getValue();
                break;
            }
        }

        $body = $this->getMessageBody($payload);
        if ($body) {
            $this->detectJobFromEmail($user, $subject, $body, $message->getId());
        }
    }

    protected function getMessageBody($payload)
    {
        $body = '';
        if ($payload->getBody() && $payload->getBody()->getData()) {
            $body = $this->decodeBody($payload->getBody()->getData());
        } else {
            $parts = $payload->getParts();
            if ($parts) {
                foreach ($parts as $part) {
                    if ($part->getMimeType() === 'text/plain') {
                        $body .= $this->decodeBody($part->getBody()->getData());
                    } elseif ($part->getMimeType() === 'text/html') {
                        $body .= strip_tags($this->decodeBody($part->getBody()->getData()));
                    }
                }
            }
        }
        return $body;
    }

    protected function decodeBody($data)
    {
        if (!$data) return '';
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    protected function detectJobFromEmail($user, $subject, $body, $messageId)
    {
        $apiKey = config('services.deepseek.api_key');
        \Illuminate\Support\Facades\Log::info('DeepSeek API Key check', ['key_status' => $apiKey ? 'Found (' . strlen($apiKey) . ' chars)' : 'NOT FOUND']);
        if (!$apiKey) return;

        $bodySnippet = substr($body, 0, 4000);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer $apiKey",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.deepseek.com/chat/completions', [
                'model' => 'deepseek-chat',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an AI that tracks job application progress from emails. Your ONLY job is to detect emails where the USER has actively applied to a job or received a follow-up on an application they submitted.

CLASSIFY as is_application_email: true ONLY for:
- Application confirmation/receipt emails ("Thank you for applying", "We received your application")
- Interview invitations or scheduling requests from the company
- Job offer or contract proposals from the company
- Rejection emails from the company about a specific application the user submitted

CLASSIFY as is_application_email: false for:
- Job alert emails (LinkedIn, Indeed, Upwork, etc.) showing available jobs
- Recruiter cold outreach ("Are you open to new opportunities?")
- Job board newsletters or recommendations
- Emails about a job listing being closed/removed/expired
- Any email where it is unclear if the user actually applied

Statuses: "applied" (confirmation of receipt), "interview" (screening/interview/test request), "offer" (job offer), "rejected" (explicit rejection from company).
Do NOT use "rejected" for expired/removed job postings.
Respond ONLY in JSON.'],
                    ['role' => 'user', 'content' => "Subject: {$subject}\nBody: {$bodySnippet}\n\nRespond with JSON: { \"is_application_email\": true/false, \"details\": { \"company\": \"...\", \"title\": \"...\", \"status\": \"applied/interview/offer/rejected\" } }"]
                ],
                'response_format' => ['type' => 'json_object']
            ]);

            Log::info('Gmail Sync: Processing — ' . $subject);

            if ($response->successful()) {
                $aiData = json_decode($response->json('choices.0.message.content'), true);

                if (empty($aiData['is_application_email'])) {
                    Log::info("Gmail Sync: Skipped (not an application email) — {$subject}");
                    return;
                }

                $details = $aiData['details'] ?? [];
                $company = trim($details['company'] ?? 'Unknown');
                $title   = trim($details['title'] ?? 'Unknown');
                $newStatus = strtolower($details['status'] ?? '');

                // Normalise status
                if (str_contains($newStatus, 'reject') || str_contains($newStatus, 'not proceed')) {
                    $newStatus = 'rejected';
                }
                if (!in_array($newStatus, ['applied', 'interview', 'offer', 'rejected'])) {
                    Log::info("Gmail Sync: Skipped (unrecognised status '{$newStatus}') — {$subject}");
                    return;
                }

                // Try to find an existing application by company (and optionally title)
                $existing = JobApplication::where('user_id', $user->id)
                    ->where('company', 'LIKE', "%{$company}%")
                    ->when($title !== 'Unknown', function ($q) use ($title) {
                        $q->orWhere('title', 'LIKE', "%{$title}%");
                    })
                    ->first();

                if ($existing) {
                    // Only update if the new status is a progression
                    $statusOrder = ['tracking' => 0, 'applied' => 1, 'interview' => 2, 'offer' => 3, 'rejected' => 3];
                    $currentRank = $statusOrder[$existing->status] ?? 0;
                    $newRank     = $statusOrder[$newStatus] ?? 0;

                    if ($newRank >= $currentRank && $existing->status !== $newStatus) {
                        $existing->update(['status' => $newStatus]);
                        Log::info("Gmail Sync: Updated {$existing->company} — {$existing->title} → {$newStatus}");
                    } else {
                        Log::info("Gmail Sync: No update needed for {$existing->company} (current: {$existing->status}, detected: {$newStatus})");
                    }
                } else {
                    // No existing record — create a new one since the user clearly applied
                    $job = JobApplication::create([
                        'user_id'  => $user->id,
                        'title'    => $title,
                        'company'  => $company,
                        'location' => 'Unknown',
                        'type'     => 'Full-time',
                        'status'   => $newStatus,
                    ]);
                    Log::info("Gmail Sync: Auto-created job for {$company} — {$title} with status {$newStatus}");
                }
            }
        } catch (\Exception $e) {
            Log::error('Email detection failed for message ' . $messageId . ': ' . $e->getMessage());
        }
    }
}

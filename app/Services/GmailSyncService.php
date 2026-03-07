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

    public function sync(GoogleAccount $account)
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
                        return;
                    }
                    $account->update([
                        'access_token' => $newToken['access_token'],
                        'token_expires_at' => now()->addSeconds($newToken['expires_in']),
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
            // Fetch recent messages with keywords
            $messagesResponse = $service->users_messages->listUsersMessages($userEmail, [
                'maxResults' => 500,
                'q' => 'subject:(application OR applied OR interview OR offer OR job OR vacancy)'
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
        $apiKey = env('DEEPSEEK_API_KEY');
        if (!$apiKey) return;

        // Truncate body
        $bodySnippet = substr($body, 0, 4000);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer $apiKey",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.deepseek.com/chat/completions', [
                'model' => 'deepseek-chat',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an AI that manages job application status updates. Analyze the email to identify the company name, job title, and the current status based on the communication. 
                    Possible statuses: 
                    - "applied": Confirmation of application receipt.
                    - "interview": Request for a screening, interview, or technical test.
                    - "offer": A job offer or contract proposal.
                    - "rejected": Explicit rejection, "not proceeding", or indication of "no interest" from the employer.
                    Respond ONLY in JSON.'],
                    ['role' => 'user', 'content' => "Subject: {$subject}\nBody: {$bodySnippet}\n\nRespond with JSON: { \"is_job_update\": true, \"details\": { \"company\": \"...\", \"title\": \"...\", \"status\": \"applied/interview/offer/rejected\" } }"]
                ],
                'response_format' => ['type' => 'json_object']
            ]);

            Log::info('Gmail Sync: ' . $subject);

            if ($response->successful()) {
                $aiData = json_decode($response->json('choices.0.message.content'), true);
                if (isset($aiData['is_job_update']) && $aiData['is_job_update']) {
                    $details = $aiData['details'] ?? [];
                    $company = $details['company'] ?? 'Unknown';
                    $title = $details['title'] ?? 'Unknown';

                    // Search for an existing job application to update
                    // We prioritize matching by company name
                    $existing = JobApplication::where('user_id', $user->id)
                        ->where('company', 'LIKE', "%{$company}%")
                        ->first();

                    if ($existing) {
                        $newStatus = strtolower($details['status'] ?? '');
                        
                        // Map potential AI status variations to our DB values
                        if (str_contains($newStatus, 'reject') || str_contains($newStatus, 'interest') || str_contains($newStatus, 'not proceed')) {
                            $newStatus = 'rejected';
                        }

                        // If the status is one of our valid types, update it
                        if (in_array($newStatus, ['applied', 'interview', 'offer', 'rejected'])) {
                             if ($existing->status !== $newStatus) {
                                 $existing->update(['status' => $newStatus]);
                                 Log::info("Gmail Sync: AI detected status change for {$existing->company} to {$newStatus}");
                             }
                        }
                    } else {
                        Log::info("Gmail Sync: Skipping. No existing application found for company identified by AI as: {$company}");
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Email detection failed for message ' . $messageId . ': ' . $e->getMessage());
        }
    }
}

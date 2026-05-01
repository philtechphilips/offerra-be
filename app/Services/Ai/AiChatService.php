<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiChatService
{
    public function isConfigured(): bool
    {
        return (bool) config('services.deepseek.api_key') || (bool) config('services.openai.api_key');
    }

    public function chatJson(array $messages, int $timeout = 60): array
    {
        $errors = [];

        if (config('services.deepseek.api_key')) {
            try {
                $content = $this->callDeepseek($messages, $timeout);
                if ($content !== null) {
                    return [
                        'content' => $content,
                        'provider' => 'deepseek',
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = "deepseek: {$e->getMessage()}";
                Log::warning('DeepSeek request failed, falling back to OpenAI.', ['error' => $e->getMessage()]);
            }
        }

        if (config('services.openai.api_key')) {
            try {
                $content = $this->callOpenAi($messages, $timeout);
                if ($content !== null) {
                    return [
                        'content' => $content,
                        'provider' => 'openai',
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = "openai: {$e->getMessage()}";
                Log::error('OpenAI fallback request failed.', ['error' => $e->getMessage()]);
            }
        }

        throw new \RuntimeException('AI request failed across providers. ' . implode(' | ', $errors));
    }

    protected function callDeepseek(array $messages, int $timeout): ?string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.deepseek.api_key'),
            'Content-Type' => 'application/json',
        ])->timeout($timeout)->post('https://api.deepseek.com/chat/completions', [
            'model' => config('services.deepseek.model', 'deepseek-chat'),
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('DeepSeek HTTP ' . $response->status() . ': ' . $response->body());
        }

        return $response->json('choices.0.message.content');
    }

    protected function callOpenAi(array $messages, int $timeout): ?string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.openai.api_key'),
            'Content-Type' => 'application/json',
        ])->timeout($timeout)->post(
            rtrim(config('services.openai.base_url', 'https://api.openai.com/v1'), '/') . '/chat/completions',
            [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => $messages,
                'response_format' => ['type' => 'json_object'],
            ]
        );

        if (!$response->successful()) {
            throw new \RuntimeException('OpenAI HTTP ' . $response->status() . ': ' . $response->body());
        }

        return $response->json('choices.0.message.content');
    }
}

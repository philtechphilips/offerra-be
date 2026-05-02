<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Ai\AiChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PublicProfileController extends Controller
{
    public function __construct(private AiChatService $aiChatService)
    {
    }

    /**
     * Public endpoint — no auth required.
     * Returns everything needed to render the public profile page.
     */
    public function show(string $username)
    {
        return $this->safeCall(function () use ($username) {
            $user = User::where('username', $username)
                ->where('public_profile_enabled', true)
                ->firstOrFail();

            $activeCV = $user->cvs()->where('is_active', true)->latest()->first()
                ?? $user->cvs()->latest()->first();

            $parsedData = $activeCV?->parsed_data ?? [];

            $techStack = $user->jobApplications()
                ->whereNotNull('tech_stack')
                ->get()
                ->flatMap(fn ($j) => $j->tech_stack ?? [])
                ->filter()
                ->countBy()
                ->sortDesc()
                ->keys()
                ->take(20)
                ->values()
                ->toArray();

            return response()->json([
                'user' => [
                    'name'                 => $user->name,
                    'username'             => $user->username,
                    'professional_headline' => $user->professional_headline,
                    'location'             => $user->location,
                    'linkedin_url'         => $user->linkedin_url,
                    'github_url'           => $user->github_url,
                    'twitter_url'          => $user->twitter_url,
                    'portfolio_url'        => $user->portfolio_url,
                    'profile_theme'        => $user->profile_theme ?? 'modern',
                ],
                'cv' => [
                    'summary'        => $parsedData['summary'] ?? $parsedData['about'] ?? null,
                    'skills'         => $parsedData['skills'] ?? [],
                    'work_experience' => $parsedData['work_experience'] ?? [],
                    'education'      => $parsedData['education'] ?? [],
                    'projects'       => $parsedData['projects'] ?? [],
                    'certifications' => $parsedData['certifications'] ?? [],
                ],
                'tech_stack' => $techStack,
            ]);
        }, 'PublicProfileController@show');
    }

    /**
     * Auth-protected — save public profile settings.
     */
    public function updateSettings(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $user = $request->user();

            $validated = $request->validate([
                'username' => [
                    'nullable',
                    'string',
                    'min:3',
                    'max:30',
                    'regex:/^[a-z0-9\-]+$/',
                    Rule::unique('users', 'username')->ignore($user->id),
                ],
                'public_profile_enabled' => 'boolean',
                'location'               => 'nullable|string|max:100',
                'linkedin_url'           => 'nullable|url',
                'github_url'             => 'nullable|url',
                'twitter_url'            => 'nullable|url',
                'portfolio_url'          => 'nullable|url',
                'professional_headline'  => 'nullable|string|max:255',
                'profile_theme'          => 'nullable|string|in:modern,minimalist,bento',
            ]);

            $user->update($validated);

            return response()->json([
                'message' => 'Public profile updated.',
                'user'    => $user->fresh(['plan', 'googleAccount']),
            ]);
        }, 'PublicProfileController@updateSettings');
    }

    /**
     * Deduce profile information from the active CV.
     */
    public function deduceFromCV(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $user = $request->user();
            $activeCV = $user->cvs()->where('is_active', true)->latest()->first()
                ?? $user->cvs()->latest()->first();

            if (!$activeCV) {
                return response()->json(['error' => 'No CV found. Please upload a resume first.'], 422);
            }

            $fallback = function () use ($activeCV, $user) {
                $data = $activeCV->parsed_data ?? [];
                return response()->json([
                    'message' => 'Profile data deduced (non-AI fallback).',
                    'deduced' => [
                        'location'              => $data['location'] ?? $user->location,
                        'linkedin_url'          => $data['linkedin'] ?? $data['linkedin_url'] ?? $user->linkedin_url,
                        'github_url'            => $data['github'] ?? $data['github_url'] ?? $user->github_url,
                        'portfolio_url'         => $data['portfolio'] ?? $data['portfolio_url'] ?? $data['website'] ?? $user->portfolio_url,
                        'professional_headline' => $data['current_title'] ?? $data['headline'] ?? $user->professional_headline,
                    ]
                ]);
            };

            if (!$this->aiChatService->isConfigured()) {
                return $fallback();
            }

            $rawText = $activeCV->cv_raw_text;
            $parsedData = json_encode($activeCV->parsed_data, JSON_PRETTY_PRINT);

            $sourceContext = "STRUCTURED DATA:\n{$parsedData}\n\nRAW CV TEXT:\n{$rawText}";

            try {
                $result = $this->aiChatService->chatJson([
                    [
                        'role' => 'system',
                        'content' => "You are a world-class career profile data extractor. Your goal is to find specific profile links and info from the provided CV data (both structured and raw).
                        
                        CRITICAL CHALLENGE: 
                        Sometimes links like GitHub, Portfolio, or Personal Websites are hidden in the text as plain strings or shorthand (e.g., 'github.com/username' or 'portfolio.io'). You MUST find these and return them as full URLs.
                        
                        Required JSON Output Keys:
                        - location: Standard City, Country format.
                        - linkedin_url: Full LinkedIn profile URL.
                        - github_url: Full GitHub profile URL.
                        - twitter_url: Full X/Twitter profile URL.
                        - portfolio_url: Full personal website or portfolio URL.
                        - professional_headline: A 1-sentence punchy professional headline (create one if missing).
                        
                        Return ONLY a valid JSON object. If a data point is absolutely not present, return null for that key."
                    ],
                    [
                        'role' => 'user',
                        'content' => "Context for deduction:\n\n{$sourceContext}"
                    ]
                ], 60);

                $deduced = json_decode($result['content'], true);

                return response()->json([
                    'message' => 'Profile data deduced with AI.',
                    'deduced' => $deduced
                ]);
            } catch (\Throwable $e) {
                Log::error('AI Deduction error: ' . $e->getMessage());
                return $fallback();
            }
        }, 'PublicProfileController@deduceFromCV');
    }

    /**
     * Check if a username is available.
     */
    public function checkUsername(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate(['username' => 'required|string|min:3|max:30|regex:/^[a-z0-9\-]+$/']);

            $taken = User::where('username', $request->username)
                ->where('id', '!=', $request->user()->id)
                ->exists();

            return response()->json(['available' => !$taken]);
        }, 'PublicProfileController@checkUsername');
    }
}

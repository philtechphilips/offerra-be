<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PublicProfileController extends Controller
{
    /**
     * Public endpoint — no auth required.
     * Returns everything needed to render the public profile page.
     */
    public function show(string $username)
    {
        $user = User::where('username', $username)
            ->where('public_profile_enabled', true)
            ->firstOrFail();

        // Active CV parsed data
        $activeCV = $user->cvs()->where('is_active', true)->latest()->first()
            ?? $user->cvs()->latest()->first();

        $parsedData = $activeCV?->parsed_data ?? [];

        // Public job stats (no sensitive details exposed)
        $jobs = $user->jobApplications();
        $totalApps   = $jobs->count();
        $interviews  = (clone $jobs)->where('status', 'interview')->count();
        $offers      = (clone $jobs)->where('status', 'offer')->count();
        $successRate = $totalApps > 0 ? round(($offers / $totalApps) * 100) : 0;

        // Tech stack from job applications
        $techStack = $user->jobApplications()
            ->whereNotNull('tech_stack')
            ->get()
            ->flatMap(fn($j) => $j->tech_stack ?? [])
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
                'professional_headline'=> $user->professional_headline,
                'location'             => $user->location,
                'linkedin_url'         => $user->linkedin_url,
                'github_url'           => $user->github_url,
                'twitter_url'          => $user->twitter_url,
                'portfolio_url'        => $user->portfolio_url,
            ],
            'cv' => [
                'summary'        => $parsedData['summary'] ?? $parsedData['about'] ?? null,
                'skills'         => $parsedData['skills'] ?? [],
                'work_experience'=> $parsedData['work_experience'] ?? [],
                'education'      => $parsedData['education'] ?? [],
                'projects'       => $parsedData['projects'] ?? [],
                'certifications' => $parsedData['certifications'] ?? [],
            ],
            'stats' => [
                'total_applications' => $totalApps,
                'interviews'         => $interviews,
                'offers'             => $offers,
                'success_rate'       => $successRate,
            ],
            'tech_stack' => $techStack,
        ]);
    }

    /**
     * Auth-protected — save public profile settings.
     */
    public function updateSettings(Request $request)
    {
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
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Public profile updated.',
            'user'    => $user->fresh(['plan', 'googleAccount']),
        ]);
    }

    /**
     * Check if a username is available.
     */
    public function checkUsername(Request $request)
    {
        $request->validate(['username' => 'required|string|min:3|max:30|regex:/^[a-z0-9\-]+$/']);

        $taken = User::where('username', $request->username)
            ->where('id', '!=', $request->user()->id)
            ->exists();

        return response()->json(['available' => !$taken]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\JobApplication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JobApplicationController extends Controller
{
    public function detect(Request $request)
    {
        $validated = $request->validate([
            'html_content' => 'required|string',
            'url' => 'required|url',
        ]);

        $apiKey = env('DEEPSEEK_API_KEY');
        if (!$apiKey) {
            return response()->json(['error' => 'AI Key not configured'], 500);
        }

        try {
            // Truncate content to avoid token limits but keep the important parts (top of page/body)
            $contentSnippet = substr($validated['html_content'], 0, 4000);

            $response = Http::withHeaders([
                'Authorization' => "Bearer $apiKey",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.deepseek.com/chat/completions', [
                'model' => 'deepseek-chat',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an AI that detects job-related content from web pages and emails. 
                    1. If the content is a Job Posting/Listing: Extract the role details and set is_job to true.
                    2. If the content is an Email (e.g. from Gmail): Detect if it is a job application confirmation, an interview invitation, or a rejection. Extract the Company and Title mentioned, and set is_job to true.
                    3. If it is unrelated: Set is_job to false.
                    
                    CRITICAL: If the Company name is not explicitly mentioned in the body, try to deduce it from the URL (e.g. from glassdoor.com/job/google -> Google) or from the email sender/signature if available in the text.
                    Possible statuses for emails: applied, interview, rejected, offer.'],
                    ['role' => 'user', 'content' => "URL: {$validated['url']}\nContent: {$contentSnippet}\n\nRespond with JSON: { 'is_job': true/false, 'details': { 'title': '...', 'company': '...', 'location': '...', 'type': '...', 'is_remote': true/false, 'salary': '...', 'status': 'applied/interview/offer/rejected' } }"]
                ],
                'response_format' => ['type' => 'json_object']
            ]);

            if ($response->successful()) {
                $aiData = json_decode($response->json('choices.0.message.content'), true);
                return response()->json($aiData);
            }

            return response()->json(['error' => 'AI failed'], 500);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        $query = $request->user()->jobApplications()->latest();

        // Search by title or company
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('company', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->query('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        $perPage = (int) $request->query('per_page', 15);
        $page = (int) $request->query('page', 1);

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'has_more' => $paginated->hasMorePages(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'string|nullable',
            'company' => 'string|nullable',
            'location' => 'string|nullable',
            'type' => 'string|nullable',
            'is_remote' => 'boolean|nullable',
            'salary' => 'string|nullable',
            'url' => 'nullable|url',
            'company_url' => 'string|nullable',
            'status' => 'string|nullable|in:applied,tracking,interview,rejected,offer',
            'cv_match_score' => 'integer|nullable|min:0|max:100',
            'cv_match_details' => 'array|nullable',
        ]);

        // Create the initial record
        $job = JobApplication::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'] ?? 'Unknown',
            'company' => $validated['company'] ?? 'Unknown',
            'location' => $validated['location'] ?? 'Unknown',
            'type' => $validated['type'] ?? 'Full-time',
            'is_remote' => $validated['is_remote'] ?? false,
            'salary' => $validated['salary'] ?? null,
            'job_url' => $validated['url'],
            'company_url' => $validated['company_url'] ?? null,
            'status' => $validated['status'] ?? 'tracking',
            'cv_match_score' => $validated['cv_match_score'] ?? null,
            'cv_match_details' => $validated['cv_match_details'] ?? null,
        ]);

        // Trigger AI enrichment in background or sync for now to test
        $this->enrichWithAI($job);

        return response()->json([
            'message' => 'Job tracked and AI enrichment started.',
            'job' => $job->fresh()
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $job = JobApplication::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'title' => 'string|nullable',
            'company' => 'string|nullable',
            'location' => 'string|nullable',
            'type' => 'string|nullable',
            'is_remote' => 'boolean|nullable',
            'salary' => 'string|nullable',
            'url' => 'url|nullable',
            'company_url' => 'string|nullable',
            'status' => 'string|nullable|in:applied,tracking,interview,rejected,offer',
        ]);

        $job->update([
            'title' => $validated['title'] ?? $job->title,
            'company' => $validated['company'] ?? $job->company,
            'location' => $validated['location'] ?? $job->location,
            'type' => $validated['type'] ?? $job->type,
            'is_remote' => $validated['is_remote'] ?? $job->is_remote,
            'salary' => array_key_exists('salary', $validated) ? $validated['salary'] : $job->salary,
            'job_url' => $validated['url'] ?? $job->job_url,
            'company_url' => $validated['company_url'] ?? $job->company_url,
            'status' => $validated['status'] ?? $job->status,
        ]);

        return response()->json([
            'message' => 'Application updated successfully.',
            'job' => $job->fresh()
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $job = JobApplication::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $job->delete();

        return response()->json([
            'message' => 'Application deleted successfully.'
        ]);
    }

    private function enrichWithAI(JobApplication $job)
    {
        $apiKey = env('DEEPSEEK_API_KEY');
        if (!$apiKey) {
            Log::warning('DEEPSEEK_API_KEY not found in .env');
            return;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer $apiKey",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.deepseek.com/chat/completions', [
                'model' => 'deepseek-chat',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a professional job recruiter. Analyze the job details and extract extra information like summary, tech stack (as array), and any potential contact info or person mentioned. Respond ONLY in JSON format.'],
                    ['role' => 'user', 'content' => "Perfect this job data: Company: {$job->company}, Title: {$job->title}, URL: {$job->job_url}. 
                    Extract: 
                    1. A 2-sentence summary of the company/role.
                    2. A tech stack (array of tags).
                    3. Contact info (emails/names) if you can deduce from the URL/Company.
                    
                    Respond with JSON object: { 'summary': '...', 'tech_stack': [...], 'contact_info': '...' }"]
                ],
                'response_format' => ['type' => 'json_object']
            ]);

            if ($response->successful()) {
                $aiData = json_decode($response->json('choices.0.message.content'), true);
                $job->update([
                    'summary' => $aiData['summary'] ?? $job->summary,
                    'tech_stack' => $aiData['tech_stack'] ?? $job->tech_stack,
                    'contact_info' => $aiData['contact_info'] ?? $job->contact_info,
                ]);
                Log::info('AI enrichment completed for job #' . $job->id);
            } else {
                Log::error('DeepSeek AI Error: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error('AI enrichment failed: ' . $e->getMessage());
        }
    }
}

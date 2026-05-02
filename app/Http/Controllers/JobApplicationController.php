<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\JobApplication;
use App\Models\Setting;
use App\Services\Ai\AiChatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JobApplicationController extends Controller
{
    public function __construct(private AiChatService $aiChatService)
    {
    }

    public function detect(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $validated = $request->validate([
                'html_content' => 'required|string',
                'url' => 'required|url',
            ]);

            $user = $request->user();
            if (!$user->hasCredits(1)) {
                return response()->json(['error' => 'Not enough credits for AI detection.'], 402);
            }

            if (!$this->aiChatService->isConfigured()) {
                Log::error('AI detect failed: No provider configured.');
                return $this->genericServerErrorResponse();
            }

            $contentSnippet = substr($validated['html_content'], 0, 4000);

            $result = $this->aiChatService->chatJson([
                ['role' => 'system', 'content' => 'You are an AI that detects job-related content from web pages and emails.
                    1. If the content is a Job Posting/Listing: Extract the role details and set is_job to true.
                    2. If the content is an Email (e.g. from Gmail): Detect if it is a job application confirmation, an interview invitation, or a rejection. Extract the Company and Title mentioned, and set is_job to true.
                    3. If it is unrelated: Set is_job to false.

                    CRITICAL: If the Company name is not explicitly mentioned in the body, try to deduce it from the URL (e.g. from glassdoor.com/job/google -> Google) or from the email sender/signature if available in the text.
                    Possible statuses for emails: applied, interview, rejected, offer.'],
                ['role' => 'user', 'content' => "URL: {$validated['url']}\nContent: {$contentSnippet}\n\nRespond with JSON: { 'is_job': true/false, 'details': { 'title': '...', 'company': '...', 'location': '...', 'type': '...', 'is_remote': true/false, 'salary': '...', 'status': 'applied/interview/offer/rejected' } }"]
            ], 60);

            $user->deductCredits(1, "Job Discovery via AI: Attempting to detect job at " . ($validated['url'] ?? 'page'));
            $aiData = json_decode($result['content'], true);
            return response()->json($aiData);
        }, 'JobApplicationController@detect');
    }

    /**
     * Aggregate counts for the user's job applications.
     * Used by dashboard widgets and the sidebar so totals are independent of pagination.
     */
    public function stats(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $userId = $request->user()->id;

            $byStatusRaw = JobApplication::where('user_id', $userId)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->toArray();

            $statuses = ['applied', 'tracking', 'interview', 'rejected', 'offer'];
            $byStatus = [];
            foreach ($statuses as $status) {
                $byStatus[$status] = (int) ($byStatusRaw[$status] ?? 0);
            }

            $total = (int) array_sum($byStatus);

            $now = now();
            $last7Start = $now->copy()->subDays(7);
            $prev7Start = $now->copy()->subDays(14);

            $recent7d = JobApplication::where('user_id', $userId)
                ->where('created_at', '>=', $last7Start)
                ->count();

            $previous7d = JobApplication::where('user_id', $userId)
                ->whereBetween('created_at', [$prev7Start, $last7Start])
                ->count();

            $interviewMatch = JobApplication::where('user_id', $userId)
                ->where('status', 'interview')
                ->whereNotNull('cv_match_score')
                ->selectRaw('COUNT(*) as total, AVG(cv_match_score) as avg_score, SUM(CASE WHEN cv_match_score >= 70 THEN 1 ELSE 0 END) as high_match')
                ->first();

            return response()->json([
                'total' => $total,
                'by_status' => $byStatus,
                'momentum' => [
                    'recent_7d' => (int) $recent7d,
                    'previous_7d' => (int) $previous7d,
                ],
                'interview_insights' => [
                    'with_score' => (int) ($interviewMatch->total ?? 0),
                    'avg_match_score' => $interviewMatch && $interviewMatch->total
                        ? (int) round((float) $interviewMatch->avg_score)
                        : 0,
                    'high_match' => (int) ($interviewMatch->high_match ?? 0),
                ],
            ]);
        }, 'JobApplicationController@stats');
    }

    public function index(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $query = $request->user()->jobApplications()->latest();

            if ($search = trim((string) $request->query('search'))) {
                $like = '%' . strtolower($search) . '%';
                $query->where(function ($q) use ($like) {
                    $q->whereRaw('LOWER(title) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(company) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(location) LIKE ?', [$like]);
                });
            }

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
        }, 'JobApplicationController@index');
    }

    public function show(Request $request, $id)
    {
        return $this->safeCall(function () use ($request, $id) {
            $job = JobApplication::where('id', $id)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();

            return response()->json($job);
        }, 'JobApplicationController@show');
    }

    public function store(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $validated = $request->validate([
                'title' => 'string|nullable',
                'company' => 'string|nullable',
                'location' => 'string|nullable',
                'type' => 'string|nullable',
                'is_remote' => 'boolean|nullable',
                'salary' => 'string|nullable',
                'url' => 'nullable|url',
                'company_url' => 'string|nullable',
                'description' => 'string|nullable',
                'status' => 'string|nullable|in:applied,tracking,interview,rejected,offer',
                'cv_match_score' => 'integer|nullable|min:0|max:100',
                'cv_match_details' => 'array|nullable',
                'follow_up_date' => 'date|nullable',
                'follow_up_note' => 'string|nullable',
            ]);

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
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 'tracking',
                'cv_match_score' => $validated['cv_match_score'] ?? null,
                'cv_match_details' => $validated['cv_match_details'] ?? null,
                'follow_up_date' => $validated['follow_up_date'] ?? null,
                'follow_up_note' => $validated['follow_up_note'] ?? null,
            ]);

            $this->enrichWithAI($job);

            return response()->json([
                'message' => 'Job tracked and AI enrichment started.',
                'job' => $job->fresh()
            ], 201);
        }, 'JobApplicationController@store');
    }

    public function update(Request $request, $id)
    {
        return $this->safeCall(function () use ($request, $id) {
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
                'description' => 'string|nullable',
                'status' => 'string|nullable|in:applied,tracking,interview,rejected,offer',
                'follow_up_date' => 'date|nullable',
                'follow_up_note' => 'string|nullable',
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
                'description' => array_key_exists('description', $validated) ? $validated['description'] : $job->description,
                'status' => $validated['status'] ?? $job->status,
                'follow_up_date' => array_key_exists('follow_up_date', $validated) ? $validated['follow_up_date'] : $job->follow_up_date,
                'follow_up_note' => array_key_exists('follow_up_note', $validated) ? $validated['follow_up_note'] : $job->follow_up_note,
            ]);

            return response()->json([
                'message' => 'Application updated successfully.',
                'job' => $job->fresh()
            ]);
        }, 'JobApplicationController@update');
    }

    public function destroy(Request $request, $id)
    {
        return $this->safeCall(function () use ($request, $id) {
            $job = JobApplication::where('id', $id)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();

            $job->delete();

            return response()->json([
                'message' => 'Application deleted successfully.'
            ]);
        }, 'JobApplicationController@destroy');
    }

    private function enrichWithAI(JobApplication $job)
    {
        $user = $job->user;
        if (!$user->hasCredits(1)) {
            Log::warning('Not enough AI credits for enrichment: Job #' . $job->id);
            return;
        }

        if (!$this->aiChatService->isConfigured()) {
            Log::warning('No AI provider API key found in .env');
            return;
        }

        try {
            $result = $this->aiChatService->chatJson([
                ['role' => 'system', 'content' => 'You are a professional job recruiter. Analyze the job details and extract extra information like summary, tech stack (as array), and any potential contact info or person mentioned. Respond ONLY in JSON format.'],
                ['role' => 'user', 'content' => "Job data: Company: {$job->company}, Title: {$job->title}, URL: {$job->job_url}.\n\nJob Description:\n" . substr($job->description ?? '', 0, 2000) . "\n\nExtract:\n1. A 2-sentence summary of the company/role.\n2. A tech stack (array of tags).\n3. Contact info (emails/names) if you can deduce from the URL/Company.\n\nRespond with JSON object: { 'summary': '...', 'tech_stack': [...], 'contact_info': '...' }"]
            ], 60);

            $user->deductCredits(1, "AI Enrichment for job: {$job->title} at {$job->company}");
            $aiData = json_decode($result['content'], true);
            $job->update([
                'summary' => $aiData['summary'] ?? $job->summary,
                'tech_stack' => $aiData['tech_stack'] ?? $job->tech_stack,
                'contact_info' => $aiData['contact_info'] ?? $job->contact_info,
            ]);
            Log::info('AI enrichment completed for job #' . $job->id, ['provider' => $result['provider']]);
        } catch (\Throwable $e) {
            // Background enrichment is best-effort; never surface errors to the user.
            Log::error('AI enrichment failed: ' . $e->getMessage());
        }
    }
}

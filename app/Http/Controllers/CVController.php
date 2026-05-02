<?php

namespace App\Http\Controllers;

use App\Models\UserProfile;
use App\Models\Setting;
use App\Services\Ai\AiChatService;
use App\Notifications\GenericNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CVController extends Controller
{
    public function __construct(private AiChatService $aiChatService)
    {
    }

    /**
     * Upload & parse a CV file (PDF, TXT, DOCX).
     * Extracts raw text, then sends to AI for structured parsing.
     */
    public function upload(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate([
                'cv' => 'required|file|mimes:pdf,txt,doc,docx|max:5120',
            ]);

            $file = $request->file('cv');
            $extension = strtolower($file->getClientOriginalExtension());
            $filename = $file->getClientOriginalName();

            $rawText = '';

            try {
                if ($extension === 'txt') {
                    $rawText = file_get_contents($file->getRealPath());
                } elseif ($extension === 'pdf') {
                    $rawText = $this->extractTextFromPDF($file->getRealPath());
                } elseif (in_array($extension, ['doc', 'docx'])) {
                    $rawText = $this->extractTextFromDocx($file->getRealPath());
                }
            } catch (\Throwable $e) {
                Log::error('CV text extraction failed: ' . $e->getMessage());
                return response()->json(['error' => 'Failed to read the file. Please try a different format.'], 422);
            }

            if (empty(trim($rawText))) {
                return response()->json(['error' => 'Could not extract text from the file. Try uploading a TXT or a different PDF.'], 422);
            }

            $path = $file->store('cvs/' . $request->user()->id, 'local');

            UserProfile::where('user_id', $request->user()->id)->update(['is_active' => false]);

            $profile = UserProfile::create([
                'user_id' => $request->user()->id,
                'cv_filename' => $filename,
                'profile_name' => pathinfo($filename, PATHINFO_FILENAME),
                'cv_raw_text' => $rawText,
                'is_active' => true,
            ]);

            $parsedData = $this->parseWithAI($rawText);

            if ($parsedData) {
                $profile->update([
                    'parsed_data' => $parsedData,
                    'parsed_at' => now(),
                ]);
            }

            $request->user()->notify(new GenericNotification(
                'CV Uploaded',
                'AI has successfully analyzed and parsed your resume.',
                'info',
                '/dashboard/profile'
            ));

            return response()->json([
                'message' => 'CV uploaded and parsed successfully.',
                'filename' => $filename,
                'parsed' => !empty($parsedData),
                'profile' => $profile->fresh(),
            ]);
        }, 'CVController@upload');
    }

    /**
     * Get the current user's CV profile data.
     */
    public function show(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $profiles = UserProfile::where('user_id', $request->user()->id)
                ->orderBy('created_at', 'desc')
                ->get();

            $activeProfile = $profiles->where('is_active', true)->first() ?? $profiles->first();

            return response()->json([
                'has_cv' => $profiles->isNotEmpty(),
                'filename' => $activeProfile ? $activeProfile->cv_filename : null,
                'profile_name' => $activeProfile ? $activeProfile->profile_name : null,
                'parsed_data' => $activeProfile ? $activeProfile->parsed_data : null,
                'is_active' => $activeProfile ? (bool) $activeProfile->is_active : false,
                'cvs' => $profiles->map(function ($profile) {
                    return [
                        'id' => $profile->id,
                        'filename' => $profile->cv_filename,
                        'profile_name' => $profile->profile_name,
                        'parsed_data' => $profile->parsed_data,
                        'is_active' => $profile->is_active,
                        'parsed_at' => $profile->parsed_at,
                        'created_at' => $profile->created_at,
                    ];
                })
            ]);
        }, 'CVController@show');
    }

    /**
     * AI Auto-fill: Takes form field descriptions and returns values from CV data.
     */
    public function autofill(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate([
                'fields' => 'required|array',
                'fields.*.id' => 'required|string',
                'fields.*.label' => 'nullable|string',
                'fields.*.type' => 'nullable|string',
                'fields.*.placeholder' => 'nullable|string',
                'fields.*.options' => 'nullable|array',
                'job_context' => 'nullable|string',
            ]);

            $profile = UserProfile::where('user_id', $request->user()->id)
                ->where('is_active', true)
                ->first() ?? UserProfile::where('user_id', $request->user()->id)->latest()->first();

            if (!$profile || !$profile->parsed_data) {
                return response()->json(['error' => 'No active CV data found. Please upload a CV first.'], 422);
            }

            if (!$this->aiChatService->isConfigured()) {
                Log::error('Autofill: AI not configured.');
                return $this->genericServerErrorResponse();
            }

            $cvData = json_encode($profile->parsed_data, JSON_PRETTY_PRINT);
            $rawCvText = substr($profile->cv_raw_text ?? '', 0, 3000);
            $fieldsData = json_encode($request->fields, JSON_PRETTY_PRINT);
            $jobContext = $request->job_context ?? 'Not specified';

            $result = $this->aiChatService->chatJson([
                [
                    'role' => 'system',
                    'content' => "You are ghostwriting job application form answers for a real person. You must write EXACTLY as if you ARE that person — first person, natural, human tone.

CRITICAL RULES:
1. NEVER mention \"CV\", \"resume\", \"based on my data\", \"according to my profile\", or anything that reveals AI involvement. You are the person.
2. Write naturally like a confident professional would. Vary sentence structure. Be specific but conversational.
3. For \"tell us about yourself\" or open-ended questions: Write a compelling 3-4 sentence narrative weaving together their experience, skills, and enthusiasm. DO NOT list bullet points. Sound like a real human writing passionately.
4. For technical skill questions (e.g. \"Tell us about your MySQL experience\"): The person IS applying for this job, so they clearly believe they can do it. If the skill appears anywhere in their background (projects, tools, stack), write confidently about it. If it's a closely related skill (e.g., they know PostgreSQL and the question asks about MySQL), bridge the knowledge. Only if the skill is completely unrelated to anything in their background, write something like \"I have foundational knowledge of [X] and I'm actively deepening my expertise\" — NEVER say \"I have no experience\".
5. For select/dropdown fields: Pick the BEST matching option from the provided options list. Return the option text EXACTLY as given.
6. For contact info (name, email, phone, etc.): Use the exact values from their data.
7. For salary expectations: Give a reasonable range based on their experience level and the role.
8. For \"How did you hear about us?\" type questions: Say \"Online job board\" or pick the most generic option.
9. Keep answers concise for short text fields (1-2 sentences max). Write more for textareas (3-5 sentences)."
                ],
                [
                    'role' => 'user',
                    'content' => "Here is the person's profile:\n\n{$cvData}\n\nAdditional context from their resume:\n{$rawCvText}\n\nThey are applying for this job: {$jobContext}\n\nFill these form fields as if you are this person:\n\n{$fieldsData}\n\nRespond ONLY with a JSON object where each key is the field 'id' and the value is what to fill. For select fields with options, the value MUST be one of the provided options exactly."
                ]
            ], 60);

            $filled = json_decode($result['content'], true);
            return response()->json([
                'success' => true,
                'filled_fields' => $filled,
            ]);
        }, 'CVController@autofill');
    }

    /**
     * Match Score: Analyze how well the user's CV matches a job.
     */
    public function matchScore(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate([
                'job_title' => 'required|string',
                'job_context' => 'required|string',
            ]);

            $profile = UserProfile::where('user_id', $request->user()->id)
                ->where('is_active', true)
                ->first() ?? UserProfile::where('user_id', $request->user()->id)->latest()->first();

            if (!$profile || !$profile->parsed_data) {
                return response()->json(['error' => 'No active CV data found.'], 422);
            }

            $cost = Setting::getVal('credit_cost_match_score', 1);
            $user = $request->user();

            if (!$user->hasCredits($cost)) {
                return response()->json([
                    'error' => 'Not enough AI credits.',
                    'required' => $cost,
                    'available' => $user->credits ?? 0
                ], 402);
            }

            if (!$this->aiChatService->isConfigured()) {
                Log::error('Match score: AI not configured.');
                return $this->genericServerErrorResponse();
            }

            $cvData = json_encode($profile->parsed_data, JSON_PRETTY_PRINT);
            $jobTitle = $request->job_title;
            $jobContext = $request->job_context;

            $aiResult = $this->aiChatService->chatJson([
                [
                    'role' => 'system',
                    'content' => 'You are a recruitment expert. Analyze how well a candidate\'s CV matches a job posting. Be realistic but fair. Consider transferable skills.'
                ],
                [
                    'role' => 'user',
                    'content' => "Candidate CV data:\n{$cvData}\n\nJob: {$jobTitle}\nJob details:\n{$jobContext}\n\nAnalyze the match and respond with JSON:\n{\n  \"match_percentage\": 75,\n  \"strengths\": [\"Strong backend experience\", \"Relevant tech stack\"],\n  \"gaps\": [\"No cloud deployment experience\", \"Missing leadership experience\"],\n  \"tip\": \"One short actionable tip to improve chances\"\n}"
                ]
            ], 60);

            $user->deductCredits($cost, "Match Analysis: {$jobTitle} at {$request->company}");
            $result = json_decode($aiResult['content'], true);
            return response()->json($result);
        }, 'CVController@matchScore');
    }

    /**
     * Generate Social bios & titles using the active CV.
     */
    public function generateBios(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $profile = UserProfile::where('user_id', $request->user()->id)
                ->where('is_active', true)
                ->first() ?? UserProfile::where('user_id', $request->user()->id)->latest()->first();

            if (!$profile || !$profile->parsed_data) {
                return response()->json(['error' => 'No active CV data found. Please upload a CV first.'], 422);
            }

            $cost = Setting::getVal('credit_cost_social_bios', 3);
            $user = $request->user();

            if (!$user->hasCredits($cost)) {
                return response()->json([
                    'error' => 'Not enough AI credits.',
                    'required' => $cost,
                    'available' => $user->credits ?? 0
                ], 402);
            }

            if (!$this->aiChatService->isConfigured()) {
                Log::error('Bio generation: AI not configured.');
                return $this->genericServerErrorResponse();
            }

            $cvData = json_encode($profile->parsed_data, JSON_PRETTY_PRINT);

            $aiResult = $this->aiChatService->chatJson([
                [
                    'role' => 'system',
                    'content' => 'You are an expert career coach, SEO specialist, and personal branding expert. Generate highly optimized profiles/bios based on the user\'s CV.'
                ],
                [
                    'role' => 'user',
                    'content' => "Candidate CV data:\n{$cvData}\n\nBased on this CV, generate SEO-friendly and highly engaging profile bios for the following platforms: LinkedIn (Headline + About section), X (Twitter) (Short punchy bio), Upwork (Headline + Description), and GitHub (Short bio). Respond strictly in JSON format matching this structure:\n{\n  \"linkedin\": {\n    \"headline\": \"...\",\n    \"about\": \"...\"\n  },\n  \"twitter\": \"...\",\n  \"upwork\": {\n    \"headline\": \"...\",\n    \"description\": \"...\"\n  },\n  \"github\": \"...\"\n}"
                ]
            ], 60);

            $user->deductCredits($cost, "Social Bio Generation for Profile");
            $result = json_decode($aiResult['content'], true);
            return response()->json($result);
        }, 'CVController@generateBios');
    }

    /**
     * Refactor Resume: Suggests optimizations based on a specific job description.
     */
    public function refactorResume(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate([
                'job_description' => 'required|string',
                'cv_id' => 'nullable|string',
            ]);

            $profile = null;
            if ($request->cv_id) {
                $profile = UserProfile::where('user_id', $request->user()->id)
                    ->where('id', $request->cv_id)
                    ->first();
            }

            if (!$profile) {
                $profile = UserProfile::where('user_id', $request->user()->id)
                    ->where('is_active', true)
                    ->first() ?? UserProfile::where('user_id', $request->user()->id)->latest()->first();
            }

            if (!$profile || !$profile->parsed_data) {
                return response()->json(['error' => 'No active CV data found.'], 422);
            }

            $cost = Setting::getVal('credit_cost_cv_optimization', 5);
            $user = $request->user();

            if (!$user->hasCredits($cost)) {
                return response()->json([
                    'error' => 'Not enough AI credits.',
                    'required' => $cost,
                    'available' => $user->credits ?? 0
                ], 402);
            }

            if (!$this->aiChatService->isConfigured()) {
                Log::error('Resume refactor: AI not configured.');
                return $this->genericServerErrorResponse();
            }

            $cvData = json_encode($profile->parsed_data, JSON_PRETTY_PRINT);
            $jobDescription = $request->job_description;

            $aiResult = $this->aiChatService->chatJson([
                [
                    'role' => 'system',
                    'content' => 'You are an expert career coach and professional resume writer. Your goal is to help candidates refactor their resume to perfectly match a specific job description. Provide actionable, high-impact suggestions.'
                ],
                [
                    'role' => 'user',
                    'content' => "Current CV data:\n{$cvData}\n\nPlease perform a deep analysis and provide a refactored version of the ENTIRE resume to match this Target Job Description:\n{$jobDescription}\n\nCRITICAL INSTRUCTIONS:\n1. PRESERVE ALL LINKS: If there are GitHub, Portfolio, LinkedIn, or Project-specific URLs in the original CV, they MUST be included in the refactored version exactly as they are.\n2. BULLET POINTS FOR PROJECTS: For any 'Projects' section (or similar), format each project as a list where each detail is an explicit line starting with a bullet point (•).\n3. DYNAMIC DISCOVERY: Identify and optimize all important sections from the source (Education, Certifications, Awards, etc.).\n\nRespond strictly in JSON format:\n{\n  \"optimized_summary\": \"Tailored 3-4 sentence professional summary.\",\n  \"key_skills_to_highlight\": [\"Skill 1\", \"Skill 2\", \"etc...\"],\n  \"experience_optimization\": [\n    {\n      \"company\": \"...\",\n      \"original_title\": \"...\",\n      \"tailored_bullets\": [\"Bullet rewritten for impact with keywords\"]\n    }\n  ],\n  \"additional_sections\": [\n    { \"title\": \"Education\", \"content\": \"Condensed education info...\" },\n    { \"title\": \"Projects\", \"content\": \"• Developed [X] using [Y] (link: [URL])\\n• Achieved [Result]...\" },\n    { \"title\": \"Certifications\", \"content\": \"• [Cert Name] - [Issuer]\" }\n  ],\n  \"strategic_advice\": \"One short paragraph of coaching advice for this role.\"\n}"
                ]
            ], 90);

            $user->deductCredits($cost, "Resume Architect: Optimized for Job Description");
            $result = json_decode($aiResult['content'], true);
            return response()->json($result);
        }, 'CVController@refactorResume');
    }

    /**
     * Store a refactored and manually edited CV.
     */
    public function storeRefactored(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate([
                'resume_data' => 'required|array',
                'profile_name' => 'required|string|max:255',
            ]);

            $data = $request->resume_data;

            UserProfile::where('user_id', $request->user()->id)->update(['is_active' => false]);

            $profile = null;
            if ($request->cv_id) {
                $profile = UserProfile::where('user_id', $request->user()->id)
                    ->where('id', $request->cv_id)
                    ->first();
            }

            $profileData = [
                'user_id' => $request->user()->id,
                'cv_filename' => 'optimized_resume.json',
                'profile_name' => $request->profile_name,
                'cv_raw_text' => $data['summary'] ?? '',
                'parsed_data' => [
                    'full_name' => $data['fullName'] ?? '',
                    'email' => $data['email'] ?? '',
                    'phone' => $data['phone'] ?? '',
                    'location' => $data['location'] ?? '',
                    'links' => $data['links'] ?? [],
                    'summary' => $data['summary'] ?? '',
                    'skills' => $data['skills'] ?? [],
                    'work_experience' => array_map(function ($exp) {
                        return [
                            'company' => $exp['company'],
                            'title' => $exp['title'],
                            'duration' => $exp['duration'] ?? '',
                            'description' => implode("\n", $exp['bullets'] ?? [])
                        ];
                    }, $data['experience'] ?? []),
                    'custom_sections' => $data['customSections'] ?? []
                ],
                'parsed_at' => now(),
                'is_active' => true,
            ];

            if ($profile) {
                $profile->update($profileData);
            } else {
                $profile = UserProfile::create($profileData);
            }

            return response()->json([
                'message' => 'Optimized resume saved to dashboard!',
                'profile' => $profile
            ]);
        }, 'CVController@storeRefactored');
    }

    /**
     * Write Proposal: Generates a persuasive job proposal (e.g., for Upwork) using CV data.
     */
    public function generateProposal(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate([
                'job_description' => 'required|string',
                'cv_id' => 'nullable|string',
            ]);

            $profile = null;
            if ($request->cv_id) {
                $profile = UserProfile::where('user_id', $request->user()->id)
                    ->where('id', $request->cv_id)
                    ->first();
            }

            if (!$profile) {
                $profile = UserProfile::where('user_id', $request->user()->id)
                    ->where('is_active', true)
                    ->first() ?? UserProfile::where('user_id', $request->user()->id)->latest()->first();
            }

            if (!$profile || !$profile->parsed_data) {
                return response()->json(['error' => 'No active CV data found. Please upload a CV first.'], 422);
            }

            $cost = Setting::getVal('credit_cost_proposal_generation', 2);
            $user = $request->user();

            if (!$user->hasCredits($cost)) {
                return response()->json([
                    'error' => 'Not enough AI credits.',
                    'required' => $cost,
                    'available' => $user->credits ?? 0
                ], 402);
            }

            if (!$this->aiChatService->isConfigured()) {
                Log::error('Proposal generation: AI not configured.');
                return $this->genericServerErrorResponse();
            }

            $cvData = json_encode($profile->parsed_data, JSON_PRETTY_PRINT);
            $jobDescription = $request->job_description;

            $aiResult = $this->aiChatService->chatJson([
                [
                    'role' => 'system',
                    'content' => "You are a world-class freelance consultant and sales expert. Your goal is to write a high-converting, irresistible job proposal (specifically for platforms like Upwork) that guarantees an interview.

CRITICAL SUCCESS FACTORS:
1. THE HOOK: Start with a powerful 1-line hook that immediately addresses the client's problem or needs. DO NOT start with \"Hi, I am...\" or \"I saw your job...\" - start with value.
2. PERSONALIZED: Reference specific details from the job description to show you've read it carefully.
3. RESULTS-ORIENTED: Show how your skills (from the provided CV) will directly solve their specific pain points.
4. PROOF OF WORK: You MUST include 1-2 most relevant projects from the candidate's data. If a project has a URL (GitHub, Demo, etc.), you MUST include the URL directly in the proposal as proof.
5. CALL TO ACTION: End with a low-friction invitation to chat or hop on a call.
6. TONE: Professional but approachable. Bold, confident, and punchy. No generic fluff.
7. FIRST PERSON: Write in the first person as if you ARE the candidate."
                ],
                [
                    'role' => 'user',
                    'content' => "My Profile Information:\n{$cvData}\n\nTarget Job Description:\n{$jobDescription}\n\nPlease write a high-converting proposal for this job. Structure it with:\n- A Killer Hook\n- The Solution/Value Proposition\n- Relevant Proof (Showcase the best matching projects and include their URLs found in my data)\n- Call to Action\n\nReturn the response in JSON format:\n{\n  \"proposal\": \"The full text of the proposal...\",\n  \"strategy_used\": \"Explain why this hook and approach were chosen for this specific job.\"\n}"
                ]
            ], 90);

            $user->deductCredits($cost, "High-Impact Proposal Generation");
            $result = json_decode($aiResult['content'], true);
            return response()->json($result);
        }, 'CVController@generateProposal');
    }

    /**
     * Interview Prep: Generates interview questions and suggested answers.
     */
    public function generateInterviewPrep(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate([
                'job_description' => 'required|string|min:20|max:20000',
                'cv_id' => 'nullable|string',
            ]);

            $profile = null;
            if ($request->cv_id) {
                $profile = UserProfile::where('user_id', $request->user()->id)
                    ->where('id', $request->cv_id)
                    ->first();
            }

            if (!$profile) {
                $profile = UserProfile::where('user_id', $request->user()->id)
                    ->where('is_active', true)
                    ->first() ?? UserProfile::where('user_id', $request->user()->id)->latest()->first();
            }

            if (!$profile || !$profile->parsed_data) {
                return response()->json(['error' => 'No active CV data found.'], 422);
            }

            $cost = Setting::getVal('credit_cost_interview_prep', 10);
            $user = $request->user();

            if (!$user->hasCredits($cost)) {
                return response()->json([
                    'error' => 'Not enough AI credits.',
                    'required' => $cost,
                    'available' => $user->credits ?? 0
                ], 402);
            }

            if (!$this->aiChatService->isConfigured()) {
                Log::error('Interview prep: AI not configured.');
                return $this->genericServerErrorResponse();
            }

            $cvData = json_encode($profile->parsed_data, JSON_PRETTY_PRINT);
            $jobDescription = $request->job_description;

            $aiResult = $this->aiChatService->chatJson([
                [
                    'role' => 'system',
                    'content' => "You are a senior hiring manager and bar-raiser who runs tough, realistic interviews. Your job is NOT to be gentle — it is to surface the hardest credible questions a strong interviewer would ask so the candidate rehearses under pressure.

PRIORITY: QUESTIONS THAT PUT THEM ON THEIR TOES
- Stress-test claims on the CV (metrics, ownership, timelines, tech depth). Ask \"how exactly\", \"what would you do differently\", \"prove it with numbers\".
- Hypotheticals and trade-offs: scarce time, conflicting priorities, ambiguous requirements, production incidents, stakeholder pushback.
- Adversarial but fair: \"I'm not convinced...\", \"What's the weakest part of your background for this role?\", \"Why shouldn't we hire you?\"
- Gaps and pivots: career changes, short tenures, employment gaps, junior vs senior scope — without being illegal (no age/family/health).
- Role-specific deep dives from the job description: stack, architecture, delivery, leadership, business impact.
- Curveballs: on-the-spot prioritization, estimating, whiteboard-style reasoning in words, \"what if\" scenarios.
- Mix behavioral STAR probes with sharp follow-up style questions (single question can imply pressure; you cannot ask follow-ups in JSON, so make each question self-contained and piercing).

CATEGORIES: Use specific labels so the candidate sees the angle, e.g. \"Pressure test\", \"Resume stress-test\", \"Trade-off / judgment\", \"Failure & conflict\", \"Technical depth\", \"Executive / bar-raiser\", \"Culture & values\", \"Why us / motivation\", \"Curveball\".

Each item must include:
- 'question': One sharp, specific question (no fluff, no \"tell me about yourself\" unless as a stress variant).
- 'category': Short label from the style above.
- 'suggested_answer': STAR-grounded answer in first person, honest if the CV is thin — do not invent employers or degrees.
- 'why_this_works': One sentence: what signal this answer sends under pressure.

Return ONLY valid JSON. No markdown fences, no text outside JSON."
                ],
                [
                    'role' => 'user',
                    'content' => "Candidate CV Data:\n{$cvData}\n\nJob description:\n{$jobDescription}\n\nGenerate between 16 and 20 interview questions (inclusive). Every question should feel like it could make a prepared candidate sweat — challenging, specific, and grounded in THIS CV and THIS job. Include a wide spread across categories (pressure, technical depth, resume stress-test, trade-offs, failure/conflict, curveballs, bar-raiser style).\n\nReturn strictly in JSON format:\n{\n  \"prep_guide\": [\n    {\n      \"category\": \"...\",\n      \"question\": \"...\",\n      \"suggested_answer\": \"...\",\n      \"why_this_works\": \"...\"\n    }\n  ],\n  \"general_tips\": [\"Tip 1\", \"Tip 2\", \"Tip 3\", \"Tip 4\", \"Tip 5\", \"Tip 6\"]\n}\n\ngeneral_tips: 6 to 8 short bullets on how to stay composed under aggressive questioning (breathing, structuring answers, buying time, clarifying, etc.)."
                ]
            ], 120);

            $decoded = json_decode($aiResult['content'], true);
            if (! is_array($decoded)) {
                Log::error('Interview prep: AI returned non-JSON', [
                    'snippet' => substr((string) $aiResult['content'], 0, 400),
                ]);

                return response()->json(['error' => 'The AI returned an unreadable response. Please try again.'], 502);
            }

            $prepGuide = [];
            foreach ($decoded['prep_guide'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $question = trim((string) ($item['question'] ?? ''));
                if ($question === '') {
                    continue;
                }
                $prepGuide[] = [
                    'category' => trim((string) ($item['category'] ?? 'General')) ?: 'General',
                    'question' => $question,
                    'suggested_answer' => trim((string) ($item['suggested_answer'] ?? '')),
                    'why_this_works' => trim((string) ($item['why_this_works'] ?? '')),
                ];
            }

            if (count($prepGuide) < 12) {
                Log::warning('Interview prep: too few questions after validation', ['count' => count($prepGuide)]);

                return response()->json([
                    'error' => 'Could not build a full pressure-test playbook (need at least 12 questions). Add more JD detail and try again.',
                ], 422);
            }

            $generalTips = [];
            foreach ($decoded['general_tips'] ?? [] as $tip) {
                if (is_string($tip) && trim($tip) !== '') {
                    $generalTips[] = trim($tip);
                }
            }

            $user->deductCredits($cost, "Interview Prep Guide: Analysis & STAR Method Answers");

            return response()->json([
                'prep_guide' => $prepGuide,
                'general_tips' => $generalTips,
                'meta' => [
                    'generated_at' => now()->toIso8601String(),
                    'question_count' => count($prepGuide),
                    'credit_cost' => $cost,
                ],
            ]);
        }, 'CVController@generateInterviewPrep');
    }

    /**
     * Generate Cover Letter: Creates a professional cover letter based on CV and JD.
     */
    public function generateCoverLetter(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->validate([
                'job_description' => 'required|string',
                'cv_id' => 'nullable|string',
            ]);

            $profile = null;
            if ($request->cv_id) {
                $profile = UserProfile::where('user_id', $request->user()->id)
                    ->where('id', $request->cv_id)
                    ->first();
            }

            if (!$profile) {
                $profile = UserProfile::where('user_id', $request->user()->id)
                    ->where('is_active', true)
                    ->first() ?? UserProfile::where('user_id', $request->user()->id)->latest()->first();
            }

            if (!$profile || !$profile->parsed_data) {
                return response()->json(['error' => 'No active CV data found. Please upload a CV first.'], 422);
            }

            $cost = Setting::getVal('credit_cost_cover_letter', 5);
            $user = $request->user();

            if (!$user->hasCredits($cost)) {
                return response()->json([
                    'error' => 'Not enough AI credits.',
                    'required' => $cost,
                    'available' => $user->credits ?? 0
                ], 402);
            }

            if (!$this->aiChatService->isConfigured()) {
                Log::error('Cover letter: AI not configured.');
                return $this->genericServerErrorResponse();
            }

            $cvData = json_encode($profile->parsed_data, JSON_PRETTY_PRINT);
            $jobDescription = $request->job_description;

            $aiResult = $this->aiChatService->chatJson([
                [
                    'role' => 'system',
                    'content' => "You are an expert career consultant and professional writer. Your goal is to write a highly persuasive, customized, and high-impact cover letter.

CRITICAL GUIDELINES:
1. PERSONALIZED: Reference specific requirements and keywords from the job description.
2. STORYTELLING: Don't just list skills. Connect the candidate's achievements to how they will solve the company's specific problems.
3. TONE: Professional, enthusiastic, and confident. Avoid cliches like \"I am writing to apply for...\".
4. STRUCTURE:
   - Powerful opening hook.
   - 2-3 body paragraphs showing evidence of impact.
   - Strong closing with a call to action.
5. LENGTH: Keep it between 250-400 words.
6. FORMAT: Use a professional business letter format but focus on the content.
7. FIRST PERSON: Write as if you ARE the candidate."
                ],
                [
                    'role' => 'user',
                    'content' => "Candidate CV Data:\n{$cvData}\n\nJob description:\n{$jobDescription}\n\nPlease generate a professional cover letter. Respond strictly in JSON format:\n{\n  \"cover_letter\": \"Full text of the cover letter with proper business formatting (Placeholders like [Date], [Hiring Manager Name] are okay)\",\n  \"strategic_approach\": \"Explain the core theme used for this specific cover letter to highlight matching strengths.\"\n}"
                ]
            ], 90);

            $user->deductCredits($cost, "Professional Cover Letter Generation");
            $result = json_decode($aiResult['content'], true);
            return response()->json($result);
        }, 'CVController@generateCoverLetter');
    }

    /**
     * Delete a specific CV profile.
     */
    public function destroy(Request $request, $id)
    {
        return $this->safeCall(function () use ($request, $id) {
            $profile = UserProfile::where('user_id', $request->user()->id)
                ->where('id', $id)
                ->firstOrFail();

            $profile->delete();

            if ($profile->is_active) {
                $latest = UserProfile::where('user_id', $request->user()->id)->latest()->first();
                if ($latest) {
                    $latest->update(['is_active' => true]);
                }
            }

            return response()->json(['message' => 'CV deleted.']);
        }, 'CVController@destroy');
    }

    /**
     * Set a CV as active.
     */
    public function activate(Request $request, $id)
    {
        return $this->safeCall(function () use ($request, $id) {
            $profile = UserProfile::where('user_id', $request->user()->id)
                ->where('id', $id)
                ->firstOrFail();

            UserProfile::where('user_id', $request->user()->id)->update(['is_active' => false]);
            $profile->update(['is_active' => true]);

            return response()->json(['message' => 'CV activated.']);
        }, 'CVController@activate');
    }

    // ========================================
    // Text Extraction Helpers
    // ========================================

    private function extractTextFromPDF(string $path): string
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $text = $pdf->getText();

            $text = preg_replace('/\s+/', ' ', $text);
            $text = preg_replace('/\n{3,}/', "\n\n", $text);

            return trim($text);
        } catch (\Throwable $e) {
            Log::error('PDF parse error: ' . $e->getMessage());
            return '';
        }
    }

    private function extractTextFromDocx(string $path): string
    {
        $text = '';

        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            $xmlContent = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xmlContent) {
                $xmlContent = str_replace('</w:p>', "\n", $xmlContent);
                $xmlContent = str_replace('</w:r>', ' ', $xmlContent);
                $text = strip_tags($xmlContent);
                $text = preg_replace('/[ \t]+/', ' ', $text);
                $text = preg_replace('/\n\s*\n/', "\n", $text);
            }
        }

        return trim($text);
    }

    /**
     * Send raw CV text to AI for structured parsing.
     */
    private function parseWithAI(string $rawText): ?array
    {
        if (!$this->aiChatService->isConfigured()) return null;

        $snippet = substr($rawText, 0, 6000);

        try {
            $result = $this->aiChatService->chatJson([
                [
                    'role' => 'system',
                    'content' => 'You are an expert CV/resume parser. Extract all information from the resume text into a structured JSON format. Be thorough and accurate. Include everything you can find.'
                ],
                [
                    'role' => 'user',
                    'content' => "Parse this resume/CV text into structured JSON:\n\n{$snippet}\n\nRespond with JSON containing these fields (leave empty string or empty array if not found):\n{\n  \"full_name\": \"\",\n  \"email\": \"\",\n  \"phone\": \"\",\n  \"location\": \"\",\n  \"linkedin\": \"\",\n  \"github\": \"\",\n  \"portfolio\": \"\",\n  \"summary\": \"\",\n  \"current_title\": \"\",\n  \"years_of_experience\": \"\",\n  \"skills\": [],\n  \"languages\": [],\n  \"education\": [\n    { \"degree\": \"\", \"institution\": \"\", \"year\": \"\", \"gpa\": \"\" }\n  ],\n  \"work_experience\": [\n    { \"title\": \"\", \"company\": \"\", \"duration\": \"\", \"description\": \"\" }\n  ],\n  \"certifications\": [],\n  \"projects\": [\n    { \"name\": \"\", \"description\": \"\" }\n  ]\n}"
                ]
            ], 60);

            $parsed = json_decode($result['content'], true);
            Log::info('CV parsed successfully with AI', ['provider' => $result['provider']]);
            return $parsed;
        } catch (\Throwable $e) {
            Log::error('CV parse exception: ' . $e->getMessage());
            return null;
        }
    }
}

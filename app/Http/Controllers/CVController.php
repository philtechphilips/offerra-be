<?php

namespace App\Http\Controllers;

use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CVController extends Controller
{
    /**
     * Upload & parse a CV file (PDF, TXT, DOCX).
     * Extracts raw text, then sends to AI for structured parsing.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'cv' => 'required|file|mimes:pdf,txt,doc,docx|max:5120', // 5MB max
        ]);

        $file = $request->file('cv');
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = $file->getClientOriginalName();

        // Extract raw text based on file type
        $rawText = '';

        try {
            if ($extension === 'txt') {
                $rawText = file_get_contents($file->getRealPath());
            } elseif ($extension === 'pdf') {
                $rawText = $this->extractTextFromPDF($file->getRealPath());
            } elseif (in_array($extension, ['doc', 'docx'])) {
                $rawText = $this->extractTextFromDocx($file->getRealPath());
            }
        } catch (\Exception $e) {
            Log::error('CV text extraction failed: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to read the file. Please try a different format.'], 422);
        }

        if (empty(trim($rawText))) {
            return response()->json(['error' => 'Could not extract text from the file. Try uploading a TXT or a different PDF.'], 422);
        }

        // Store the file
        $path = $file->store('cvs/' . $request->user()->id, 'local');

        // Set all existing CVs to inactive
        UserProfile::where('user_id', $request->user()->id)->update(['is_active' => false]);

        // Save raw text as a new CV
        $profile = UserProfile::create([
            'user_id' => $request->user()->id,
            'cv_filename' => $filename,
            'profile_name' => pathinfo($filename, PATHINFO_FILENAME),
            'cv_raw_text' => $rawText,
            'is_active' => true,
        ]);

        // Parse with AI
        $parsedData = $this->parseWithAI($rawText);

        if ($parsedData) {
            $profile->update([
                'parsed_data' => $parsedData,
                'parsed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'CV uploaded and parsed successfully.',
            'filename' => $filename,
            'parsed' => !empty($parsedData),
            'profile' => $profile->fresh(),
        ]);
    }

    /**
     * Get the current user's CV profile data.
     */
    public function show(Request $request)
    {
        $profiles = UserProfile::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($profiles->isEmpty()) {
            return response()->json(['cvs' => []]);
        }

        return response()->json([
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
    }

    /**
     * AI Auto-fill: Takes form field descriptions and returns values from CV data.
     */
    public function autofill(Request $request)
    {
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

        $apiKey = env('DEEPSEEK_API_KEY');
        if (!$apiKey) {
            return response()->json(['error' => 'AI key not configured.'], 500);
        }

        $cvData = json_encode($profile->parsed_data, JSON_PRETTY_PRINT);
        $rawCvText = substr($profile->cv_raw_text ?? '', 0, 3000);
        $fieldsData = json_encode($request->fields, JSON_PRETTY_PRINT);
        $jobContext = $request->job_context ?? 'Not specified';

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer $apiKey",
                'Content-Type' => 'application/json',
            ])->timeout(45)->post('https://api.deepseek.com/chat/completions', [
                'model' => 'deepseek-chat',
                'messages' => [
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
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->successful()) {
                $filled = json_decode($response->json('choices.0.message.content'), true);
                return response()->json([
                    'success' => true,
                    'filled_fields' => $filled,
                ]);
            }

            Log::error('DeepSeek autofill error: ' . $response->body());
            return response()->json(['error' => 'AI autofill failed.'], 500);

        } catch (\Exception $e) {
            Log::error('Autofill exception: ' . $e->getMessage());
            return response()->json(['error' => 'AI service unavailable.'], 500);
        }
    }

    /**
     * Match Score: Analyze how well the user's CV matches a job.
     */
    public function matchScore(Request $request)
    {
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

        $apiKey = env('DEEPSEEK_API_KEY');
        if (!$apiKey) {
            return response()->json(['error' => 'AI key not configured.'], 500);
        }

        $cvData = json_encode($profile->parsed_data, JSON_PRETTY_PRINT);
        $jobTitle = $request->job_title;
        $jobContext = $request->job_context;

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer $apiKey",
                'Content-Type' => 'application/json',
            ])->timeout(20)->post('https://api.deepseek.com/chat/completions', [
                'model' => 'deepseek-chat',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a recruitment expert. Analyze how well a candidate\'s CV matches a job posting. Be realistic but fair. Consider transferable skills.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Candidate CV data:\n{$cvData}\n\nJob: {$jobTitle}\nJob details:\n{$jobContext}\n\nAnalyze the match and respond with JSON:\n{\n  \"match_percentage\": 75,\n  \"strengths\": [\"Strong backend experience\", \"Relevant tech stack\"],\n  \"gaps\": [\"No cloud deployment experience\", \"Missing leadership experience\"],\n  \"tip\": \"One short actionable tip to improve chances\"\n}"
                    ]
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->successful()) {
                $result = json_decode($response->json('choices.0.message.content'), true);
                return response()->json($result);
            }

            return response()->json(['error' => 'Analysis failed.'], 500);

        } catch (\Exception $e) {
            Log::error('Match score exception: ' . $e->getMessage());
            return response()->json(['error' => 'AI service unavailable.'], 500);
        }
    }

    /**
     * Generate Social bios & titles using the active CV.
     */
    public function generateBios(Request $request)
    {
        $profile = UserProfile::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->first() ?? UserProfile::where('user_id', $request->user()->id)->latest()->first();

        if (!$profile || !$profile->parsed_data) {
            return response()->json(['error' => 'No active CV data found. Please upload a CV first.'], 422);
        }

        $apiKey = env('DEEPSEEK_API_KEY');
        if (!$apiKey) {
            return response()->json(['error' => 'AI key not configured.'], 500);
        }

        $cvData = json_encode($profile->parsed_data, JSON_PRETTY_PRINT);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer $apiKey",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.deepseek.com/chat/completions', [
                'model' => 'deepseek-chat',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert career coach, SEO specialist, and personal branding expert. Generate highly optimized profiles/bios based on the user\'s CV.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Candidate CV data:\n{$cvData}\n\nBased on this CV, generate SEO-friendly and highly engaging profile bios for the following platforms: LinkedIn (Headline + About section), X (Twitter) (Short punchy bio), Upwork (Headline + Description), and GitHub (Short bio). Respond strictly in JSON format matching this structure:\n{\n  \"linkedin\": {\n    \"headline\": \"...\",\n    \"about\": \"...\"\n  },\n  \"twitter\": \"...\",\n  \"upwork\": {\n    \"headline\": \"...\",\n    \"description\": \"...\"\n  },\n  \"github\": \"...\"\n}"
                    ]
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->successful()) {
                $result = json_decode($response->json('choices.0.message.content'), true);
                return response()->json($result);
            }

            return response()->json(['error' => 'Bio generation failed.'], 500);

        } catch (\Exception $e) {
            Log::error('Bio generation exception: ' . $e->getMessage());
            return response()->json(['error' => 'AI service unavailable.'], 500);
        }
    }


    /**
     * Delete a specific CV profile.
     */
    public function destroy(Request $request, $id)
    {
        $profile = UserProfile::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $profile->delete();

        // If the deleted one was active, activate the most recent remaining one
        if ($profile->is_active) {
            $latest = UserProfile::where('user_id', $request->user()->id)->latest()->first();
            if ($latest) {
                $latest->update(['is_active' => true]);
            }
        }

        return response()->json(['message' => 'CV deleted.']);
    }

    /**
     * Set a CV as active.
     */
    public function activate(Request $request, $id)
    {
        $profile = UserProfile::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        UserProfile::where('user_id', $request->user()->id)->update(['is_active' => false]);
        $profile->update(['is_active' => true]);

        return response()->json(['message' => 'CV activated.']);
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

            // Clean up common artifacts
            $text = preg_replace('/\s+/', ' ', $text);
            $text = preg_replace('/\n{3,}/', "\n\n", $text);

            return trim($text);
        } catch (\Exception $e) {
            Log::error('PDF parse error: ' . $e->getMessage());
            return '';
        }
    }

    private function extractTextFromDocx(string $path): string
    {
        $text = '';

        // DOCX is a ZIP file containing XML
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            // Main content is in word/document.xml
            $xmlContent = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xmlContent) {
                // Strip XML tags but preserve paragraph breaks
                $xmlContent = str_replace('</w:p>', "\n", $xmlContent);
                $xmlContent = str_replace('</w:r>', ' ', $xmlContent);
                $text = strip_tags($xmlContent);
                // Clean up whitespace
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
        $apiKey = env('DEEPSEEK_API_KEY');
        if (!$apiKey) return null;

        // Truncate to reasonable length for the API
        $snippet = substr($rawText, 0, 6000);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer $apiKey",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.deepseek.com/chat/completions', [
                'model' => 'deepseek-chat',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert CV/resume parser. Extract all information from the resume text into a structured JSON format. Be thorough and accurate. Include everything you can find.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Parse this resume/CV text into structured JSON:\n\n{$snippet}\n\nRespond with JSON containing these fields (leave empty string or empty array if not found):\n{\n  \"full_name\": \"\",\n  \"email\": \"\",\n  \"phone\": \"\",\n  \"location\": \"\",\n  \"linkedin\": \"\",\n  \"github\": \"\",\n  \"portfolio\": \"\",\n  \"summary\": \"\",\n  \"current_title\": \"\",\n  \"years_of_experience\": \"\",\n  \"skills\": [],\n  \"languages\": [],\n  \"education\": [\n    { \"degree\": \"\", \"institution\": \"\", \"year\": \"\", \"gpa\": \"\" }\n  ],\n  \"work_experience\": [\n    { \"title\": \"\", \"company\": \"\", \"duration\": \"\", \"description\": \"\" }\n  ],\n  \"certifications\": [],\n  \"projects\": [\n    { \"name\": \"\", \"description\": \"\" }\n  ]\n}"
                    ]
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->successful()) {
                $parsed = json_decode($response->json('choices.0.message.content'), true);
                Log::info('CV parsed successfully with AI');
                return $parsed;
            }

            Log::error('CV parse AI error: ' . $response->body());
            return null;

        } catch (\Exception $e) {
            Log::error('CV parse exception: ' . $e->getMessage());
            return null;
        }
    }
}

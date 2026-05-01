<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\FieldMemory;
use App\Models\UserProfile;
use App\Models\UserSignature;
use App\Models\SignRequest;
use App\Services\Ai\AiChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function __construct(private AiChatService $aiChatService)
    {
    }

    /**
     * Upload a new PDF document.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'document' => 'required|file|mimes:pdf|max:10240', // 10MB max
            'name' => 'nullable|string|max:255',
        ]);

        $file = $request->file('document');
        $filename = $request->name ?? $file->getClientOriginalName();
        $path = $file->store('documents/' . $request->user()->id, 'local');

        $doc = Document::create([
            'user_id' => $request->user()->id,
            'name' => $filename,
            'file_path' => $path,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'document' => $doc,
        ]);
    }

    /**
     * List all documents for the user.
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 12);
        $page = (int) $request->query('page', 1);

        $paginated = Document::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

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

    /**
     * Fetch a single document (latest metadata / signed_path for DocSign).
     */
    public function show(Request $request, $id)
    {
        $doc = Document::where('user_id', $request->user()->id)->findOrFail($id);

        return response()->json($doc);
    }

    /**
     * Download a document (original or signed).
     */
    public function download(Request $request, $id)
    {
        $doc = Document::where('user_id', $request->user()->id)->findOrFail($id);
        $type = $request->query('type', 'original'); // original or signed

        $path = ($type === 'signed' && $doc->signed_path) ? $doc->signed_path : $doc->file_path;

        if (!Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'File not found.'], 404);
        }

        return response()->download(
            Storage::disk('local')->path($path),
            ($type === 'signed' ? 'signed_' : '') . $doc->name
        );
    }

    /**
     * Delete a document.
     */
    public function destroy(Request $request, $id)
    {
        $doc = Document::where('user_id', $request->user()->id)->findOrFail($id);

        Storage::disk('local')->delete($doc->file_path);
        if ($doc->signed_path) {
            Storage::disk('local')->delete($doc->signed_path);
        }

        $doc->delete();

        return response()->json(['message' => 'Document deleted.']);
    }

    /**
     * Create a shareable anonymous signing link for a document.
     */
    public function shareAnonymous(Request $request, $id)
    {
        $doc = Document::where('user_id', $request->user()->id)->findOrFail($id);

        $signRequest = SignRequest::create([
            'user_id' => $request->user()->id,
            'document_id' => $doc->id,
            'receiver_email' => 'anonymous@offerra.local',
            'receiver_name' => 'Anonymous Signer',
            'access_token' => Str::random(60),
            'status' => 'pending',
            'metadata' => [
                'anonymous' => true,
                'shared_via' => 'docsign_share_link',
            ],
        ]);

        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $signUrl = $frontendUrl . '/sign/' . $signRequest->access_token;

        return response()->json([
            'message' => 'Anonymous signing link created.',
            'sign_url' => $signUrl,
            'sign_request_id' => $signRequest->id,
        ]);
    }

    /**
     * Clear all field memory for the user.
     */
    public function clearMemory(Request $request)
    {
        FieldMemory::where('user_id', $request->user()->id)->delete();
        return response()->json(['message' => 'Field memory cleared.']);
    }

    /**
     * Save the signed version of the document.
     */
    public function saveSigned(Request $request, $id)
    {
        $doc = Document::where('user_id', $request->user()->id)->findOrFail($id);
        
        $request->validate([
            'pdf_base64' => 'required|string',
            'fields' => 'nullable|array',
            'field_data' => 'nullable|array', // { "Full Name": "John Doe", ... }
        ]);

        // Decode and save the signed PDF
        $pdfData = base64_decode($request->pdf_base64);
        $signedFilename = 'signed_' . Str::random(10) . '_' . $doc->name;
        $signedPath = 'documents/' . $request->user()->id . '/' . $signedFilename;

        Storage::disk('local')->put($signedPath, $pdfData);

        $currentMetadata = (array) ($doc->metadata ?? []);
        $mergedMetadata = $currentMetadata;

        if ($request->filled('fields')) {
            $existingFields = collect((array) ($currentMetadata['fields'] ?? []));
            $incomingOwnerFields = collect((array) $request->input('fields', []))
                ->map(function ($field) {
                    if (!is_array($field)) {
                        return null;
                    }
                    $field['owner_type'] = 'owner';
                    return $field;
                })
                ->filter()
                ->values();

            $preservedNonOwnerFields = $existingFields
                ->filter(fn ($field) => ($field['owner_type'] ?? 'owner') !== 'owner')
                ->values();

            $mergedMetadata['fields'] = $preservedNonOwnerFields
                ->concat($incomingOwnerFields)
                ->values()
                ->all();
        }

        $doc->update([
            'signed_path' => $signedPath,
            'status' => 'signed',
            'signed_at' => now(),
            'metadata' => $mergedMetadata,
        ]);

        // Save field data to memory for future auto-fills
        if ($request->field_data) {
            foreach ($request->field_data as $name => $value) {
                if (empty($value)) continue;
                if (!is_string($value)) continue;

                // Do not persist large binary payloads (e.g. base64 signatures/images) in memory table.
                if (str_starts_with($value, 'data:image/')) continue;
                
                FieldMemory::updateOrCreate(
                    ['user_id' => $request->user()->id, 'field_name' => $name],
                    ['field_value' => $value]
                );
            }
        }

        return response()->json([
            'message' => 'Document signed successfully.',
            'document' => $doc,
        ]);
    }

    /**
     * Truly Intelligent AI Autofill: Semantic mapping between PDF labels and user data.
     */
    public function intelligentAutofill(Request $request)
    {
        $request->validate([
            'labels' => 'required|array',
            'job_context' => 'nullable|string',
        ]);

        $user = $request->user();
        $profile = UserProfile::where('user_id', $user->id)
            ->where('is_active', true)
            ->first() ?? UserProfile::where('user_id', $user->id)->latest()->first();

        if (!$profile || !$profile->parsed_data) {
            return response()->json(['error' => 'No active CV data found.'], 422);
        }

        if (!$this->aiChatService->isConfigured()) {
            return response()->json(['error' => 'AI key not configured.'], 500);
        }

        $cvData = json_encode($profile->parsed_data, JSON_PRETTY_PRINT);
        $labels = json_encode($request->labels);
        
        try {
            $result = $this->aiChatService->chatJson([
                [
                    'role' => 'system',
                    'content' => "You are a PDF data matching expert. You will be given a user's CV data and a list of text labels extracted from a PDF form.
Your task is to map each PDF label to its corresponding piece of information from the user's CV.

RULES:
1. ONLY map labels that clearly belong to the candidate/user.
2. DO NOT map labels for other parties (Witness, Employer, Spouse, Next of Kin, Landlord, etc.).
3. If a label is ambiguous (e.g. just 'Name' and there is also 'Employer Name' and 'Candidate Name'), map it only if you are confident it is for the user.
4. For Signature fields (e.g. 'Signature', 'Sign here', 'Applicant Signature', or lines like '_________'): Return the value '[SIGNATURE]'.
5. For Date fields (e.g. 'Date', 'Signed on', 'Today\'s Date'): Return the value '[DATE]'.
6. Format your response exactly as a JSON object where the key is the ORIGINAL LABEL and the value is the USER'S DATA."
                ],
                [
                    'role' => 'user',
                    'content' => "User CV Data:\n{$cvData}\n\nPDF Labels:\n{$labels}\n\nMap these labels to user data. Respond ONLY with the JSON mapping."
                ]
            ], 45);

            $mapping = json_decode($result['content'], true);
            
            // Also fetch default signature if available
            $defaultSig = UserSignature::where('user_id', $user->id)
                ->where('is_default', true)
                ->first() ?? UserSignature::where('user_id', $user->id)->latest()->first();

            return response()->json([
                'mapping' => $mapping,
                'default_signature' => $defaultSig ? $defaultSig->signature_data : null
            ]);
        } catch (\Exception $e) {
            Log::error('Intelligent Autofill failed: ' . $e->getMessage());
            return response()->json(['error' => 'AI Service Error.'], 500);
        }
    }

    /**
     * Get suggestions for form fields based on CV and memory.
     */
    public function getFieldSuggestions(Request $request)
    {
        $user = $request->user();
        
        // 1. Get memory data
        $memory = FieldMemory::where('user_id', $user->id)
            ->pluck('field_value', 'field_name')
            ->toArray();

        // 2. Get CV data (if exists)
        $profile = UserProfile::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        $cvData = $profile ? ($profile->parsed_data ?? []) : [];

        // Flatten CV data for easy matching
        $cvSuggestions = $this->flattenCVData($cvData);

        // Merge, memory takes precedence as it's more "recent" or "explicit"
        $suggestions = array_merge($cvSuggestions, $memory);

        return response()->json($suggestions);
    }

    public function flattenCVData($data)
    {
        if (empty($data)) return [];

        $flat = [
            'name' => $data['full_name'] ?? '',
            'full name' => $data['full_name'] ?? '',
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'location' => $data['location'] ?? '',
        ];

        // Specific mappings
        if (!empty($data['full_name'])) {
            $parts = explode(' ', $data['full_name']);
            $flat['first name'] = $parts[0] ?? '';
            $flat['last name'] = count($parts) > 1 ? end($parts) : '';
        }

        return array_filter($flat);
    }

    /**
     * Get all saved signatures for the user.
     */
    public function getSignatures(Request $request)
    {
        $signatures = UserSignature::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($signatures);
    }

    /**
     * Save a new signature (drawn or uploaded).
     */
    public function saveSignature(Request $request)
    {
        $request->validate([
            'signature_data' => 'required|string', // Base64 or URL
            'type' => 'nullable|string|in:drawn,uploaded',
            'is_default' => 'nullable|boolean',
        ]);

        // If setting as default, unset previous default
        if ($request->is_default) {
            UserSignature::where('user_id', $request->user()->id)->update(['is_default' => false]);
        }

        $sig = UserSignature::create([
            'user_id' => $request->user()->id,
            'signature_data' => $request->signature_data,
            'type' => $request->type ?? 'drawn',
            'is_default' => $request->is_default ?? false,
        ]);

        return response()->json([
            'message' => 'Signature saved.',
            'signature' => $sig
        ]);
    }

    /**
     * Delete a saved signature.
     */
    public function deleteSignature(Request $request, $id)
    {
        $sig = UserSignature::where('user_id', $request->user()->id)->findOrFail($id);
        $sig->delete();

        return response()->json(['message' => 'Signature deleted.']);
    }
}

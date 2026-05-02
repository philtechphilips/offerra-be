<?php

namespace App\Http\Controllers;

use App\Models\SignRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Public token-based signing (used by DocSign share links).
 */
class GuestSignController extends Controller
{
    public function show(string $token)
    {
        return $this->safeCall(function () use ($token) {
            $signRequest = SignRequest::with(['user:id,name', 'document'])
                ->where('access_token', $token)
                ->firstOrFail();

            $signRequest->document->makeVisible(['metadata']);

            if ($signRequest->status === 'signed') {
                return response()->json(['error' => 'This document has already been signed.'], 403);
            }

            if ($signRequest->status === 'pending') {
                $signRequest->update(['status' => 'viewed']);
            }

            return response()->json([
                'sign_request' => $signRequest,
                'document'     => [
                    'name'     => $signRequest->document->name,
                    'file_url' => route('sign.file', ['token' => $token]),
                ],
            ]);
        }, 'GuestSignController@show');
    }

    public function file(string $token)
    {
        return $this->safeCall(function () use ($token) {
            $signRequest = SignRequest::where('access_token', $token)->firstOrFail();
            $doc = $signRequest->document;
            $path = $doc->signed_path ?: $doc->file_path;

            if (! Storage::disk('local')->exists($path)) {
                abort(404);
            }

            return response()->file(Storage::disk('local')->path($path));
        }, 'GuestSignController@file');
    }

    public function sign(Request $request, string $token)
    {
        return $this->safeCall(function () use ($request, $token) {
            $signRequest = SignRequest::where('access_token', $token)
                ->where('status', '!=', 'signed')
                ->firstOrFail();

            $request->validate([
                'pdf_base64' => 'nullable|string',
                'metadata'   => 'nullable|array',
                'fields'     => 'nullable|array',
                'finalize'   => 'nullable|boolean',
            ]);

            if (! $request->filled('pdf_base64') && ! $request->filled('metadata') && ! $request->filled('fields')) {
                return response()->json([
                    'error' => 'Either pdf_base64, metadata, or fields is required.',
                ], 422);
            }

            $doc = $signRequest->document;
            $shouldFinalize = (bool) $request->boolean('finalize', false);

            if ($request->filled('pdf_base64')) {
                $pdfData = base64_decode($request->pdf_base64);
                $signedFilename = 'guest_signed_' . Str::random(10) . '_' . $doc->name;
                $signedPath = 'documents/' . $signRequest->user_id . '/' . $signedFilename;

                Storage::disk('local')->put($signedPath, $pdfData);

                $doc->signed_path = $signedPath;
            }

            if ($request->filled('metadata')) {
                $docMetadata = (array) ($doc->metadata ?? []);
                $doc->metadata = array_merge($docMetadata, (array) $request->input('metadata', []));
            }

            if ($request->filled('fields')) {
                $docMetadata = (array) ($doc->metadata ?? []);
                $existingFields = collect((array) ($docMetadata['fields'] ?? []));
                $incomingGuestFields = collect((array) $request->input('fields', []))
                    ->map(function ($field) {
                        if (! is_array($field)) {
                            return null;
                        }
                        $field['owner_type'] = 'guest';

                        return $field;
                    })
                    ->filter()
                    ->values();

                $preservedNonGuestFields = $existingFields
                    ->filter(fn ($field) => ($field['owner_type'] ?? 'owner') !== 'guest')
                    ->values();

                $docMetadata['fields'] = $preservedNonGuestFields
                    ->concat($incomingGuestFields)
                    ->values()
                    ->all();

                $doc->metadata = $docMetadata;
            }

            if ($shouldFinalize) {
                $doc->status = 'signed';
                $doc->signed_at = now();
                $signRequest->status = 'signed';
                $signRequest->signed_at = now();
            }

            $doc->save();
            $signRequest->save();

            return response()->json([
                'message' => $shouldFinalize
                    ? 'Document signed successfully. Thank you!'
                    : 'Draft saved successfully.',
                'status' => $shouldFinalize ? 'signed' : 'draft_saved',
            ]);
        }, 'GuestSignController@sign');
    }
}

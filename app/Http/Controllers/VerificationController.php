<?php

namespace App\Http\Controllers;

use App\Models\SignatureRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class VerificationController extends Controller
{
    public function show(string $code): View
    {
        $signatureRequest = SignatureRequest::with('document')->where('verification_code', $code)->firstOrFail();
        $document = $signatureRequest->document;
        $currentHash = Storage::disk('local')->exists($document->file_path)
            ? hash_file('sha256', Storage::disk('local')->path($document->file_path)) : null;
        $integrity = $document->sealed_sha256
            ? $currentHash && hash_equals($document->sealed_sha256, $currentHash)
            : $currentHash && hash_equals((string) $document->sha256, $currentHash);

        return view('verification.show', compact('signatureRequest', 'document', 'currentHash', 'integrity'));
    }
}

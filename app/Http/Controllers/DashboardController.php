<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $email = mb_strtolower($request->user()->email);
        $toSign = Document::query()->with(['requester', 'signatureRequests'])
            ->whereHas('signatureRequests', fn ($q) => $q->where('signer_email', $email)->where('status', 'pending'))
            ->latest()->get();
        $sent = Document::query()->with('signatureRequests')->where('requester_id', $request->user()->id)
            ->where('status', 'pending')->latest()->get();

        return view('dashboard', compact('toSign', 'sent'));
    }
}

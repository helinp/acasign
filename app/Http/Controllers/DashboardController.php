<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Nombre de derniers documents signés proposés sur l'accueil. */
    public const RECENT_SIGNED_SIZES = [5, 10, 20];

    public function __invoke(Request $request): View
    {
        $email = mb_strtolower($request->user()->email);
        $request->validate(['recent' => ['nullable', 'integer', 'in:'.implode(',', self::RECENT_SIGNED_SIZES)]]);
        $recentCount = $request->integer('recent', self::RECENT_SIGNED_SIZES[0]);
        $toSign = Document::query()->with(['requester', 'signatureRequests'])
            ->whereHas('signatureRequests', fn ($q) => $q->where('signer_email', $email)->where('status', 'pending'))
            ->where('status', 'pending')
            ->orderBy('expires_at')->get();
        $sent = Document::query()->with('signatureRequests')->where('requester_id', $request->user()->id)
            ->where('status', 'pending')->latest()->get();
        $recentSigned = Document::query()->with(['requester', 'signatureRequests'])
            ->involving($request->user())
            ->where('status', 'signed')
            ->latest('completed_at')
            ->limit($recentCount)->get();

        return view('dashboard', [
            'toSign' => $toSign,
            'sent' => $sent,
            'recentSigned' => $recentSigned,
            'recentCount' => $recentCount,
            'recentSizes' => self::RECENT_SIGNED_SIZES,
        ]);
    }
}

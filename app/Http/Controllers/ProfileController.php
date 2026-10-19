<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSignatureImageRequest;
use App\Services\AuditService;
use App\Services\EncryptedSignatureStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('profile.show', ['user' => $request->user()]);
    }

    public function update(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'job_title' => ['nullable', 'string', 'max:120'],
        ]);
        $request->user()->update($data);
        $audit->record('profile.identity.updated', null, $request->user(), [], $request);

        return back()->with('success', 'Vos informations de signature ont été mises à jour.');
    }

    /**
     * Image de signature déchiffrée, visible uniquement par son propriétaire.
     */
    public function signatureImage(Request $request, EncryptedSignatureStorage $storage): Response
    {
        abort_unless($request->user()->signature_path, 404);

        return response($storage->bytes($request->user()), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeSignature(StoreSignatureImageRequest $request, EncryptedSignatureStorage $storage, AuditService $audit): RedirectResponse
    {
        $user = $request->user();
        $path = $storage->store($user, $request->file('signature'));
        $user->update(['signature_path' => $path]);
        $audit->record('profile.signature.updated', null, $user, [], $request);

        return back()->with('success', 'Votre signature a été enregistrée et chiffrée.');
    }

    public function deleteSignature(Request $request, EncryptedSignatureStorage $storage, AuditService $audit): RedirectResponse
    {
        $user = $request->user();
        $storage->delete($user);
        $user->update(['signature_path' => null]);
        $audit->record('profile.signature.deleted', null, $user, [], $request);

        return back()->with('success', 'Votre signature a été supprimée.');
    }
}

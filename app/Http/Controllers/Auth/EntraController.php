<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EntraOidcService;
use App\Services\InternalIdentityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EntraController extends Controller
{
    public function redirect(EntraOidcService $entra): RedirectResponse
    {
        return redirect()->away($entra->authorizationUrl());
    }

    public function callback(Request $request, EntraOidcService $entra, InternalIdentityService $identities, AuditService $audit): RedirectResponse
    {
        if ($request->filled('error')) {
            Log::warning('Connexion Entra refusée par Microsoft.', ['error' => $request->string('error')->toString(), 'description' => $request->string('error_description')->limit(500)->toString()]);

            return redirect()->route('login')->withErrors(['entra' => 'Microsoft a refusé la connexion ('.$request->string('error')->limit(60).'). Réessayez ou contactez l’administrateur.']);
        }
        $request->validate(['code' => ['required', 'string'], 'state' => ['required', 'string']]);
        try {
            $claims = $entra->exchangeAndValidate($request->string('code')->toString(), $request->string('state')->toString());
        } catch (\Throwable $exception) {
            // Détail (code AADSTS, réponse de Microsoft) dans le journal ; message neutre pour l'utilisateur.
            Log::error('Échec de la connexion Entra.', ['exception' => $exception->getMessage(), 'response' => $exception instanceof RequestException ? $exception->response->body() : null]);

            return redirect()->route('login')->withErrors(['entra' => 'La connexion Microsoft a échoué. Réessayez ; si le problème persiste, contactez l’administrateur.']);
        }
        $email = $identities->normalizeAndValidate($claims['preferred_username'] ?? $claims['email'] ?? '');
        $bootstrapAdmin = mb_strtolower((string) config('services.entra.bootstrap_admin_email'));

        $user = User::updateOrCreate(
            ['entra_oid' => $claims['oid']],
            [
                'tenant_id' => $claims['tid'],
                'name' => $claims['name'] ?? $email,
                'email' => $email,
                'last_login_at' => now(),
            ]
        );
        abort_if($user->disabled_at, 403, 'Ce compte est désactivé dans l’application.');
        if ($bootstrapAdmin && $email === $bootstrapAdmin && ! $user->isAdmin()) {
            $user->update(['role' => 'admin', 'can_send' => true]);
        }
        Auth::login($user);
        SignatureRequest::whereNull('signer_id')->where('signer_email', $email)->update(['signer_id' => $user->id, 'signer_name' => $user->name]);
        $request->session()->regenerate();
        $audit->record('auth.login', null, $user, ['tenant_id' => $claims['tid']], $request);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request, AuditService $audit): RedirectResponse
    {
        if ($request->user()) {
            $audit->record('auth.logout', null, $request->user(), [], $request);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\DevUserSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Connexion sans Entra réservée au développement local (APP_ENV=local et DEV_LOGIN_ENABLED=true).
 */
class DevLoginController extends Controller
{
    public static function isEnabled(): bool
    {
        return app()->environment('local') && (bool) config('app.dev_login_enabled');
    }

    public function __invoke(Request $request, AuditService $audit): RedirectResponse
    {
        abort_unless(self::isEnabled(), 404);

        $data = $request->validate(['account' => ['required', 'string', 'in:'.implode(',', array_keys(DevUserSeeder::ACCOUNTS))]]);
        $user = User::query()->where('email', DevUserSeeder::emailFor($data['account']))->first();
        abort_if($user === null, 404, 'Compte de démonstration absent : lancez php artisan db:seed --class=DevUserSeeder.');
        abort_if($user->disabled_at, 403, 'Ce compte est désactivé dans l’application.');

        Auth::login($user);
        $request->session()->regenerate();
        $user->update(['last_login_at' => now()]);
        $audit->record('auth.dev_login', null, $user, ['account' => $data['account']], $request);

        return redirect()->intended(route('dashboard'));
    }
}

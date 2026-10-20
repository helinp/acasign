<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditService;
use App\Support\Color;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingController extends Controller
{
    /** Réglages texte enregistrés tels quels (vide = valeur par défaut). */
    private const TEXT_KEYS = ['entity_name', 'entity_short_name', 'app_subtitle', 'email_domain', 'contact_email', 'footer_text'];

    public function edit(): View
    {
        return view('admin.settings', [
            'values' => collect(self::TEXT_KEYS)->mapWithKeys(fn (string $key) => [$key => Setting::value($key)])->all(),
            'logoPath' => Setting::logoPath(),
            'actionColor' => Setting::actionColor(),
            'brandColor' => Setting::brandColor(),
            'defaultEmailDomain' => config('services.entra.allowed_domains')[0] ?? 'exemple.be',
            'mailConfig' => [
                'mailer' => (string) config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'from' => config('mail.from.address'),
            ],
        ]);
    }

    public function update(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'entity_name' => ['nullable', 'string', 'max:120'],
            'entity_short_name' => ['nullable', 'string', 'max:30'],
            'app_subtitle' => ['nullable', 'string', 'max:60'],
            'email_domain' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/i'],
            'contact_email' => ['nullable', 'email:rfc', 'max:120'],
            'footer_text' => ['nullable', 'string', 'max:300'],
            'action_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/', $this->minimumContrast('#FFFFFF', 4.5, 'les liens et boutons')],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/', $this->minimumContrast('#F7F8FA', 3.0, 'le nom de l’établissement')],
            'reset_colors' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
            'remove_logo' => ['nullable', 'boolean'],
        ], [], [
            'app_subtitle' => 'sous-titre', 'email_domain' => 'domaine e-mail', 'contact_email' => 'e-mail de contact',
            'footer_text' => 'pied de page', 'action_color' => 'couleur des actions', 'brand_color' => 'couleur du nom',
        ]);
        foreach (self::TEXT_KEYS as $key) {
            Setting::put($key, filled($data[$key] ?? null) ? trim($data[$key]) : null);
        }
        foreach (['action_color' => Setting::DEFAULT_ACTION_COLOR, 'brand_color' => Setting::DEFAULT_BRAND_COLOR] as $key => $default) {
            $color = strtoupper((string) ($data[$key] ?? ''));
            Setting::put($key, $request->boolean('reset_colors') || ! Color::isHex($color) || $color === $default ? null : $color);
        }

        $current = Setting::logoPath();
        if ($request->hasFile('logo') || $request->boolean('remove_logo')) {
            if ($current) {
                Storage::disk('local')->delete($current);
            }
            $path = $request->hasFile('logo')
                ? $request->file('logo')->storeAs('branding', 'logo-'.now()->timestamp.'.'.$request->file('logo')->extension(), 'local')
                : null;
            Setting::put('logo_path', $path);
        }
        $audit->record('admin.settings.updated', null, $request->user(), [
            'entity_name' => Setting::value('entity_name'), 'logo' => (bool) Setting::logoPath(),
            'action_color' => Setting::actionColor(), 'brand_color' => Setting::brandColor(),
        ], $request);

        return back()->with('success', 'Réglages enregistrés.');
    }

    /**
     * Envoi immédiat (hors file d'attente) d'un e-mail de test, pour valider la configuration MAIL_*.
     */
    public function testMail(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['test_recipient' => ['required', 'email:rfc', 'max:120']], [], ['test_recipient' => 'destinataire']);
        try {
            Mail::raw(
                'Ceci est un e-mail de test envoyé par '.config('app.name').' pour '.Setting::entityName().".\n\nSi vous le recevez, l’envoi des notifications fonctionne.",
                fn ($mail) => $mail->to($data['test_recipient'])->subject('Test d’envoi – '.config('app.name')),
            );
        } catch (\Throwable $exception) {
            Log::error('Échec de l’e-mail de test.', ['to' => $data['test_recipient'], 'exception' => $exception->getMessage()]);

            return back()->withInput()->with('mail_test_error', mb_substr($exception->getMessage(), 0, 500));
        }
        $audit->record('admin.mail.tested', null, $request->user(), ['to' => $data['test_recipient'], 'mailer' => config('mail.default')], $request);

        return back()->with('success', 'E-mail de test envoyé à '.$data['test_recipient'].' : vérifiez la boîte de réception et les courriers indésirables.');
    }

    /** Refuse une couleur trop peu contrastée sur son fond (lisibilité, WCAG 1.4.3). */
    private function minimumContrast(string $background, float $minimum, string $subject): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($background, $minimum, $subject): void {
            if (Color::isHex($value) && Color::contrast($value, $background) < $minimum) {
                $fail(sprintf('Couleur trop claire pour %s : contraste %s:1, minimum %s:1. Choisissez une teinte plus foncée.', $subject, number_format(Color::contrast($value, $background), 1, ',', ''), str_replace('.', ',', (string) $minimum)));
            }
        };
    }
}

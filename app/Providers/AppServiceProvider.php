<?php

namespace App\Providers;

use App\Models\Setting;
use App\Models\SignatureRequest;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Vues « racines » : une section enfant (ex. auth.login) est rendue avant son layout.
        View::composer(['layouts.app', 'auth.login', 'verification.show'], function (\Illuminate\View\View $view): void {
            $logoPath = Setting::logoPath();
            $view->with([
                'entityName' => Setting::entityName(),
                'shortName' => Setting::shortName(),
                'appSubtitle' => Setting::subtitle(),
                'themeCss' => Setting::themeCss(),
                'contactEmail' => Setting::contactEmail(),
                'footerText' => Setting::footerText(),
                'logoUrl' => $logoPath ? route('branding.logo', ['v' => substr(md5($logoPath), 0, 8)]) : null,
                'toSignCount' => auth()->check()
                    ? SignatureRequest::query()->where('signer_email', mb_strtolower(auth()->user()->email))->where('status', 'pending')
                        ->whereHas('document', fn ($query) => $query->where('status', 'pending'))->count()
                    : 0,
            ]);
        });
    }
}

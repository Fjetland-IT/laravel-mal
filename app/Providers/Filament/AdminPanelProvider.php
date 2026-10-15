<?php

namespace App\Providers\Filament;

use App\Filament\Auth\AutoSubmittingAppAuthentication;
use App\Filament\Auth\Login;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $brand = (string) config('admin.brand');

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Egen Login-side og MFA-leverandør: fokus på kodefeltet, og avvist
            // kode tømmes (app/Filament/Auth).
            ->login(Login::class)
            ->profile(isSimple: false)
            // Tofaktor: påkrevd i produksjon, av lokalt (config/admin.php).
            ->multiFactorAuthentication(
                [
                    AutoSubmittingAppAuthentication::make()
                        ->recoverable()
                        ->regenerableRecoveryCodes()
                        ->brandName($brand),
                ],
                isRequired: config('admin.mfa_required'),
            )
            ->brandName($brand)
            ->colors([
                'primary' => Color::hex((string) config('admin.color')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

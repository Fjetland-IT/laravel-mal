<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tofaktor (MFA) på /admin
    |--------------------------------------------------------------------------
    |
    | Når denne er true må alle admin-brukere sette opp en autentiseringsapp
    | (TOTP) før de får tilgang til panelet. Standard: påkrevd i produksjon,
    | av i lokal utvikling. Kan overstyres med ADMIN_MFA_REQUIRED i .env.
    |
    | NB: her må APP_ENV leses med env(), IKKE app()->environment().
    | Konfigfilene evalueres i LoadConfiguration::bootstrap() *før*
    | $app['env'] bindes, så app()->environment() kaster
    | BindingResolutionException så tidlig i oppstarten at Laravel ikke
    | engang klarer å rendre feilsiden.
    |
    */

    'mfa_required' => filter_var(
        env('ADMIN_MFA_REQUIRED', env('APP_ENV') === 'production'),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    |--------------------------------------------------------------------------
    | Panelets utseende
    |--------------------------------------------------------------------------
    |
    | Merkenavnet vises i panelet og i autentiseringsappen ved siden av koden.
    | Fargen er primærfargen i Filament (hex).
    |
    */

    'brand' => env('ADMIN_BRAND', env('APP_NAME', 'Admin')),

    'color' => env('ADMIN_COLOR', '#2563eb'),

];

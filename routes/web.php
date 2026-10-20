<?php

use App\Http\Controllers\SitemapController;
use App\Support\IndexNow;
use App\Support\KjentEnhet;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

// SEO
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

// IndexNow-nøkkel (keyLocation). 404 når INDEXNOW_KEY ikke er satt.
Route::get('/indexnow.txt', function () {
    $key = IndexNow::key();
    abort_if($key === null, 404);

    return response($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
})->name('indexnow.key');

// Admin håndteres av Filament-panelet på /admin (app/Providers/Filament).
// Helsesjekk: /up (bootstrap/app.php).

// Merk denne enheten som kjent: innlogginger herfra varsles ikke på e-post. Krever innlogget admin.
// L5 (O17, som dash i F7c): cookien er en HMAC (bruker, utløp, salt) med ADMIN_KNOWN_DEVICE_TOKEN som nøkkel,
// ikke selve tokenet, og varer i 180 dager. 404 når ADMIN_KNOWN_DEVICE_TOKEN ikke er satt.
Route::get('/admin/kjent-enhet', function () {
    $nokkel = (string) config('mail.admin_known_device_token');
    abort_if($nokkel === '', 404);

    return response('Denne enheten er nå merket som kjent i '.KjentEnhet::DAGER.' dager. Innlogginger herfra varsles ikke lenger på e-post.')
        ->cookie(KjentEnhet::COOKIE, KjentEnhet::lag((int) auth()->id(), $nokkel), 60 * 24 * KjentEnhet::DAGER);
})->middleware(['auth'])->name('admin.known-device');

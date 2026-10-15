<?php

use App\Http\Controllers\SitemapController;
use App\Listeners\SendBackendLoginAlert;
use App\Support\IndexNow;
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

// Merk denne enheten som kjent: innlogginger herfra varsles ikke på e-post.
// Krever innlogget admin. Cookie i 5 år. 404 når ADMIN_KNOWN_DEVICE_TOKEN ikke er satt.
Route::get('/admin/kjent-enhet', function () {
    $token = config('mail.admin_known_device_token');
    abort_unless($token, 404);

    return response('Denne enheten er nå merket som kjent. Innlogginger herfra varsles ikke lenger på e-post.')
        ->cookie(SendBackendLoginAlert::KNOWN_DEVICE_COOKIE, $token, 60 * 24 * 365 * 5);
})->middleware(['auth'])->name('admin.known-device');

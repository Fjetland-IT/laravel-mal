<?php

namespace App\Providers;

use App\Mail\Transport\Smtp2goTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Carbon::setLocale('nb');

        // Egen SMTP2GO-transport (API-nøkkel), brukes med MAIL_MAILER=smtp2go.
        Mail::extend('smtp2go', function (): Smtp2goTransport {
            return new Smtp2goTransport((string) config('services.smtp2go.key'));
        });
    }
}

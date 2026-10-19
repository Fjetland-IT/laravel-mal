<?php

declare(strict_types=1);

namespace App\Support;

use App\Mail\MfaEndringVarsel;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Varsel og logglinje når autentiseringsapper endres (O15). E-posten går til ADMIN_ALERT_EMAIL, som
 * innloggingsvarselet, men uten unntak for kjente enheter. Hemmeligheten er aldri med.
 */
class MfaVarsel
{
    public static function send(string $hendelse, User $user, string $appNavn, ?string $ip = null): void
    {
        $ip ??= request()?->ip() ?? 'ukjent';
        $antall = $user->mfaApper()->count();

        Log::notice('mfa: '.$hendelse, [
            'user_id' => $user->getKey(),
            'app' => $appNavn,
            'ip' => $ip,
            'antall' => $antall,
        ]);

        $to = config('mail.admin_alert_address');

        if (! $to) {
            return;
        }

        try {
            Mail::to($to)->send(new MfaEndringVarsel(
                hendelse: $hendelse,
                appNavn: $appNavn,
                userName: (string) ($user->name ?? 'Ukjent bruker'),
                userEmail: (string) ($user->email ?? 'ukjent'),
                ip: $ip,
                antallIgjen: $antall,
            ));
        } catch (Throwable $e) {
            // En feilet e-post skal aldri stoppe endringen.
            report($e);
        }
    }
}

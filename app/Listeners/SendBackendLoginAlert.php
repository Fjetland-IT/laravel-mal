<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Mail\BackendLoginAlert;
use App\Support\KjentEnhet;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-postvarsel ved hver innlogging i /admin. Oppdages automatisk av Laravels
 * event discovery.
 *
 * Ingen varsel når ADMIN_ALERT_EMAIL er tom, eller når innloggingen skjer fra en
 * kjent enhet (signert cookie fra /admin/kjent-enhet, 180 dager; se App\Support\KjentEnhet).
 */
class SendBackendLoginAlert
{
    public const KNOWN_DEVICE_COOKIE = KjentEnhet::COOKIE;

    public function handle(Login $event): void
    {
        $to = config('mail.admin_alert_address');

        if (! $to) {
            return;
        }

        $nokkel = (string) config('mail.admin_known_device_token');
        if ($nokkel !== '' && KjentEnhet::gyldig(request()?->cookie(self::KNOWN_DEVICE_COOKIE), (int) $event->user->getAuthIdentifier(), $nokkel)) {
            return;
        }

        try {
            Mail::to($to)->send(new BackendLoginAlert(
                userName: $event->user->name ?? 'Ukjent bruker',
                userEmail: $event->user->email ?? 'ukjent',
                ip: request()?->ip() ?? 'ukjent',
                userAgent: (string) (request()?->userAgent() ?? ''),
                viaRemember: $event->remember,
            ));
        } catch (Throwable $e) {
            // En feilet e-post skal aldri stoppe en innlogging.
            report($e);
        }
    }
}

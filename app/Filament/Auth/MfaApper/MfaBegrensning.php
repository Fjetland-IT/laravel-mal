<?php

declare(strict_types=1);

namespace App\Filament\Auth\MfaApper;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Høyst fem forsøk i minuttet per bruker på «Legg til app» og «Fjern», som Filaments egne
 * MFA-handlinger. Kommer i tillegg til ->rateLimit(5) på handlingen (per IP).
 */
class MfaBegrensning
{
    public const MAKS_FORSOK = 5;

    public static function treff(string $handling, Action $action): void
    {
        $nokkel = 'mfa-apper:'.$handling.':'.Filament::getUserScopedAuthIdentifier();

        if (RateLimiter::tooManyAttempts($nokkel, self::MAKS_FORSOK)) {
            Notification::make()
                ->title('For mange forsøk')
                ->body('Vent '.RateLimiter::availableIn($nokkel).' sekunder og prøv igjen.')
                ->danger()
                ->send();

            $action->halt();
        }

        RateLimiter::hit($nokkel);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Auth\MfaApper;

use App\Filament\Auth\MfaApperAuthentication;
use App\Mail\MfaEndringVarsel;
use App\Models\User;
use App\Support\MfaVarsel;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * «Fjern» for én app på profilsiden (O15).
 *
 * Har brukeren flere apper, kreves en fersk kode fra en av de andre. Siste app kan bare fjernes når
 * panelet ikke krever MFA (lokalt); da kreves kode fra appen selv, og gjenopprettingskodene slettes
 * også, som med Filaments «Slå av».
 */
class FjernMfaAppAction
{
    public static function make(MfaApperAuthentication $mfa, int $appId): Action
    {
        $antall = static fn (): int => $mfa->bruker(Filament::auth()->user())->mfaApper()->count();

        return Action::make('fjernMfaApp')
            ->label('Fjern')
            ->color('danger')
            ->icon(Heroicon::Trash)
            ->link()
            ->visible(fn (): bool => $mfa->kanFjerne(Filament::auth()->user()))
            ->modalWidth(Width::Medium)
            ->modalIcon(Heroicon::OutlinedTrash)
            ->modalHeading(fn (): string => 'Fjern «'.($mfa->egenApp($appId)->navn ?? 'appen').'»')
            ->modalDescription(fn (): string => $antall() > 1
                ? 'Bekreft med en kode fra en av de andre appene dine. Appen du fjerner, kan ikke brukes til innlogging etterpå.'
                : 'Dette er den siste appen. Tofaktor slås av for brukeren, og gjenopprettingskodene slettes.')
            ->schema(fn (): array => [
                OneTimeCodeInput::make('kode')
                    ->label(fn (): string => $antall() > 1 ? 'Kode fra en av de andre appene' : 'Kode fra appen')
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($mfa, $appId, $antall): void {
                        $app = $mfa->egenApp($appId);
                        $unntatt = ($antall() > 1) ? $app?->getKey() : null;

                        // Sjekkes uten å bruke opp koden; den brukes opp i action().
                        if ($app && ($mfa->finnApp(Filament::auth()->user(), $value, $unntatt) !== null)) {
                            return;
                        }

                        $fail($antall() > 1
                            ? 'Koden stemmer ikke med noen av de andre appene dine, eller den er brukt før.'
                            : 'Koden stemmer ikke, eller den er brukt før.');
                    }),
            ])
            ->beforeFormValidated(fn (Action $action) => MfaBegrensning::treff('fjern', $action))
            ->modalSubmitAction(fn (Action $action) => $action->label('Fjern')->color('danger'))
            ->action(function (Action $action, array $data) use ($mfa, $appId): void {
                $user = $mfa->bruker(Filament::auth()->user());

                $resultat = DB::transaction(function () use ($mfa, $user, $appId, $data): string|array {
                    // Én endring om gangen per bruker, så to samtidige fjerninger ikke tømmer alt.
                    User::query()->whereKey($user->getKey())->lockForUpdate()->first();

                    $app = $mfa->egenApp($appId);

                    if (! $app) {
                        return 'Appen finnes ikke lenger.';
                    }

                    $antall = $user->mfaApper()->count();

                    if (($antall === 1) && $mfa->erPakrevd()) {
                        return 'Den siste appen kan ikke fjernes, fordi tofaktor er påkrevd. Legg til en annen app først.';
                    }

                    if ($mfa->brukKode($user, $data['kode'], ($antall > 1) ? $app->getKey() : null) === null) {
                        return 'Koden er brukt før eller ikke gyldig lenger. Prøv en ny.';
                    }

                    $navn = $app->navn;
                    $app->delete();
                    $mfa->glemGammelHemmelighet($user);

                    if (($antall === 1) && $mfa->isRecoverable()) {
                        $mfa->saveRecoveryCodes($user, null);
                    }

                    return ['navn' => $navn];
                });

                if (is_string($resultat)) {
                    Notification::make()->title($resultat)->danger()->send();
                    $action->halt();
                }

                MfaVarsel::send(MfaEndringVarsel::FJERNET, $user, $resultat['navn']);

                Notification::make()
                    ->title('Appen er fjernet')
                    ->success()
                    ->send();

                $mfa->lastSidenPaNytt($action);
            })
            ->rateLimit(5);
    }
}

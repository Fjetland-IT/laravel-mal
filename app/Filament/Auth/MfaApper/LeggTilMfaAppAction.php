<?php

declare(strict_types=1);

namespace App\Filament\Auth\MfaApper;

use App\Filament\Auth\MfaApperAuthentication;
use App\Mail\MfaEndringVarsel;
use App\Models\MfaApp;
use App\Models\User;
use App\Support\MfaVarsel;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * «Legg til app» på profilsiden (O15): app nr. 2 og oppover.
 *
 * Krever en kode fra den nye appen (bevis på at QR-koden er skannet) og en fersk kode fra en app
 * brukeren har fra før, så en kapret økt ikke kan legge til angriperens app. Hemmeligheten ligger
 * kryptert i handlingens argumenter til den lagres, og vises aldri igjen etterpå.
 */
class LeggTilMfaAppAction
{
    public static function make(MfaApperAuthentication $mfa): Action
    {
        $hemmelighet = static fn (Action $action): string => decrypt($action->getArguments()['encrypted'])['secret'];

        return Action::make('leggTilMfaApp')
            ->label('Legg til app')
            ->color('primary')
            ->icon(Heroicon::Plus)
            ->link()
            ->mountUsing(function (HasActions $livewire, Schema $schema) use ($mfa): void {
                $schema->fill();

                $livewire->mergeMountedActionArguments([
                    'encrypted' => encrypt([
                        'secret' => $mfa->generateSecret(),
                        'userKey' => Filament::getUserScopedAuthIdentifier(),
                    ]),
                ]);
            })
            ->modalWidth(Width::Large)
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->modalIcon(Heroicon::OutlinedDevicePhoneMobile)
            ->modalIconColor('primary')
            ->modalHeading('Legg til autentiseringsapp')
            ->modalDescription('Skann QR-koden med den nye appen. Bekreft med en kode fra den nye appen og en kode fra en app du har registrert fra før.')
            ->schema(fn (Action $action): array => [
                TextInput::make('navn')
                    ->label('Navn på appen')
                    ->placeholder('For eksempel iPhone eller 1Password')
                    ->required()
                    ->maxLength(MfaApp::NAVN_MAKS)
                    ->regex(MfaApp::NAVN_REGEX)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($mfa): void {
                        if (is_string($value) && self::navnErBrukt($mfa->bruker(Filament::auth()->user()), $value)) {
                            $fail('Du har allerede en app med dette navnet.');
                        }
                    }),
                Group::make([
                    Text::make('Skann QR-koden med den nye appen:')
                        ->color('neutral'),
                    Image::make(
                        url: fn (): string => $mfa->generateQrCodeDataUri($hemmelighet($action)),
                        alt: 'QR-kode for den nye autentiseringsappen',
                    )
                        ->imageHeight('12rem')
                        ->alignCenter(),
                    Flex::make([
                        Text::make('Eller skriv inn denne koden:')
                            ->color('neutral')
                            ->grow(false),
                        Text::make(fn (): string => $hemmelighet($action))
                            ->fontFamily(FontFamily::Mono)
                            ->color('neutral')
                            ->copyable()
                            ->copyMessage('Kopiert')
                            ->grow(false),
                    ])->from('sm'),
                ])->dense(),
                OneTimeCodeInput::make('kodeNy')
                    ->label('Kode fra den nye appen')
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($action, $hemmelighet, $mfa): void {
                        if (is_string($value) && ($mfa->tidssteg($hemmelighet($action), $value) !== null)) {
                            return;
                        }

                        $fail('Koden stemmer ikke med den nye appen.');
                    }),
                OneTimeCodeInput::make('kodeEksisterende')
                    ->label('Kode fra en app du har fra før')
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($mfa): void {
                        // Sjekkes uten å bruke opp koden; den brukes opp i action() når alt annet er gyldig.
                        if ($mfa->finnApp(Filament::auth()->user(), $value) !== null) {
                            return;
                        }

                        $fail('Koden stemmer ikke med noen av appene dine, eller den er brukt før.');
                    }),
            ])
            ->beforeFormValidated(fn (Action $action) => MfaBegrensning::treff('legg_til', $action))
            ->modalSubmitAction(fn (Action $action) => $action->label('Legg til'))
            ->action(function (Action $action, array $arguments, array $data) use ($mfa): void {
                $user = $mfa->bruker(Filament::auth()->user());
                $kryptert = decrypt($arguments['encrypted']);

                if (Filament::getUserScopedAuthIdentifier($user) !== $kryptert['userKey']) {
                    // Krypterte argumenter skal ikke kunne flyttes mellom brukere (som i Filament).
                    Log::warning('mfa: legg til avvist, argumentene gjelder en annen bruker', ['user_id' => $user->getKey()]);
                    Notification::make()->title('Dialogen gjelder ikke denne brukeren. Last siden på nytt.')->danger()->send();
                    $action->halt();
                }

                $navn = trim((string) $data['navn']);
                $nyttSteg = $mfa->tidssteg($kryptert['secret'], (string) $data['kodeNy']);

                try {
                    $resultat = DB::transaction(function () use ($mfa, $user, $navn, $kryptert, $data, $nyttSteg): string|MfaApp {
                        // Én endring om gangen per bruker.
                        User::query()->whereKey($user->getKey())->lockForUpdate()->first();

                        if ($nyttSteg === null) {
                            return 'Koden fra den nye appen er ikke gyldig lenger. Prøv en ny.';
                        }

                        if ($user->mfaApper()->count() >= MfaApp::MAKS) {
                            return 'Du har allerede '.MfaApp::MAKS.' apper. Fjern en før du legger til en ny.';
                        }

                        if (self::navnErBrukt($user, $navn)) {
                            return 'Du har allerede en app med dette navnet.';
                        }

                        if ($mfa->brukKode($user, $data['kodeEksisterende']) === null) {
                            return 'Koden fra den eksisterende appen er brukt før eller ikke gyldig lenger. Prøv en ny.';
                        }

                        $app = $user->mfaApper()->create([
                            'navn' => $navn,
                            'hemmelighet' => $kryptert['secret'],
                        ]);

                        // Koden fra den nye appen kan ikke brukes igjen ved innlogging.
                        $app->forceFill(['sist_brukt_steg' => $nyttSteg])->save();

                        return $app;
                    });
                } catch (UniqueConstraintViolationException) {
                    // Databasen kan regne navn som like der PHP ikke gjør det (aksenter, ß/ss i MySQL).
                    $resultat = 'Du har allerede en app med dette navnet.';
                }

                if (is_string($resultat)) {
                    Notification::make()->title($resultat)->danger()->send();
                    $action->halt();
                }

                MfaVarsel::send(MfaEndringVarsel::LAGT_TIL, $user, $resultat->navn);

                Notification::make()
                    ->title('Appen er lagt til')
                    ->success()
                    ->send();

                $mfa->lastSidenPaNytt($action);
            })
            ->rateLimit(5);
    }

    public static function navnErBrukt(User $user, string $navn, ?int $unntatt = null): bool
    {
        return $user->mfaApper()
            ->when($unntatt !== null, fn ($q) => $q->whereKeyNot($unntatt))
            ->get(['id', 'navn'])
            ->contains(fn (MfaApp $app): bool => mb_strtolower($app->navn) === mb_strtolower(trim($navn)));
    }
}

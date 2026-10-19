<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Filament\Auth\MfaApper\FjernMfaAppAction;
use App\Filament\Auth\MfaApper\GiNyttNavnMfaAppAction;
use App\Filament\Auth\MfaApper\LeggTilMfaAppAction;
use App\Mail\MfaEndringVarsel;
use App\Models\MfaApp;
use App\Models\User;
use App\Support\MfaVarsel;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\Actions\RegenerateAppAuthenticationRecoveryCodesAction;
use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use LogicException;
use SensitiveParameter;

/**
 * Flere autentiseringsapper (TOTP) per bruker (O15).
 *
 * Filaments AppAuthentication har én hemmelighet per bruker (users.app_authentication_secret). Denne
 * leverandøren leser i stedet tabellen mfa_apper (App\Models\MfaApp), høyst MfaApp::MAKS per bruker:
 *
 *  - Innlogging godtar en gyldig kode fra hvilken som helst av brukerens apper, med samme kodevindu og
 *    samme rate limiting som før (Filaments innloggingsside). En kode fra samme eller et eldre
 *    tidssteg avvises per app (sist_brukt_steg).
 *  - Profilsiden lister appene (navn, lagt til, sist brukt) og har «Legg til app», «Gi nytt navn» og
 *    «Fjern». Første app settes opp med Filaments egen handling (passord, QR, gjenopprettingskoder som
 *    før); den lagres som «Første app». Nye apper krever kode fra den nye appen og en fersk kode fra en
 *    eksisterende. Fjerning krever kode fra en av de gjenværende, og siste app kan ikke fjernes når
 *    panelet krever MFA.
 *  - Gjenopprettingskodene ligger fortsatt på users og virker som før.
 *  - Nødvei: `php artisan mfa:nullstill <e-post>` på serveren (App\Console\Commands\MfaNullstill).
 *
 * getId() er fortsatt «app», så tilstanden heter data.multiFactor.app.* som før (Login tømmer den).
 */
class MfaApperAuthentication extends AutoSubmittingAppAuthentication
{
    public function isEnabled(Authenticatable $user): bool
    {
        return $this->bruker($user)->mfaApper()->exists();
    }

    /**
     * @return Collection<int, MfaApp>
     */
    public function apper(Authenticatable $user): Collection
    {
        return $this->bruker($user)->mfaApper()->get();
    }

    public function getSecret(HasAppAuthentication $user): string
    {
        throw new LogicException('En bruker kan ha flere hemmeligheter (mfa_apper). Bruk finnApp() eller brukKode().');
    }

    /**
     * Kalles av Filaments oppsett-handling når brukeren ikke har noen app: den første lagres som
     * «Første app». Med null (Filaments deaktivering, som ikke vises her) slettes alle appene.
     */
    public function saveSecret(HasAppAuthentication $user, #[SensitiveParameter] ?string $secret): void
    {
        $bruker = $this->bruker($user);

        if ($secret === null) {
            // Filaments «Slå av» vises ikke her; vern likevel regelen om siste app.
            if ($this->erPakrevd()) {
                throw new LogicException('Tofaktor er påkrevd; siste app kan ikke fjernes.');
            }

            $bruker->mfaApper()->delete();
            $this->glemGammelHemmelighet($bruker);
            DB::afterCommit(fn () => MfaVarsel::send(MfaEndringVarsel::FJERNET, $bruker, 'alle'));

            return;
        }

        if ($bruker->mfaApper()->exists()) {
            throw new LogicException('Brukeren har allerede en app. Nye apper legges til med «Legg til app».');
        }

        $app = $bruker->mfaApper()->create([
            'navn' => MfaApp::FORSTE_NAVN,
            'hemmelighet' => $secret,
        ]);

        // Koden fra oppsettet (og eldre koder) skal ikke kunne brukes ved innlogging. Steget koden kom
        // fra, kjenner vi ikke her; dagens steg dekker den i praksis.
        $app->forceFill(['sist_brukt_steg' => $this->google2FA->getTimestamp()])->save();

        // Etter commit: Filament lagrer i en transaksjon, og et varsel om noe som ble rullet tilbake er feil.
        DB::afterCommit(fn () => MfaVarsel::send(MfaEndringVarsel::LAGT_TIL, $bruker, MfaApp::FORSTE_NAVN));
    }

    /**
     * Tømmer Filaments gamle enkelthemmelighet for brukeren (users.app_authentication_secret, til kolonnen
     * er fjernet), så en tilbakerulling ikke gjør en fjernet app gyldig igjen.
     */
    public function glemGammelHemmelighet(User $user): void
    {
        if (Schema::hasColumn($user->getTable(), 'app_authentication_secret')) {
            DB::table($user->getTable())->where($user->getKeyName(), $user->getKey())->update(['app_authentication_secret' => null]);
        }
    }

    /**
     * Med $secret: sjekker en kode mot én bestemt hemmelighet (oppsett av en ny app), som Filament.
     * Uten: godtar koden fra en av den innloggede brukerens apper og sperrer den mot gjenbruk.
     * Filaments «Lag nye gjenopprettingskoder» går hit.
     */
    public function verifyCode(#[SensitiveParameter] string $code, #[SensitiveParameter] ?string $secret = null, bool $shouldPreventCodeReuse = false): bool
    {
        if ($secret !== null) {
            return parent::verifyCode($code, $secret, $shouldPreventCodeReuse);
        }

        return $this->brukKode(Filament::auth()->user(), $code) !== null;
    }

    /**
     * Appen koden kommer fra, uten å bruke den opp. Koder som allerede er brukt (samme eller eldre
     * tidssteg), gir null.
     */
    public function finnApp(Authenticatable $user, #[SensitiveParameter] mixed $kode, ?int $unntatt = null): ?MfaApp
    {
        if (! is_string($kode)) {
            return null;
        }

        foreach ($this->apper($user) as $app) {
            if (($app->getKey() !== $unntatt) && ($this->tidssteg($app->hemmelighet, $kode, $app->sist_brukt_steg) !== null)) {
                return $app;
            }
        }

        return null;
    }

    /**
     * Godtar koden fra en av brukerens apper (unntatt $unntatt), oppdaterer «sist brukt» og sperrer
     * koden og alle eldre koder fra samme app. Gir appen, eller null.
     *
     * Sperren er en betinget UPDATE (sist_brukt_steg < nytt steg), så to samtidige forsøk med samme
     * kode ikke begge kan lykkes.
     */
    public function brukKode(Authenticatable $user, #[SensitiveParameter] mixed $kode, ?int $unntatt = null): ?MfaApp
    {
        if (! is_string($kode)) {
            return null;
        }

        foreach ($this->apper($user) as $app) {
            if ($app->getKey() === $unntatt) {
                continue;
            }

            $steg = $this->tidssteg($app->hemmelighet, $kode, $app->sist_brukt_steg);

            if ($steg === null) {
                continue;
            }

            $naa = now();

            $oppdatert = MfaApp::query()
                ->whereKey($app->getKey())
                ->where(fn ($q) => $q->whereNull('sist_brukt_steg')->orWhere('sist_brukt_steg', '<', $steg))
                ->update(['sist_brukt_steg' => $steg, 'sist_brukt_at' => $naa]);

            if ($oppdatert === 1) {
                $app->forceFill(['sist_brukt_steg' => $steg, 'sist_brukt_at' => $naa])->syncOriginal();

                return $app;
            }
        }

        return null;
    }

    /**
     * Tidssteget koden hører til for hemmeligheten, innenfor kodevinduet og nyere enn $forrigeSteg.
     */
    public function tidssteg(#[SensitiveParameter] string $hemmelighet, #[SensitiveParameter] string $kode, ?int $forrigeSteg = null): ?int
    {
        if (preg_match('/^\d{6}$/', $kode) !== 1) {
            return null;
        }

        // Med et forrige steg gir verifyKeyNewer() tidssteget i stedet for true.
        $forrigeSteg ??= $this->google2FA->getTimestamp() - $this->getCodeWindow() - 1;

        $steg = $this->google2FA->verifyKeyNewer($hemmelighet, $kode, $forrigeSteg, $this->getCodeWindow());

        return is_int($steg) ? $steg : null;
    }

    /** Om panelet krever MFA, så siste app ikke kan fjernes. Uten panel: config/admin.php. */
    public function erPakrevd(): bool
    {
        $panel = Filament::getCurrentPanel();

        return $panel
            ? $panel->isMultiFactorAuthenticationRequired()
            : (bool) config('admin.mfa_required', true);
    }

    public function kanFjerne(Authenticatable $user): bool
    {
        $antall = $this->bruker($user)->mfaApper()->count();

        return ($antall > 1) || (($antall === 1) && (! $this->erPakrevd()));
    }

    /**
     * Appen med denne ID-en, bare blant den innloggede brukerens egne apper.
     */
    public function egenApp(int $id): ?MfaApp
    {
        return $this->bruker(Filament::auth()->user())->mfaApper()->whereKey($id)->first();
    }

    /**
     * Laster siden på nytt etter en endring i lista. Filament mellomlagrer skjemaet i Livewire-komponenten,
     * så nye eller fjernede rader vises ellers først etter neste sideinnlasting. Varslingen følger med.
     */
    public function lastSidenPaNytt(Action $action): void
    {
        $livewire = $action->getLivewire();

        if (! $livewire instanceof Component) {
            return;
        }

        $url = ($livewire instanceof SetUpRequiredMultiFactorAuthentication)
            ? Filament::getSetUpRequiredMultiFactorAuthenticationUrl()
            : Filament::getProfileUrl();

        if (filled($url)) {
            $livewire->redirect($url);
        }
    }

    public function bruker(?Authenticatable $user): User
    {
        if (! $user instanceof User) {
            throw new LogicException('MfaApperAuthentication krever App\Models\User.');
        }

        return $user;
    }

    /**
     * Innloggingsutfordringen: som Filaments, men koden sjekkes mot alle brukerens apper.
     *
     * @param  Authenticatable&User  $user
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        $isRecoverable = $this->isRecoverable();

        return $this->medFokus([
            OneTimeCodeInput::make('code')
                ->label('Kode fra en av autentiseringsappene dine')
                ->submitOnCompletion()
                ->belowContent(fn (Get $get): Action => Action::make('useRecoveryCode')
                    ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.code.actions.use_recovery_code.label'))
                    ->link()
                    ->action(fn (Set $set) => $set('useRecoveryCode', true))
                    ->visible(fn (): bool => $isRecoverable && (! $get('useRecoveryCode'))))
                ->validationAttribute(__('filament-panels::auth/multi-factor/app/provider.login_form.code.validation_attribute'))
                ->required(fn (Get $get): bool => (! $isRecoverable) || (! $get('useRecoveryCode')) || blank($get('recoveryCode')))
                ->rule(function () use ($user): Closure {
                    return function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                        if ($this->brukKode($user, $value) !== null) {
                            return;
                        }

                        $fail(__('filament-panels::auth/multi-factor/app/provider.login_form.code.messages.invalid'));
                    };
                }),
            TextInput::make('recoveryCode')
                ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.label'))
                ->validationAttribute(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.validation_attribute'))
                ->password()
                ->revealable(Filament::arePasswordsRevealable())
                ->autocomplete('one-time-code')
                ->rule(function () use ($user): Closure {
                    return function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                        if (blank($value)) {
                            return;
                        }

                        if (is_string($value) && $this->verifyRecoveryCode($value, $user)) {
                            return;
                        }

                        $fail(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.messages.invalid'));
                    };
                })
                ->visible(fn (Get $get): bool => $isRecoverable && $get('useRecoveryCode'))
                ->live(onBlur: true),
        ]);
    }

    /**
     * Profilsiden (og «Sett opp tofaktor»-siden): handlingene, lista over apper og oppfordringen om
     * en app til.
     */
    public function getManagementSchemaComponents(): array
    {
        $user = $this->bruker(Filament::auth()->user());
        $apper = $this->apper($user);

        $komponenter = [
            Actions::make($this->getActions())
                ->label('Autentiseringsapper')
                ->belowContent('En kode fra hvilken som helst av appene gir innlogging. Registrer gjerne to, '
                    .'for eksempel på telefonen og i passordbehandleren, så mister du ikke tilgangen om én blir borte. '
                    .'Høyst '.MfaApp::MAKS.'.')
                ->afterLabel(fn (): Text => $apper->isNotEmpty()
                    ? Text::make($apper->count() === 1 ? '1 app' : $apper->count().' apper')->badge()->color('success')
                    : Text::make(__('filament-panels::auth/multi-factor/app/provider.management_schema.actions.messages.disabled'))->badge())
                ->key('mfa-apper-handlinger'),
        ];

        foreach ($apper as $app) {
            $komponenter[] = Flex::make([
                Group::make([
                    Text::make($app->navn)
                        ->weight(FontWeight::Bold)
                        ->color('neutral'),
                    Text::make($this->beskrivelse($app))
                        ->size(TextSize::Small)
                        ->color('gray'),
                ])->dense(),
                // Én handlingsgruppe per app (egen nøkkel), så app-ID-en bindes på serveren og ikke
                // kommer fra klienten.
                Actions::make([
                    GiNyttNavnMfaAppAction::make($this, $app->getKey()),
                    FjernMfaAppAction::make($this, $app->getKey()),
                ])
                    ->alignEnd()
                    ->grow(false)
                    ->key('mfa-app-'.$app->getKey()),
            ])->from('sm')->key('mfa-app-rad-'.$app->getKey());
        }

        if ($apper->count() === 1) {
            $komponenter[] = Text::make('Du har bare én autentiseringsapp. Legg inn en til, for eksempel i passordbehandleren, '
                .'så kommer du inn selv om telefonen blir borte.')
                ->color('warning');
        }

        return $komponenter;
    }

    /**
     * @return array<Action>
     */
    public function getActions(): array
    {
        $user = $this->bruker(Filament::auth()->user());

        return [
            // Første app: Filaments egen oppsett (passord, QR, gjenopprettingskoder). Lagres via saveSecret().
            SetUpAppAuthenticationAction::make($this)
                ->hidden(fn (): bool => $this->isEnabled($user))
                ->after(fn (Action $action) => $this->lastSidenPaNytt($action)),
            LeggTilMfaAppAction::make($this)
                ->visible(fn (): bool => $this->isEnabled($user) && ($user->mfaApper()->count() < MfaApp::MAKS)),
            RegenerateAppAuthenticationRecoveryCodesAction::make($this)
                ->visible(fn (): bool => $this->isEnabled($user) && $this->isRecoverable() && $this->canRegenerateRecoveryCodes()),
        ];
    }

    protected function beskrivelse(MfaApp $app): string
    {
        $lagtTil = 'Lagt til '.$app->created_at->timezone('Europe/Oslo')->format('d.m.Y');

        $sistBrukt = $app->sist_brukt_at
            ? 'sist brukt '.$app->sist_brukt_at->timezone('Europe/Oslo')->format('d.m.Y H:i')
            : 'ikke brukt ennå';

        return $lagtTil.' · '.$sistBrukt;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Auth\MfaApper\MfaBegrensning;
use App\Filament\Auth\MfaApperAuthentication;
use App\Mail\MfaEndringVarsel;
use App\Models\MfaApp;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

/**
 * Flere autentiseringsapper per bruker (O15): innlogging, profilsiden, migreringen og mfa:nullstill.
 */
class MfaApperTest extends TestCase
{
    use RefreshDatabase;

    private const HANDLINGER = 'app.mfa-apper-handlinger';

    private MfaApperAuthentication $mfa;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['mail.admin_alert_address' => 'varsel@example.test']);

        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        // Testmiljøet krever ikke MFA (config/admin.php); testene som trenger det, slår det på selv.
        $panel->requiresMultiFactorAuthentication(false);

        $this->mfa = collect(Filament::getMultiFactorAuthenticationProviders())
            ->first(fn ($provider) => $provider instanceof MfaApperAuthentication);
        $this->assertNotNull($this->mfa, 'Panelet skal bruke MfaApperAuthentication.');

        $this->google2fa = app(Google2FA::class);
    }

    /** Lager en app for brukeren og gir hemmeligheten. */
    private function nyApp(User $user, string $navn): string
    {
        $hemmelighet = $this->mfa->generateSecret();
        $user->mfaApper()->create(['navn' => $navn, 'hemmelighet' => $hemmelighet]);

        return $hemmelighet;
    }

    /** Koden for hemmeligheten, $steg tidssteg (30 s) fra nå. */
    private function kode(string $hemmelighet, int $steg = 0): string
    {
        return $this->google2fa->oathTotp($hemmelighet, $this->google2fa->getTimestamp() + $steg);
    }

    /** En sekssifret kode som ikke er gyldig for noen av hemmelighetene i kodevinduet. */
    private function ugyldigKode(string ...$hemmeligheter): string
    {
        foreach (['000000', '111111', '222222', '333333', '444444'] as $kandidat) {
            foreach ($hemmeligheter as $hemmelighet) {
                if ($this->mfa->tidssteg($hemmelighet, $kandidat) !== null) {
                    continue 2;
                }
            }

            return $kandidat;
        }

        $this->fail('Fant ingen ugyldig kode å teste med.');
    }

    private function utfordring(User $user): Testable
    {
        // Filaments rate limiting på innloggingen (5 i minuttet) gjelder her også; testene logger inn
        // flere ganger på rad.
        Cache::flush();

        return Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password', 'remember' => false])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertNotSet('userUndertakingMultiFactorAuthentication', null);
    }

    private function loggInnMedKode(User $user, string $kode): Testable
    {
        return $this->utfordring($user)
            ->set('data.multiFactor.app.code', $kode)
            ->call('authenticate');
    }

    private function profil(User $user): Testable
    {
        $this->actingAs($user);

        return Livewire::test(EditProfile::class);
    }

    private function radHandling(string $navn, MfaApp $app): TestAction
    {
        return TestAction::make($navn)->schemaComponent("app.mfa-app-rad-{$app->getKey()}.mfa-app-{$app->getKey()}", 'content');
    }

    /** Åpner «Legg til app» og gir hemmeligheten den nye appen fikk. */
    private function startLeggTil(Testable $profil): string
    {
        $profil->mountAction(TestAction::make('leggTilMfaApp')->schemaComponent(self::HANDLINGER, 'content'));

        $mounted = $profil->instance()->mountedActions;

        return decrypt(end($mounted)['arguments']['encrypted'])['secret'];
    }

    // --- Innlogging ---

    public function test_kode_fra_hver_av_to_apper_gir_innlogging(): void
    {
        $user = User::factory()->create();
        $telefon = $this->nyApp($user, 'Telefon');
        $passord = $this->nyApp($user, '1Password');

        $this->loggInnMedKode($user, $this->kode($telefon))->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->mfaApper()->where('navn', 'Telefon')->value('sist_brukt_at'));

        auth()->logout();

        $this->loggInnMedKode($user, $this->kode($passord))->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->mfaApper()->where('navn', '1Password')->value('sist_brukt_steg'));
    }

    public function test_feil_kode_avvises(): void
    {
        $user = User::factory()->create();
        $a = $this->nyApp($user, 'Telefon');
        $b = $this->nyApp($user, '1Password');

        $this->loggInnMedKode($user, $this->ugyldigKode($a, $b))
            ->assertHasErrors('data.multiFactor.app.code')
            ->assertSet('data.multiFactor.app.code', null);

        $this->assertGuest();
    }

    public function test_gjenbrukt_kode_avvises_og_eldre_koder_fra_samme_app_ogsa(): void
    {
        $user = User::factory()->create();
        $telefon = $this->nyApp($user, 'Telefon');
        $this->nyApp($user, '1Password');

        $this->loggInnMedKode($user, $this->kode($telefon))->assertHasNoErrors();
        auth()->logout();

        $this->loggInnMedKode($user, $this->kode($telefon))->assertHasErrors('data.multiFactor.app.code');
        $this->assertGuest();

        // En eldre kode fra samme app (forrige tidssteg) er også sperret.
        $this->loggInnMedKode($user, $this->kode($telefon, -1))->assertHasErrors('data.multiFactor.app.code');
        $this->assertGuest();
    }

    public function test_kode_fra_fjernet_app_avvises(): void
    {
        $user = User::factory()->create();
        $gammel = $this->nyApp($user, 'Gammel telefon');
        $this->nyApp($user, 'Ny telefon');

        $user->mfaApper()->where('navn', 'Gammel telefon')->delete();

        $this->loggInnMedKode($user, $this->kode($gammel))->assertHasErrors('data.multiFactor.app.code');
        $this->assertGuest();
    }

    public function test_samtidig_bruk_av_samme_kode_godtas_bare_en_gang(): void
    {
        $user = User::factory()->create();
        $telefon = $this->nyApp($user, 'Telefon');
        $kode = $this->kode($telefon);

        $this->assertNotNull($this->mfa->brukKode($user, $kode));
        $this->assertNull($this->mfa->brukKode($user, $kode));
    }

    // --- Legg til ---

    public function test_legg_til_app_med_kode_fra_ny_og_eksisterende_app(): void
    {
        $user = User::factory()->create();
        $telefon = $this->nyApp($user, 'Telefon');

        $profil = $this->profil($user);
        $ny = $this->startLeggTil($profil);

        $profil->setActionData([
            'navn' => '1Password',
            'kodeNy' => $this->kode($ny),
            'kodeEksisterende' => $this->kode($telefon),
        ])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            // Siden lastes på nytt, så lista viser den nye appen (Filament mellomlagrer skjemaet).
            ->assertRedirect(Filament::getProfileUrl());

        $app = $user->mfaApper()->where('navn', '1Password')->first();
        $this->assertNotNull($app);
        $this->assertSame($ny, $app->hemmelighet);
        $this->assertNotNull($app->sist_brukt_steg, 'Koden fra den nye appen skal ikke kunne brukes ved innlogging.');
        $this->assertSame(2, $user->mfaApper()->count());

        Mail::assertSent(MfaEndringVarsel::class, fn (MfaEndringVarsel $mail): bool => $mail->hasTo('varsel@example.test')
            && ($mail->hendelse === MfaEndringVarsel::LAGT_TIL)
            && ($mail->appNavn === '1Password')
            && ($mail->antallIgjen === 2));

        // Den nye appen gir innlogging med neste kode.
        auth()->logout();
        $this->loggInnMedKode($user, $this->kode($ny, 1))->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_legg_til_uten_fersk_kode_fra_eksisterende_app_avvises(): void
    {
        $user = User::factory()->create();
        $telefon = $this->nyApp($user, 'Telefon');

        $profil = $this->profil($user);
        $ny = $this->startLeggTil($profil);

        // Uten kode.
        $profil->setActionData(['navn' => 'Angriper', 'kodeNy' => $this->kode($ny), 'kodeEksisterende' => ''])
            ->callMountedAction()
            ->assertHasFormErrors(['kodeEksisterende']);

        // Med en kode fra den nye appen i stedet for en eksisterende.
        $profil->setActionData(['navn' => 'Angriper', 'kodeNy' => $this->kode($ny), 'kodeEksisterende' => $this->kode($ny)])
            ->callMountedAction()
            ->assertHasFormErrors(['kodeEksisterende']);

        // Med en kode som allerede er brukt til innlogging.
        $brukt = $this->kode($telefon);
        $this->assertNotNull($this->mfa->brukKode($user, $brukt));
        $profil->setActionData(['navn' => 'Angriper', 'kodeNy' => $this->kode($ny), 'kodeEksisterende' => $brukt])
            ->callMountedAction()
            ->assertHasFormErrors(['kodeEksisterende']);

        $this->assertSame(['Telefon'], $user->mfaApper()->pluck('navn')->all());
        Mail::assertNotSent(MfaEndringVarsel::class);
    }

    public function test_legg_til_med_feil_kode_fra_ny_app_eller_brukt_navn_avvises(): void
    {
        $user = User::factory()->create();
        $telefon = $this->nyApp($user, 'Telefon');

        $profil = $this->profil($user);
        $ny = $this->startLeggTil($profil);

        $profil->setActionData(['navn' => 'Nettbrett', 'kodeNy' => $this->ugyldigKode($ny), 'kodeEksisterende' => $this->kode($telefon)])
            ->callMountedAction()
            ->assertHasFormErrors(['kodeNy']);

        $profil->setActionData(['navn' => 'telefon', 'kodeNy' => $this->kode($ny), 'kodeEksisterende' => $this->kode($telefon)])
            ->callMountedAction()
            ->assertHasFormErrors(['navn']);

        $this->assertSame(1, $user->mfaApper()->count());
    }

    public function test_hoyst_fem_apper(): void
    {
        $user = User::factory()->create();

        foreach (range(1, MfaApp::MAKS - 1) as $i) {
            $this->nyApp($user, "App {$i}");
        }

        $this->profil($user)->assertActionVisible(TestAction::make('leggTilMfaApp')->schemaComponent(self::HANDLINGER, 'content'));

        $this->nyApp($user, 'App 5');

        $this->profil($user)
            ->assertActionDoesNotExist(TestAction::make('leggTilMfaApp')->schemaComponent(self::HANDLINGER, 'content'));
    }

    public function test_sjette_app_stoppes_pa_serveren(): void
    {
        $user = User::factory()->create();
        $forste = $this->nyApp($user, 'App 1');

        foreach (range(2, MfaApp::MAKS - 1) as $i) {
            $this->nyApp($user, "App {$i}");
        }

        $profil = $this->profil($user);
        $ny = $this->startLeggTil($profil);

        // En annen fane legger til nr. 5 mens dialogen står åpen.
        $this->nyApp($user, 'App 5');

        $profil->setActionData(['navn' => 'App 6', 'kodeNy' => $this->kode($ny), 'kodeEksisterende' => $this->kode($forste)])
            ->callMountedAction();

        $this->assertSame(MfaApp::MAKS, $user->mfaApper()->count());
        $this->assertFalse($user->mfaApper()->where('navn', 'App 6')->exists());
    }

    // --- Fjern ---

    public function test_siste_app_kan_ikke_fjernes_nar_mfa_er_pakrevd(): void
    {
        Filament::getCurrentPanel()->requiresMultiFactorAuthentication();

        $user = User::factory()->create();
        $this->nyApp($user, 'Telefon');
        $app = $user->mfaApper()->first();

        $this->profil($user)
            ->assertActionDoesNotExist($this->radHandling('fjernMfaApp', $app));

        $this->assertFalse($this->mfa->kanFjerne($user));
    }

    public function test_siste_app_stoppes_pa_serveren_ogsa_nar_den_andre_fjernes_samtidig(): void
    {
        Filament::getCurrentPanel()->requiresMultiFactorAuthentication();

        $user = User::factory()->create();
        $this->nyApp($user, 'A');
        $b = $this->nyApp($user, 'B');
        $appA = $user->mfaApper()->where('navn', 'A')->first();
        $appB = $user->mfaApper()->where('navn', 'B')->first();

        // Dialogen for å fjerne B er åpen da A fjernes i en annen fane. B er da siste app, og selv med
        // en gyldig kode skal serveren la den stå (handlingen er skjult når den sendes, og action()
        // sjekker antallet på nytt i en transaksjon).
        $profil = $this->profil($user)->mountAction($this->radHandling('fjernMfaApp', $appB));
        $appA->delete();

        $profil->setActionData(['kode' => $this->kode($b)])
            ->callMountedAction();

        $this->assertSame(['B'], $user->mfaApper()->pluck('navn')->all());
        Mail::assertNotSent(MfaEndringVarsel::class);
    }

    public function test_fjerning_krever_kode_fra_en_av_de_andre_appene(): void
    {
        Filament::getCurrentPanel()->requiresMultiFactorAuthentication();

        $user = User::factory()->create();
        $gammel = $this->nyApp($user, 'Gammel telefon');
        $ny = $this->nyApp($user, 'Ny telefon');
        $app = $user->mfaApper()->where('navn', 'Gammel telefon')->first();

        $profil = $this->profil($user)
            ->assertActionVisible($this->radHandling('fjernMfaApp', $app));

        // Uten kode, med feil kode, og med kode fra appen som skal fjernes.
        $profil->callAction($this->radHandling('fjernMfaApp', $app), ['kode' => ''])
            ->assertHasFormErrors(['kode']);
        $profil->callAction($this->radHandling('fjernMfaApp', $app), ['kode' => $this->ugyldigKode($gammel, $ny)])
            ->assertHasFormErrors(['kode']);
        $profil->callAction($this->radHandling('fjernMfaApp', $app), ['kode' => $this->kode($gammel)])
            ->assertHasFormErrors(['kode']);

        $this->assertSame(2, $user->mfaApper()->count());

        $profil->callAction($this->radHandling('fjernMfaApp', $app), ['kode' => $this->kode($ny)])
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getProfileUrl());

        $this->assertSame(['Ny telefon'], $user->mfaApper()->pluck('navn')->all());

        Mail::assertSent(MfaEndringVarsel::class, fn (MfaEndringVarsel $mail): bool => ($mail->hendelse === MfaEndringVarsel::FJERNET)
            && ($mail->appNavn === 'Gammel telefon')
            && ($mail->antallIgjen === 1));
    }

    public function test_siste_app_kan_fjernes_nar_mfa_ikke_er_pakrevd_og_tar_gjenopprettingskodene(): void
    {
        $user = User::factory()->create();
        $hemmelighet = $this->nyApp($user, 'Telefon');
        $user->saveAppAuthenticationRecoveryCodes([Hash::make('kode-1')]);
        $app = $user->mfaApper()->first();

        $this->profil($user)
            ->callAction($this->radHandling('fjernMfaApp', $app), ['kode' => $this->kode($hemmelighet)])
            ->assertHasNoFormErrors();

        $this->assertSame(0, $user->mfaApper()->count());
        $this->assertNull($user->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_annen_brukers_app_kan_ikke_fjernes(): void
    {
        $meg = User::factory()->create();
        $min = $this->nyApp($meg, 'Min');
        $this->nyApp($meg, 'Min andre');

        $annen = User::factory()->create();
        $this->nyApp($annen, 'Hans');
        $this->nyApp($annen, 'Hans andre');
        $hans = $annen->mfaApper()->first();

        // Handlingen finnes bare for egne apper, og egenApp() slår bare opp blant dem.
        $this->actingAs($meg);
        $this->assertNull($this->mfa->egenApp($hans->getKey()));
        $this->assertSame(2, $annen->mfaApper()->count());
        $this->assertNotNull($min);
    }

    public function test_en_annen_brukers_app_har_ingen_handling(): void
    {
        $annen = User::factory()->create();
        $this->nyApp($annen, 'Hans');
        $this->nyApp($annen, 'Hans andre');
        $hans = $annen->mfaApper()->first();

        $meg = User::factory()->create();
        $this->nyApp($meg, 'Min');

        $this->profil($meg)
            ->assertActionDoesNotExist($this->radHandling('fjernMfaApp', $hans))
            ->assertActionDoesNotExist($this->radHandling('giNyttNavnMfaApp', $hans));

        $this->assertSame(2, $annen->mfaApper()->count());
    }

    public function test_fjern_tommer_den_gamle_kolonnen(): void
    {
        $user = User::factory()->create();
        $this->nyApp($user, 'A');
        $b = $this->nyApp($user, 'B');
        DB::table('users')->where('id', $user->getKey())->update(['app_authentication_secret' => 'gammel']);
        $app = $user->mfaApper()->where('navn', 'A')->first();

        $this->profil($user)
            ->callAction($this->radHandling('fjernMfaApp', $app), ['kode' => $this->kode($b)])
            ->assertHasNoFormErrors();

        $this->assertNull(DB::table('users')->where('id', $user->getKey())->value('app_authentication_secret'));
    }

    public function test_fjern_har_egen_grense_pa_forsok_per_bruker(): void
    {
        $user = User::factory()->create();
        $this->nyApp($user, 'A');
        $b = $this->nyApp($user, 'B');
        $app = $user->mfaApper()->where('navn', 'A')->first();

        $profil = $this->profil($user);

        // Fem forsøk brukt opp (fra hvilken som helst IP); da stopper også et forsøk med riktig kode.
        foreach (range(1, MfaBegrensning::MAKS_FORSOK) as $i) {
            RateLimiter::hit('mfa-apper:fjern:'.Filament::getUserScopedAuthIdentifier($user));
        }

        $profil->callAction($this->radHandling('fjernMfaApp', $app), ['kode' => $this->kode($b)])
            ->assertNotified('For mange forsøk');

        $this->assertSame(2, $user->mfaApper()->count());
    }

    // --- Gi nytt navn ---

    public function test_gi_nytt_navn(): void
    {
        $user = User::factory()->create();
        $this->nyApp($user, 'Første app');
        $this->nyApp($user, 'Telefon');
        $app = $user->mfaApper()->where('navn', 'Første app')->first();

        $profil = $this->profil($user);

        $profil->callAction($this->radHandling('giNyttNavnMfaApp', $app), ['navn' => 'TELEFON'])
            ->assertHasFormErrors(['navn']);

        $profil->callAction($this->radHandling('giNyttNavnMfaApp', $app), ['navn' => 'iPhone'])
            ->assertHasNoFormErrors();

        $this->assertSame('iPhone', $app->fresh()->navn);

        $profil->callAction($this->radHandling('giNyttNavnMfaApp', $app), ['navn' => "Tlf\e[31m"])
            ->assertHasFormErrors(['navn']);
        $this->assertSame('iPhone', $app->fresh()->navn);
    }

    public function test_navn_som_databasen_regner_som_like_gir_ingen_feilside(): void
    {
        $user = User::factory()->create();
        $this->nyApp($user, 'Café');
        $this->nyApp($user, 'Telefon');
        $app = $user->mfaApper()->where('navn', 'Telefon')->first();

        // I MySQL (utf8mb4_0900_ai_ci) er «Cafe» og «Café» like for den unike indeksen; i SQLite ikke.
        // Uansett skal det ikke bli en feilside.
        $this->profil($user)->callAction($this->radHandling('giNyttNavnMfaApp', $app), ['navn' => 'Cafe']);

        $this->assertSame(2, $user->mfaApper()->count());
    }

    // --- Profilsiden ---

    public function test_profilsiden_viser_apper_uten_hemmeligheter_og_oppfordrer_til_en_til(): void
    {
        $user = User::factory()->create();
        $hemmelighet = $this->nyApp($user, '<b>Telefon</b>');

        $html = $this->profil($user)->html();

        $this->assertStringContainsString('&lt;b&gt;Telefon&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Telefon</b>', $html);
        $this->assertStringNotContainsString($hemmelighet, $html);
        $this->assertStringContainsString('ikke brukt ennå', $html);
        $this->assertStringContainsString('Du har bare én autentiseringsapp', $html);

        $this->nyApp($user, '1Password');

        $this->assertStringNotContainsString('Du har bare én autentiseringsapp', $this->profil($user)->html());
    }

    public function test_forste_app_settes_opp_med_filaments_oppsett_som_forste_app(): void
    {
        $user = User::factory()->create();

        $profil = $this->profil($user)
            ->assertActionVisible(TestAction::make('setUpAppAuthentication')->schemaComponent(self::HANDLINGER, 'content'))
            ->assertActionDoesNotExist(TestAction::make('leggTilMfaApp')->schemaComponent(self::HANDLINGER, 'content'));

        $profil->mountAction(TestAction::make('setUpAppAuthentication')->schemaComponent(self::HANDLINGER, 'content'));
        $mounted = $profil->instance()->mountedActions;
        $hemmelighet = decrypt(end($mounted)['arguments']['encrypted'])['secret'];

        $profil->setActionData(['code' => $this->kode($hemmelighet), 'password' => 'password'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $app = $user->mfaApper()->first();
        $this->assertSame(MfaApp::FORSTE_NAVN, $app?->navn);
        $this->assertSame($hemmelighet, $app->hemmelighet);
        $this->assertCount(8, $user->fresh()->getAppAuthenticationRecoveryCodes());
        Mail::assertSent(MfaEndringVarsel::class, fn (MfaEndringVarsel $mail): bool => $mail->hendelse === MfaEndringVarsel::LAGT_TIL);

        // Koden fra oppsettet kan ikke brukes igjen ved innlogging; neste kode kan.
        auth()->logout();
        $this->loggInnMedKode($user, $this->kode($hemmelighet))->assertHasErrors('data.multiFactor.app.code');
        $this->assertGuest();
        $this->loggInnMedKode($user, $this->kode($hemmelighet, 1))->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_nye_gjenopprettingskoder_godtar_kode_fra_app_nr_to(): void
    {
        $user = User::factory()->create();
        $this->nyApp($user, 'Telefon');
        $passord = $this->nyApp($user, '1Password');
        $user->saveAppAuthenticationRecoveryCodes([Hash::make('gammel')]);

        $this->profil($user)
            ->callAction(TestAction::make('regenerateAppAuthenticationRecoveryCodes')->schemaComponent(self::HANDLINGER, 'content'), [
                'code' => $this->kode($passord),
                'password' => 'password',
            ])
            ->assertHasNoFormErrors();

        $this->assertCount(8, $user->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_gjenopprettingskode_gir_fortsatt_innlogging(): void
    {
        $user = User::factory()->create();
        $this->nyApp($user, 'Telefon');
        $user->saveAppAuthenticationRecoveryCodes([Hash::make('abc-123')]);

        $this->utfordring($user)
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', 'abc-123')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    // --- Nødvei ---

    public function test_mfa_nullstill_fjerner_appene_og_gir_ny_registrering(): void
    {
        $user = User::factory()->create(['email' => 'aksel@example.test']);
        $telefon = $this->nyApp($user, 'Telefon');
        $this->nyApp($user, '1Password');
        $user->saveAppAuthenticationRecoveryCodes([Hash::make('kode-1')]);

        $this->artisan('mfa:nullstill', ['epost' => 'aksel@example.test', '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, $user->mfaApper()->count());
        $this->assertNull($user->fresh()->getAppAuthenticationRecoveryCodes());
        $this->assertFalse($this->mfa->isEnabled($user));

        Mail::assertSent(MfaEndringVarsel::class, fn (MfaEndringVarsel $mail): bool => ($mail->hendelse === MfaEndringVarsel::NULLSTILT)
            && ($mail->ip === 'server (artisan)')
            && ($mail->antallIgjen === 0));

        // Neste innlogging har ingen app å spørre om; profilen tilbyr nytt oppsett.
        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password', 'remember' => false])
            ->call('authenticate')
            ->assertSet('userUndertakingMultiFactorAuthentication', null);
        $this->assertAuthenticatedAs($user);

        $this->profil($user)
            ->assertActionVisible(TestAction::make('setUpAppAuthentication')->schemaComponent(self::HANDLINGER, 'content'));

        $this->assertNotNull($telefon);
    }

    public function test_mfa_nullstill_spor_og_avbryter_ved_nei(): void
    {
        $user = User::factory()->create(['email' => 'aksel@example.test']);
        $this->nyApp($user, 'Telefon');

        $this->artisan('mfa:nullstill', ['epost' => 'aksel@example.test'])
            ->expectsConfirmation('Slette alle autentiseringsappene og gjenopprettingskodene? Neste innlogging ber om ny registrering.', 'no')
            ->assertFailed();

        $this->assertSame(1, $user->mfaApper()->count());
        Mail::assertNotSent(MfaEndringVarsel::class);
    }

    public function test_mfa_nullstill_ukjent_bruker(): void
    {
        $this->artisan('mfa:nullstill', ['epost' => 'ukjent@example.test', '--force' => true])
            ->assertFailed();
    }
}

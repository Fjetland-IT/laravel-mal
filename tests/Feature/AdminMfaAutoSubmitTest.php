<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Auth\AutoSubmittingAppAuthentication;
use App\Filament\Auth\Login;
use App\Filament\Auth\MfaApperAuthentication;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tofaktor-utfordringen i /admin: koden skal sendes inn av seg selv når siste
 * siffer er på plass, og en avvist kode skal tømmes fra feltet.
 *
 * Selve auto-innsendingen er JavaScript (Filaments egen fra 5.10) og kan ikke kjøres
 * her – testen sikrer at den er slått på, at vi ikke legger en egen innsending oppå,
 * at fokusskriptet havner på kodefeltet, og at serversiden er korrekt.
 */
class AdminMfaAutoSubmitTest extends TestCase
{
    private User $user;

    private MfaApperAuthentication $provider;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        // Bare brukertabellene: innloggingen trenger ikke mer, og migreringene i en app
        // laget fra malen er ikke nødvendigvis sqlite-kompatible. sqlite :memory: er
        // ferskt per test.
        Artisan::call('migrate', [
            '--path' => [
                ...glob(database_path('migrations/*users*.php')),
                ...glob(database_path('migrations/*mfa_apper*.php')),
            ],
            '--realpath' => true,
        ]);

        Mail::fake(); // Innloggingsvarselet (SendBackendLoginAlert) skal ikke sende noe her.

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->provider = collect(Filament::getMultiFactorAuthenticationProviders())
            ->first(fn ($provider) => $provider instanceof AutoSubmittingAppAuthentication);

        $this->assertInstanceOf(MfaApperAuthentication::class, $this->provider, 'Panelet skal bruke MfaApperAuthentication (som arver fokusgrepene).');

        $this->secret = $this->provider->generateSecret();
        $this->user = User::factory()->create();
        $this->user->mfaApper()->create(['navn' => 'Telefon', 'hemmelighet' => $this->secret]);
    }

    /** Logger inn med e-post og passord, så vi står i tofaktor-utfordringen. */
    private function startChallenge(): Testable
    {
        return Livewire::test(Login::class)
            ->fillForm([
                'email' => $this->user->email,
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();
    }

    /** En sekssifret kode som IKKE er gyldig akkurat nå (heller ikke i vinduet rundt). */
    private function invalidCode(): string
    {
        $secret = $this->secret;

        foreach (['000000', '111111', '222222', '333333'] as $candidate) {
            if (! $this->provider->verifyCode($candidate, $secret)) {
                return $candidate;
            }
        }

        $this->fail('Fant ingen ugyldig kode å teste med.');
    }

    public function test_kodefeltet_far_auto_innsending_og_fokus(): void
    {
        $component = $this->startChallenge();

        $this->assertNotNull($component->get('userUndertakingMultiFactorAuthentication'));

        $html = $component->html();

        $this->assertMatchesRegularExpression(
            '/x-init="([^"]*)"/',
            $html,
            'Kodefeltet skal ha et x-init-attributt.',
        );

        // Innsendingen er Filaments egen (5.10+) – og bare den: ingen egen requestSubmit().
        $this->assertStringContainsString('filamentOneTimeCodeInput({ shouldSubmitOnCompletion: true })', $html);
        $this->assertStringNotContainsString('requestSubmit()', $html, 'Egen innsending ville gitt dobbel innsending sammen med Filaments.');

        preg_match_all('/x-init="([^"]*)"/', $html, $matches);
        $scripts = array_values(array_filter($matches[1], fn (string $script) => str_contains($script, 'focusInput(0)')));

        $this->assertCount(1, $scripts, 'Nøyaktig ett x-init skal styre fokus.');
        $this->assertStringContainsString("\$watch('state'", $scripts[0]);
        $this->assertStringNotContainsString('&quot;', $scripts[0], 'Skriptet må være fritt for doble anførselstegn (attributtverdien escapes ikke).');

        // Skriptet sitter på selve kodekomponenten (Alpine-scope filamentOneTimeCodeInput).
        $this->assertMatchesRegularExpression(
            '/x-data="filamentOneTimeCodeInput\\([^"]*\\)"[^>]*x-init="/s',
            $html,
        );
    }

    public function test_feil_kode_tommes_fra_feltet(): void
    {
        $this->startChallenge()
            ->set('data.multiFactor.app.code', $this->invalidCode())
            ->call('authenticate')
            ->assertHasErrors('data.multiFactor.app.code')
            ->assertSet('data.multiFactor.app.code', null)
            ->assertNotSet('userUndertakingMultiFactorAuthentication', null);

        $this->assertGuest();
    }

    public function test_riktig_kode_logger_inn(): void
    {
        $this->startChallenge()
            ->set('data.multiFactor.app.code', $this->provider->getCurrentCode($this->user, $this->secret))
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_feil_passord_rorer_ikke_tofaktor_tilstanden(): void
    {
        Livewire::test(Login::class)
            ->fillForm([
                'email' => $this->user->email,
                'password' => 'feil-passord',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email'])
            ->assertSet('userUndertakingMultiFactorAuthentication', null);

        $this->assertGuest();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Listeners\SendBackendLoginAlert;
use App\Mail\BackendLoginAlert;
use App\Models\User;
use App\Support\KjentEnhet;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Innloggingsvarsel og kjent enhet. L5 (O17, port fra dash F7c): cookien er en HMAC med
 * ADMIN_KNOWN_DEVICE_TOKEN som nøkkel og 180 dagers utløp; gamle cookies med selve tokenet godtas ikke.
 */
class InnloggingsvarselTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'b4f1c0de0123456789abcdef0123456789abcdef0123456789abcdef01234567';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'mail.admin_alert_address' => 'varsel@example.test',
            'mail.admin_known_device_token' => self::TOKEN,
        ]);
    }

    private function loggInn(?string $cookie = null, ?User $bruker = null): void
    {
        $request = Request::create('/admin/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        if ($cookie !== null) {
            $request->cookies->set(SendBackendLoginAlert::KNOWN_DEVICE_COOKIE, $cookie);
        }
        $this->app->instance('request', $request);

        event(new Login('web', $bruker ?? User::factory()->create(['name' => 'Testbruker']), false));
    }

    public function test_innlogging_varsles(): void
    {
        $this->loggInn();

        Mail::assertSent(BackendLoginAlert::class, function (BackendLoginAlert $mail): bool {
            return $mail->hasTo('varsel@example.test')
                && $mail->userName === 'Testbruker'
                && $mail->ip === '203.0.113.7';
        });
    }

    public function test_kjent_enhet_varsles_ikke(): void
    {
        $bruker = User::factory()->create();
        $this->loggInn(KjentEnhet::lag($bruker->id, self::TOKEN), $bruker);

        Mail::assertNothingSent();
    }

    public function test_feil_cookie_varsles(): void
    {
        $this->loggInn('feil-verdi');

        Mail::assertSent(BackendLoginAlert::class);
    }

    /** Selve hemmeligheten som cookie (slik den var før O17) godtas ikke lenger: ett ekstra varsel. */
    public function test_gammel_cookie_med_selve_tokenet_varsles(): void
    {
        $this->loggInn(self::TOKEN);

        Mail::assertSent(BackendLoginAlert::class);
    }

    public function test_utlopt_cookie_varsles(): void
    {
        $bruker = User::factory()->create();
        $cookie = KjentEnhet::lag($bruker->id, self::TOKEN);
        $this->travel(KjentEnhet::DAGER + 1)->days();

        $this->loggInn($cookie, $bruker);

        Mail::assertSent(BackendLoginAlert::class);
    }

    public function test_cookie_for_annen_bruker_eller_med_annen_nokkel_varsles(): void
    {
        $bruker = User::factory()->create();
        $annen = User::factory()->create();

        $this->assertFalse(KjentEnhet::gyldig(KjentEnhet::lag($annen->id, self::TOKEN), $bruker->id, self::TOKEN));
        $this->assertFalse(KjentEnhet::gyldig(KjentEnhet::lag($bruker->id, 'annen-nokkel'), $bruker->id, self::TOKEN));
        $this->assertFalse(KjentEnhet::gyldig(['v1'], $bruker->id, self::TOKEN));
        $this->assertFalse(KjentEnhet::gyldig(KjentEnhet::lag($bruker->id, self::TOKEN), $bruker->id, ''));

        // Endret utløp gir feil HMAC.
        [$v, $id, , $salt, $mac] = explode('.', KjentEnhet::lag($bruker->id, self::TOKEN));
        $this->assertFalse(KjentEnhet::gyldig(implode('.', [$v, $id, (string) (time() + 10 * 365 * 86400), $salt, $mac]), $bruker->id, self::TOKEN));

        $this->loggInn(KjentEnhet::lag($annen->id, self::TOKEN), $bruker);
        Mail::assertSent(BackendLoginAlert::class);
    }

    public function test_uten_adresse_sendes_ingenting(): void
    {
        config(['mail.admin_alert_address' => null]);

        $this->loggInn();

        Mail::assertNothingSent();
    }

    public function test_kjent_enhet_ruten_krever_innlogging(): void
    {
        $this->get('/admin/kjent-enhet')->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_kjent_enhet_ruten_setter_signert_cookie_i_180_dager(): void
    {
        $bruker = User::factory()->create();
        $svar = $this->actingAs($bruker)->get('/admin/kjent-enhet')->assertOk();

        $cookie = $svar->getCookie(SendBackendLoginAlert::KNOWN_DEVICE_COOKIE, decrypt: false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertEqualsWithDelta(now()->getTimestamp() + KjentEnhet::DAGER * 86400, $cookie->getExpiresTime(), 120);

        $verdi = (string) $svar->getCookie(SendBackendLoginAlert::KNOWN_DEVICE_COOKIE)?->getValue();
        $this->assertStringNotContainsString(self::TOKEN, $verdi);
        $this->assertTrue(KjentEnhet::gyldig($verdi, $bruker->id, self::TOKEN));
    }

    public function test_kjent_enhet_ruten_gir_404_uten_token(): void
    {
        config(['mail.admin_known_device_token' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/admin/kjent-enhet')
            ->assertNotFound();
    }
}

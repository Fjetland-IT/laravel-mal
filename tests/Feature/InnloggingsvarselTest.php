<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Listeners\SendBackendLoginAlert;
use App\Mail\BackendLoginAlert;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

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

    private function loggInn(?string $cookie = null): void
    {
        $request = Request::create('/admin/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        if ($cookie !== null) {
            $request->cookies->set(SendBackendLoginAlert::KNOWN_DEVICE_COOKIE, $cookie);
        }
        $this->app->instance('request', $request);

        event(new Login('web', User::factory()->create(['name' => 'Testbruker']), false));
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
        $this->loggInn(self::TOKEN);

        Mail::assertNothingSent();
    }

    public function test_feil_cookie_varsles(): void
    {
        $this->loggInn('feil-verdi');

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

    public function test_kjent_enhet_ruten_setter_cookien(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/kjent-enhet')
            ->assertOk()
            ->assertCookie(SendBackendLoginAlert::KNOWN_DEVICE_COOKIE, self::TOKEN);
    }

    public function test_kjent_enhet_ruten_gir_404_uten_token(): void
    {
        config(['mail.admin_known_device_token' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/admin/kjent-enhet')
            ->assertNotFound();
    }
}

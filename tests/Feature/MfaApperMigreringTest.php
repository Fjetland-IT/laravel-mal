<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Auth\MfaApperAuthentication;
use App\Models\MfaApp;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

/**
 * Migreringene for O15: gammel hemmelighet flyttes til mfa_apper uten ny registrering, og kolonnen
 * kan fjernes senere uten at innlogging slutter å virke.
 *
 * DatabaseMigrations i stedet for RefreshDatabase: testene kjører DDL, og i MySQL (`./dev test`) avslutter
 * DDL en åpen transaksjon.
 */
class MfaApperMigreringTest extends TestCase
{
    use DatabaseMigrations;

    private const OPPRETT = 'migrations/2026_10_20_000000_create_mfa_apper_table.php';

    private const SENERE = 'migrations/senere/2026_10_20_000001_drop_app_authentication_secret_from_users.php';

    private MfaApperAuthentication $mfa;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->mfa = collect(Filament::getMultiFactorAuthenticationProviders())
            ->first(fn ($provider) => $provider instanceof MfaApperAuthentication);
        $this->google2fa = app(Google2FA::class);
    }

    private function kode(string $hemmelighet, int $steg = 0): string
    {
        return $this->google2fa->oathTotp($hemmelighet, $this->google2fa->getTimestamp() + $steg);
    }

    private function loggInn(User $user, string $kode): void
    {
        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password', 'remember' => false])
            ->call('authenticate')
            ->assertNotSet('userUndertakingMultiFactorAuthentication', null)
            ->set('data.multiFactor.app.code', $kode)
            ->call('authenticate')
            ->assertHasNoErrors();
    }

    public function test_migreringen_flytter_gammel_hemmelighet_uten_ny_registrering(): void
    {
        $migrering = require database_path(self::OPPRETT);
        $migrering->down();

        $hemmelighet = $this->mfa->generateSecret();
        $user = User::factory()->create();
        // Slik Filaments encrypted-cast lagret den før O15.
        DB::table('users')->where('id', $user->getKey())->update([
            'app_authentication_secret' => Crypt::encryptString($hemmelighet),
        ]);
        $uten = User::factory()->create();

        $migrering->up();

        $app = $user->mfaApper()->sole();
        $this->assertSame(MfaApp::FORSTE_NAVN, $app->navn);
        $this->assertSame($hemmelighet, $app->hemmelighet);
        $this->assertSame(0, $uten->mfaApper()->count());

        $this->loggInn($user, $this->kode($hemmelighet));
        $this->assertAuthenticatedAs($user);

        // Kolonnen fjernes senere; innlogging virker også etterpå.
        auth()->logout();
        $senere = require database_path(self::SENERE);
        $senere->up();
        $this->assertFalse(Schema::hasColumn('users', 'app_authentication_secret'));

        $this->loggInn($user->fresh(), $this->kode($hemmelighet, 1));
        $this->assertAuthenticatedAs($user);

        // Og den kan rulles tilbake (kolonnen kommer tilbake tom).
        $senere->down();
        $this->assertTrue(Schema::hasColumn('users', 'app_authentication_secret'));
    }

    public function test_brukere_uten_hemmelighet_far_ingen_app(): void
    {
        $migrering = require database_path(self::OPPRETT);
        $migrering->down();

        $tom = User::factory()->create();
        DB::table('users')->where('id', $tom->getKey())->update(['app_authentication_secret' => '']);
        $null = User::factory()->create();

        $migrering->up();

        $this->assertSame(0, MfaApp::query()->count());
        $this->assertFalse($this->mfa->isEnabled($tom));
        $this->assertFalse($this->mfa->isEnabled($null));
    }

    public function test_alle_hemmeligheter_kopieres_ogsa_over_en_side(): void
    {
        $migrering = require database_path(self::OPPRETT);
        $migrering->down();

        $kryptert = Crypt::encryptString($this->mfa->generateSecret());
        $naa = now();

        foreach (array_chunk(range(1, 1250), 250) as $del) {
            DB::table('users')->insert(array_map(fn (int $i): array => [
                'name' => "Bruker {$i}",
                'email' => "bruker{$i}@example.test",
                'password' => 'x',
                'app_authentication_secret' => $kryptert,
                'created_at' => $naa,
                'updated_at' => $naa,
            ], $del));
        }

        $migrering->up();

        $this->assertSame(1250, MfaApp::query()->count());
        $this->assertSame(1250, MfaApp::query()->distinct()->count('user_id'));
    }

    public function test_senere_migrering_kjores_ikke_av_migrate(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'app_authentication_secret'));
        $this->assertFileExists(database_path(self::SENERE));
        $this->assertTrue(Schema::hasTable('mfa_apper'));
    }
}

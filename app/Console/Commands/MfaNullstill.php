<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Auth\MfaApperAuthentication;
use App\Mail\MfaEndringVarsel;
use App\Models\User;
use App\Support\MfaVarsel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Nødvei når en bruker har mistet alle autentiseringsappene (O15): sletter brukerens rader i mfa_apper
 * og gjenopprettingskodene, så neste innlogging ber om ny registrering.
 *
 * Kjøres som app-brukeren på serveren, aldri fra dash eller MCP:
 *
 *     sudo -iu <app> bash -c 'cd /srv/<app> && php8.5 artisan mfa:nullstill <e-post>'
 *
 * Sender samme varsel-e-post som når en app fjernes.
 */
class MfaNullstill extends Command
{
    protected $signature = 'mfa:nullstill
        {epost : E-postadressen til brukeren}
        {--force : Ikke spør om bekreftelse}';

    protected $description = 'Slett alle autentiseringsappene (og gjenopprettingskodene) til en bruker, så neste innlogging ber om ny registrering';

    public function handle(): int
    {
        $epost = trim((string) $this->argument('epost'));
        $user = User::query()->where('email', $epost)->first();

        if (! $user) {
            $this->error("Fant ingen bruker med e-posten {$epost}.");

            return self::FAILURE;
        }

        $apper = $user->mfaApper()->pluck('navn');

        // Navnene er skrevet av brukeren: ingen formatering eller kontrolltegn ut i terminalen.
        $vis = fn (string $tekst): string => OutputFormatter::escape((string) preg_replace('/\p{C}/u', '?', $tekst));

        $this->line('Bruker: '.$vis("{$user->name} <{$user->email}>"));
        $this->line('Apper: '.($apper->isEmpty() ? 'ingen' : $apper->map(fn (string $navn): string => '«'.$vis($navn).'»')->implode(', ')));

        if (! $this->option('force') && ! $this->confirm('Slette alle autentiseringsappene og gjenopprettingskodene? Neste innlogging ber om ny registrering.')) {
            $this->line('Avbrutt. Ingenting er endret.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user): void {
            $user->mfaApper()->delete();
            $user->saveAppAuthenticationRecoveryCodes(null);
            app(MfaApperAuthentication::class)->glemGammelHemmelighet($user);
        });

        MfaVarsel::send(MfaEndringVarsel::NULLSTILT, $user, $apper->isEmpty() ? 'ingen' : $apper->implode(', '), 'server (artisan)');

        $this->info("Nullstilt. {$apper->count()} app(er) slettet. Varsel sendt til ADMIN_ALERT_EMAIL hvis den er satt.");

        return self::SUCCESS;
    }
}

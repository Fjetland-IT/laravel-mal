<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flere autentiseringsapper (TOTP) per bruker (O15).
 *
 * Filament lagrer én hemmelighet per bruker i users.app_authentication_secret. Her får hver bruker
 * opptil MfaApp::MAKS rader i mfa_apper, og innlogging godtar kode fra hvilken som helst av dem.
 *
 * Eksisterende hemmeligheter kopieres over som «Første app», så ingen må registrere på nytt.
 * Verdien kopieres kryptert som den er: både den gamle kolonnen og mfa_apper.hemmelighet bruker
 * Laravels «encrypted»-cast med samme APP_KEY, så hemmeligheten dekrypteres aldri her.
 *
 * Den gamle kolonnen blir stående til innlogging er bekreftet i drift. Den fjernes av
 * database/migrations/senere/…_drop_app_authentication_secret_from_users.php (se README).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_apper', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('navn', 50);
            // Kryptert med Laravels encrypted-cast (MfaApp), derfor text.
            $table->text('hemmelighet');
            $table->timestamp('sist_brukt_at')->nullable();
            // TOTP-tidssteg (30 s) for siste godtatte kode. Koder fra samme eller eldre steg avvises.
            $table->unsignedBigInteger('sist_brukt_steg')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'navn']);
        });

        if (! Schema::hasColumn('users', 'app_authentication_secret')) {
            return;
        }

        $naa = now();

        // chunkById (nøkkel, ikke offset): innsettingene endrer ikke hvilke brukere som står igjen.
        DB::table('users')
            ->whereNotNull('app_authentication_secret')
            ->where('app_authentication_secret', '!=', '')
            ->whereNotExists(fn ($q) => $q->from('mfa_apper')->whereColumn('mfa_apper.user_id', 'users.id'))
            ->select(['id', 'app_authentication_secret'])
            ->chunkById(500, function ($brukere) use ($naa): void {
                DB::table('mfa_apper')->insert($brukere->map(fn (object $bruker): array => [
                    'user_id' => $bruker->id,
                    'navn' => 'Første app',
                    'hemmelighet' => $bruker->app_authentication_secret,
                    'created_at' => $naa,
                    'updated_at' => $naa,
                ])->all());
            });
    }

    public function down(): void
    {
        // Den gamle kolonnen står fortsatt, så innlogging virker som før uten tabellen. Apper lagt
        // til etter migreringen går tapt, og brukere der en app er fjernet eller MFA nullstilt, har
        // ingen gammel hemmelighet igjen (MfaApperAuthentication::glemGammelHemmelighet()).
        Schema::dropIfExists('mfa_apper');
    }
};

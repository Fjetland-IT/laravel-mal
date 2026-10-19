<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O15, del 2: fjerner Filaments gamle hemmelighetskolonne etter at innlogging med mfa_apper er
 * bekreftet i drift.
 *
 * Ligger i database/migrations/senere/ og kjøres ikke av `php artisan migrate` før den flyttes til
 * database/migrations/ (Laravel leser ikke underkataloger). Se README, «Flere MFA-apper».
 *
 * Gjenopprettingskodene (app_authentication_recovery_codes) blir stående.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'app_authentication_secret')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('app_authentication_secret');
            });
        }
    }

    public function down(): void
    {
        // Kolonnen kommer tilbake tom. Hemmelighetene ligger i mfa_apper.
        if (! Schema::hasColumn('users', 'app_authentication_secret')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('app_authentication_secret')->nullable();
            });
        }
    }
};

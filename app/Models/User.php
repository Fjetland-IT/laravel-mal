<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Tofaktor (TOTP) for admin-panelet. Traitene håndterer kryptert lagring
    // av app_authentication_secret og -recovery_codes, og skjuler dem i output.
    use InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    /**
     * Filament nekter alle i ikke-lokale miljøer uten denne (403 på /admin).
     * Alle rader i users-tabellen er admin-brukere, så innlogget = tilgang.
     * Trenger appen andre brukere, må dette strammes inn.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Navnet som vises i autentiseringsappen ved siden av koden. */
    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }
}

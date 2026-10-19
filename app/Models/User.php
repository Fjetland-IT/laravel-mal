<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LogicException;
use SensitiveParameter;

// app_authentication_secret er Filaments gamle enkelthemmelighet. Den skjules til kolonnen er
// fjernet (O15, database/migrations/senere/).
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Tofaktor (TOTP) for admin-panelet: appene ligger i mfa_apper (MfaApp, O15). Traiten under
    // håndterer de krypterte gjenopprettingskodene på users, som før.
    use InteractsWithAppAuthenticationRecovery;

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
     * Brukerens autentiseringsapper, eldste først.
     *
     * @return HasMany<MfaApp, $this>
     */
    public function mfaApper(): HasMany
    {
        return $this->hasMany(MfaApp::class)->orderBy('id');
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

    /**
     * Filaments enkelthemmelighet brukes ikke lenger (App\Filament\Auth\MfaApperAuthentication leser
     * mfa_apper). Kaster, så en kodevei som fortsatt leser den, feiler synlig i stedet for å godta
     * bare én app.
     */
    public function getAppAuthenticationSecret(): ?string
    {
        throw new LogicException('Hemmelighetene ligger i mfa_apper. Bruk MfaApperAuthentication.');
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        throw new LogicException('Hemmelighetene ligger i mfa_apper. Bruk MfaApperAuthentication.');
    }
}

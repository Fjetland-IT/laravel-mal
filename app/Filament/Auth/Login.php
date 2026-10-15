<?php

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

/**
 * Filaments innloggingsside, med ett tillegg: en avvist engangskode tømmes fra
 * kodefeltet før feilmeldingen vises. Uten dette blir de seks sifrene stående,
 * neste forsøk må skrives oppå dem, og Filaments auto-innsending slår ikke inn
 * (den krever at koden går fra ufullstendig til fullstendig).
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        try {
            return parent::authenticate();
        } catch (ValidationException $exception) {
            if (filled($this->userUndertakingMultiFactorAuthentication)) {
                foreach (Filament::getMultiFactorAuthenticationProviders() as $provider) {
                    if ($provider instanceof AppAuthentication) {
                        data_set($this->data, "multiFactor.{$provider->getId()}.code", null);
                    }
                }
            }

            throw $exception;
        }
    }
}

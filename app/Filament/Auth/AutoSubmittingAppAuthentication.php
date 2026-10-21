<?php

namespace App\Filament\Auth;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Forms\Components\OneTimeCodeInput;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Filaments app-baserte tofaktor (TOTP), med fokus-grep i innloggingsutfordringen:
 *
 *  - kodefeltet får fokus idet det dukker opp (Livewire bytter ut passordskjemaet
 *    uten sideinnlasting, så «autofocus» i HTML virker ikke der), men bare med mus/styreflate:
 *    på berøringsskjerm krasjet Chrome og Edge på iPad (Chromium for iOS) når feltet fikk fokus
 *    i samme øyeblikk som passordskjemaet forsvant – Safari ikke (okt. 2026). Der trykker man selv, og
 *  - etter en avvist kode tømmer Login-siden feltet, og første siffer får fokus igjen.
 *
 * Selve auto-innsendingen gjør Filament selv fra 5.10 (OneTimeCodeInput::submitOnCompletion(),
 * slått på i AppAuthentication), med vern mot å sende samme kode to ganger. Vi sender derfor
 * ikke inn noe her; et eget requestSubmit() ville gitt dobbel innsending og brent to av fem
 * forsøk per minutt.
 *
 * Skriptet ligger som x-init på selve kodekomponenten (Alpine-scope
 * «filamentOneTimeCodeInput»), så «state» og «focusInput» er de innebygde.
 *
 * Ved Filament-oppgraderinger: sjekk denne mot ny innebygd oppførsel
 * (tests/Feature/AdminMfaAutoSubmitTest.php feiler hvis noe er endret).
 */
class AutoSubmittingAppAuthentication extends AppAuthentication
{
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        return $this->medFokus(parent::getChallengeFormComponents($user));
    }

    /**
     * Legger fokusskriptet på kodefeltet. Brukes også av MfaApperAuthentication, som bygger
     * utfordringen selv (O15).
     *
     * @param  array<mixed>  $components
     * @return array<mixed>
     */
    protected function medFokus(array $components): array
    {
        foreach ($components as $component) {
            if ($component instanceof OneTimeCodeInput) {
                // Attributtverdien escapes ikke: hold JS-en fri for doble anførselstegn.
                $component->extraAlpineAttributes(['x-init' => self::focusScript()]);
            }
        }

        return $components;
    }

    protected static function focusScript(): string
    {
        return <<<'JS'
            $watch('state', (value, previous) => {
                if (String(value ?? '').length === 0 && String(previous ?? '').length > 0) {
                    $nextTick(() => focusInput(0));
                }
            });
            if (! window.matchMedia('(pointer: coarse)').matches) $nextTick(() => focusInput(0));
            JS;
    }
}

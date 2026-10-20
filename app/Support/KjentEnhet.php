<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Kjent-enhet-cookien (L5 i F5-gjennomgangen; port fra dash.fjetland.it i O17). Den slår bare av e-postvarselet ved innlogging,
 * men skal likevel ikke være selve hemmeligheten eller vare i fem år. Verdien er
 * «v1.<bruker>.<utløp>.<salt>.<hmac>», der HMAC-en er SHA-256 med ADMIN_KNOWN_DEVICE_TOKEN som nøkkel.
 * Bytte av nøkkelen gjør alle eksisterende cookies ugyldige.
 */
final class KjentEnhet
{
    public const COOKIE = 'kjent_enhet';

    public const DAGER = 180;

    public static function lag(int $brukerId, string $nokkel): string
    {
        $utlop = now()->addDays(self::DAGER)->getTimestamp();
        $salt = bin2hex(random_bytes(16));

        return implode('.', ['v1', $brukerId, $utlop, $salt, self::mac($brukerId, $utlop, $salt, $nokkel)]);
    }

    public static function gyldig(mixed $verdi, int $brukerId, string $nokkel): bool
    {
        if (! is_string($verdi) || $nokkel === '' || strlen($verdi) > 200) {
            return false;
        }

        $deler = explode('.', $verdi);
        if (count($deler) !== 5 || $deler[0] !== 'v1') {
            return false;
        }

        [, $bruker, $utlop, $salt, $mac] = $deler;
        if (! ctype_digit($bruker) || ! ctype_digit($utlop) || preg_match('/^[0-9a-f]{32}$/', $salt) !== 1) {
            return false;
        }
        if ((int) $bruker !== $brukerId || (int) $utlop <= now()->getTimestamp()) {
            return false;
        }

        return hash_equals(self::mac($brukerId, (int) $utlop, $salt, $nokkel), $mac);
    }

    private static function mac(int $brukerId, int $utlop, string $salt, string $nokkel): string
    {
        return hash_hmac('sha256', "kjent_enhet|v1|{$brukerId}|{$utlop}|{$salt}", $nokkel);
    }
}

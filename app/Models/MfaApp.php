<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Én registrert autentiseringsapp (TOTP) for en bruker (O15).
 *
 * Hemmeligheten lagres kryptert og vises aldri etter registrering. sist_brukt_steg er TOTP-tidssteget
 * for siste godtatte kode fra denne appen; koder fra samme eller eldre steg avvises (RFC 6238).
 *
 * @property int $id
 * @property int $user_id
 * @property string $navn
 * @property string $hemmelighet
 * @property ?Carbon $sist_brukt_at
 * @property ?int $sist_brukt_steg
 * @property Carbon $created_at
 */
#[Table('mfa_apper')]
#[Fillable(['navn', 'hemmelighet'])]
#[Hidden(['hemmelighet'])]
class MfaApp extends Model
{
    /** Høyst så mange apper per bruker. */
    public const MAKS = 5;

    /** Maks lengde på navnet (kolonnen er string(50)). */
    public const NAVN_MAKS = 50;

    /** Gyldige navn: ingen kontrolltegn eller usynlige formattegn (\p{C}). */
    public const NAVN_REGEX = '/^[^\p{C}]+$/u';

    /** Navnet en hemmelighet fra før O15 får ved migreringen, og første app fra Filaments oppsett. */
    public const FORSTE_NAVN = 'Første app';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hemmelighet' => 'encrypted',
            'sist_brukt_at' => 'datetime',
            'sist_brukt_steg' => 'integer',
        ];
    }
}

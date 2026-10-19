<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Varsel når en autentiseringsapp legges til eller fjernes, eller MFA nullstilles fra serveren (O15).
 * Går til samme adresse som innloggingsvarselet (ADMIN_ALERT_EMAIL). Inneholder aldri hemmeligheten.
 */
class MfaEndringVarsel extends Mailable
{
    public const LAGT_TIL = 'lagt_til';

    public const FJERNET = 'fjernet';

    public const NULLSTILT = 'nullstilt';

    public function __construct(
        public string $hendelse,
        public string $appNavn,
        public string $userName,
        public string $userEmail,
        public string $ip,
        public int $antallIgjen,
    ) {}

    public function overskrift(): string
    {
        return match ($this->hendelse) {
            self::LAGT_TIL => 'autentiseringsapp lagt til',
            self::FJERNET => 'autentiseringsapp fjernet',
            self::NULLSTILT => 'tofaktor nullstilt fra serveren',
            default => 'endring i tofaktor',
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: config('app.name').': '.$this->overskrift().' – '.$this->userName
                .' '.now()->timezone('Europe/Oslo')->format('d.m.Y H:i'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.mfa-endring',
            with: [
                'appName' => (string) config('app.name'),
                'host' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
                'time' => now()->timezone('Europe/Oslo')->format('d.m.Y H:i:s (T)'),
                'overskrift' => $this->overskrift(),
            ],
        );
    }
}

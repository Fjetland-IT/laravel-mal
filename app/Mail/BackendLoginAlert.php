<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BackendLoginAlert extends Mailable
{
    public function __construct(
        public string $userName,
        public string $userEmail,
        public string $ip,
        public string $userAgent,
        public bool $viaRemember = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: config('app.name').': innlogging i admin – '.$this->userName
                .' '.now()->timezone('Europe/Oslo')->format('d.m.Y H:i'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.backend-login-alert',
            with: [
                'appName' => (string) config('app.name'),
                'host' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
                'time' => now()->timezone('Europe/Oslo')->format('d.m.Y H:i:s (T)'),
            ],
        );
    }
}

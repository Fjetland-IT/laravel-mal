<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * SMTP2GO-transport via HTTP-API-et (rå MIME).
 *
 * Den offisielle pakken smtp2go-oss/smtp2go-laravel er ikke kompatibel med
 * Laravel 13, så vi snakker med API-et direkte: POST /v3/email/mime med
 * Base64-kodet MIME og X-Smtp2go-Api-Key-header.
 */
class Smtp2goTransport extends AbstractTransport
{
    private const ENDPOINT = 'https://api.smtp2go.com/v3/email/mime';

    public function __construct(private readonly string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $response = Http::withHeaders([
            'X-Smtp2go-Api-Key' => $this->apiKey,
        ])->post(self::ENDPOINT, [
            'mime_email' => base64_encode($message->toString()),
        ]);

        if ($response->failed()) {
            throw new TransportException(
                'SMTP2GO-API svarte med HTTP '.$response->status().': '.$response->body()
            );
        }

        if (($response->json('data.succeeded') ?? 0) < 1) {
            $errors = implode('; ', (array) ($response->json('data.failures') ?? $response->json('data.error') ?? ['ukjent feil']));

            throw new TransportException('SMTP2GO avviste e-posten: '.$errors);
        }
    }

    public function __toString(): string
    {
        return 'smtp2go';
    }
}

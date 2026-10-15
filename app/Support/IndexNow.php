<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IndexNow: varsler Bing (og dermed DuckDuckGo, som bruker Bings indeks),
 * Yandex, Seznam, Naver m.fl. når en URL er ny, endret eller borte.
 *
 * Kilden er sitemapet: det rendres internt (ingen nettverkskall), og hver
 * URL sammenlignes med forrige innsending i storage/app/indexnow-state.json.
 * Første kjøring sender alt; senere kjøringer bare nye URL-er, URL-er med ny
 * <lastmod> og URL-er som har forsvunnet fra sitemapet.
 *
 * Vert: INDEXNOW_URL, ellers APP_URL. Nøkkelen (INDEXNOW_KEY) publiseres på /indexnow.txt og oppgis som
 * keyLocation. Uten nøkkel gjør klassen ingenting – sett den bare i
 * produksjon.
 */
class IndexNow
{
    public const MAX_URLS_PER_REQUEST = 10000;

    private const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** Gyldig IndexNow-nøkkel: 8–128 tegn, a–z, A–Z, 0–9 og bindestrek. */
    public static function key(): ?string
    {
        $key = config('services.indexnow.key');

        return is_string($key) && preg_match('/^[A-Za-z0-9-]{8,128}$/', $key) === 1 ? $key : null;
    }

    public function enabled(): bool
    {
        return self::key() !== null;
    }

    /**
     * Den offentlige verten som skal varsles. INDEXNOW_URL overstyrer APP_URL,
     * for apper der APP_URL ikke er den offentlige verten.
     */
    public function baseUrl(): string
    {
        $url = config('services.indexnow.url') ?: config('app.url');

        return rtrim((string) $url, '/');
    }

    public function host(): string
    {
        return strtolower((string) parse_url($this->baseUrl(), PHP_URL_HOST));
    }

    /**
     * Alle URL-er i sitemapet (følger sitemap-indekser ett nivå ned).
     *
     * @return array<string, string|null> url => lastmod
     */
    public function sitemapEntries(): array
    {
        $entries = [];
        $queue = [$this->baseUrl().'/sitemap.xml'];
        $seen = [];

        while ($queue !== [] && count($seen) < 100) {
            $loc = array_shift($queue);
            if (isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;

            $xml = $this->fetch($loc);
            if ($xml === null) {
                continue;
            }

            $previous = libxml_use_internal_errors(true);
            $doc = simplexml_load_string($xml, options: LIBXML_NONET);
            libxml_use_internal_errors($previous);
            if ($doc === false) {
                continue;
            }

            $children = $doc->children(self::SITEMAP_NS);

            if ($doc->getName() === 'sitemapindex') {
                foreach ($children->sitemap as $sitemap) {
                    $queue[] = trim((string) $sitemap->children(self::SITEMAP_NS)->loc);
                }

                continue;
            }

            foreach ($children->url as $url) {
                $fields = $url->children(self::SITEMAP_NS);
                $u = trim((string) $fields->loc);
                if ($u === '') {
                    continue;
                }
                $lastmod = trim((string) $fields->lastmod);
                $entries[$u] = $lastmod !== '' ? $lastmod : null;
            }
        }

        return $entries;
    }

    /** Henter en side fra appen selv via HTTP-kjernen (uten nettverk). */
    public function fetch(string $url): ?string
    {
        try {
            $response = app(HttpKernel::class)->handle(Request::create($url, 'GET'));
        } catch (Throwable $e) {
            Log::warning('IndexNow: klarte ikke å rendre '.$url, ['error' => $e->getMessage()]);

            return null;
        }

        return $response->getStatusCode() === 200 ? (string) $response->getContent() : null;
    }

    /**
     * URL-er som skal sendes: nye, endret lastmod, eller fjernet fra sitemapet.
     * Uten tidligere tilstand sendes alt.
     *
     * @param  array<string, string|null>  $current
     * @param  array<string, string|null>|null  $previous
     * @return list<string>
     */
    public function changedUrls(array $current, ?array $previous): array
    {
        if ($previous === null) {
            return array_keys($current);
        }

        $changed = [];
        foreach ($current as $url => $lastmod) {
            if (! array_key_exists($url, $previous) || $previous[$url] !== $lastmod) {
                $changed[] = $url;
            }
        }
        foreach (array_keys($previous) as $url) {
            if (! array_key_exists($url, $current)) {
                $changed[] = $url;
            }
        }

        return $changed;
    }

    /** @param  list<string>  $urls */
    public function submit(array $urls): bool
    {
        $key = self::key();
        if ($key === null) {
            return false;
        }

        $host = $this->host();
        $urls = array_values(array_unique(array_filter(
            $urls,
            fn (string $u): bool => strtolower((string) parse_url($u, PHP_URL_HOST)) === $host,
        )));

        foreach (array_chunk($urls, self::MAX_URLS_PER_REQUEST) as $chunk) {
            try {
                $response = Http::timeout(30)
                    ->acceptJson()
                    ->post((string) config('services.indexnow.endpoint', 'https://api.indexnow.org/indexnow'), [
                        'host' => $host,
                        'key' => $key,
                        'keyLocation' => $this->baseUrl().'/indexnow.txt',
                        'urlList' => $chunk,
                    ]);
            } catch (Throwable $e) {
                Log::warning('IndexNow: innsending feilet', ['error' => $e->getMessage()]);

                return false;
            }

            // 200 = mottatt, 202 = mottatt, nøkkel valideres senere.
            if (! in_array($response->status(), [200, 202], true)) {
                Log::warning('IndexNow: innsending avvist', ['status' => $response->status(), 'body' => $response->body()]);

                return false;
            }
        }

        return true;
    }

    public static function statePath(): string
    {
        return storage_path('app/indexnow-state.json');
    }

    /** @return array<string, string|null>|null */
    public function loadState(): ?array
    {
        if (! File::exists(self::statePath())) {
            return null;
        }

        $data = json_decode((string) File::get(self::statePath()), true);

        return is_array($data) ? $data : null;
    }

    /** @param  array<string, string|null>  $entries */
    public function saveState(array $entries): void
    {
        File::ensureDirectoryExists(dirname(self::statePath()));
        File::put(self::statePath(), json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}

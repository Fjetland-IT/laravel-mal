<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\IndexNow;
use Illuminate\Console\Command;

/**
 * Sender nye/endrede/fjernede URL-er fra sitemapet til IndexNow (Bing,
 * DuckDuckGo via Bing, Yandex, Seznam, Naver). Kjøres hver time av
 * scheduleren; gjør ingenting uten INDEXNOW_KEY.
 */
class IndexNowSend extends Command
{
    protected $signature = 'indexnow:send
        {--all : Send alle URL-er i sitemapet, ikke bare endringene}
        {--dry-run : Vis hva som ville blitt sendt, uten å sende eller lagre}';

    protected $description = 'Varsle Bing/DuckDuckGo m.fl. om endrede sider via IndexNow';

    public function handle(IndexNow $indexNow): int
    {
        if (! $indexNow->enabled()) {
            $this->line('IndexNow er ikke konfigurert (INDEXNOW_KEY mangler) – hopper over.');

            return self::SUCCESS;
        }

        $entries = $indexNow->sitemapEntries();
        if ($entries === []) {
            $this->error('Fant ingen URL-er i sitemapet – sender ingenting og beholder forrige tilstand.');

            return self::FAILURE;
        }

        $urls = $this->option('all')
            ? array_keys($entries)
            : $indexNow->changedUrls($entries, $indexNow->loadState());

        if ($this->option('dry-run')) {
            $this->info(count($urls).' URL-er ville blitt sendt:');
            foreach ($urls as $url) {
                $this->line('  '.$url);
            }

            return self::SUCCESS;
        }

        if ($urls === []) {
            $this->line('Ingen endringer siden forrige innsending.');
            $indexNow->saveState($entries);

            return self::SUCCESS;
        }

        if (! $indexNow->submit($urls)) {
            $this->error('IndexNow avviste innsendingen – se loggen. Prøver igjen ved neste kjøring.');

            return self::FAILURE;
        }

        $indexNow->saveState($entries);
        $this->info('Sendte '.count($urls).' URL-er til IndexNow.');

        return self::SUCCESS;
    }
}

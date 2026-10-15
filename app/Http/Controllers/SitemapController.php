<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * /sitemap.xml. Kilden for IndexNow (app/Support/IndexNow.php) og søkemotorene.
 *
 * Malen har bare forsiden. Legg til appens sider i urls(), gjerne med lastmod
 * fra updated_at, så IndexNow bare sender det som faktisk er endret. Store
 * sitemaps: cache resultatet (Cache::remember) eller del i en sitemap-indeks.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $body = '';
        foreach ($this->urls() as $url) {
            $body .= "  <url>\n";
            $body .= '    <loc>'.e($url['loc'])."</loc>\n";
            if (! empty($url['lastmod'])) {
                $body .= '    <lastmod>'.e($url['lastmod'])."</lastmod>\n";
            }
            $body .= "  </url>\n";
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$body
            .'</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * @return list<array{loc: string, lastmod?: string|null}>
     */
    protected function urls(): array
    {
        return [
            ['loc' => route('home')],
        ];
    }
}

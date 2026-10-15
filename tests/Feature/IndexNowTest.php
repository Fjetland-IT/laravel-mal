<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\IndexNow;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class IndexNowTest extends TestCase
{
    private const KEY = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

    /** Svaret IndexNow-API-et gir i testene. */
    private int $apiStatus = 200;

    /** @var array<string, string> sitemap-URL => XML */
    private array $sitemaps = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://example.test',
            'services.indexnow.key' => self::KEY,
            'services.indexnow.endpoint' => 'https://api.indexnow.org/indexnow',
        ]);
        File::delete(IndexNow::statePath());
        Http::preventStrayRequests();
        Http::fake(['api.indexnow.org/*' => fn () => Http::response('', $this->apiStatus)]);

        $this->sitemaps = [
            'https://example.test/sitemap.xml' => $this->index(['https://example.test/sitemap-a.xml']),
            'https://example.test/sitemap-a.xml' => $this->urlset([
                'https://example.test/' => null,
                'https://example.test/side' => '2026-10-01T10:00:00+02:00',
                'https://annet.test/fremmed' => null,
            ]),
        ];

        $this->partialMock(IndexNow::class, function ($mock): void {
            $mock->shouldReceive('fetch')->andReturnUsing(fn (string $url) => $this->sitemaps[$url] ?? null);
        });
    }

    protected function tearDown(): void
    {
        File::delete(IndexNow::statePath());

        parent::tearDown();
    }

    public function test_nokkelfilen_serveres_som_ren_tekst(): void
    {
        $this->get('/indexnow.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee(self::KEY, false);
    }

    public function test_nokkelfilen_gir_404_uten_nokkel(): void
    {
        // Uten unntakshåndtering: 404-siden rendres ikke (den krever databasen i noen av repoene).
        config(['services.indexnow.key' => null]);
        $this->withoutExceptionHandling();
        $this->expectException(NotFoundHttpException::class);

        $this->get('/indexnow.txt');
    }

    public function test_ugyldig_nokkel_regnes_som_manglende(): void
    {
        config(['services.indexnow.key' => 'kort']);
        $this->assertNull(IndexNow::key());

        config(['services.indexnow.key' => 'har mellomrom og er lang nok']);
        $this->assertNull(IndexNow::key());
    }

    public function test_forste_kjoring_sender_alle_url_er_pa_egen_vert(): void
    {
        $this->artisan('indexnow:send')->assertSuccessful();

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->url() === 'https://api.indexnow.org/indexnow'
                && $request['host'] === 'example.test'
                && $request['key'] === self::KEY
                && $request['keyLocation'] === 'https://example.test/indexnow.txt'
                && $request['urlList'] === ['https://example.test/', 'https://example.test/side'];
        });
    }

    public function test_bare_endringer_sendes_ved_neste_kjoring(): void
    {
        $this->artisan('indexnow:send')->assertSuccessful();
        $this->artisan('indexnow:send')->assertSuccessful();
        Http::assertSentCount(1);

        $this->sitemaps['https://example.test/sitemap-a.xml'] = $this->urlset([
            'https://example.test/side' => '2026-10-02T09:00:00+02:00',
            'https://example.test/ny' => null,
        ]);
        $this->artisan('indexnow:send')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $r): bool => $r['urlList'] === [
            'https://example.test/side',
            'https://example.test/ny',
            'https://example.test/',
        ]);
    }

    public function test_indexnow_url_overstyrer_app_url(): void
    {
        config(['app.url' => 'https://dev.example.test', 'services.indexnow.url' => 'https://example.test/']);

        $this->artisan('indexnow:send')->assertSuccessful();

        Http::assertSent(fn (ClientRequest $r): bool => $r['host'] === 'example.test'
            && $r['keyLocation'] === 'https://example.test/indexnow.txt');
    }

    public function test_avvist_innsending_lagrer_ikke_tilstand(): void
    {
        $this->apiStatus = 403;

        $this->artisan('indexnow:send')->assertFailed();
        $this->assertFileDoesNotExist(IndexNow::statePath());
    }

    public function test_uten_nokkel_gjores_ingenting(): void
    {
        config(['services.indexnow.key' => null]);

        $this->artisan('indexnow:send')->assertSuccessful();
        Http::assertNothingSent();
    }

    /** @param list<string> $locs */
    private function index(array $locs): string
    {
        $body = implode('', array_map(fn (string $l) => "<sitemap><loc>{$l}</loc></sitemap>", $locs));

        return '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$body.'</sitemapindex>';
    }

    /** @param array<string, string|null> $urls */
    private function urlset(array $urls): string
    {
        $body = '';
        foreach ($urls as $loc => $lastmod) {
            $body .= '<url><loc>'.$loc.'</loc>'.($lastmod ? '<lastmod>'.$lastmod.'</lastmod>' : '').'</url>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$body.'</urlset>';
    }
}

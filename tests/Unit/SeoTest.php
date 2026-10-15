<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Seo;
use PHPUnit\Framework\TestCase;

class SeoTest extends TestCase
{
    public function test_kort_tittel_er_uendret(): void
    {
        $this->assertSame('Forside | Merke', Seo::title('Forside | Merke', 'Merke'));
    }

    public function test_suffikset_droppes_forst(): void
    {
        $title = str_repeat('a', 60).' | Merke.no';

        $this->assertSame(str_repeat('a', 60), Seo::title($title, 'Merke'));
    }

    public function test_tankestrek_og_bindestrek_som_skille(): void
    {
        $base = str_repeat('b', 65);

        $this->assertSame($base, Seo::title($base.' – Merke', 'Merke'));
        $this->assertSame($base, Seo::title($base.' - Merke', 'Merke'));
    }

    public function test_lang_tittel_kuttes_ved_ordskille(): void
    {
        $title = 'Dette er en veldig lang tittel som fortsetter og fortsetter langt over grensen | Merke';
        $result = Seo::title($title, 'Merke');

        $this->assertLessThanOrEqual(Seo::MAX_TITLE, mb_strlen($result));
        $this->assertStringEndsWith('…', $result);
        $this->assertStringStartsWith('Dette er en veldig lang tittel', $result);
        $this->assertStringNotContainsString('Merke', $result);
        $this->assertDoesNotMatchRegularExpression('/\s…$/u', $result);
    }

    public function test_ord_uten_mellomrom_kuttes_hardt(): void
    {
        $result = Seo::title(str_repeat('x', 100), 'Merke');

        $this->assertSame(Seo::MAX_TITLE, mb_strlen($result));
    }

    public function test_dekoding_gir_en_gang_escaping(): void
    {
        $this->assertSame('Ost & kjeks', Seo::decode('Ost &amp; kjeks'));
        $this->assertSame('Ost & kjeks', Seo::decode('  Ost & kjeks '));
    }
}

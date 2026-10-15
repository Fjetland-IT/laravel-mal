<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** Rutene malen leverer: forsiden, helsesjekken og sitemapet. */
class GrunnrutenTest extends TestCase
{
    public function test_forsiden_svarer(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<title>'.e(config('app.name')).'</title>', false);
    }

    public function test_helsesjekken_svarer(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_sitemapet_har_forsiden(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>'.route('home').'</loc>', false);
    }

    public function test_admin_krever_innlogging(): void
    {
        $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    }
}

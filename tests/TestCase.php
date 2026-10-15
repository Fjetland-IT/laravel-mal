<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Testene skal ikke være avhengige av et Vite-bygg (public/build).
        $this->withoutVite();
    }
}

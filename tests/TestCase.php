<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Page tests assert on the Inertia response, not the compiled
        // assets. Without this every page test depends on a local
        // `npm run build` (public/build is gitignored) being newer than
        // the page it renders, and fails with a Vite manifest error.
        $this->withoutVite();
    }
}

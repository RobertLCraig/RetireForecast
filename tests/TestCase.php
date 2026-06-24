<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The app layout pulls bundled assets via @vite. The build output is a
        // gitignored artifact, so tests must not depend on it being present;
        // neutralise Vite so view rendering works without `npm run build`.
        $this->withoutVite();
    }
}

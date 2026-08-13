<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum's EnsureFrontendRequestsAreStateful only pushes the
        // session/CSRF middleware onto the 'api' stack for requests it can
        // identify as coming from the SPA frontend (Referer/Origin header
        // matching config('sanctum.stateful')). Simulate that here so
        // feature tests exercise the same session-cookie auth path a real
        // browser would (TDD-ADR-002).
        $this->withHeader('Referer', config('app.frontend_url'));
    }
}

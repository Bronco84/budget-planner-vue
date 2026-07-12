<?php

namespace Tests;

use App\Http\Middleware\EnsureUserHasPasskey;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Passkey enrollment is enforced globally on the web middleware group.
        // Factory users have no passkey, so leaving it on redirects every
        // authenticated request to the registration page and makes feature tests
        // untestable. Disable it by default; passkey-specific tests that need the
        // enforcement can opt back in with $this->withMiddleware(...).
        $this->withoutMiddleware(EnsureUserHasPasskey::class);
    }
}

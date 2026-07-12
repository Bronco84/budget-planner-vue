<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Registration is passkey-based (WebAuthn). The Breeze password-registration test
 * was removed because /register no longer accepts a password; the passkey
 * registration ceremony is covered separately.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }
}

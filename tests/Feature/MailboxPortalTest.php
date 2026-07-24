<?php

namespace Tests\Feature;

use Tests\TestCase;

class MailboxPortalTest extends TestCase
{
    public function test_root_serves_the_spa_shell(): void
    {
        $this->get('/')
            ->assertSuccessful()
            ->assertSee('mailbox-app', false)
            ->assertSee('manifest.webmanifest', false);
    }

    public function test_client_routes_serve_the_spa(): void
    {
        $this->get('/login')->assertSuccessful()->assertSee('mailbox-app', false);
        $this->get('/compose')->assertSuccessful()->assertSee('mailbox-app', false);
    }

    public function test_admin_and_api_are_not_captured_by_the_spa(): void
    {
        // /admin belongs to Filament (redirects to tenant/login), never the SPA shell.
        $this->get('/admin')->assertRedirect();

        // Mailbox API stays JSON, not the SPA shell.
        $this->getJson('/api/mailbox/me')->assertUnauthorized();
    }
}

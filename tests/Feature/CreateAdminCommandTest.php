<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_with_given_credentials(): void
    {
        $this->artisan('app:create-admin', [
            'email' => 'me@example.com',
            '--name' => 'Me',
            '--password' => 'supersecret',
        ])->assertSuccessful();

        $user = User::where('email', 'me@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Me', $user->name);
        $this->assertTrue(Hash::check('supersecret', $user->password));
    }

    public function test_updates_password_for_existing_email(): void
    {
        User::factory()->create(['email' => 'me@example.com']);

        $this->artisan('app:create-admin', [
            'email' => 'me@example.com',
            '--password' => 'newpassword1',
        ])->assertSuccessful();

        $this->assertTrue(Hash::check('newpassword1', User::where('email', 'me@example.com')->first()->password));
        $this->assertSame(1, User::where('email', 'me@example.com')->count());
    }

    public function test_rejects_short_password(): void
    {
        $this->artisan('app:create-admin', [
            'email' => 'me@example.com',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'me@example.com']);
    }
}

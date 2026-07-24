<?php

namespace Tests\Feature;

use App\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use App\Models\CloudflareAccount;
use App\Models\Mailbox;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class MailboxResourceTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): CloudflareAccount
    {
        $user = User::factory()->create();
        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);
        $account->users()->attach($user->id, ['role' => 'owner']);
        $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($account);

        return $account;
    }

    public function test_page_renders(): void
    {
        $account = $this->boot();

        $this->get("/admin/{$account->slug}/mailboxes")->assertSuccessful();
    }

    public function test_admin_creates_a_usable_mailbox(): void
    {
        $account = $this->boot();

        Livewire::test(CreateMailbox::class)
            ->fillForm([
                'email' => 'support@a.com',
                'display_name' => 'Support',
                'password' => 'password123',
                'login_enabled' => true,
            ])
            ->call('create')
            ->assertHasNoErrors();

        $mailbox = Mailbox::where('email', 'support@a.com')->first();

        $this->assertNotNull($mailbox);
        $this->assertSame($account->id, $mailbox->cloudflare_account_id);   // tenant-scoped
        $this->assertNotNull($mailbox->domain_id);                          // domain auto-resolved
        $this->assertTrue(Hash::check('password123', $mailbox->password));  // hashed, usable for login
    }
}

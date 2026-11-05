<?php

namespace Tests\Feature;

use App\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use App\Models\CloudflareAccount;
use App\Models\Mailbox;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
        $domain = $account->domains()->first();

        Livewire::test(CreateMailbox::class)
            ->fillForm([
                'domain_id' => $domain->id,
                'local_part' => 'support',
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

    public function test_admin_can_set_a_profile_photo(): void
    {
        Storage::fake('local');
        config(['cloudflare.attachments_disk' => 'local', 'cloudflare.avatars_disk' => null]);
        $account = $this->boot();
        $domain = $account->domains()->first();

        Livewire::test(CreateMailbox::class)
            ->fillForm([
                'avatar_path' => UploadedFile::fake()->image('ada.png', 600, 600), // the browser crops to 1:1 first
                'domain_id' => $domain->id,
                'local_part' => 'ada',
                'password' => 'password123',
                'login_enabled' => true,
            ])
            ->call('create')
            ->assertHasNoErrors();

        $mailbox = Mailbox::where('email', 'ada@a.com')->firstOrFail();

        $this->assertNotNull($mailbox->avatar_token);
        Storage::disk('local')->assertExists($mailbox->avatar_path);
        [$w, $h, $type] = getimagesizefromstring(Storage::disk('local')->get($mailbox->avatar_path));
        $this->assertSame([256, 256, IMAGETYPE_JPEG], [$w, $h, $type]); // same pipeline as the portal

        $this->get("/admin/{$account->slug}/mailboxes")->assertSuccessful()->assertSee('/avatars/'.$mailbox->avatar_token, false);
    }

    public function test_an_unusable_admin_photo_is_a_field_error_not_a_crash(): void
    {
        Storage::fake('local');
        config(['cloudflare.attachments_disk' => 'local', 'cloudflare.avatars_disk' => null]);
        $account = $this->boot();

        Livewire::test(CreateMailbox::class)
            ->fillForm([
                // Square, so it passes the 1:1 rule, but too large to decode.
                'avatar_path' => UploadedFile::fake()->image('huge.png', 4200, 4200),
                'domain_id' => $account->domains()->first()->id,
                'local_part' => 'big',
                'password' => 'password123',
            ])
            ->call('create')
            ->assertHasFormErrors(['avatar_path']);

        $this->assertNull(Mailbox::where('email', 'big@a.com')->first());
    }
}

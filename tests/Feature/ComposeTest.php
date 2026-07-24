<?php

namespace Tests\Feature;

use App\Filament\Pages\Compose;
use App\Models\CloudflareAccount;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ComposeTest extends TestCase
{
    use RefreshDatabase;

    private function bootTenant(): array
    {
        $user = User::factory()->create();
        $account = CloudflareAccount::create([
            'name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok',
        ]);
        $account->users()->attach($user->id, ['role' => 'owner']);
        $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com', 'sending_enabled' => true]);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($account);

        return [$user, $account];
    }

    public function test_compose_page_renders(): void
    {
        [$user, $account] = $this->bootTenant();

        $this->get("/admin/{$account->slug}/compose")->assertSuccessful();
    }

    public function test_compose_sends_and_logs(): void
    {
        [$user, $account] = $this->bootTenant();

        Http::fake([
            '*/email/sending/send' => Http::response([
                'success' => true, 'errors' => [], 'messages' => [],
                'result' => ['delivered' => ['to@x.com'], 'queued' => [], 'permanent_bounces' => []],
            ]),
        ]);

        Livewire::test(Compose::class)
            ->fillForm([
                'from_domain' => 'a.com',
                'from_local' => 'info',
                'to' => 'to@x.com',
                'subject' => 'Merhaba',
                'html' => '<p>Selam</p>',
            ])
            ->call('send')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sent_emails', [
            'cloudflare_account_id' => $account->id,
            'from_email' => 'info@a.com',
            'status' => 'delivered',
        ]);
    }
}

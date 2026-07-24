<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\CloudflareAccount;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): CloudflareAccount
    {
        $user = User::factory()->create();
        $account = CloudflareAccount::create([
            'name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'old-token', 'sending_driver' => 'api',
        ]);
        $account->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($account);

        return $account;
    }

    public function test_page_renders(): void
    {
        $account = $this->boot();

        $this->get("/admin/{$account->slug}/settings")->assertSuccessful();
    }

    public function test_updates_token_when_provided(): void
    {
        $account = $this->boot();

        Livewire::test(Settings::class)
            ->fillForm(['name' => 'Acme', 'account_id' => 'acc1', 'sending_driver' => 'smtp', 'api_token' => 'new-token'])
            ->call('save')
            ->assertHasNoErrors();

        $account->refresh();
        $this->assertSame('new-token', $account->api_token);
        $this->assertSame('smtp', $account->sending_driver);
    }

    public function test_keeps_token_when_left_blank(): void
    {
        $account = $this->boot();

        Livewire::test(Settings::class)
            ->fillForm(['name' => 'Renamed', 'account_id' => 'acc1', 'sending_driver' => 'api', 'api_token' => null])
            ->call('save')
            ->assertHasNoErrors();

        $account->refresh();
        $this->assertSame('old-token', $account->api_token); // preserved
        $this->assertSame('Renamed', $account->name);
    }
}

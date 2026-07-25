<?php

namespace Tests\Feature;

use App\Models\CloudflareAccount;
use App\Models\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailboxPimTest extends TestCase
{
    use RefreshDatabase;

    private function mailbox(string $email = 'me@a.com'): Mailbox
    {
        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc'.$email, 'api_token' => 'tok']);
        $domain = $account->domains()->create(['zone_id' => 'z'.$email, 'name' => 'a.com']);

        return $account->mailboxes()->create([
            'domain_id' => $domain->id, 'email' => $email, 'password' => 'password123', 'login_enabled' => true,
        ]);
    }

    public function test_event_crud_scoped_to_mailbox(): void
    {
        $mailbox = $this->mailbox();
        Sanctum::actingAs($mailbox);

        $id = $this->postJson('/api/mailbox/events', [
            'title' => 'Demo', 'starts_at' => now()->toIso8601String(), 'location' => 'Zoom',
        ])->assertCreated()->json('event.id');

        $this->getJson('/api/mailbox/events')->assertOk()->assertJsonFragment(['title' => 'Demo']);
        $this->putJson("/api/mailbox/events/{$id}", ['title' => 'Demo 2', 'starts_at' => now()->toIso8601String()])
            ->assertOk()->assertJsonPath('event.title', 'Demo 2');
        $this->deleteJson("/api/mailbox/events/{$id}")->assertOk();
        $this->assertDatabaseCount('events', 0);
    }

    public function test_contacts_and_tasks_are_scoped(): void
    {
        $mine = $this->mailbox('me@a.com');
        $other = $this->mailbox('other@a.com');
        $other->contacts()->create(['name' => 'Theirs']);
        $other->tasks()->create(['title' => 'TheirTask']);

        Sanctum::actingAs($mine);
        $this->postJson('/api/mailbox/contacts', ['name' => 'Jürgen', 'email' => 'j@x.com'])->assertCreated();
        $this->getJson('/api/mailbox/contacts')->assertOk()
            ->assertJsonFragment(['name' => 'Jürgen'])
            ->assertJsonMissing(['name' => 'Theirs']);

        $tid = $this->postJson('/api/mailbox/tasks', ['title' => 'Do it'])->assertCreated()->json('task.id');
        $this->putJson("/api/mailbox/tasks/{$tid}", ['done' => true])->assertOk()->assertJsonPath('task.done', true);
        $this->getJson('/api/mailbox/tasks')->assertOk()
            ->assertJsonFragment(['title' => 'Do it'])
            ->assertJsonMissing(['title' => 'TheirTask']);
    }

    public function test_cannot_touch_other_mailbox_records(): void
    {
        $mine = $this->mailbox('me@a.com');
        $other = $this->mailbox('other@a.com');
        $theirEvent = $other->events()->create(['title' => 'X', 'starts_at' => now()]);

        Sanctum::actingAs($mine);
        $this->deleteJson("/api/mailbox/events/{$theirEvent->id}")->assertNotFound();
    }
}

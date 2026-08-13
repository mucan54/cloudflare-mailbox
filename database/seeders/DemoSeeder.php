<?php

namespace Database\Seeders;

use App\Models\CloudflareAccount;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Event;
use App\Models\Mailbox;
use App\Models\SentEmail;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Rich, self-contained demo data for screenshots / local exploration.
 *
 *   php artisan migrate:fresh && php artisan db:seed --class=DemoSeeder
 *
 * Admin login:   admin@example.com / password  (tenant: Acme Corp)
 * Mailbox login: ada@acme.com / password
 *
 * No Cloudflare calls are made — everything is written straight to the DB.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => bcrypt('password')],
        );

        $account = CloudflareAccount::create([
            'name' => 'Acme Corp',
            'slug' => 'acme',
            'account_id' => '9a7b3c1d5e2f40a1b8c6d4e2f0a1b2c3',
            'api_token' => 'demo-token-not-real',
            'webhook_secret' => 'demo-secret',
            'sending_driver' => 'api',
            'worker_deployed_at' => null, // keeps the setup checklist partially done
            'last_synced_at' => now()->subMinutes(12),
        ]);

        $admin->cloudflareAccounts()->syncWithoutDetaching([$account->id => ['role' => 'owner']]);

        $acme = Domain::create([
            'cloudflare_account_id' => $account->id,
            'zone_id' => 'zone_acme_com',
            'name' => 'acme.com',
            'status' => 'active',
            'routing_enabled' => true,
            'sending_enabled' => true,
            'inbound_capture' => 'catch_all',
            'last_synced_at' => now()->subMinutes(12),
        ]);

        Domain::create([
            'cloudflare_account_id' => $account->id,
            'zone_id' => 'zone_acme_io',
            'name' => 'acme.io',
            'status' => 'active',
            'routing_enabled' => true,
            'sending_enabled' => false, // shows the "Enable Sending" action
            'inbound_capture' => 'per_address',
            'last_synced_at' => now()->subHours(3),
        ]);

        Domain::create([
            'cloudflare_account_id' => $account->id,
            'zone_id' => 'zone_team_acme',
            'name' => 'team.acme.com',
            'status' => 'active',
            'routing_enabled' => true,
            'sending_enabled' => true,
            'inbound_capture' => 'none',
            'last_synced_at' => now()->subDay(),
        ]);

        $mailboxes = collect([
            ['ada@acme.com', 'Ada Lovelace'],
            ['grace@acme.com', 'Grace Hopper'],
            ['alan@acme.com', 'Alan Turing'],
            ['katherine@acme.com', 'Katherine Johnson'],
        ])->map(fn ($m) => Mailbox::create([
            'cloudflare_account_id' => $account->id,
            'domain_id' => $acme->id,
            'email' => $m[0],
            'display_name' => $m[1],
            'signature' => "— {$m[1]}\nAcme Corp",
            'password' => 'password',
            'login_enabled' => true,
            'last_login_at' => now()->subHours(random_int(1, 40)),
        ]));

        $ada = $mailboxes->first();

        $this->seedInbox($account, $acme, $ada);
        $this->seedSent($account, $acme, $ada);
        $this->seedContacts($ada);
        $this->seedCalendar($ada);
        $this->seedTasks($ada);
    }

    private function seedInbox(CloudflareAccount $account, Domain $domain, Mailbox $ada): void
    {
        $base = [
            'cloudflare_account_id' => $account->id,
            'domain_id' => $domain->id,
            'mailbox_id' => $ada->id,
            'to_email' => $ada->email,
            'folder' => 'inbox',
        ];

        // A real 3-message conversation (JWZ threading via Message-ID chain).
        $root = '<q3-planning-1@acme.com>';
        $reply1 = '<q3-planning-2@acme.com>';

        Email::create($base + [
            'ingest_key' => Str::uuid()->toString(),
            'message_id' => $root,
            'from_name' => 'Grace Hopper',
            'from_email' => 'grace@acme.com',
            'subject' => 'Q3 planning sync',
            'text_body' => "Hi Ada,\n\nCan we lock the Q3 roadmap this week? I'd like to align on the top three initiatives before the leadership review on Friday.\n\nThanks,\nGrace",
            'html_body' => '<p>Hi Ada,</p><p>Can we lock the <strong>Q3 roadmap</strong> this week? I\'d like to align on the top three initiatives before the leadership review on Friday.</p><p>Thanks,<br>Grace</p>',
            'read_at' => now()->subDays(2),
            'received_at' => now()->subDays(2)->setTime(9, 12),
        ]);

        Email::create($base + [
            'ingest_key' => Str::uuid()->toString(),
            'message_id' => $reply1,
            'in_reply_to' => $root,
            'references' => [$root],
            'from_name' => 'Alan Turing',
            'from_email' => 'alan@acme.com',
            'subject' => 'Re: Q3 planning sync',
            'text_body' => "Adding myself — I can cover the platform workstream.\n\nProposing Wed 2pm. Works for me.\n\nAlan",
            'read_at' => now()->subDays(1),
            'received_at' => now()->subDays(1)->setTime(14, 3),
        ]);

        Email::create($base + [
            'ingest_key' => Str::uuid()->toString(),
            'message_id' => '<q3-planning-3@acme.com>',
            'in_reply_to' => $reply1,
            'references' => [$root, $reply1],
            'from_name' => 'Grace Hopper',
            'from_email' => 'grace@acme.com',
            'subject' => 'Re: Q3 planning sync',
            'text_body' => "Wed 2pm works. I'll send an invite with the agenda attached.\n\nGrace",
            'read_at' => null,
            'starred' => true,
            'received_at' => now()->subHours(4)->setTime(11, 22),
        ]);

        // Standalone messages of varied kinds.
        $singles = [
            ['GitHub', 'notifications@github.com', '[acme/mailbox] Pull request #44 was merged',
                "@mucan54 merged pull request #44 into main.\n\n\"Add cloud+envelope brand logo and redesign setup-checklist widget\"", true, false, now()->subHours(6)],
            ['Stripe', 'receipts@stripe.com', 'Your receipt from Acme Corp #2043-1179',
                "Amount paid: \$49.00\nDate: ".now()->subHours(8)->toFormattedDateString()."\nCard: Visa •••• 4242", true, false, now()->subHours(8)],
            ['Katherine Johnson', 'katherine@acme.com', 'Launch metrics — week 1',
                'First week numbers are in: 3,120 signups, 41% activation, churn under 2%. Full dashboard linked internally.', false, true, now()->subHours(20)],
            ['Figma', 'team@figma.com', 'Ada, your team was mentioned in “Mailbox v2”',
                'Grace left a comment on the Mailbox v2 file: "Envelope icon looks great in the top bar now."', false, false, now()->subDay()->setTime(16, 45)],
            ['Notion', 'no-reply@notion.so', 'Weekly digest: 7 updates across your workspace',
                "Here's what changed in Acme HQ this week — 7 pages updated, 2 new docs, 12 comments.", true, false, now()->subDays(2)->setTime(8, 0)],
            ['Linear', 'notifications@linear.app', 'ACM-231 assigned to you',
                '"Persist sent Message-ID for full threading" was assigned to you by Grace Hopper.', false, false, now()->subDays(2)->setTime(10, 30)],
            ['Cloudflare', 'noreply@notify.cloudflare.com', 'Email Routing is active on acme.com',
                'Your domain acme.com is now routing email through Cloudflare. Catch-all is enabled.', true, false, now()->subDays(3)],
            ['Amazon', 'ship-confirm@amazon.com', 'Your order has shipped',
                'Your package with "Mechanical Keyboard" is on the way. Arriving tomorrow.', true, false, now()->subDays(4)],
        ];

        foreach ($singles as [$name, $from, $subject, $text, $read, $starred, $at]) {
            Email::create($base + [
                'ingest_key' => Str::uuid()->toString(),
                'message_id' => '<'.Str::uuid().'@mail.local>',
                'from_name' => $name,
                'from_email' => $from,
                'subject' => $subject,
                'text_body' => $text,
                'read_at' => $read ? $at : null,
                'starred' => $starred,
                'received_at' => $at,
            ]);
        }
    }

    private function seedSent(CloudflareAccount $account, Domain $domain, Mailbox $ada): void
    {
        $base = [
            'cloudflare_account_id' => $account->id,
            'domain_id' => $domain->id,
            'mailbox_id' => $ada->id,
            'driver' => 'api',
            'from_email' => $ada->email,
        ];

        SentEmail::create($base + [
            'to' => ['grace@acme.com', 'alan@acme.com'],
            'subject' => 'Re: Q3 planning sync',
            'message_id' => '<reply-ada-1@acme.com>',
            'in_reply_to' => '<q3-planning-3@acme.com>',
            'references' => ['<q3-planning-1@acme.com>', '<q3-planning-2@acme.com>', '<q3-planning-3@acme.com>'],
            'text_body' => "Perfect — Wed 2pm confirmed. I'll prep the roadmap draft beforehand.\n\nAda",
            'html_body' => '<p>Perfect — Wed 2pm confirmed. I\'ll prep the roadmap draft beforehand.</p><p>Ada</p>',
            'status' => 'delivered',
            'sent_at' => now()->subHours(3),
        ]);

        SentEmail::create($base + [
            'to' => ['candidate@example.com'],
            'subject' => 'Welcome to Acme — your account is ready',
            'message_id' => '<welcome-1@acme.com>',
            'text_body' => 'Hi! Your Acme account is live. Log in any time at https://acme.com/app.',
            'status' => 'delivered',
            'sent_at' => now()->subHours(9),
        ]);

        SentEmail::create($base + [
            'to' => ['old-address@bounced.example'],
            'subject' => 'Invoice #2043',
            'text_body' => 'Please find your invoice attached.',
            'status' => 'bounced',
            'error' => 'Recipient permanently bounced (550 5.1.1 user unknown).',
            'sent_at' => now()->subDay(),
        ]);

        SentEmail::create([
            'cloudflare_account_id' => $account->id,
            'domain_id' => null,
            'mailbox_id' => $ada->id,
            'driver' => 'api',
            'from_email' => 'noreply@acme.io',
            'to' => ['someone@example.com'],
            'subject' => 'Newsletter — March',
            'text_body' => 'This month at Acme…',
            'status' => 'failed',
            'error' => 'email.sending.error.email.sending_disabled — Bu domain Cloudflare’de Email Sending için onboard edilmemiş. '
                .'Çözüm: Cloudflare Dashboard → Compute → Email Service → Email Sending → “Onboard Domain”. '
                .'Panel: https://dash.cloudflare.com/9a7b3c1d5e2f40a1b8c6d4e2f0a1b2c3/email/sending',
            'sent_at' => now()->subDays(2),
        ]);
    }

    private function seedContacts(Mailbox $ada): void
    {
        $people = [
            ['Grace Hopper', 'grace@acme.com', '+1 202 555 0142', 'Acme Corp', 'VP Engineering', true],
            ['Alan Turing', 'alan@acme.com', '+44 20 7946 0958', 'Acme Corp', 'Principal Engineer', true],
            ['Katherine Johnson', 'katherine@acme.com', '+1 757 555 0110', 'Acme Corp', 'Data Science Lead', false],
            ['Margaret Hamilton', 'margaret@apollo.example', '+1 617 555 0133', 'Apollo Labs', 'Director of Software', false],
            ['Tim Berners-Lee', 'tim@web.example', '+44 20 7946 0321', 'W3C', 'Founder', true],
            ['Radia Perlman', 'radia@net.example', '+1 415 555 0187', 'NetWorks', 'Network Architect', false],
        ];

        foreach ($people as [$name, $email, $phone, $company, $title, $fav]) {
            Contact::create([
                'mailbox_id' => $ada->id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'company' => $company,
                'title' => $title,
                'favorite' => $fav,
            ]);
        }
    }

    private function seedCalendar(Mailbox $ada): void
    {
        $events = [
            ['Q3 planning sync', 'Zoom', now()->setTime(14, 0), now()->setTime(15, 0), false, '#f59e0b'],
            ['1:1 with Grace', 'Room 4B', now()->addDay()->setTime(10, 30), now()->addDay()->setTime(11, 0), false, '#0ea5e9'],
            ['Leadership review', 'Boardroom', now()->addDays(2)->setTime(9, 0), now()->addDays(2)->setTime(10, 30), false, '#8b5cf6'],
            ['Team offsite', 'Lakeside', now()->addDays(4)->startOfDay(), now()->addDays(4)->endOfDay(), true, '#22c55e'],
            ['Design review', 'Figma', now()->addDays(1)->setTime(16, 0), now()->addDays(1)->setTime(16, 45), false, '#ec4899'],
        ];

        foreach ($events as [$title, $loc, $start, $end, $allDay, $color]) {
            Event::create([
                'mailbox_id' => $ada->id,
                'title' => $title,
                'location' => $loc,
                'starts_at' => $start,
                'ends_at' => $end,
                'all_day' => $allDay,
                'color' => $color,
            ]);
        }
    }

    private function seedTasks(Mailbox $ada): void
    {
        $tasks = [
            ['Draft Q3 roadmap', false, now()->addDay()],
            ['Review PR #44 (logo + widget)', false, now()],
            ['Reply to Katherine on launch metrics', false, now()->addDays(2)],
            ['Onboard acme.io for sending', false, now()->addDays(3)],
            ['Ship threading fix', true, now()->subDay()],
            ['Prepare leadership review deck', false, now()->addDays(2)],
        ];

        foreach ($tasks as $i => [$title, $done, $due]) {
            Task::create([
                'mailbox_id' => $ada->id,
                'title' => $title,
                'done' => $done,
                'due_on' => $due,
                'position' => $i,
            ]);
        }
    }
}

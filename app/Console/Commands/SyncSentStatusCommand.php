<?php

namespace App\Console\Commands;

use App\Models\CloudflareAccount;
use App\Services\Mail\SentStatusReconciler;
use Illuminate\Console\Command;
use Throwable;

/**
 * Promote queued outbound mail to the final Cloudflare activity status.
 * Schedule it every few minutes (see routes/console.php).
 */
class SyncSentStatusCommand extends Command
{
    protected $signature = 'mail:sync-sent-status';

    protected $description = 'Update queued sent mail to Cloudflare\'s final status (sent or delivered)';

    public function handle(SentStatusReconciler $reconciler): int
    {
        $updated = 0;

        foreach (CloudflareAccount::query()->get() as $account) {
            if (! $account->isConnected()) {
                continue;
            }

            try {
                $count = $reconciler->reconcile($account);
                $updated += $count;
                $this->line($account->slug.': '.$count);
            } catch (Throwable $e) {
                $this->error($account->slug.': '.$e->getMessage());
            }
        }

        $this->info("Updated {$updated} sent message(s).");

        return self::SUCCESS;
    }
}

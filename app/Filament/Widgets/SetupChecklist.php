<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Compose;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Resources\Mailboxes\MailboxResource;
use App\Models\CloudflareAccount;
use App\Services\Cloudflare\WorkerDeployer;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class SetupChecklist extends Widget
{
    protected string $view = 'filament.widgets.setup-checklist';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -3;

    /**
     * @return array<int, array{label: string, done: bool, hint: string, url: ?string}>
     */
    public function getItems(): array
    {
        /** @var CloudflareAccount $account */
        $account = Filament::getTenant();

        $drifted = $account->isWorkerDeployed() && (new WorkerDeployer($account))->isDrifted();

        return [
            [
                'label' => __('Connect your Cloudflare account'),
                'done' => $account->isConnected(),
                'hint' => __('Add an API token in Settings.'),
                'url' => $this->safeUrl(Settings::class),
            ],
            [
                'label' => __('Sync your domains'),
                'done' => $account->isSynced(),
                'hint' => __('Run “Full Sync” on the Domains page.'),
                'url' => $this->safeUrl(DomainResource::class),
            ],
            [
                'label' => $drifted ? __('Redeploy the inbound Worker (drifted)') : __('Deploy the inbound Worker'),
                'done' => $account->isWorkerDeployed() && ! $drifted,
                'hint' => __('Click “Deploy Worker”, then set a domain to catch-all → Worker.'),
                'url' => $this->safeUrl(DomainResource::class),
            ],
            [
                'label' => __('Create at least one mailbox'),
                'done' => $account->mailboxes()->exists(),
                'hint' => __('Add an address + password under Mailboxes.'),
                'url' => class_exists(MailboxResource::class) ? $this->safeUrl(MailboxResource::class) : $this->safeUrl(Compose::class),
            ],
        ];
    }

    public function getDoneCount(): int
    {
        return count(array_filter($this->getItems(), fn ($i) => $i['done']));
    }

    public function getTotalCount(): int
    {
        return count($this->getItems());
    }

    public function getProgress(): int
    {
        $total = $this->getTotalCount();

        return $total ? (int) round($this->getDoneCount() / $total * 100) : 0;
    }

    public function isComplete(): bool
    {
        return $this->getDoneCount() === $this->getTotalCount();
    }

    private function safeUrl(string $resource): ?string
    {
        try {
            return $resource::getUrl();
        } catch (\Throwable) {
            return null;
        }
    }
}

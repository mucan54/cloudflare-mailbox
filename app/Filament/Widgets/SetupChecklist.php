<?php

namespace App\Filament\Widgets;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\WorkerDeployer;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class SetupChecklist extends Widget
{
    protected string $view = 'filament.widgets.setup-checklist';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -3;

    public static function canView(): bool
    {
        $account = Filament::getTenant();

        // Hide once fully set up.
        return $account instanceof CloudflareAccount
            && ! ($account->isConnected() && $account->isSynced() && $account->isWorkerDeployed() && $account->mailboxes()->exists());
    }

    /**
     * @return array<int, array{label: string, done: bool, hint: string}>
     */
    public function getItems(): array
    {
        /** @var CloudflareAccount $account */
        $account = Filament::getTenant();

        $drifted = $account->isWorkerDeployed() && (new WorkerDeployer($account))->isDrifted();

        return [
            [
                'label' => 'Cloudflare hesabı bağlı',
                'done' => $account->isConnected(),
                'hint' => 'Ayarlar’dan API token ekleyin.',
            ],
            [
                'label' => 'Domainler senkronize',
                'done' => $account->isSynced(),
                'hint' => 'Domainler sayfasında “Full Sync”.',
            ],
            [
                'label' => 'Gelen Worker deploy edildi'.($drifted ? ' (kaymış!)' : ''),
                'done' => $account->isWorkerDeployed() && ! $drifted,
                'hint' => 'Domainler’de bir domaini catch-all → Worker yapın ve deploy edin.',
            ],
            [
                'label' => 'En az bir mailbox',
                'done' => $account->mailboxes()->exists(),
                'hint' => 'Mailbox’lar’dan bir adres + şifre oluşturun.',
            ],
        ];
    }
}

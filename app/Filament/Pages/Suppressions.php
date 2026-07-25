<?php

namespace App\Filament\Pages;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\CloudflareClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/**
 * Manage the account's Email Sending suppression list — the addresses
 * Cloudflare refuses to send to after hard bounces or spam complaints. Data is
 * live from the Cloudflare API (not stored locally).
 */
class Suppressions extends Page
{
    protected string $view = 'filament.pages.suppressions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Mail';

    protected static ?int $navigationSort = 40;

    /** @var array<int, array<string, string>> */
    public array $rows = [];

    public ?string $loadError = null;

    public bool $loaded = false;

    public static function getNavigationLabel(): string
    {
        return 'Baskılama listesi';
    }

    public function getTitle(): string
    {
        return 'Baskılama listesi (Suppression List)';
    }

    public function mount(): void
    {
        $this->load();
    }

    public function load(): void
    {
        $this->loaded = true;
        $this->loadError = null;
        $this->rows = [];

        $account = Filament::getTenant();
        if (! $account instanceof CloudflareAccount || ! $account->isConnected()) {
            $this->loadError = 'Önce Cloudflare hesabını bağlayın.';

            return;
        }

        try {
            $result = CloudflareClient::forAccount($account)->listSuppressions();
        } catch (Throwable $e) {
            $this->loadError = $e->getMessage();

            return;
        }

        $this->rows = collect($result)
            ->map(fn ($r) => [
                'key' => (string) ($r['id'] ?? $r['email'] ?? $r['address'] ?? ''),
                'email' => (string) ($r['email'] ?? $r['address'] ?? ''),
                'reason' => (string) ($r['reason'] ?? $r['type'] ?? '—'),
                'created_at' => (string) ($r['created_at'] ?? $r['created'] ?? ''),
            ])
            ->filter(fn ($r) => $r['email'] !== '')
            ->values()
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Yenile')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action('load'),

            Action::make('add')
                ->label('Adres ekle')
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    TextInput::make('email')
                        ->label('E-posta adresi')
                        ->email()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->add($data['email']);
                }),
        ];
    }

    public function add(string $email): void
    {
        $account = Filament::getTenant();
        if (! $account instanceof CloudflareAccount || ! $account->isConnected()) {
            return;
        }

        try {
            CloudflareClient::forAccount($account)->addSuppression($email);
        } catch (Throwable $e) {
            Notification::make()->title('Eklenemedi')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Adres baskılama listesine eklendi')->success()->send();
        $this->load();
    }

    public function remove(string $key): void
    {
        $account = Filament::getTenant();
        if (! $account instanceof CloudflareAccount || ! $account->isConnected()) {
            return;
        }

        try {
            CloudflareClient::forAccount($account)->deleteSuppression($key);
        } catch (Throwable $e) {
            Notification::make()->title('Kaldırılamadı')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Adres listeden kaldırıldı')->success()->send();
        $this->load();
    }

    public function form(Schema $schema): Schema
    {
        return $schema;
    }
}

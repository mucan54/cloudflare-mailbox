<?php

namespace App\Filament\Pages;

use App\Models\CloudflareAccount;
use App\Services\Mail\EmailSender;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

class Compose extends Page
{
    protected string $view = 'filament.pages.compose';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Mail';

    public static function getNavigationLabel(): string
    {
        return __('Compose');
    }

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('from_domain')
                    ->label('Gönderen domain')
                    ->options(fn () => $this->fromDomainOptions())
                    ->required()
                    ->native(false)
                    ->live(),

                TextInput::make('from_local')
                    ->label('Gönderen (yerel kısım)')
                    ->placeholder('info')
                    ->default('info')
                    ->required(),

                TextInput::make('to')
                    ->label('Alıcı(lar)')
                    ->helperText('Virgülle ayırın')
                    ->required(),

                TextInput::make('cc')->label('CC'),
                TextInput::make('bcc')->label('BCC'),

                TextInput::make('subject')->label('Konu')->required(),

                RichEditor::make('html')->label('Mesaj')->required(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')
                ->label('Gönder')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->action('send'),
        ];
    }

    public function send(): void
    {
        $data = $this->form->getState();

        /** @var CloudflareAccount $account */
        $account = Filament::getTenant();

        if (! $account->isConnected()) {
            Notification::make()->title('Önce Cloudflare hesabını bağlayın')->warning()->send();

            return;
        }

        try {
            $sent = app(EmailSender::class)->send($account, [
                'from' => $data['from_local'].'@'.$data['from_domain'],
                'to' => $this->splitAddresses($data['to'] ?? ''),
                'cc' => $this->splitAddresses($data['cc'] ?? ''),
                'bcc' => $this->splitAddresses($data['bcc'] ?? ''),
                'subject' => $data['subject'] ?? null,
                'html' => $data['html'] ?? null,
            ]);
        } catch (Throwable $e) {
            Notification::make()->title('Gönderilemedi')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Mail işlendi')
            ->body('Durum: '.$sent->status)
            ->status($sent->status === 'failed' ? 'danger' : 'success')
            ->send();

        $this->form->fill(['from_local' => 'info']);
    }

    /**
     * @return array<string, string>
     */
    protected function fromDomainOptions(): array
    {
        /** @var CloudflareAccount $account */
        $account = Filament::getTenant();

        return $account->domains()
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function splitAddresses(string $value): array
    {
        return collect(preg_split('/[,;\s]+/', $value) ?: [])
            ->map(fn ($v) => trim($v))
            ->filter()
            ->values()
            ->all();
    }
}

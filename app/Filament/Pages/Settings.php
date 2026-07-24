<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Tenancy\RegisterCloudflareAccount;
use App\Models\CloudflareAccount;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CloudflareException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Tenant settings — edit the Cloudflare API token (e.g. after entering a wrong
 * or under-scoped one), account id, and sending driver; test the connection.
 */
class Settings extends Page
{
    protected string $view = 'filament.pages.settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Cloudflare';

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    protected static ?int $navigationSort = 90;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $account = $this->account();

        $this->form->fill([
            'name' => $account->name,
            'account_id' => $account->account_id,
            'sending_driver' => $account->sending_driver ?: 'api',
            'api_token' => null, // never expose the stored token
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('name')
                    ->label('Hesap adı / etiket')
                    ->required(),

                TextInput::make('account_id')
                    ->label('Cloudflare Account ID')
                    ->required(),

                TextInput::make('api_token')
                    ->label('API Token')
                    ->password()
                    ->revealable()
                    ->autocomplete(false)
                    ->helperText(new HtmlString(
                        'Mevcut token güvenlik için gösterilmez. <b>Değiştirmek için</b> yeni bir token '
                        .'yapıştırın; boş bırakırsanız mevcut token korunur.<br>'
                        .'<a href="'.e(RegisterCloudflareAccount::tokenTemplateUrl()).'" target="_blank" '
                        .'class="fi-link" style="text-decoration:underline">Cloudflare’de yeni token oluştur</a> '
                        .'— gereken izinler: Account · <b>Email Routing Addresses</b> · Edit | Zone · '
                        .'<b>Email Routing Rules</b> · Edit | Account · <b>Email Sending</b> · Edit | '
                        .'Account · <b>Workers Scripts</b> · Edit | Zone · <b>Zone</b> · Read.'
                    )),

                Select::make('sending_driver')
                    ->label('Gönderim sürücüsü')
                    ->options(['api' => 'REST API (önerilen)', 'smtp' => 'SMTP'])
                    ->required()
                    ->native(false),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Bağlantıyı test et')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action('testConnection'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $account = $this->account();

        $update = [
            'name' => $data['name'],
            'account_id' => $data['account_id'],
            'sending_driver' => $data['sending_driver'],
        ];

        if (filled($data['api_token'] ?? null)) {
            $update['api_token'] = $data['api_token'];
        }

        $account->update($update);

        // Clear the token field again after saving.
        $this->form->fill([
            'name' => $account->name,
            'account_id' => $account->account_id,
            'sending_driver' => $account->sending_driver,
            'api_token' => null,
        ]);

        Notification::make()->title('Ayarlar kaydedildi')->success()->send();
    }

    public function testConnection(): void
    {
        $data = $this->form->getState();
        $account = $this->account();

        // Test with the freshly entered token if provided, otherwise the stored one.
        $token = filled($data['api_token'] ?? null) ? $data['api_token'] : $account->api_token;
        $client = new CloudflareClient($token, $data['account_id'] ?: $account->account_id);

        try {
            $client->verifyToken();
            $zones = $client->listZones();
        } catch (CloudflareException $e) {
            Notification::make()->title('Bağlantı başarısız')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Bağlantı başarılı')
            ->body(count($zones).' domain görüldü. Token geçerli.')
            ->success()
            ->send();
    }

    protected function account(): CloudflareAccount
    {
        return Filament::getTenant();
    }
}

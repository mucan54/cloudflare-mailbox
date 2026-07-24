<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CloudflareException;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class RegisterCloudflareAccount extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Cloudflare hesabı bağla';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Hesap adı / etiket')
                ->required()
                ->maxLength(255),

            TextInput::make('api_token')
                ->label('Cloudflare API Token')
                ->password()
                ->revealable()
                ->required()
                ->helperText(new HtmlString(
                    'Gereken izinler önceden seçili olarak '
                    .'<a href="'.e(static::tokenTemplateUrl()).'" target="_blank" class="fi-link" '
                    .'style="text-decoration:underline">Cloudflare token oluşturma sayfasını aç</a>, '
                    .'"Create Token" deyip token’ı buraya yapıştırın.'
                )),

            TextInput::make('account_id')
                ->label('Account ID (opsiyonel)')
                ->helperText('Boş bırakırsanız token’dan otomatik bulunur. Birden fazla hesabınız varsa doldurun.')
                ->maxLength(255),
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        $client = new CloudflareClient($data['api_token']);

        // 1) Token geçerli mi?
        try {
            $client->verifyToken();
        } catch (CloudflareException $e) {
            Notification::make()
                ->title('Token doğrulanamadı')
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }

        // 2) Hesabı otomatik bul / doğrula
        $accountId = filled($data['account_id'] ?? null) ? $data['account_id'] : null;

        if (! $accountId) {
            try {
                $accounts = $client->listAccounts();
            } catch (CloudflareException $e) {
                Notification::make()->title('Hesaplar alınamadı')->body($e->getMessage())->danger()->send();
                throw new Halt;
            }

            if (count($accounts) === 0) {
                Notification::make()->title('Bu token’a bağlı hesap bulunamadı')->danger()->send();
                throw new Halt;
            }

            if (count($accounts) > 1) {
                $ids = collect($accounts)->map(fn ($a) => ($a['name'] ?? '').' ('.$a['id'].')')->implode(', ');
                Notification::make()
                    ->title('Birden fazla hesap bulundu')
                    ->body('Lütfen Account ID alanını doldurun: '.$ids)
                    ->warning()
                    ->send();

                throw new Halt;
            }

            $accountId = $accounts[0]['id'];
        }

        // 3) Tenant kaydı
        $account = CloudflareAccount::create([
            'name' => $data['name'],
            'account_id' => $accountId,
            'api_token' => $data['api_token'],
        ]);

        $account->users()->attach(Auth::id(), ['role' => 'owner']);

        Notification::make()
            ->title('Cloudflare hesabı bağlandı')
            ->body('Şimdi domainlerinizi çekmek için “Full Sync” yapabilirsiniz.')
            ->success()
            ->send();

        return $account;
    }

    public static function tokenTemplateUrl(): string
    {
        $cfg = config('cloudflare.token_template');
        $groups = rawurlencode(json_encode($cfg['permission_groups']));
        $name = rawurlencode($cfg['name']);

        return "{$cfg['dashboard_url']}?permissionGroupKeys={$groups}&accountId=*&zoneId=all&name={$name}";
    }
}

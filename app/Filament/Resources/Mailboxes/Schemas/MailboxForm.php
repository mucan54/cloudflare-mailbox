<?php

namespace App\Filament\Resources\Mailboxes\Schemas;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Support\AvatarImage;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MailboxForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('avatar_path')
                ->label(__('Profile photo'))
                ->avatar()
                ->imageEditor()
                ->disk(fn () => Mailbox::avatarDisk())
                ->visibility('private')
                ->maxSize(5120)
                // Same pipeline as the portal upload: square, 256 px JPEG,
                // metadata stripped. The model removes the replaced file.
                ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                    [$bytes, $ext] = AvatarImage::normalize($file->get());
                    $path = 'avatars/'.Str::random(24).'.'.$ext;
                    Storage::disk(Mailbox::avatarDisk())->put($path, $bytes);

                    return $path;
                })
                ->helperText(__('Shown in the mailbox app and on mail this address sends to people on this server.'))
                ->columnSpanFull(),

            Select::make('domain_id')
                ->label(__('Domain'))
                ->options(fn () => Filament::getTenant()->domains()->orderBy('name')->pluck('name', 'id'))
                ->required()
                ->native(false)
                ->searchable()
                ->live()
                ->helperText(__('Pick one of your domains.')),

            TextInput::make('local_part')
                ->label(__('Address'))
                ->placeholder('info')
                ->required()
                ->prefixIcon('heroicon-o-at-symbol')
                ->suffix(fn (Get $get): string => '@'.(Domain::find($get('domain_id'))?->name ?? 'domain'))
                ->helperText(__('The part before @. This becomes the login for the mailbox app.')),

            TextInput::make('display_name')
                ->label(__('Display name'))
                ->maxLength(255),

            TextInput::make('password')
                ->label(__('Password'))
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText(__('Set on create; leave blank when editing to keep the current password.')),

            Toggle::make('login_enabled')
                ->label(__('Login enabled'))
                ->default(true),
        ]);
    }
}

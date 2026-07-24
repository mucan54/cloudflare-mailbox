<?php

namespace App\Filament\Widgets;

use App\Models\Email;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestEmails extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return __('Latest incoming mail');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Email::query()
                ->where('cloudflare_account_id', Filament::getTenant()->getKey())
                ->latest('received_at'))
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('from_email')
                    ->label(__('From'))
                    ->description(fn (Email $e) => $e->from_name)
                    ->limit(30),

                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->limit(50)
                    ->weight(fn (Email $e) => $e->read_at ? null : 'bold'),

                TextColumn::make('to_email')
                    ->label(__('To'))
                    ->toggleable(),

                TextColumn::make('received_at')
                    ->label(__('Received'))
                    ->since()
                    ->sortable(),
            ])
            ->emptyStateHeading(__('No mail yet'))
            ->emptyStateIcon('heroicon-o-inbox');
    }
}

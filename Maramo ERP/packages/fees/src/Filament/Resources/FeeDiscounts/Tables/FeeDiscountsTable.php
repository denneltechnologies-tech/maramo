<?php

namespace Classivo\Fees\Filament\Resources\FeeDiscounts\Tables;

use Classivo\Fees\Models\FeeDiscount;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * Fees Discounts list: Name, Code, Type, Amount/Percent, Students
 * assigned, Expiry, Status.
 */
class FeeDiscountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => FeeDiscount::TYPES[$state] ?? ucfirst((string) $state))
                    ->color(fn (?string $state): string => $state === 'percent' ? 'info' : 'success'),
                TextColumn::make('value')
                    ->label(__('Amount / %'))
                    ->formatStateUsing(fn ($state, FeeDiscount $record): string => $record->type === 'percent'
                        ? rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%'
                        : number_format((float) $state, 2)),
                TextColumn::make('students_count')
                    ->label(__('Students'))
                    ->badge()
                    ->color('gray')
                    ->counts('students'),
                TextColumn::make('valid_to')->label(__('Expiry'))->date()->placeholder('—'),
                ToggleColumn::make('status')->label(__('Active')),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}

<?php

namespace Classivo\Fees\Filament\Resources\FeeGroups\Tables;

use Classivo\Fees\Models\FeeGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * Fees Groups list: Name, Description, Fee-masters count, Total amount,
 * Status.
 */
class FeeGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('description')->placeholder('—')->limit(50),
                TextColumn::make('masters_count')
                    ->label(__('Fee masters'))
                    ->badge()
                    ->color('info')
                    ->counts('masters'),
                TextColumn::make('total_amount')
                    ->label(__('Total amount'))
                    ->money(fn (FeeGroup $record) => $record->branch?->currency ?? 'INR')
                    ->state(fn (FeeGroup $record): float => $record->total_amount),
                ToggleColumn::make('status')->label(__('Active')),
            ])
            ->filters([
                TernaryFilter::make('status')->label(__('Active')),
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

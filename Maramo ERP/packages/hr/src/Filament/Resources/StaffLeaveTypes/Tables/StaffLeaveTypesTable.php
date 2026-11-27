<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * Staff leave-types list (spec §15): Name, Days allowed, Paid, Status.
 */
class StaffLeaveTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('days_allowed')
                    ->label(__('hr::ui.days_year'))
                    ->placeholder('—'),
                IconColumn::make('is_paid')
                    ->label(__('hr::ui.paid'))
                    ->boolean(),
                ToggleColumn::make('status')
                    ->label(__('hr::ui.active')),
            ])
            ->filters([
                TernaryFilter::make('is_paid')->label(__('hr::ui.paid')),
                TernaryFilter::make('status')->label(__('hr::ui.active')),
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

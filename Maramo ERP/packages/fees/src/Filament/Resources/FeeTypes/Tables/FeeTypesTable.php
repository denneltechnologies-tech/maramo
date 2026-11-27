<?php

namespace Classivo\Fees\Filament\Resources\FeeTypes\Tables;

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
 * Fees Types list: Name, Code, Description, Used-in-groups count, Status.
 */
class FeeTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge()->color('gray')->placeholder('—')->searchable(),
                TextColumn::make('description')->placeholder('—')->limit(50),
                TextColumn::make('masters_count')
                    ->label(__('Used in masters'))
                    ->badge()
                    ->color('info')
                    ->counts('masters'),
                IconColumn::make('is_system')->label(__('System'))->boolean(),
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

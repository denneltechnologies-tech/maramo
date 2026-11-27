<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveAllocations\Tables;

use Classivo\Hr\Models\StaffLeaveAllocation;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff leave-allocations list (spec §15): Staff, Leave type, Allocated, Used, Balance.
 */
class StaffLeaveAllocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['staff', 'leaveType']))
            ->columns([
                TextColumn::make('staff.full_name')
                    ->label(__('hr::ui.staff'))
                    ->searchable(['first_name', 'last_name'])
                    // full_name is an accessor — bare sortable() 500s on header
                    // click (Phase 2 CRUD-loop finding).
                    ->sortable(['first_name']),
                TextColumn::make('leaveType.name')
                    ->label(__('hr::ui.leave_type'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('days_allocated')
                    ->label(__('hr::ui.allocated'))
                    ->numeric(),
                TextColumn::make('balance')
                    ->label(__('hr::ui.balance'))
                    ->badge()
                    ->color('success')
                    ->state(fn (StaffLeaveAllocation $record): float => $record->staff
                        ? $record->staff->leaveBalance($record->staff_leave_type_id, $record->session_id)
                        : (float) $record->days_allocated),
                TextColumn::make('note')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('staff_leave_type_id')
                    ->label(__('hr::ui.leave_type'))
                    ->relationship('leaveType', 'name'),
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
            ->defaultSort('created_at', 'desc');
    }
}

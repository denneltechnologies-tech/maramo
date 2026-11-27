<?php

namespace Classivo\Exams\Filament\Resources\Grades\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * Grades list: Grade, percent range, grade point, is-fail.
 */
class GradesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('classivo-exams::ui.grade'))->badge()->color('info')->sortable(),
                TextColumn::make('range')
                    ->label(__('classivo-exams::ui.percent_range'))
                    ->state(fn ($record): string => rtrim(rtrim((string) $record->min_percent, '0'), '.').'% – '.rtrim(rtrim((string) $record->max_percent, '0'), '.').'%'),
                TextColumn::make('grade_point')->label(__('classivo-exams::ui.gpa'))->placeholder('—'),
                TextColumn::make('remark')->label(__('classivo-exams::ui.field_remark'))->placeholder('—')->toggleable(),
                IconColumn::make('is_fail')->label(__('classivo-exams::ui.fail'))->boolean(),
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
            ->defaultSort('min_percent', 'desc');
    }
}

<?php

namespace Classivo\Exams\Filament\Resources\ExamGroups\Tables;

use Classivo\Exams\Models\ExamGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exam-groups list: Name, Type, Exams count, Status.
 */
class ExamGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('exams'))
            ->columns([
                TextColumn::make('name')->label(__('classivo-exams::ui.field_name'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('type')->label(__('classivo-exams::ui.field_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ExamGroup::typeOptions()[$state] ?? ucfirst((string) $state))
                    ->color('info'),
                TextColumn::make('exams_count')
                    ->label(__('classivo-exams::ui.exams'))
                    ->badge()
                    ->color('gray'),
                IconColumn::make('status')->label(__('classivo-exams::ui.field_status'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')->label(__('classivo-exams::ui.field_created_at'))->dateTime()->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->options(ExamGroup::typeOptions()),
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

<?php

namespace Classivo\Exams\Filament\Resources\Exams\Tables;

use Classivo\Exams\Filament\Pages\BuildExamSchedule;
use Classivo\Exams\Filament\Pages\ExamResults;
use Classivo\Exams\Filament\Pages\MarksRegister;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamGroup;
use Classivo\Exams\Services\ExamResultService;
use Classivo\Exams\Support\Authorize;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exams list: Exam name, group, class scope, dates, result published,
 * status. Row actions: Edit, Exam Schedule, Marks Register, Results, Publish /
 * Unpublish (gated by `exam.publish_result`), Delete.
 */
class ExamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['examGroup', 'schoolClass']))
            ->columns([
                TextColumn::make('name')->label(__('classivo-exams::ui.field_name'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('examGroup.name')->label(__('classivo-exams::ui.nav_group'))->badge()->color('info')->placeholder('—'),
                TextColumn::make('schoolClass.name')->label(__('classivo-exams::ui.class_scope'))->placeholder(__('classivo-exams::ui.all')),
                TextColumn::make('start_date')->label(__('classivo-exams::ui.field_start_date'))->date()->placeholder('—')->sortable(),
                TextColumn::make('end_date')->label(__('classivo-exams::ui.field_end_date'))->date()->placeholder('—')->toggleable(),
                IconColumn::make('is_published')->label(__('classivo-exams::ui.result_published_col'))->boolean(),
                IconColumn::make('status')->label(__('classivo-exams::ui.field_status'))->boolean()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('exam_group_id')
                    ->label(__('classivo-exams::ui.exam_group'))
                    ->options(fn (): array => ExamGroup::query()->orderBy('name')->pluck('name', 'id')->all()),
                TernaryFilter::make('is_published')->label(__('classivo-exams::ui.result_published_col')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('schedule')
                        ->label(__('classivo-exams::ui.exam_schedule'))
                        ->icon('heroicon-o-calendar-days')
                        ->url(fn (Exam $record): string => BuildExamSchedule::getUrl(['exam' => $record->id])),
                    Action::make('marksRegister')
                        ->label(__('classivo-exams::ui.marks_register'))
                        ->icon('heroicon-o-pencil-square')
                        ->url(fn (Exam $record): string => MarksRegister::getUrl(['exam' => $record->id])),
                    Action::make('results')
                        ->label(__('classivo-exams::ui.results'))
                        ->icon('heroicon-o-trophy')
                        ->url(fn (Exam $record): string => ExamResults::getUrl(['exam' => $record->id])),
                    Action::make('publish')
                        ->label(__('classivo-exams::ui.publish_result'))
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (Exam $record): bool => ! $record->is_published && Authorize::can('publish_result'))
                        ->action(function (Exam $record): void {
                            app(ExamResultService::class)->publish($record);
                            Notification::make()->success()->title(__('classivo-exams::ui.result_published'))->send();
                        }),
                    Action::make('unpublish')
                        ->label(__('classivo-exams::ui.unpublish_result'))
                        ->icon('heroicon-o-lock-closed')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (Exam $record): bool => $record->is_published && Authorize::can('publish_result'))
                        ->action(function (Exam $record): void {
                            app(ExamResultService::class)->unpublish($record);
                            Notification::make()->success()->title(__('classivo-exams::ui.result_unpublished'))->send();
                        }),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('start_date', 'desc');
    }
}

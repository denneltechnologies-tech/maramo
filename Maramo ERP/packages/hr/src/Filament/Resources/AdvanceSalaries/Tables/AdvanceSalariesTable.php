<?php

namespace Classivo\Hr\Filament\Resources\AdvanceSalaries\Tables;

use Carbon\CarbonImmutable;
use Classivo\Hr\Models\AdvanceSalary;
use Classivo\Hr\Support\Currency;
use Classivo\Hr\Support\HrLabels;
use Classivo\Hr\Support\HrPermissions;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Advance-salary list (spec §15): Staff, Amount, Month, Status. Approve / Reject
 * row actions, each taking the approver's remark — the same `advance_salaries.remark`
 * column the mobile decide endpoint writes, so a decision explains itself wherever it
 * was taken. `reason` (the applicant's) is never touched by a decision.
 */
class AdvanceSalariesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['staff', 'approvedBy']))
            ->columns([
                TextColumn::make('staff.full_name')
                    ->label(__('hr::ui.staff'))
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name']),
                TextColumn::make('amount')->money(Currency::code())->sortable(),
                TextColumn::make('month')
                    ->formatStateUsing(fn (?int $state, AdvanceSalary $record): string => $state
                        ? CarbonImmutable::create((int) $record->year, (int) $state, 1)->format('M Y')
                        : (string) $record->year),
                TextColumn::make('reason')->label(__('hr::ui.reason'))->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('remark')
                    ->label(__('hr::ui.decision_remark'))
                    ->limit(40)
                    ->placeholder('—')
                    ->tooltip(fn (AdvanceSalary $record): ?string => $record->remark)
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => HrLabels::enum(AdvanceSalary::STATUSES, 'advance_salary.statuses')[$state] ?? ucfirst((string) $state))
                    ->color(fn (?string $state): string => AdvanceSalary::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('approvedBy.name')->label(__('hr::ui.reviewed_by'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(HrLabels::enum(AdvanceSalary::STATUSES, 'advance_salary.statuses')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->authorize(fn (): bool => HrPermissions::can(HrPermissions::ADVANCE_SALARIES_APPROVE))
                    ->label(__('hr::ui.approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (AdvanceSalary $record): bool => $record->status === 'pending')
                    ->schema([
                        Textarea::make('remark')
                            ->label(__('hr::ui.remark_optional'))
                            ->rows(2)
                            ->maxLength(2000),
                    ])
                    ->action(function (AdvanceSalary $record, array $data): void {
                        $record->update(array_merge(
                            ['status' => 'approved', 'approved_by' => auth()->id()],
                            filled($data['remark'] ?? null) ? ['remark' => $data['remark']] : [],
                        ));

                        Notification::make()->success()->title(__('hr::ui.advance_approved'))->send();
                    }),
                Action::make('reject')
                    ->authorize(fn (): bool => HrPermissions::can(HrPermissions::ADVANCE_SALARIES_APPROVE))
                    ->label(__('hr::ui.reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (AdvanceSalary $record): bool => $record->status === 'pending')
                    ->schema([
                        Textarea::make('remark')
                            ->label(__('hr::ui.remark_optional'))
                            ->rows(2)
                            ->maxLength(2000),
                    ])
                    ->action(function (AdvanceSalary $record, array $data): void {
                        $record->update(array_merge(
                            ['status' => 'rejected', 'approved_by' => auth()->id()],
                            filled($data['remark'] ?? null) ? ['remark' => $data['remark']] : [],
                        ));

                        Notification::make()->warning()->title(__('hr::ui.advance_rejected'))->send();
                    }),
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

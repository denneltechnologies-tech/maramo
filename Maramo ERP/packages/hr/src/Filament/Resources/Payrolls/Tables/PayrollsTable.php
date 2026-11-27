<?php

namespace Classivo\Hr\Filament\Resources\Payrolls\Tables;

use Classivo\Hr\Models\Payroll;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Support\Currency;
use Classivo\Hr\Support\HrLabels;
use Classivo\Hr\Support\HrPermissions;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payroll list (spec §15): Staff, Period, Basic, Allowances, Deductions, Gross,
 * Net salary, Status. Per-row View payslip / Lock / Pay (gated `payroll.pay`)
 * actions.
 *
 * ── THE TWO NEW COLUMNS ──────────────────────────────────────────────────────
 * "LOP" surfaces the loss-of-pay days the engine derived from attendance. It is
 * shown even while loss of pay is switched OFF — the days are still calculated
 * and recorded — so a school can watch what it would come to for a month or two
 * before any of it reaches a payslip.
 *
 * "Locked" is the state that makes a payslip stop moving. A locked row is
 * refused by generation exactly as a paid one is, which is what stops a payslip
 * an employee has already downloaded being restated behind them.
 */
class PayrollsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['staff.designation']))
            ->columns([
                TextColumn::make('staff.employee_id')
                    ->label(__('hr::ui.staff_id'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('staff.full_name')
                    ->label(__('hr::ui.name'))
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('period_label')
                    ->label(__('hr::ui.period'))
                    ->state(fn (Payroll $record): string => $record->period_label),
                TextColumn::make('basic_salary')->label(__('hr::ui.basic'))->money(Currency::code())->sortable(),
                TextColumn::make('allowance_total')->label(__('hr::ui.allowances'))->money(Currency::code()),
                TextColumn::make('deduction_total')->label(__('hr::ui.deductions'))->money(Currency::code()),
                TextColumn::make('lop_days')
                    ->label(__('hr::ui.lop'))
                    ->badge()
                    ->color(fn (?string $state): string => (float) $state > 0 ? 'danger' : 'gray')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => (float) $state > 0
                        ? rtrim(rtrim(number_format((float) $state, 1), '0'), '.').' d'
                        : null)
                    ->tooltip(fn (Payroll $record): ?string => $record->payable_days !== null
                        ? 'Payable '.rtrim(rtrim(number_format((float) $record->payable_days, 1), '0'), '.')
                            .' of '.$record->month_days.' days'
                        : null)
                    ->toggleable(),
                TextColumn::make('gross')->money(Currency::code())->toggleable(),
                TextColumn::make('tax')->money(Currency::code())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('employer_contribution_total')
                    ->label(__('hr::ui.employer_cost'))
                    ->money(Currency::code())
                    ->tooltip(__('hr::ui.the_school_s_pf_esi'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('net_salary')
                    ->label(__('hr::ui.net_salary'))
                    ->money(Currency::code())
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => HrLabels::enum(Payroll::STATUSES, 'payroll.statuses')[$state] ?? ucfirst((string) $state))
                    ->color(fn (?string $state): string => Payroll::STATUS_COLORS[$state] ?? 'gray'),
                IconColumn::make('locked_at')
                    ->label(__('hr::ui.locked'))
                    ->boolean()
                    ->tooltip(fn (Payroll $record): ?string => $record->isLocked()
                        ? 'Frozen '.$record->locked_at?->format('d M Y H:i').' — regeneration will not restate it.'
                        : __('hr::messages.payroll.restate')),
            ])
            ->filters([
                SelectFilter::make('status')->options(HrLabels::enum(Payroll::STATUSES, 'payroll.statuses')),
                SelectFilter::make('month')
                    ->options(__('hr::enums.months')),
                SelectFilter::make('year')
                    ->options(fn (): array => Payroll::query()->distinct()->orderByDesc('year')->pluck('year', 'year')->all()),
                Filter::make('has_override')
                    ->label(__('hr::ui.has_a_hand_corrected_line'))
                    ->query(fn (Builder $query): Builder => $query->whereHas('details', fn (Builder $d): Builder => $d->where('is_override', true))),
                Filter::make('has_lop')
                    ->label(__('hr::ui.has_loss_of_pay'))
                    ->query(fn (Builder $query): Builder => $query->where('lop_days', '>', 0)),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('payslip')
                    ->authorize(fn (Payroll $record): bool => HrPermissions::can(HrPermissions::PAYSLIP_DOWNLOAD))
                    ->label(__('hr::ui.payslip'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->color('gray')
                    ->url(fn (Payroll $record): string => route('payroll.payslip', $record))
                    ->openUrlInNewTab(),
                // Locking is deliberately available BEFORE payment. A payslip is
                // issued before the money moves, and once the employee has seen
                // the figure it has to stop changing — whatever anybody later
                // edits on the staff master.
                Action::make('lock')
                    ->label(__('hr::ui.lock'))
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('warning')
                    ->visible(fn (Payroll $record): bool => ! $record->isLocked()
                        && HrPermissions::can(HrPermissions::PAYROLL_GENERATE))
                    ->requiresConfirmation()
                    ->modalHeading(__('hr::ui.lock_this_payslip'))
                    ->modalDescription(__('hr::ui.regeneration_will_leave_it_exactly'))
                    ->action(function (Payroll $record): void {
                        $record->lock(auth()->id());

                        Notification::make()->success()->title(__('hr::ui.payslip_locked'))->send();
                    }),
                Action::make('unlock')
                    ->label(__('hr::ui.unlock'))
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('gray')
                    ->visible(fn (Payroll $record): bool => $record->isLocked()
                        && ! $record->isPaid()
                        && HrPermissions::can(HrPermissions::PAYROLL_GENERATE))
                    ->requiresConfirmation()
                    ->modalHeading(__('hr::ui.unlock_this_payslip'))
                    ->modalDescription(__('hr::ui.the_next_generation_run_will'))
                    ->action(function (Payroll $record): void {
                        $record->unlock();

                        Notification::make()->success()->title(__('hr::ui.payslip_unlocked'))->send();
                    }),
                Action::make('pay')
                    ->label(__('hr::ui.pay'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Payroll $record): bool => $record->status === Payroll::STATUS_GENERATED
                        && HrPermissions::can(HrPermissions::PAYROLL_PAY))
                    ->requiresConfirmation()
                    ->modalDescription(__('hr::ui.mark_this_payroll_as_paid'))
                    ->action(function (Payroll $record): void {
                        app(PayrollService::class)->markPaid($record, auth()->id());

                        Notification::make()->success()->title(__('hr::ui.payroll_marked_as_paid'))->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

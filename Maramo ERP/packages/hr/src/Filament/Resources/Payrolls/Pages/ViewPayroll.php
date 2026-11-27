<?php

namespace Classivo\Hr\Filament\Resources\Payrolls\Pages;

use Classivo\Hr\Filament\Resources\Payrolls\PayrollResource;
use Classivo\Hr\Models\Payroll;
use Classivo\Hr\Models\PayrollDetail;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Support\Currency;
use Classivo\Hr\Support\HrLabels;
use Classivo\Hr\Support\HrPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Payslip view (spec §15 "View Payslip") — the salary breakdown for one payroll with
 * a Print/Download PDF link, the per-line override, the lock, and a Pay action
 * (gated `payroll.pay`).
 *
 * ── THE OVERRIDE LIVES HERE BECAUSE THERE WAS NOWHERE ELSE ───────────────────
 * There was no edit path to a payroll line at all. Correcting one amount for one
 * person meant editing the staff master and regenerating — which rebuilt every
 * line for everybody and applied the correction to every future month too. The
 * two actions below are the missing path: set one line, record why, and have the
 * next generation run build the computed lines around it instead of over it.
 */
class ViewPayroll extends ViewRecord
{
    protected static string $resource = PayrollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('payslip')
                ->authorize(fn (): bool => HrPermissions::can(HrPermissions::PAYSLIP_DOWNLOAD))
                ->label(__('hr::ui.download_payslip'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->url(fn (Payroll $record): string => route('payroll.payslip', ['payroll' => $record, 'download' => 1]))
                ->openUrlInNewTab(),

            Action::make('override')
                ->label(__('hr::ui.override_a_line'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('warning')
                ->visible(fn (Payroll $record): bool => $record->isRegenerable()
                    && HrPermissions::can(HrPermissions::PAYROLL_GENERATE))
                ->modalHeading(__('hr::ui.override_a_payslip_line'))
                ->modalDescription(__('hr::ui.the_amount_you_set_here'))
                ->schema(fn (Payroll $record): array => [
                    Select::make('detail_id')
                        ->label(__('hr::ui.line'))
                        ->options($record->details()
                            ->get()
                            ->mapWithKeys(fn (PayrollDetail $d): array => [
                                $d->id => $d->name.' — '.Currency::symbol().number_format((float) $d->amount, 2)
                                    .' ('.__('hr::enums.payroll_detail.types.'.$d->type).')',
                            ])
                            ->all())
                        ->native(false)
                        ->required(),
                    TextInput::make('amount')
                        ->label(__('hr::ui.amount'))
                        ->numeric()
                        ->minValue(0)
                        ->prefix(Currency::symbol())
                        ->required(),
                    Textarea::make('reason')
                        ->label(__('hr::ui.reason'))
                        ->placeholder(__('hr::ui.e_g_arrears_agreed_with'))
                        ->helperText(__('hr::ui.recorded_against_the_line_an'))
                        ->rows(2)
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (array $data, Payroll $record): void {
                    $detail = $record->details()->find($data['detail_id']);

                    if ($detail === null) {
                        Notification::make()->danger()->title(__('hr::ui.that_line_no_longer_exists'))->send();

                        return;
                    }

                    app(PayrollService::class)->overrideLine(
                        $detail,
                        (float) $data['amount'],
                        (string) $data['reason'],
                        auth()->id(),
                    );

                    Notification::make()->success()->title(__('hr::ui.line_overridden_totals_recalculated'))->send();
                }),

            Action::make('clearOverride')
                ->label(__('hr::ui.clear_an_override'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->visible(fn (Payroll $record): bool => $record->isRegenerable()
                    && $record->overriddenDetails()->exists()
                    && HrPermissions::can(HrPermissions::PAYROLL_GENERATE))
                ->modalDescription(__('hr::ui.the_line_goes_back_to'))
                ->schema(fn (Payroll $record): array => [
                    Select::make('detail_id')
                        ->label(__('hr::ui.overridden_line'))
                        ->options($record->overriddenDetails()
                            ->get()
                            ->mapWithKeys(fn (PayrollDetail $d): array => [
                                $d->id => $d->name.' — '.Currency::symbol().number_format((float) $d->amount, 2)
                                    .__('hr::messages.payroll.engine_says', ['amount' => Currency::symbol().number_format((float) ($d->computed_amount ?? 0), 2)]),
                            ])
                            ->all())
                        ->native(false)
                        ->required(),
                ])
                ->action(function (array $data, Payroll $record): void {
                    $detail = $record->details()->find($data['detail_id']);

                    if ($detail !== null) {
                        app(PayrollService::class)->clearOverride($detail);
                    }

                    Notification::make()->success()->title(__('hr::ui.override_cleared_totals_recalculated'))->send();
                }),

            Action::make('lock')
                ->label(__('hr::ui.lock_payslip'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('warning')
                ->visible(fn (Payroll $record): bool => ! $record->isLocked()
                    && HrPermissions::can(HrPermissions::PAYROLL_GENERATE))
                ->requiresConfirmation()
                ->modalDescription(__('hr::ui.regeneration_will_leave_this_payslip'))
                ->action(function (Payroll $record): void {
                    $record->lock(auth()->id());

                    Notification::make()->success()->title(__('hr::ui.payslip_locked'))->send();
                }),

            Action::make('pay')
                ->label(__('hr::ui.mark_paid'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (Payroll $record): bool => $record->status === Payroll::STATUS_GENERATED
                    && HrPermissions::can(HrPermissions::PAYROLL_PAY))
                ->requiresConfirmation()
                ->modalDescription(__('hr::ui.this_records_the_payment_locks'))
                ->action(function (Payroll $record): void {
                    app(PayrollService::class)->markPaid($record, auth()->id());

                    Notification::make()->success()->title(__('hr::ui.payroll_marked_as_paid'))->send();
                }),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('hr::ui.payslip'))
                ->columns(3)
                ->schema([
                    TextEntry::make('staff.full_name')->label(__('hr::ui.staff')),
                    TextEntry::make('staff.employee_id')->label(__('hr::ui.staff_id')),
                    TextEntry::make('period_label')->label(__('hr::ui.period'))->state(fn (Payroll $r): string => $r->period_label),
                    TextEntry::make('basic_salary')->label(__('hr::ui.basic_salary'))->money(Currency::code()),
                    TextEntry::make('allowance_total')->label(__('hr::ui.allowances'))->money(Currency::code()),
                    TextEntry::make('deduction_total')->label(__('hr::ui.deductions'))->money(Currency::code()),
                    TextEntry::make('gross')->label(__('hr::ui.gross'))->money(Currency::code()),
                    TextEntry::make('tax')->money(Currency::code()),
                    TextEntry::make('net_salary')->label(__('hr::ui.net_salary'))->money(Currency::code())->weight('bold'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => HrLabels::enum(Payroll::STATUSES, 'payroll.statuses')[$state] ?? ucfirst((string) $state))
                        ->color(fn (?string $state): string => Payroll::STATUS_COLORS[$state] ?? 'gray'),
                    TextEntry::make('payment_date')->date()->placeholder('—'),
                    TextEntry::make('paidBy.name')->label(__('hr::ui.paid_by'))->placeholder('—'),
                ]),

            // Why the figure is what it is. Without this the employee who queries
            // a short payslip has to be answered from an attendance register that
            // may since have been edited.
            Section::make(__('hr::ui.attendance_basis'))
                ->columns(4)
                ->visible(fn (Payroll $record): bool => $record->month_days !== null)
                ->schema([
                    TextEntry::make('month_days')->label(__('hr::ui.days_in_month')),
                    TextEntry::make('working_days')
                        ->label(__('hr::ui.working_days'))
                        ->formatStateUsing(fn (?string $state): string => rtrim(rtrim(number_format((float) $state, 1), '0'), '.')),
                    TextEntry::make('payable_days')
                        ->label(__('hr::ui.payable_days'))
                        ->formatStateUsing(fn (?string $state): string => rtrim(rtrim(number_format((float) $state, 1), '0'), '.')),
                    TextEntry::make('lop_days')
                        ->label(__('hr::ui.loss_of_pay_days'))
                        ->badge()
                        ->color(fn (?string $state): string => (float) $state > 0 ? 'danger' : 'gray')
                        ->formatStateUsing(fn (?string $state): string => rtrim(rtrim(number_format((float) $state, 1), '0'), '.')),
                    TextEntry::make('statutoryRuleSet.name')
                        ->label(__('hr::ui.statutory_rules_applied'))
                        ->placeholder(__('hr::ui.none_no_rule_set_covered'))
                        ->columnSpan(2),
                    TextEntry::make('locked_at')
                        ->label(__('hr::ui.locked'))
                        ->dateTime('d M Y H:i')
                        ->placeholder(__('hr::ui.not_locked_regeneration_will_restate'))
                        ->columnSpan(2),
                ]),

            Section::make(__('hr::ui.lines'))
                ->schema([
                    RepeatableEntry::make('details')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('type')->badge(),
                            TextEntry::make('name'),
                            TextEntry::make('amount')->money(Currency::code()),
                            // The override, shown next to the line it changed —
                            // with the engine's own figure, so the size of the
                            // correction never has to be taken on trust.
                            TextEntry::make('override_reason')
                                ->label(__('hr::ui.override'))
                                ->placeholder('—')
                                ->badge()
                                // An ORPHANED override — one whose engine
                                // counterpart has gone, so nothing recomputes it —
                                // is flagged red rather than amber. It is a live
                                // amount on the payslip that no calculation
                                // produces any more, and it needs a decision
                                // rather than just a note.
                                ->color(fn (PayrollDetail $record): string => $record->computed_amount === null ? 'danger' : 'warning')
                                ->visible(fn (PayrollDetail $record): bool => (bool) $record->is_override)
                                ->formatStateUsing(fn (?string $state, PayrollDetail $record): string => $record->computed_amount === null
                                    ? __('hr::messages.payroll.no_longer_computed', ['state' => (string) $state])
                                    : trim(__('hr::messages.payroll.was_amount', ['amount' => Currency::symbol().number_format((float) $record->computed_amount, 2), 'state' => (string) $state]))),
                        ]),
                ]),

            Section::make(__('hr::ui.employer_contributions'))
                ->description(__('hr::ui.the_school_s_own_pf'))
                ->visible(fn (Payroll $record): bool => (float) $record->employer_contribution_total > 0)
                ->schema([
                    TextEntry::make('employer_contribution_total')
                        ->label(__('hr::ui.total_employer_cost'))
                        ->money(Currency::code())
                        ->weight('bold'),
                    RepeatableEntry::make('employerContributions')
                        ->hiddenLabel()
                        ->columns(2)
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('amount')->money(Currency::code()),
                        ]),
                ]),
        ]);
    }
}

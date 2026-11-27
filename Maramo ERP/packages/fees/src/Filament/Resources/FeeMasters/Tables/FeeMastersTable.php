<?php

namespace Classivo\Fees\Filament\Resources\FeeMasters\Tables;

use Classivo\Fees\Filament\Resources\FeeMasters\Actions\FeeScheduleActions;
use Classivo\Fees\Filament\Resources\FeeMasters\Actions\GuardedFeeMasterDeleteActions;
use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Support\Money;
use Classivo\Fees\Support\StudentCategories;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fees Masters list: Fees group, Fees type, Amount, Due date, the
 * billing cycle, who the price applies to, its effective window, Fine, Status.
 *
 * THE LIST NOW HOLDS TWO KINDS OF ROW — a billable fee line, and a recurring
 * DEFINITION that generates them (see the FeeMaster class docblock). The "Billing"
 * column is what tells them apart at a glance, because the difference matters:
 * editing a definition re-prices every instalment that has not gone out yet, while
 * editing an instalment changes one month for whoever has not been billed it.
 */
class FeeMastersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('feeGroup.name')->label(__('Fees group'))->searchable()->sortable(),
                TextColumn::make('feeType.name')->label(__('Fees type'))->badge()->color('gray'),
                TextColumn::make('amount')->money(fn (FeeMaster $record) => $record->branch?->currency ?? 'INR')->sortable(),
                TextColumn::make('due_date')->date()->placeholder('—')->sortable(),

                // Definition, instalment, or a plain one-off — the one column that
                // says which of the two kinds of row this is.
                TextColumn::make('billing_frequency')
                    ->label(__('Billing'))
                    ->badge()
                    ->state(fn (FeeMaster $record): string => self::billingLabel($record))
                    ->color(fn (FeeMaster $record): string => match (true) {
                        $record->isScheduleParent() => 'info',
                        $record->parent_fee_master_id !== null => 'gray',
                        default => 'gray',
                    }),

                // The three applicability dimensions, in precedence order so the
                // column reads the way the resolver ranks them.
                TextColumn::make('studentCategory.name')
                    ->label(__('Category'))
                    ->badge()
                    ->color('warning')
                    ->placeholder(__('All'))
                    ->visible(fn (): bool => StudentCategories::available())
                    ->toggleable(),
                TextColumn::make('section.name')
                    ->label(__('Section'))
                    ->badge()
                    ->placeholder(__('All'))
                    ->toggleable(),
                TextColumn::make('schoolClass.name')->label(__('Class'))->badge()->placeholder(__('All classes')),

                // VISIBLE BY DEFAULT. Once a price can be revised, the list holds
                // the superseded row and the row that replaced it as two lines that
                // are identical in every other column — same group, same type, same
                // class, each with its own Revise button. Hiding the one column that
                // tells them apart is how an operator revises a dead price and is
                // told it worked.
                TextColumn::make('effective_from')
                    ->label(__('In force'))
                    ->state(fn (FeeMaster $record): string => self::effectiveLabel($record))
                    ->color(fn (FeeMaster $record): string => $record->isEffectiveOn() ? 'success' : 'gray')
                    ->description(fn (FeeMaster $record): ?string => $record->isEffectiveOn() ? null : 'not in force today')
                    ->toggleable(),

                TextColumn::make('fine_type')
                    ->label(__('Fine'))
                    ->badge()
                    ->state(fn (FeeMaster $record): string => self::fineLabel($record))
                    ->color(fn (FeeMaster $record): string => $record->fineRule()['type'] === 'none' ? 'gray' : 'warning'),
                ToggleColumn::make('status')->label(__('Active')),
            ])
            ->filters([
                SelectFilter::make('fee_group_id')
                    ->label(__('Fees group'))
                    ->relationship('feeGroup', 'name'),
                SelectFilter::make('fee_type_id')
                    ->label(__('Fees type'))
                    ->relationship('feeType', 'name'),
                SelectFilter::make('class_id')
                    ->label(__('Class'))
                    ->relationship('schoolClass', 'name'),
                SelectFilter::make('section_id')
                    ->label(__('Section'))
                    ->relationship('section', 'name'),
                SelectFilter::make('student_category_id')
                    ->label(__('Student category'))
                    ->relationship('studentCategory', 'name')
                    ->visible(fn (): bool => StudentCategories::available()),

                // A twelve-month schedule is thirteen rows. This is how an
                // operator reads the price list as prices (definitions and
                // one-offs) rather than as a calendar of instalments.
                SelectFilter::make('row_type')
                    ->label(__('Row type'))
                    ->options([
                        'definitions' => __('Prices (definitions + one-off)'),
                        'installments' => __('Generated instalments only'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'definitions' => $query->whereNull('parent_fee_master_id'),
                        'installments' => $query->whereNotNull('parent_fee_master_id'),
                        default => $query,
                    }),

                Filter::make('effective_today')
                    ->label(__('In force today'))
                    ->query(fn (Builder $query): Builder => $query->effectiveOn()),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                FeeScheduleActions::generateInstallments(),
                FeeScheduleActions::revise(),
                GuardedFeeMasterDeleteActions::record(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GuardedFeeMasterDeleteActions::bulk(),
                    // ForceDeleteBulkAction IS DELIBERATELY ABSENT — do not add
                    // it back. `fee_student_allocations.fee_master_id` is
                    // ON DELETE CASCADE, so permanently deleting a master makes
                    // MySQL hard delete every allocation ever made from it,
                    // paid ones included, in a statement Eloquent never sees:
                    // no model events, no activity log, nothing left to restore.
                    // `fee_payment_details.fee_student_allocation_id` is
                    // ON DELETE SET NULL, so the receipt lines outlive the purge
                    // pointing at nothing — collected money that can no longer
                    // be explained. Restore below covers the real need (undoing
                    // a mistaken archive); a genuine purge is a human runbook
                    // against the database, not a toolbar button two clicks from
                    // "select all". FeeMaster::booted() refuses the force delete
                    // as well, so this is defence in depth, not just a hidden
                    // button.

                    // PIN fetchSelectedRecords() HERE TOO. Left off, Filament
                    // restores the selection with one mass UPDATE that fires no
                    // model events, so FeeMaster::restoring() never runs: the
                    // masters come back WITHOUT the allocations retired with
                    // them, and the next Assign Fees writes duplicate bills
                    // (firstOrCreate cannot see a soft-deleted row). It is also
                    // what brings a definition's INSTALMENTS back with it.
                    RestoreBulkAction::make()->fetchSelectedRecords(),
                ]),
            ])
            ->defaultSort('id');
    }

    /** "Monthly ×12", "Instalment 3 of monthly", or "One time". */
    private static function billingLabel(FeeMaster $record): string
    {
        $cycle = FeeMaster::BILLING_FREQUENCIES[$record->billing_frequency] ?? __('One time');

        if ($record->isScheduleParent()) {
            return $cycle.' ×'.($record->installment_count ?? '?');
        }

        if ($record->parent_fee_master_id !== null) {
            return __('Instalment ').($record->installment_number ?? '?').' · '.$cycle;
        }

        return $cycle;
    }

    /** The effective window in one phrase. */
    private static function effectiveLabel(FeeMaster $record): string
    {
        $from = $record->effective_from?->format('d M Y');
        $to = $record->effective_to?->format('d M Y');

        return match (true) {
            $from === null && $to === null => __('Always'),
            $from === null => __('Until ').$to,
            $to === null => __('From ').$from,
            default => $from.' – '.$to,
        };
    }

    /**
     * The fine in one phrase, read through {@see FeeMaster::fineRule()} so a line
     * using a NAMED rule from `fee_fines` reports that rule rather than the empty
     * inline columns beside it.
     */
    private static function fineLabel(FeeMaster $record): string
    {
        $rule = $record->fineRule();

        if ($rule['type'] === 'none') {
            return __('None');
        }

        $value = $rule['type'] === 'percent'
            ? rtrim(rtrim(number_format($rule['amount'], 2), '0'), '.').'%'
            : Money::symbol().number_format($rule['amount'], 2);

        $rate = match ($rule['frequency']) {
            'daily' => '/day',
            'weekly' => '/week',
            'monthly' => '/month',
            default => '',
        };

        return $value.$rate.($rule['grace_days'] > 0 ? ' after '.$rule['grace_days'].'d' : '');
    }
}

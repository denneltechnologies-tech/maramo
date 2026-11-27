<?php

namespace Classivo\Hr\Filament\Resources\Staff\Actions;

use Classivo\Hr\Models\Staff;
use Classivo\Hr\Support\Currency;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Number;

/**
 * The PERMANENT-delete actions for Staff, with the "this person has been paid"
 * guard attached — built here rather than inline so the Edit screen and the bulk
 * toolbar cannot drift apart. Every force-delete path the panel offers must come
 * from this class.
 *
 * WHAT THIS STOPS. `payrolls.staff_id` and `advance_salaries.staff_id` are ON
 * DELETE CASCADE. Permanently deleting an employee therefore hard-deletes every
 * payroll they ever had — PAID ones included — and every payslip line under
 * them, in a single statement Eloquent never sees: no model events, no activity
 * log, no soft-deleted row to restore. Five payrolls, four of them paid and
 * disbursed, disappeared that way in one unconfirmed click, and nothing anywhere
 * recorded that they had existed.
 *
 * Those are financial records the school has to be able to produce. An employee
 * who has left is ARCHIVED — the ordinary Delete action already does exactly
 * that, keeps their payslips reachable, and can be undone.
 *
 * WHY A UI GUARD *AND* {@see Staff::booted()}. Same reasoning as any
 * guarded delete:
 * the model hook is the correctness backstop and also covers console commands,
 * seeders and the API, but it can only return false, which Filament reports as a
 * generic "failed to delete". This layer turns that refusal into a sentence
 * naming how many payrolls and how much money were about to go. Neither is
 * redundant — the model without this is silent, this without the model is
 * bypassable.
 */
final class GuardedStaffDeleteActions
{
    /** Permanent delete for the Edit page header. */
    public static function record(): ForceDeleteAction
    {
        return ForceDeleteAction::make()
            ->modalDescription(__('hr::messages.guard.record_modal'))
            ->before(function (Staff $record, ForceDeleteAction $action): void {
                $summary = $record->payrollHistorySummary();

                if ($summary['payrolls'] < 1 && $summary['advances'] < 1) {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->persistent()
                    ->title(__('hr::messages.guard.not_deleted_title', ['name' => static::label($record)]))
                    ->body(static::body($record, $summary))
                    ->send();

                $action->cancel();
            });
    }

    /**
     * Bulk permanent delete. Refuses the WHOLE selection when any one member of
     * it has salary history: a partial purge would leave the operator with a
     * count they cannot reconcile against what they selected, and the records
     * that did go are the ones they would not have checked.
     */
    public static function bulk(): ForceDeleteBulkAction
    {
        return ForceDeleteBulkAction::make()
            // PIN THIS. With fetchSelectedRecords() off, Filament purges the
            // selection with one query on the model, firing NO model events, so
            // Staff::forceDeleting never runs and the guard below is the only
            // thing standing between a careless "select all" and every payslip in
            // the school. The same trap is documented on BranchesTable.
            ->fetchSelectedRecords()
            ->modalDescription(__('hr::messages.guard.bulk_modal'))
            ->before(function (EloquentCollection $records, ForceDeleteBulkAction $action): void {
                $blocked = [];
                $payrolls = 0;
                $paid = 0;
                $money = 0.0;

                foreach ($records as $record) {
                    if (! $record instanceof Staff) {
                        continue;
                    }

                    $summary = $record->payrollHistorySummary();

                    if ($summary['payrolls'] < 1 && $summary['advances'] < 1) {
                        continue;
                    }

                    $blocked[] = static::label($record);
                    $payrolls += $summary['payrolls'];
                    $paid += $summary['paid'];
                    $money += $summary['paid_total'];
                }

                if ($blocked === []) {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->persistent()
                    ->title(__('hr::messages.guard.bulk_title', ['count' => count($blocked)]))
                    ->body(__('hr::messages.guard.bulk_body', [
                        'names' => static::names($blocked),
                        'payrolls' => trans_choice('hr::messages.guard.payrolls', $payrolls, ['count' => $payrolls]),
                        'paid' => __('hr::messages.guard.of_them', ['count' => $paid]),
                        'disbursed' => $money > 0 ? __('hr::messages.guard.and_disbursed', ['amount' => static::money($records->first(), $money)]) : '',
                    ]))
                    ->send();

                $action->cancel();
            });
    }

    /**
     * The sentence shown when one staff member is refused.
     *
     * @param  array{payrolls: int, paid: int, paid_total: float, advances: int}  $summary
     */
    private static function body(Staff $record, array $summary): string
    {
        $parts = [];

        if ($summary['payrolls'] > 0) {
            $parts[] = trans_choice('hr::messages.guard.payrolls', $summary['payrolls'], ['count' => $summary['payrolls']])
                .($summary['paid'] > 0
                    ? __('hr::messages.guard.paid_detail', ['paid' => $summary['paid'], 'amount' => self::money($record, $summary['paid_total'])])
                    : '');
        }

        if ($summary['advances'] > 0) {
            $parts[] = trans_choice('hr::messages.guard.advances', $summary['advances'], ['count' => $summary['advances']]);
        }

        return implode(__('hr::messages.guard.and'), $parts).__('hr::messages.guard.body_tail');
    }

    /** "3 payrolls" / "1 payroll". */
    private static function plural(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    /** Money in the branch's own currency, falling back to INR. */
    private static function money(?Staff $record, float $amount): string
    {
        return (string) Number::currency($amount, $record?->branch?->currency ?: Currency::code());
    }

    /** "Anita Sharma (EMP0001)" — enough for an operator to find the row again. */
    private static function label(Staff $record): string
    {
        $id = trim((string) $record->employee_id);

        return trim($record->full_name.($id === '' ? '' : ' ('.$id.')'));
    }

    /** At most three names, so one careless "select all" cannot fill the screen. */
    private static function names(array $labels): string
    {
        $shown = array_slice($labels, 0, 3);
        $rest = count($labels) - count($shown);

        return implode(', ', $shown).($rest > 0 ? __('hr::messages.guard.names_more', ['count' => $rest]) : '.');
    }
}

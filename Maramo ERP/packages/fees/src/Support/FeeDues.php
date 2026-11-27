<?php

namespace Classivo\Fees\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Students\Models\Student;
use Illuminate\Support\Collection;

/**
 * FeeDues — current-vs-upcoming fee due computation .
 *
 * Parents are only pressed for the CURRENT month's dues; later months are
 * optional advance payments. The split is by allocation due date:
 *
 *   current due    = unpaid balances with due_date <= end of the current month
 *                    (this month's installment + all overdue/arrears)
 *   upcoming total = unpaid balances due in months AFTER the current one
 *
 * The "pay by" day of month comes from {@see GeneralSettings::$fee_due_day}
 * (admin-configurable on the General Settings page, default 10) and renders as
 * a label like "by 10 Aug".
 */
class FeeDues
{
    /** Fallback due day when the setting is unavailable (e.g. pre-migration). */
    public const DEFAULT_DUE_DAY = 10;

    /** The school's fee due day of month (1–28), from GeneralSettings. */
    public static function dueDay(): int
    {
        $day = (int) config('fees.due_day', self::DEFAULT_DUE_DAY);

        return min(28, max(1, $day));
    }

    /** Human "pay by" label for the current month, e.g. "by 10 Aug". */
    public static function dueLabel(?CarbonInterface $asOf = null): string
    {
        $asOf ??= CarbonImmutable::now();

        return 'by '.self::dueDay().' '.$asOf->format('M');
    }

    /** True when the due date falls in a month AFTER the current one. */
    public static function isUpcoming(?CarbonInterface $dueDate, ?CarbonInterface $asOf = null): bool
    {
        if ($dueDate === null) {
            return false;
        }

        $asOf ??= CarbonImmutable::now();

        return $dueDate->copy()->startOfDay()->greaterThan($asOf->copy()->endOfMonth());
    }

    /**
     * Split a set of allocations into the currently-due and upcoming outstanding
     * totals (see class docblock for the definition).
     *
     * @param  Collection<int, FeeStudentAllocation>  $allocations
     * @return array{current_due: float, upcoming_total: float}
     */
    public static function split(Collection $allocations, ?CarbonInterface $asOf = null): array
    {
        $current = 0.0;
        $upcoming = 0.0;

        foreach ($allocations as $allocation) {
            if (self::isUpcoming($allocation->due_date, $asOf)) {
                $upcoming += $allocation->balance();
            } else {
                $current += $allocation->balance();
            }
        }

        return [
            'current_due' => round($current, 2),
            'upcoming_total' => round($upcoming, 2),
        ];
    }

    /**
     * The student's currently-due amount for the active session (this month +
     * arrears; excludes upcoming months). Uses the eager-loaded feeAllocations
     * relation when present — mirrors {@see Student::feesSummary()}.
     */
    public static function currentDue(Student $student, ?CarbonInterface $asOf = null): float
    {
        $allocations = $student->relationLoaded('feeAllocations')
            ? $student->getRelation('feeAllocations')
            : FeeStudentAllocation::query()->where('student_id', $student->getKey())->get();

        return self::split($allocations, $asOf)['current_due'];
    }

    /**
     * Totals across a student's allocations (current session when the request
     * context is resolved): total payable, paid, discount, fine and balance.
     *
     * @return array{total: float, paid: float, discount: float, fine: float, balance: float}
     */
    public static function summary(int $studentId): array
    {
        $total = $paid = $discount = $fine = 0.0;

        foreach (FeeStudentAllocation::query()->where('student_id', $studentId)->get() as $allocation) {
            $total += $allocation->payable();
            $paid += (float) $allocation->paid_amount;
            $discount += (float) $allocation->discount_amount;
            $fine += (float) $allocation->fine_amount;
        }

        return [
            'total' => round($total, 2),
            'paid' => round($paid, 2),
            'discount' => round($discount, 2),
            'fine' => round($fine, 2),
            'balance' => round(max(0, $total - $paid), 2),
        ];
    }
}

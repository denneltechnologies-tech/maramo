<?php

namespace Classivo\Hr\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Hr\Support\HrAuth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AdvanceSalary (spec §15 Payroll "Advance Salary" / §32 Finance) — a staff advance
 * request that, once approved, is auto-deducted from that month's payroll on
 * generation. Tenant + branch scoped, soft-deleting, money decimal(15,2).
 *
 * TWO NOTES, TWO OWNERS: `reason` is the APPLICANT's ("school fees for my daughter");
 * `remark` (2026_09_28_000003) is the APPROVER's ("approved — deduct over two
 * months"). They are never written by the same person and one must never overwrite
 * the other. Same split as `staff_leave_requests.reason` / `review_remark`.
 *
 * ── RECOVERY OVER MORE THAN ONE MONTH ────────────────────────────────────────
 * That approver's remark — "deduct over two months" — was, until the schedule
 * columns were added, something the system could not do. The whole amount came
 * out of the request's own month and there was no second month.
 *
 * A staff loan is this cycle with more instalments, so the cycle was extended
 * rather than a parallel `staff_loans` table being built beside it with its own
 * approval, its own inbox adapter and its own way of drifting out of step.
 * {@see installmentFor()} is the whole of it.
 *
 * `recovery_status` is deliberately NOT `status`: `status` is the APPROVAL state,
 * and an advance can be approved for months before it is fully recovered. One
 * column cannot carry both without one of them lying.
 */
class AdvanceSalary extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    protected $table = 'advance_salaries';

    public const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    public const STATUS_COLORS = [
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'staff_id',
        'amount',
        'reason',
        'remark',
        'month',
        'year',
        'status',
        'installments',
        'installment_amount',
        'recovery_start_month',
        'recovery_start_year',
        'recovered_amount',
        'recovery_status',
        'approved_by',
    ];

    /** Recovery progress. Distinct from `status`, which is the APPROVAL state. */
    /**
     * Recovery states in which nothing further is owed, so no instalment is due.
     * Read by {@see installmentFor()} and by the payroll engine's advance query.
     */
    public const SETTLED_RECOVERY_STATUSES = ['recovered', 'written_off'];

    public const RECOVERY_STATUSES = [
        'pending' => 'Not started',
        'recovering' => 'Recovering',
        'recovered' => 'Fully recovered',
        'written_off' => 'Written off',
    ];

    public const RECOVERY_STATUS_COLORS = [
        'pending' => 'gray',
        'recovering' => 'warning',
        'recovered' => 'success',
        'written_off' => 'danger',
    ];

    /**
     * Ceiling on the generated schedule.
     *
     * A fixed `installment_amount` implies an instalment COUNT, and a tiny
     * amount against a large advance implies a very large one. Twenty years of
     * monthly instalments is past the point where the figure is a typo rather
     * than a plan, and the cap keeps a mistyped ₹1 from building a schedule the
     * size of a table scan.
     */
    private const MAX_INSTALLMENTS = 240;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'month' => 'integer',
            'year' => 'integer',
            'installments' => 'integer',
            'installment_amount' => 'decimal:2',
            'recovery_start_month' => 'integer',
            'recovery_start_year' => 'integer',
            'recovered_amount' => 'decimal:2',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(HrAuth::userModel(), 'approved_by');
    }

    // ---------------------------------------------------------------------
    // Recovery schedule
    // ---------------------------------------------------------------------

    /**
     * The full recovery plan: one entry per month, in order.
     *
     * DERIVED, NEVER STORED, AND NEVER READ FROM A RUNNING TOTAL. That is what
     * makes payroll generation safely repeatable: regenerating a month asks the
     * schedule what that month owes and gets the same answer every time, where
     * decrementing a balance would recover the advance twice.
     *
     * Two ways to express a plan, because schools use both:
     *   • an instalment COUNT — "over four months" — splits the amount evenly;
     *   • a fixed instalment AMOUNT — "₹2,000 a month until it is clear" —
     *     implies the count.
     * Either way the LAST instalment absorbs the rounding, so the schedule sums
     * to the advance exactly and the employee is never short-changed by a paisa
     * or over-recovered by one.
     *
     * An advance created before the schedule existed has installments = 1 and
     * starts in its own month, which is exactly the single full deduction it has
     * always produced.
     *
     * @return array<int, array{month: int, year: int, amount: float}>
     */
    public function schedule(): array
    {
        $total = round((float) $this->amount, 2);

        if ($total <= 0) {
            return [];
        }

        $startMonth = (int) ($this->recovery_start_month ?: $this->month);
        $startYear = (int) ($this->recovery_start_year ?: $this->year);

        if ($startMonth < 1 || $startMonth > 12 || $startYear < 1) {
            return [];
        }

        $fixed = round((float) ($this->installment_amount ?? 0), 2);

        // `$clamped` records that the plan as typed does NOT fit inside the cap.
        //
        // It has to, because the "last instalment absorbs the remainder" rule
        // below is only correct for a plan that runs to completion. Applied to a
        // truncated one it dumped the ENTIRE unrecovered balance into the final
        // instalment — ₹5,00,000 at ₹1,000 a month became 239 payments of ₹1,000
        // and a single ₹2,61,000 deduction out of somebody's salary.
        //
        // A clamped plan instead recovers exactly what it says, month by month,
        // and simply leaves a balance outstanding at the end. That balance is
        // visible through {@see outstandingAfter()} rather than arriving as a
        // shock, and the school can extend the plan.
        $clamped = false;

        if ($fixed > 0) {
            $required = (int) ceil($total / $fixed);
            $count = min(self::MAX_INSTALLMENTS, $required);
            $clamped = $required > self::MAX_INSTALLMENTS;
            $per = $fixed;
        } else {
            $required = max(1, (int) $this->installments ?: 1);
            $count = min(self::MAX_INSTALLMENTS, $required);
            // An even split still divides the WHOLE advance across the instalments
            // it is allowed, so it is never short and never needs clamping here.
            $per = round($total / $count, 2);
        }

        $schedule = [];
        $recovered = 0.0;

        for ($i = 0; $i < $count; $i++) {
            // The final instalment is the remainder, so rounding cannot make the
            // schedule miss or overshoot the advance — but ONLY when the plan
            // actually runs to completion. See `$clamped` above.
            $amount = ($i === $count - 1 && ! $clamped) ? round($total - $recovered, 2) : $per;

            if ($amount <= 0) {
                break;
            }

            $offset = $startMonth - 1 + $i;

            $schedule[] = [
                'month' => (int) ($offset % 12) + 1,
                'year' => $startYear + intdiv($offset, 12),
                'amount' => $amount,
            ];

            $recovered = round($recovered + $amount, 2);
        }

        return $schedule;
    }

    /**
     * What this advance recovers in one payroll period; 0.0 when it is not due.
     *
     * A SETTLED advance recovers nothing, ever. `recovery_status` was declared,
     * cast and given labels but read nowhere, so writing an advance off changed a
     * badge and the deduction carried on coming out of the employee's pay every
     * month regardless. The test belongs here rather than in the caller's query
     * so that every route to an instalment honours it.
     *
     * BOTH terminal states stop it, not only `written_off`. "Fully recovered" is
     * the status an administrator sets when the employee has repaid the balance
     * some other way — in cash, or out of a final settlement — and it is the more
     * likely of the two to be used. Honouring only `written_off` meant that
     * marking an advance recovered changed a green badge while payroll went on
     * deducting the instalment every month, taking the money a second time. The
     * schedule is not the authority on whether anything is still owed; the
     * recovery status is.
     */
    public function installmentFor(int $month, int $year): float
    {
        if (in_array($this->recovery_status, self::SETTLED_RECOVERY_STATUSES, true)) {
            return 0.0;
        }

        foreach ($this->schedule() as $installment) {
            if ($installment['month'] === $month && $installment['year'] === $year) {
                return $installment['amount'];
            }
        }

        return 0.0;
    }

    /** The last period this advance is scheduled to recover in, or null. */
    public function recoveryEndsAt(): ?string
    {
        $schedule = $this->schedule();

        if ($schedule === []) {
            return null;
        }

        $last = end($schedule);

        return sprintf('%04d-%02d', $last['year'], $last['month']);
    }

    /**
     * Still owed, per the schedule, as at the END of a period.
     *
     * Computed from the plan rather than from `recovered_amount`, for the same
     * reason the instalment is: the plan is stable under regeneration and a
     * running total is not.
     */
    public function outstandingAfter(int $month, int $year): float
    {
        $recovered = 0.0;

        foreach ($this->schedule() as $installment) {
            if ($installment['year'] < $year || ($installment['year'] === $year && $installment['month'] <= $month)) {
                $recovered += $installment['amount'];
            }
        }

        return round(max(0.0, (float) $this->amount - $recovered), 2);
    }
}

<?php

namespace Classivo\Fees\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Classivo\Core\Tenancy\Scopes\BranchScope;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Core\Tenancy\Scopes\TenantScope;
use Classivo\Fees\Contracts\HolidayCalendar;
use Classivo\Fees\Events\FeeAccrued;
use Classivo\Fees\Models\FeeFine;
use Classivo\Fees\Models\FeeStudentAllocation;
use Illuminate\Database\Eloquent\Builder;

/**
 * FeeAccrualService — turns an overdue fee into a fine that actually exists
 *.
 *
 * *** WHAT WAS WRONG ***
 * ---------------------------------------------------------------------------
 * Nothing in this application ever CHARGED a fine. `fee_student_allocations`
 * .fine_amount was only ever written by FeeCollectionService, from a number the
 * Collect screen had pre-filled into a form field — so a fine existed if, and only
 * if, a human happened to open the Collect page for that student and leave the
 * suggestion in place. It was a suggestion at the till, not a debt.
 *
 * Everything downstream therefore under-reported. `balance()`, the defaulter
 * report, the head-wise outstanding report, the parent app's "amount due", the
 * payment gateway's order total and every fee statement read `fine_amount`, and
 * for a family that had simply not come in yet, that number was zero no matter how
 * late they were. The school's own dues ledger disagreed with its own fine policy.
 *
 * This service is the missing charge. The `fees:accrue-fines` command runs it
 * (schedule it nightly).
 *
 * *** WHAT MAKES IT SAFE TO RE-RUN ***
 * The sweep is idempotent by ARITHMETIC, not by a "has run today" flag, which is
 * what makes it safe after a crash, a missed night, a manual run, or three runs in
 * one hour:
 *
 *      target = the fine this debt's OWN rule says is due as of today
 *      delta  = target − fine_amount already charged
 *      charge delta, but only when it is positive
 *
 * Re-running the same day computes the same target and a delta of zero, and
 * writes nothing. A week of missed nights catches up in one pass. A fine an
 * operator charged by hand at the till is already inside `fine_amount`, so the
 * sweep tops it up to the rule and never double-charges it — and never claws back
 * a fine that was charged and is larger than the rule now says, because a fine
 * already collected is money the family has handed over.
 *
 * *** THE RULE IT USES IS THE ONE THE DEBT WAS ISSUED UNDER ***
 * {@see ruleFor()} reads the snapshot frozen onto the allocation by
 * {@see FeeAllocationService::allocate()}, and falls back to the master's live
 * rule only for allocations issued before snapshots existed. Editing a fee
 * master's fine rule therefore changes what FUTURE dues accrue, and nothing about
 * dues already issued — which is the whole point of a snapshot, and the bug that
 * fell out of not having one.
 *
 * *** PERCENTAGE FINES ARE CHARGED ON WHAT IS STILL OWED ***
 * {@see baseFor()} is the outstanding PRINCIPAL, not the master's amount. A family
 * that has paid four fifths of a ₹20,000 term fee is fined on the ₹4,000 they
 * still owe, not on the ₹20,000 they were billed. The old code passed the master's
 * full amount, so a part payment reduced the debt and not the penalty on it.
 *
 * Callers that suggest a fine at the till should use {@see unchargedFine()}
 * rather than `FeeMaster::computeFine()`: it answers "what is still to charge
 * on THIS debt", which also stops a counter screen adding a fine the nightly
 * sweep has already charged.
 */
class FeeAccrualService
{
    /**
     * Holiday dates per tenant scope, built once per process run.
     *
     * @var array<string, array<string, true>>
     */
    private array $holidayCache = [];

    /**
     * The fine rule this debt was ISSUED under, in the canonical shape.
     *
     * A null `fine_type` means the allocation predates the snapshot columns
     * (2026_10_09_000003 deliberately did not backfill them — writing today's
     * rule onto historical debts is the very thing being fixed). Those rows fall
     * back to the master's live rule, which is exactly what the application did
     * for them before, so nothing about them changes.
     *
     * @return array{type: string, amount: float, frequency: string, grace_days: int}
     */
    public function ruleFor(FeeStudentAllocation $allocation): array
    {
        if ($allocation->fine_type !== null) {
            return FeeFine::normalise(
                $allocation->fine_type,
                $allocation->fine_rule_frequency,
                (float) $allocation->fine_rule_amount,
                (int) $allocation->fine_grace_days,
            );
        }

        return $allocation->feeMaster?->fineRule()
            ?? FeeFine::normalise('none', null, 0.0, 0);
    }

    /**
     * The money a percentage fine is measured against: the outstanding PRINCIPAL.
     *
     * amount − discount − paid, floored at zero. `paid_amount` advances by the fee
     * AND any fine collected with it (see FeeCollectionService), so once it exceeds
     * the discounted fee the principal is settled and the base is zero — a family
     * that owes nothing but a fine accrues no further percentage on it.
     *
     * Deliberately NOT `balance()`, which already includes `fine_amount`: charging
     * a percentage against a figure that contains last night's percentage is
     * compound interest, and nobody wrote that policy down.
     */
    public function baseFor(FeeStudentAllocation $allocation): float
    {
        return round(max(0, (float) $allocation->amount
            - (float) $allocation->discount_amount
            - (float) $allocation->paid_amount), 2);
    }

    /**
     * The total fine this debt's rule says is due as of `$asOf` (default: today).
     * This is a CUMULATIVE figure, not an increment.
     */
    public function fineDueOn(FeeStudentAllocation $allocation, ?CarbonInterface $asOf = null): float
    {
        if ($allocation->fine_waived || $allocation->due_date === null) {
            return 0.0;
        }

        $asOf ??= now();
        $daysOverdue = $allocation->daysOverdue($asOf);

        if ($daysOverdue <= 0) {
            return 0.0;
        }

        return FeeFine::evaluate(
            $this->ruleFor($allocation),
            $this->baseFor($allocation),
            $daysOverdue,
            $this->holidayDaysFor($allocation, $asOf),
        );
    }

    /**
     * The part of the due fine that has NOT been charged to this allocation yet —
     * what a collection screen should suggest, and what the sweep is about to add.
     * Zero once the sweep has run, which is what keeps the two from double-charging
     * the same family.
     */
    public function unchargedFine(FeeStudentAllocation $allocation, ?CarbonInterface $asOf = null): float
    {
        return round(max(0, $this->fineDueOn($allocation, $asOf) - (float) $allocation->fine_amount), 2);
    }

    /**
     * Charge the outstanding fine onto one allocation. Returns the money added
     * (0 when there was nothing to add). `$dryRun` computes without writing.
     */
    public function accrue(FeeStudentAllocation $allocation, ?CarbonInterface $asOf = null, bool $dryRun = false): float
    {
        // A settled debt is closed. Raising a fine on it would reopen a paid line
        // months later, which is not a fine — it is a billing error with a
        // receipt already printed against it. A fine on a late payment is charged
        // at the till, by the collection path, at the moment it is taken.
        if ($allocation->fine_waived || $allocation->balance() <= 0) {
            return 0.0;
        }

        $delta = $this->unchargedFine($allocation, $asOf);

        if ($delta <= 0) {
            return 0.0;
        }

        if ($dryRun) {
            return $delta;
        }

        $allocation->fine_amount = round((float) $allocation->fine_amount + $delta, 2);
        // Written as a string, not a Carbon: `fine_accrued_at` is deliberately not
        // in the model's casts, and
        // an uncast attribute must be handed to the driver in a shape it accepts.
        $allocation->fine_accrued_at = ($asOf ?? now())->copy()->toDateTimeString();

        // save(), not saveQuietly(): this moves money on a student's account and
        // the activity log is where a bursar looks when a parent disputes a
        // figure. logOnlyDirty keeps the entry to the fields that changed, and the
        // sweep only reaches here when the figure actually moved, so a settled
        // night writes nothing at all.
        $allocation->syncStatus()->save();

        event(new FeeAccrued(
            allocationId: (int) $allocation->id,
            studentId: (int) $allocation->student_id,
            fineAmount: (float) $allocation->fine_amount,
            delta: $delta,
            date: ($asOf ?? now())->toDateString(),
            schoolId: (int) $allocation->school_id,
            branchId: $allocation->branch_id !== null ? (int) $allocation->branch_id : null,
            sessionId: $allocation->session_id !== null ? (int) $allocation->session_id : null,
        ));

        return $delta;
    }

    /**
     * Every allocation the sweep should look at: live, overdue as of `$asOf`, not
     * settled and not waived.
     *
     * UNSCOPED ON PURPOSE. This runs from the scheduler, where there is no current
     * school, branch or session — and it must cross all three, because every
     * school's overdue dues are due. Tenancy is re-applied per row where it
     * matters: {@see holidayDaysFor()} reads each allocation's OWN school, branch
     * and session calendar rather than the ambient one.
     *
     * `status <> 'paid'` and `due_date < $asOf` are the leading columns of
     * `fee_alloc_status_due_idx` (2026_10_07_000001), so this stays an index range
     * scan rather than a table sweep as the school grows.
     */
    public function dueAllocations(?CarbonInterface $asOf = null, ?int $schoolId = null): Builder
    {
        $asOf ??= now();

        return FeeStudentAllocation::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class, SessionScope::class])
            ->where('status', '!=', 'paid')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $asOf->copy()->startOfDay()->toDateString())
            ->where('fine_waived', false)
            ->when($schoolId !== null, fn (Builder $q) => $q->where('school_id', $schoolId));
    }

    /**
     * Days inside this debt's overdue window on which the school was shut.
     *
     * Holidays come from the bound {@see HolidayCalendar} (none by default): one call
     * per school/branch/session per process run, cached below, and then set
     * lookups per allocation.
     *
     * Only a DAILY fine is affected — {@see FeeFine::evaluate()} explains why a
     * weekly or monthly rate is measured in calendar time — so nothing is queried
     * for the rules that would not use the answer.
     */
    public function holidayDaysFor(FeeStudentAllocation $allocation, ?CarbonInterface $asOf = null): int
    {
        if (($this->ruleFor($allocation)['frequency'] ?? 'one_time') !== 'daily') {
            return 0;
        }

        if ($allocation->due_date === null) {
            return 0;
        }

        $asOf = ($asOf ?? now())->copy()->startOfDay();
        // The window is the days the fine could accrue over: the day AFTER the due
        // date up to and including today.
        $from = $allocation->due_date->copy()->startOfDay()->addDay();

        if ($from->gt($asOf)) {
            return 0;
        }

        $holidays = $this->holidays(
            (int) $allocation->school_id,
            $allocation->branch_id === null ? null : (int) $allocation->branch_id,
            $allocation->session_id === null ? null : (int) $allocation->session_id,
        );

        if ($holidays === []) {
            return 0;
        }

        $days = 0;

        for ($day = $from->copy(); ! $day->gt($asOf); $day->addDay()) {
            if (isset($holidays[$day->toDateString()])) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * Every holiday DATE for one tenant scope, as a set keyed by Y-m-d.
     *
     * Multi-day holidays are expanded here rather than range-matched per
     * allocation, because the same handful of ranges is asked about once per
     * overdue allocation and a set lookup is free. Ranges are clamped to a year so
     * a mis-entered end date ("holiday until 2099") cannot spin the loop.
     *
     * @return array<string, true>
     */
    private function holidays(int $schoolId, ?int $branchId, ?int $sessionId): array
    {
        $key = $schoolId.'|'.($branchId ?? '-').'|'.($sessionId ?? '-');

        if (isset($this->holidayCache[$key])) {
            return $this->holidayCache[$key];
        }

        $events = app(HolidayCalendar::class)->holidayRanges($schoolId, $branchId, $sessionId);

        $dates = [];

        foreach ($events as $event) {
            $start = Carbon::parse($event['start'])->startOfDay();
            $end = empty($event['end']) ? $start->copy() : Carbon::parse($event['end'])->startOfDay();

            if ($end->lt($start)) {
                $end = $start->copy();
            }

            $limit = $start->copy()->addYear();

            if ($end->gt($limit)) {
                $end = $limit;
            }

            for ($day = $start->copy(); ! $day->gt($end); $day->addDay()) {
                $dates[$day->toDateString()] = true;
            }
        }

        return $this->holidayCache[$key] = $dates;
    }
}

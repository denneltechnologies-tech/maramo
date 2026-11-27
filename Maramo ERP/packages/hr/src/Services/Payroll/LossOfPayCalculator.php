<?php

namespace Classivo\Hr\Services\Payroll;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffAttendance;
use Classivo\Hr\Models\StaffLeaveAllocation;
use Classivo\Hr\Models\StaffLeaveRequest;
use Classivo\Hr\Services\BranchCalendar;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Services\StaffAttendanceService;

/**
 * LossOfPayCalculator — how many days of this month the employee is actually to
 * be paid for.
 *
 * ── WHAT WAS WRONG ───────────────────────────────────────────────────────────
 * Payroll paid the identical amount whether a teacher attended twenty-two days
 * or none. `staff_attendances` has been populated the whole time, staff leave
 * has been approved through a real workflow the whole time, and payroll simply
 * never read either. This class is the join that was missing; it adds no new
 * source of truth and asks for no new data entry.
 *
 * ── WHAT IT READS, AND WHOSE RULES IT REUSES ─────────────────────────────────
 *   • `staff_attendances` — the daily mark, written by the Take Staff Attendance
 *     page and by leave approval.
 *   • the branch's configured weekend, via
 *     {@see BranchCalendar::weekendDaysFor()} — the SAME call
 *     {@see StaffAttendanceService::markApprovedLeave()} uses, so payroll and the
 *     attendance register cannot disagree about which days are working days.
 *   • `staff_leave_types.is_paid` — the paid/unpaid flag that has existed since
 *     the leave module shipped and had, until now, no consequence anywhere.
 *   • `staff_leave_allocations` — so paid leave taken BEYOND the employee's
 *     allocation stops being free.
 *
 * ── THE DAY RULES ────────────────────────────────────────────────────────────
 *   weekend           not a working day; no pay effect either way.
 *   holiday           the school was shut. Paid.
 *   present / late    paid in full. Late is an attendance matter, not a pay one.
 *   half_day          half a day of loss of pay.
 *   absent            a full day of loss of pay.
 *   leave             paid or not according to the leave type behind it, and
 *                     according to whether the allocation still had room.
 *   UNMARKED          PAID. This is the most consequential rule in the file and
 *                     it is deliberate: an unmarked day usually means nobody took
 *                     the register, not that the teacher stayed home. Docking pay
 *                     for a clerk's omission is the one failure mode here that
 *                     actually costs somebody money they earned. A school that
 *                     takes attendance reliably can flip
 *                     `payroll.unmarked_days_paid` to 0 in its rule set.
 *
 * ── DAYS BEFORE THE EMPLOYEE JOINED ──────────────────────────────────────────
 * They are never ABSENCE — nobody is absent from a job they had not started —
 * and are excluded from the working-day count on that basis.
 *
 * Whether they are also unPAID is a separate question, and one payroll has
 * always answered wrongly: somebody joining on the 20th drew a full month's
 * salary, because nothing consulted the joining date at all. `prorate_joiners`
 * fixes that, and ships at 0 so that turning loss of pay on does not silently
 * change a second thing at the same time. With it on, the days before joining
 * are counted into the proration under their own breakdown key — which is what
 * lets {@see PayrollService} label the resulting line "Pro-rata
 * (part month)" rather than telling a new starter they lost pay for a fortnight
 * they were not yet employed for.
 *
 * There is no equivalent for a LEAVER, deliberately: `staff` has a joining date
 * but no relieving date, so the last day worked is not recorded anywhere. Payroll
 * simply stops when the record goes inactive. Prorating a final month needs that
 * column first, and guessing it from a status change would be a date invented
 * inside a money calculation.
 *
 * ── AND IT STILL DOES NOT DOCK ANYBODY BY DEFAULT ────────────────────────────
 * `payroll.lop_enabled` ships at 0. The calculation runs regardless and its
 * result is recorded on the payroll, so a school can SEE what loss of pay would
 * come to for a month or two before any of it reaches a payslip. Turning a
 * live payroll's pay down for 38 people is not something a deploy should do on
 * its own.
 */
class LossOfPayCalculator
{
    /** Divide the month's pay by calendar days, or by working days. */
    public const BASIS_CALENDAR = 'calendar_days';

    public const BASIS_WORKING = 'working_days';

    /** Attendance marks that cost the employee pay, and how much of a day each costs. */
    public const LOSS_PER_DAY = [
        'absent' => 1.0,
        'half_day' => 0.5,
    ];

    public function __construct(
        private readonly BranchCalendar $attendance,
    ) {}

    /**
     * Payable and lost days for one employee for one month.
     *
     * @param  array<string, string>  $params  the `payroll` params of the governing rule set
     * @return array{
     *     month_days: int, working_days: float, payable_days: float, lop_days: float,
     *     basis: string, basis_days: float, enabled: bool,
     *     breakdown: array<string, float>, notes: array<int, string>
     * }
     */
    public function calculate(Staff $staff, int $month, int $year, array $params = []): array
    {
        $start = CarbonImmutable::create($year, $month, 1);
        $end = $start->endOfMonth();
        $monthDays = (int) $start->daysInMonth;

        $basis = $params['lop_basis'] ?? self::BASIS_CALENDAR;
        $unmarkedPaid = StatutoryCalculator::truthy($params['unmarked_days_paid'] ?? '1');
        $enabled = StatutoryCalculator::truthy($params['lop_enabled'] ?? '0');
        $prorateJoiners = StatutoryCalculator::truthy($params['prorate_joiners'] ?? '0');

        $weekend = $this->attendance->weekendDaysFor($staff->branch_id);

        // Days the employee was actually employed for. Someone who joined on the
        // 20th is not absent for the first nineteen days of the month.
        $joined = $staff->joining_date ? CarbonImmutable::parse($staff->joining_date) : null;
        $employedFrom = $joined !== null && $joined->greaterThan($start) ? $joined : $start;

        $notes = [];

        // ── NOT EMPLOYED AT ALL DURING THE PERIOD ────────────────────────────
        // Returning zero loss here meant the engine saw "no loss of pay" and paid
        // a FULL MONTH to somebody who had not started yet. That is not a
        // proration question and it is not gated on `prorate_joiners`: that flag
        // governs how a PARTIAL month is treated, and no school can be relying on
        // paying people for months before they join. The whole period is unpaid.
        if ($joined !== null && $joined->greaterThan($end)) {
            $wholePeriod = $basis === self::BASIS_WORKING
                ? $this->schoolWorkingDays($staff, $start, $end, $weekend)
                : (float) $monthDays;

            return $this->result(
                $monthDays, 0.0, 0.0, $wholePeriod, $basis, $enabled,
                ['absent' => 0.0, 'half_day' => 0.0, 'unpaid_leave' => 0.0, 'unallocated_leave' => 0.0, 'not_employed' => $wholePeriod],
                ['Employee joins '.$joined->format('j M Y').' — after this period ends. Nothing is payable for it.'],
                $wholePeriod,
                notEmployedAtAll: true,
            );
        }

        if ($joined !== null && $joined->greaterThan($start)) {
            $notes[] = 'Joined '.$joined->format('j M').'; earlier days are outside the employment'
                .($prorateJoiners ? ' and are prorated out of the month’s pay.' : ' and are NOT deducted (prorate_joiners is off).');
        }

        $marks = StaffAttendance::query()
            ->where('staff_id', $staff->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (StaffAttendance $row): string => $row->date->toDateString());

        $leaveByDate = $this->approvedLeaveByDate($staff, $start->toDateString(), $end->toDateString());
        $allowanceLeft = $this->paidLeaveAllowanceLeft($staff, $start->toDateString(), $weekend);

        // TWO working-day counters, and the difference is the bug they fix.
        //
        //   $schoolWorkingDays — every day the SCHOOL worked this month, whoever
        //                        was employed. This is the DENOMINATOR on a
        //                        working-day basis.
        //   $workingDays       — those days the employee was employed for. This
        //                        is what gets reported, and what "working days"
        //                        means on the payslip.
        //
        // They used to be one counter that excluded pre-joining days, while the
        // loss-of-pay total INCLUDED them — so a joiner could accumulate 16 lost
        // days against a basis of 8, giving factor = max(0, 1 − 16/8) = 0 and a
        // brand-new teacher a payslip for ₹0.
        $schoolWorkingDays = 0.0;
        $workingDays = 0.0;
        $lop = 0.0;
        $breakdown = [
            'absent' => 0.0,
            'half_day' => 0.0,
            'unpaid_leave' => 0.0,
            'unallocated_leave' => 0.0,
            'not_employed' => 0.0,
        ];
        $unmarked = 0;

        foreach (CarbonPeriod::create($start, $end) as $day) {
            $date = $day->format('Y-m-d');
            $isWeekend = in_array($day->dayOfWeek, $weekend, true);

            $mark = $marks->get($date);
            $status = $mark?->status;

            // Holiday is a property of the DAY, not of the person: the school was
            // shut. Neither a working day nor a loss, for anybody.
            $isHoliday = $status === 'holiday';
            $isSchoolWorkingDay = ! $isWeekend && ! $isHoliday;

            if ($isSchoolWorkingDay) {
                $schoolWorkingDays += 1.0;
            }

            // A day before joining is never absence — nobody is absent from a job
            // they had not started. Whether it is also unpaid is the
            // `prorate_joiners` question, and the basis decides which days can
            // carry the charge: on a calendar basis a weekend before the start
            // date is genuinely unpaid, on a working-day basis it was never
            // payable in the first place.
            if ($day->lessThan($employedFrom)) {
                if ($prorateJoiners && ($basis === self::BASIS_CALENDAR || $isSchoolWorkingDay)) {
                    $lop += 1.0;
                    $breakdown['not_employed'] += 1.0;
                }

                continue;
            }

            if (! $isSchoolWorkingDay) {
                continue;
            }

            $workingDays += 1.0;

            if ($status === null) {
                if (! $unmarkedPaid) {
                    $lop += 1.0;
                    $breakdown['absent'] += 1.0;
                }

                $unmarked++;

                continue;
            }

            if ($status === 'leave') {
                $lost = $this->leaveDayLoss($leaveByDate[$date] ?? null, $allowanceLeft, $breakdown);
                $lop += $lost;

                continue;
            }

            if (isset(self::LOSS_PER_DAY[$status])) {
                $lop += self::LOSS_PER_DAY[$status];
                $breakdown[$status] += self::LOSS_PER_DAY[$status];
            }
        }

        if ($unmarked > 0) {
            $notes[] = $unmarkedPaid
                ? $unmarked.' working day(s) had no attendance mark and were paid.'
                : $unmarked.' working day(s) had no attendance mark and were treated as absent.';
        }

        // The divisor. Calendar days is the more common convention and is the
        // default; a school that pays a per-working-day rate switches the basis.
        //
        // On a working-day basis this is the SCHOOL's working days, not the
        // employee's — the numerator may include days before they joined, so the
        // denominator has to as well or the ratio exceeds 1 and the payslip
        // collapses to zero.
        $basisDays = $basis === self::BASIS_WORKING ? $schoolWorkingDays : (float) $monthDays;

        if ($basisDays <= 0) {
            $basisDays = (float) $monthDays;
            $notes[] = __('hr::messages.calc.lop_no_working_days');
        }

        // Belt and braces: whatever the day rules produce, a month cannot lose
        // more than all of itself. Anything hitting this is a bug worth seeing.
        if ($lop > $basisDays) {
            $notes[] = __('hr::messages.calc.lop_capped', ['cap' => rtrim(rtrim(number_format($basisDays, 2), '0'), '.')]);
            $lop = $basisDays;
        }

        $payable = max(0.0, $basisDays - $lop);

        return $this->result($monthDays, $workingDays, $payable, $lop, $basis, $enabled, $breakdown, $notes, $basisDays);
    }

    /**
     * Days the SCHOOL worked in a range: neither weekend nor marked as a holiday
     * for this staff member's branch.
     *
     * Used for the "had not joined at all" case, where the day-by-day loop is
     * skipped entirely but a denominator is still needed.
     *
     * @param  array<int, int>  $weekend
     */
    protected function schoolWorkingDays(Staff $staff, CarbonImmutable $start, CarbonImmutable $end, array $weekend): float
    {
        $holidays = StaffAttendance::query()
            ->where('staff_id', $staff->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('status', 'holiday')
            ->get()
            ->keyBy(fn (StaffAttendance $row): string => $row->date->toDateString());

        $days = 0.0;

        foreach (CarbonPeriod::create($start, $end) as $day) {
            if (in_array($day->dayOfWeek, $weekend, true)) {
                continue;
            }

            if ($holidays->has($day->format('Y-m-d'))) {
                continue;
            }

            $days += 1.0;
        }

        return $days;
    }

    /**
     * Approved leave requests covering each date of the period, keyed by date.
     *
     * Only APPROVED leave counts. A pending application does not excuse an
     * absence and must not be paid on the strength of a decision nobody has
     * taken yet.
     *
     * @return array<string, StaffLeaveRequest>
     */
    protected function approvedLeaveByDate(Staff $staff, string $from, string $to): array
    {
        $requests = StaffLeaveRequest::query()
            ->where('staff_id', $staff->id)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $to)
            ->whereDate('to_date', '>=', $from)
            ->with('leaveType')
            ->orderBy('from_date')
            ->get();

        $byDate = [];

        foreach ($requests as $request) {
            if ($request->from_date === null || $request->to_date === null) {
                continue;
            }

            foreach (CarbonPeriod::create($request->from_date, $request->to_date) as $day) {
                $byDate[$day->format('Y-m-d')] = $request;
            }
        }

        return $byDate;
    }

    /**
     * How much of one leave day is lost pay, consuming the paid-leave allowance
     * as it goes.
     *
     * Three outcomes:
     *   • the leave type is UNPAID — a full day of loss, always. That is what
     *     `staff_leave_types.is_paid = false` has meant since the day the column
     *     was added; nothing had ever acted on it.
     *   • the type is paid AND the allowance still has room — free, and the
     *     allowance shrinks by a day.
     *   • the type is paid but the allowance is EXHAUSTED — a full day of loss.
     *     This is the "netted against allocations" half: approving leave beyond
     *     what somebody was granted does not create budget, it creates unpaid
     *     leave.
     *
     * A `leave` mark with no approved request behind it (typed straight into the
     * register) is treated as PAID. It carries no leave type, so there is nothing
     * that says otherwise, and guessing "unpaid" would dock pay on the strength
     * of a missing record.
     *
     * @param  array<int, float>  $allowanceLeft  leave-type id => days still allocated
     * @param  array<string, float>  $breakdown
     */
    protected function leaveDayLoss(?StaffLeaveRequest $request, array &$allowanceLeft, array &$breakdown): float
    {
        if ($request === null) {
            return 0.0;
        }

        $type = $request->leaveType;

        if ($type !== null && ! $type->is_paid) {
            $breakdown['unpaid_leave'] += 1.0;

            return 1.0;
        }

        $typeId = (int) $request->staff_leave_type_id;

        // A type with no allocation row at all is not treated as a zero balance:
        // plenty of schools grant casual leave without ever recording an
        // allocation, and reading that absence as "no entitlement" would turn
        // every approved casual day into a deduction.
        if (! array_key_exists($typeId, $allowanceLeft)) {
            return 0.0;
        }

        if ($allowanceLeft[$typeId] >= 1.0) {
            $allowanceLeft[$typeId] -= 1.0;

            return 0.0;
        }

        $breakdown['unallocated_leave'] += 1.0;

        return 1.0;
    }

    /**
     * Paid-leave days still available per leave type at the START of the period:
     * days allocated, less days already consumed BEFORE this month.
     *
     * ── COUNTED IN WORKING DAYS, LIKE THE MONTH ITSELF ───────────────────────
     * Prior usage used to be summed with `StaffLeaveRequest::$days`, which is
     * INCLUSIVE CALENDAR days — while the current month consumes the allowance
     * one WORKING day at a time, in the loop above. A leave request over a
     * weekend therefore burned more allowance in history than the identical
     * request would burn this month, and an employee could be docked for
     * "unallocated" leave they still had. Both halves now count working days.
     *
     * ── AND STRADDLING REQUESTS ARE COUNTED ──────────────────────────────────
     * The filter used to be `to_date < periodStart`, so a request that BEGAN
     * before the period and ran into it contributed nothing at all — its earlier
     * days were free. Any request starting before the period is now walked, and
     * only the portion that falls before `periodStart` is charged; the days
     * inside the period are charged by the main loop, as they should be.
     *
     * Leave taken LATER in the year is still not subtracted: this month is being
     * paid now, and a day cannot be retrospectively unfunded by a request
     * approved afterwards.
     *
     * @param  array<int, int>  $weekend
     * @return array<int, float>
     */
    protected function paidLeaveAllowanceLeft(Staff $staff, string $periodStart, array $weekend): array
    {
        $allocated = StaffLeaveAllocation::query()
            ->where('staff_id', $staff->id)
            ->selectRaw('staff_leave_type_id, SUM(days_allocated) as days')
            ->groupBy('staff_leave_type_id')
            ->pluck('days', 'staff_leave_type_id');

        if ($allocated->isEmpty()) {
            return [];
        }

        $boundary = CarbonImmutable::parse($periodStart);

        $used = [];

        $requests = StaffLeaveRequest::query()
            ->where('staff_id', $staff->id)
            ->where('status', 'approved')
            ->whereDate('from_date', '<', $periodStart)
            ->get();

        foreach ($requests as $request) {
            if ($request->from_date === null || $request->to_date === null) {
                continue;
            }

            $last = $request->to_date->lessThan($boundary)
                ? CarbonImmutable::parse($request->to_date)
                : $boundary->subDay();

            $typeId = (int) $request->staff_leave_type_id;

            foreach (CarbonPeriod::create($request->from_date, $last) as $day) {
                if (in_array($day->dayOfWeek, $weekend, true)) {
                    continue;
                }

                $used[$typeId] = ($used[$typeId] ?? 0.0) + 1.0;
            }
        }

        $left = [];

        foreach ($allocated as $typeId => $days) {
            $left[(int) $typeId] = max(0.0, (float) $days - ($used[(int) $typeId] ?? 0.0));
        }

        return $left;
    }

    /**
     * @param  array<string, float>  $breakdown
     * @param  array<int, string>  $notes
     * @return array<string, mixed>
     */
    protected function result(
        int $monthDays,
        float $workingDays,
        float $payable,
        float $lop,
        string $basis,
        bool $enabled,
        array $breakdown,
        array $notes,
        ?float $basisDays = null,
        bool $notEmployedAtAll = false,
    ): array {
        return [
            'month_days' => $monthDays,
            'working_days' => round($workingDays, 2),
            'payable_days' => round($payable, 2),
            'lop_days' => round($lop, 2),
            'basis' => $basis,
            'basis_days' => round($basisDays ?? (float) $monthDays, 2),
            'enabled' => $enabled,
            // "Employed for none of this period" is NOT a loss-of-pay outcome and
            // is deliberately not gated on the loss-of-pay switch: paying somebody
            // for a month before they started is wrong whatever the flags say.
            'not_employed_at_all' => $notEmployedAtAll,
            'breakdown' => array_map(static fn (float $v): float => round($v, 2), $breakdown),
            'notes' => $notes,
        ];
    }
}

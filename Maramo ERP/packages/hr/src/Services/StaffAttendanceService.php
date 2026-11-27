<?php

namespace Classivo\Hr\Services;

use Carbon\CarbonPeriod;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffAttendance;
use Classivo\Hr\Models\StaffLeaveRequest;
use Illuminate\Support\Collection;

/**
 * StaffAttendanceService (spec §15 "Staff Attendance") — the single code path shared
 * by the Take Staff Attendance page, the staff attendance report and the test suite.
 * Staff counterpart of the student attendance service.
 *
 * Percentage model (same as students):
 *   • Countable days = present/absent/late/half_day (holiday + leave excluded).
 *   • Credit: present = 1, late = 1, half_day = 0.5, absent = 0.
 *   • % = round(credit / countable * 100, 2); 0.0 with no countable days.
 *
 * Approved leave stamps `leave` across the days it covers, exactly as
 * the student attendance module does for students — see
 * {@see markApprovedLeave()} for the one rule that differs and why.
 */
class StaffAttendanceService
{
    public const COUNTABLE = ['present', 'absent', 'late', 'half_day'];

    /**
     * The remark every machine-written leave day carries. It is the ONLY marker that
     * says "this row was written by an approval and may be taken back", so
     * {@see clearLeaveMarks()} removes exactly these rows and never a mark a human
     * made. Same string the student side writes (student attendance).
     */
    public const LEAVE_REMARK = 'Approved leave';

    /**
     * Existing marks an approval is allowed to REPLACE with `leave`.
     *
     * Only `absent`. That is the whole point of the link: a member whose leave is
     * approved after the register was taken was marked absent for days they had
     * permission to miss, and leaving that absence standing is the bug.
     *
     * Everything else is left exactly as it is:
     *   • present / late / half_day — they actually came in. Whatever the leave
     *     says, attendance is a record of what happened, and an approval must not
     *     erase evidence of work done.
     *   • holiday — the school was shut. That is a property of the DAY, not of
     *     this member, and overwriting it would make one person's approved leave
     *     silently redefine the calendar for that date.
     *   • leave — already the value being written; rewritten harmlessly.
     *
     * @var array<int, string>
     */
    public const LEAVE_OVERWRITABLE = ['absent', 'leave'];

    public const CREDIT = [
        'present' => 1.0,
        'late' => 1.0,
        'half_day' => 0.5,
        'absent' => 0.0,
    ];

    /**
     * Active staff, optionally filtered by department, ordered by employee_id.
     *
     * @return Collection<int, Staff>
     */
    public function roster(?int $departmentId = null): Collection
    {
        return Staff::query()
            ->where('status', 'active')
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->orderBy('employee_id')
            ->get();
    }

    /**
     * Existing staff marks for a date, keyed by staff_id.
     *
     * @return Collection<int, StaffAttendance>
     */
    public function existingDaily(string $date): Collection
    {
        return StaffAttendance::query()
            ->whereDate('date', $date)
            ->get()
            ->keyBy('staff_id');
    }

    /**
     * Persist staff attendance for a date. Each row is [staff_id, status, remark?].
     * Idempotent per (staff, date) via updateOrCreate.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return int number of rows written
     */
    public function saveDaily(string $date, array $rows, ?int $markedBy = null): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $staffId = (int) ($row['staff_id'] ?? 0);

            if ($staffId <= 0) {
                continue;
            }

            $staff = Staff::find($staffId);

            if (! $staff) {
                continue;
            }

            StaffAttendance::updateOrCreate(
                ['staff_id' => $staffId, 'date' => $date],
                [
                    'school_id' => $staff->school_id,
                    'branch_id' => $staff->branch_id,
                    'status' => $row['status'] ?? 'present',
                    'remark' => $row['remark'] ?? null,
                    'marked_by' => $markedBy,
                ],
            );

            $count++;
        }

        return $count;
    }

    /**
     * Status → count map for a staff member over an inclusive date range.
     *
     * @return array<string, int>
     */
    public function statusCounts(int $staffId, string $from, string $to): array
    {
        $counts = array_fill_keys(array_keys(StaffAttendance::STATUSES), 0);

        $rows = StaffAttendance::query()
            ->where('staff_id', $staffId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($rows as $status => $aggregate) {
            $counts[$status] = (int) $aggregate;
        }

        return $counts;
    }

    /**
     * Attendance percentage for a staff member over an inclusive date range using the
     * documented COUNTABLE / CREDIT model. Returns 0.0 with no countable days.
     */
    public function percentage(int $staffId, string $from, string $to): float
    {
        $counts = $this->statusCounts($staffId, $from, $to);

        $countable = 0;
        $credit = 0.0;

        foreach (self::COUNTABLE as $status) {
            $countable += $counts[$status];
            $credit += $counts[$status] * self::CREDIT[$status];
        }

        return $countable === 0 ? 0.0 : round($credit / $countable * 100, 2);
    }

    /**
     * Stamp `leave` into `staff_attendances` for every working day an APPROVED staff
     * leave covers. The staff twin of the student leave-approval attendance write, and
     * mirrored on it deliberately: same CarbonPeriod walk, same weekend skip from the
     * branch's configured `weekend_days`, same idempotent updateOrCreate per
     * (staff, date), same 'Approved leave' remark, same "number of days marked"
     * return value.
     *
     * ONE RULE DIFFERS, ON PURPOSE. The student version overwrites whatever mark it
     * finds; this one only replaces the statuses in {@see LEAVE_OVERWRITABLE} (absent,
     * and a leave day it would rewrite identically) and steps over present / late /
     * half_day / holiday. `staff_attendances` is the input to payroll-adjacent reporting
     * and to StaffAttendanceSummaryReport, so a day somebody was recorded as having
     * WORKED must not be turned into leave by a later approval. Days it steps over are
     * not counted in the return value — "3 of 5 days marked" is the honest answer, and
     * the caller can say so.
     *
     * Callers do not call this directly: {@see StaffLeaveRequest::approve()} does, so
     * every approval path (mobile API, panel table action, panel edit page, the
     * approvals workflow) marks attendance identically. That was the whole reason the
     * rule was not written into any one of them.
     *
     * @return int working days actually stamped
     */
    public function markApprovedLeave(StaffLeaveRequest $leave, ?int $markedBy = null): int
    {
        $staff = $leave->staff;

        if (! $staff instanceof Staff || $leave->from_date === null || $leave->to_date === null) {
            return 0;
        }

        $weekend = app(BranchCalendar::class)->weekendDaysFor($leave->branch_id ?? $staff->branch_id);

        $existing = StaffAttendance::query()
            ->where('staff_id', $staff->id)
            ->whereBetween('date', [$leave->from_date->toDateString(), $leave->to_date->toDateString()])
            ->get()
            ->keyBy(fn (StaffAttendance $row): string => $row->date->toDateString());

        $marked = 0;

        foreach (CarbonPeriod::create($leave->from_date, $leave->to_date) as $day) {
            if (in_array($day->dayOfWeek, $weekend, true)) {
                continue;
            }

            $date = $day->toDateString();
            $mark = $existing->get($date);

            if ($mark !== null && ! in_array($mark->status, self::LEAVE_OVERWRITABLE, true)) {
                continue;
            }

            StaffAttendance::updateOrCreate(
                ['staff_id' => $staff->id, 'date' => $date],
                [
                    'school_id' => $leave->school_id ?? $staff->school_id,
                    'branch_id' => $leave->branch_id ?? $staff->branch_id,
                    'status' => 'leave',
                    'remark' => self::LEAVE_REMARK,
                    'marked_by' => $markedBy,
                ],
            );

            $marked++;
        }

        return $marked;
    }

    /**
     * Take back the leave days an approval wrote — the undo half of
     * {@see markApprovedLeave()}, run when an approved leave is rejected, deleted or
     * otherwise stops being approved.
     *
     * Only rows this service wrote are removed: status `leave` AND remark
     * {@see LEAVE_REMARK}, inside the leave's own date range for that member. A `leave`
     * day somebody typed by hand, or one written by a DIFFERENT approved leave that has
     * since been edited to carry another remark, is not this request's to delete.
     *
     * A day that was `absent` BEFORE the approval overwrote it comes back UNMARKED, not
     * absent. The pre-approval value is not stored anywhere, and re-inventing an absence
     * for somebody whose leave was later rejected would be a guess with a payroll
     * consequence — an empty day is the honest state, and the roster shows it as
     * unmarked so the register can be retaken.
     *
     * Rows are FORCE-deleted rather than soft-deleted. A soft-deleted row still occupies
     * the (staff_id, date) unique key on any server that did not take the
     * soft-delete-aware index migrations, so re-approving the same leave would collide;
     * and the row carries nothing a human wrote, so there is nothing to archive.
     *
     * @return int rows removed
     */
    public function clearLeaveMarks(StaffLeaveRequest $leave): int
    {
        if ($leave->from_date === null || $leave->to_date === null) {
            return 0;
        }

        return (int) StaffAttendance::query()
            ->where('staff_id', $leave->staff_id)
            ->whereBetween('date', [$leave->from_date->toDateString(), $leave->to_date->toDateString()])
            ->where('status', 'leave')
            ->where('remark', self::LEAVE_REMARK)
            ->forceDelete();
    }
}

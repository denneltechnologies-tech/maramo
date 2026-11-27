<?php

namespace Classivo\Hr\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Hr\Services\StaffAttendanceService;
use Classivo\Hr\Support\HrAuth;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StaffLeaveRequest (spec §15 Staff Leave "Approve Leave Requests" / §32) — a STAFF
 * leave application over a date range. DISTINCT from the student LeaveRequest model.
 * Approve/Reject transitions are performed by the resource actions; approved days
 * count against the leave allocation balance. Tenant + branch scoped, soft-deleting.
 *
 * ── APPROVAL ALSO WRITES ATTENDANCE ──────────────────────────────────────────
 * {@see approve()} stamps `leave` into `staff_attendances` for the working days it
 * covers, and {@see reject()} takes those days back — the staff twin of what
 * the student attendance module has always done for
 * students. The rule lives HERE, on the model, rather than in any one caller,
 * because five surfaces decide staff leave (the mobile API, the leave table's two
 * actions, the edit page, the approvals workflow) and a rule written into one of
 * them is a rule the other four silently do not have. The mechanics — which days,
 * which existing marks are respected — are in
 * {@see StaffAttendanceService::markApprovedLeave()}.
 */
class StaffLeaveRequest extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

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
        'staff_leave_type_id',
        'from_date',
        'to_date',
        'reason',
        'status',
        'review_remark',
        'approved_by',
        'applied_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date:Y-m-d',
            'to_date' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Archiving an approved leave is how this system "cancels" one — there is no
     * cancel endpoint — so it must take its attendance days back with it, and
     * restoring it must put them back. Without this an archived leave leaves
     * phantom `leave` days that no request explains.
     */
    protected static function booted(): void
    {
        static::deleted(function (StaffLeaveRequest $request): void {
            if ($request->status === 'approved') {
                app(StaffAttendanceService::class)->clearLeaveMarks($request);
            }
        });

        static::restored(function (StaffLeaveRequest $request): void {
            if ($request->status === 'approved') {
                app(StaffAttendanceService::class)->markApprovedLeave($request, $request->approved_by);
            }
        });
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(StaffLeaveType::class, 'staff_leave_type_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(HrAuth::userModel(), 'approved_by');
    }

    /** Inclusive number of days covered by the request. */
    protected function days(): Attribute
    {
        return Attribute::get(function (): int {
            if (! $this->from_date || ! $this->to_date) {
                return 0;
            }

            return $this->from_date->diffInDays($this->to_date) + 1;
        });
    }

    /**
     * Approve this request, stamping the reviewer + timestamp AND marking the days
     * it covers as `leave` in `staff_attendances` — so an approved leave actually
     * suppresses the absent mark it is meant to.
     *
     * @return int working days stamped (0 when every covered day already carried a
     *             present/late/half-day/holiday mark this must not overwrite)
     */
    public function approve(?int $approverId = null): int
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'reviewed_at' => now(),
        ]);

        return app(StaffAttendanceService::class)->markApprovedLeave($this, $approverId);
    }

    /**
     * Reject this request with an optional reviewer remark, taking back any leave days
     * a previous approval had written. Rejecting a request that was never approved
     * clears nothing, because there is nothing of ours on those dates.
     *
     * @return int leave days taken back
     */
    public function reject(?int $approverId = null, ?string $remark = null): int
    {
        $this->update([
            'status' => 'rejected',
            'approved_by' => $approverId,
            'review_remark' => $remark,
            'reviewed_at' => now(),
        ]);

        return app(StaffAttendanceService::class)->clearLeaveMarks($this);
    }

    /**
     * The earliest existing pending/approved leave for this staff member whose date
     * range overlaps [$from, $to] — one leave per day is enforced everywhere
     * (mobile API + all portal apply pages) through this single finder.
     */
    public static function overlappingFor(int $ownerId, string $from, string $to): ?self
    {
        return static::query()
            ->where('staff_id', $ownerId)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', $to)
            ->whereDate('to_date', '>=', $from)
            ->orderBy('from_date')
            ->first();
    }
}

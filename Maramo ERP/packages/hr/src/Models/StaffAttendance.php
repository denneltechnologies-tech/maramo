<?php

namespace Classivo\Hr\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Hr\Support\HrAuth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StaffAttendance (spec §15 "Staff Attendance" / §32) — one daily attendance mark per
 * staff per date. DISTINCT from the student Attendance model. Tenant + branch scoped,
 * soft-deleting, activity-logged. Idempotent per (staff_id, date).
 */
class StaffAttendance extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    public const STATUSES = [
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'half_day' => 'Half Day',
        'holiday' => 'Holiday',
        'leave' => 'Leave',
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'staff_id',
        'date',
        'status',
        'remark',
        'marked_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(HrAuth::userModel(), 'marked_by');
    }
}

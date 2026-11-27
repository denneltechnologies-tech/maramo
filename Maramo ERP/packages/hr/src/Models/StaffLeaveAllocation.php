<?php

namespace Classivo\Hr\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StaffLeaveAllocation (spec §15 Staff Leave "Leave Allocation" / §32) — days of a
 * staff_leave_type granted to a staff member for a session. Tenant + branch + session
 * scoped, soft-deleting, activity-logged.
 */
class StaffLeaveAllocation extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'staff_id',
        'staff_leave_type_id',
        'days_allocated',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'days_allocated' => 'decimal:1',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(StaffLeaveType::class, 'staff_leave_type_id');
    }
}

<?php

namespace Classivo\Hr\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StaffLeaveType (spec §15 Staff Leave "Leave Types" / §32) — the STAFF-leave category
 * master with an optional annual allowance and a paid/unpaid flag. DISTINCT from the
 * student LeaveType model. Tenant + branch scoped, soft-deleting, activity-logged.
 */
class StaffLeaveType extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'name',
        'days_allowed',
        'is_paid',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'days_allowed' => 'integer',
            'is_paid' => 'boolean',
            'status' => 'boolean',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StaffLeaveAllocation::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(StaffLeaveRequest::class);
    }
}

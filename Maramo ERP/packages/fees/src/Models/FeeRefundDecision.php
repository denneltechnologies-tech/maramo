<?php

namespace Classivo\Fees\Models;

use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Fees\Support\Users;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FeeRefundDecision — one row per refund lifecycle event (requested,
 * auto_approved, approved, rejected, voided, issued, paid_out). Append-only,
 * written in the same transaction as the transition it records.
 */
class FeeRefundDecision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'branch_id',
        'fee_refund_id',
        'decision',
        'remark',
        'decided_by',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(FeeRefund::class, 'fee_refund_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'decided_by');
    }
}

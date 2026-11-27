<?php

namespace Classivo\Fees\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeRefundLine — one line of a {@see FeeRefund}: money coming back out of one
 * {@see FeePaymentDetail} of the original receipt.
 *
 * ACTIVITY-LOGGED for the same reason {@see FeePaymentDetail} is: this row is the
 * only place a per-head refund figure is written down, and money leaving the
 * school is at least as worth a causer as a discount granted at the counter.
 *
 * `feePaymentDetail` and `allocation` are withTrashed() because a refund line
 * must keep naming what it refunded even after the receipt line or the
 * allocation is soft-deleted — the same reasoning
 * {@see FeeStudentAllocation::feeMaster()} gives for its own withTrashed().
 *
 * Tenant + branch scoped, soft-deleting. Money = decimal(15,2).
 */
class FeeRefundLine extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'fee_refund_id',
        'fee_payment_detail_id',
        'fee_student_allocation_id',
        'fee_type_id',
        'label',
        'amount',
        'restore_dues',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'restore_dues' => 'boolean',
        ];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(FeeRefund::class, 'fee_refund_id');
    }

    public function feePaymentDetail(): BelongsTo
    {
        return $this->belongsTo(FeePaymentDetail::class, 'fee_payment_detail_id')->withTrashed();
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(FeeStudentAllocation::class, 'fee_student_allocation_id')->withTrashed();
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class)->withTrashed();
    }

    /** The head this line refunded, as the credit note should print it. */
    public function headLabel(): string
    {
        return $this->feeType?->name ?? $this->label ?? 'Fee';
    }
}

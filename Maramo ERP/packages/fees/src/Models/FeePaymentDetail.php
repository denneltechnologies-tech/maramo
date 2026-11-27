<?php

namespace Classivo\Fees\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Fees\Support\Users;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeePaymentDetail — one line of a receipt: the amount applied to a
 * single {@see FeeStudentAllocation} (or a free-hand `label` line with no allocation).
 * Reverting the parent payment reads these to restore each allocation's balance.
 * Tenant + branch scoped, soft-deleting. Money = decimal(15,2).
 *
 * A per-line concession is written down HERE with its author
 * (`discount_granted_by`) and reason (`discount_reason`), and a late fine with
 * `fine_charged_by` / `fine_reason`, so neither is anonymous.
 */
class FeePaymentDetail extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'fee_payment_id',
        'fee_student_allocation_id',
        'fee_type_id',
        'fee_discount_id',
        'label',
        'amount',
        'discount_amount',
        'discount_granted_by',
        'discount_reason',
        'fine_amount',
        // The fine's audit pair, mirroring the discount pair above. A concession
        // was fully attributed while a penalty was anonymous — the same receipt
        // recorded who granted a discount and why, and nothing at all about who
        // levied a fine.
        'fine_charged_by',
        'fine_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'fine_amount' => 'decimal:2',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class, 'fee_payment_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(FeeStudentAllocation::class, 'fee_student_allocation_id');
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    /**
     * The catalogue concession this line's discount came from, when one was picked.
     * NULL for a hand-typed figure — in which case `discount_reason` is the record.
     * withTrashed() for the same reason {@see FeeStudentAllocation::feeMaster()} does
     * it: retiring a concession must not un-name a write-off already granted from it.
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(FeeDiscount::class, 'fee_discount_id')->withTrashed();
    }

    /** The user who granted this line's discount (never the collector by default). */
    public function discountGrantedBy(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'discount_granted_by');
    }
}

<?php

namespace Classivo\Fees\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Fees\Support\Users;
use Classivo\Students\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeePayment — a collection receipt. Carries a sequential
 * per-branch receipt number (invoice_prefix + series), totals, payment mode and
 * collector; {@see FeePaymentDetail} lines apportion it across allocations. A reversed
 * receipt keeps its row with `status = reverted`. Tenant + branch + session scoped,
 * soft-deleting. Money = decimal(15,2).
 */
class FeePayment extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    public const MODES = [
        'cash' => 'Cash',
        'mobile_money' => 'Mobile Money (MoMo / Telecel / AT)',
        'bank_deposit' => 'Bank Deposit',
        'bank_transfer' => 'Bank Transfer',
        'card' => 'Card / POS',
        'cheque' => 'Cheque',
        'online' => 'Online (gateway)',
    ];

    /**
     * Modes a person may choose when recording money by hand. `online` is
     * written only by the payments package after a verified gateway payment.
     */
    public const MANUAL_MODES = [
        'cash' => 'Cash',
        'mobile_money' => 'Mobile Money (MoMo / Telecel / AT)',
        'bank_deposit' => 'Bank Deposit',
        'bank_transfer' => 'Bank Transfer',
        'card' => 'Card / POS',
        'cheque' => 'Cheque',
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'student_id',
        'receipt_no',
        'receipt_seq',
        'payment_date',
        'total_amount',
        'paid_amount',
        'discount_amount',
        'fine_amount',
        'mode',
        'reference_no',
        'note',
        'collected_by',
        'status',
        'reverted_at',
        'reverted_by',
        'revert_reason',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'fine_amount' => 'decimal:2',
            'receipt_seq' => 'integer',
            'reverted_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(FeePaymentDetail::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'collected_by');
    }

    public function revertedBy(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'reverted_by');
    }

    public function isReverted(): bool
    {
        return $this->status === 'reverted';
    }
}

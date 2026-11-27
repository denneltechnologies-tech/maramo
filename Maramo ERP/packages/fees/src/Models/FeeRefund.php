<?php

namespace Classivo\Fees\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Fees\Support\FeeRefunds;
use Classivo\Fees\Support\Users;
use Classivo\Students\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeRefund — a full or partial refund against ONE collection receipt.
 *
 * LIFECYCLE — money moves at exactly one point:
 *
 *   requested ──(0 approvals needed)──► approved ──issue()──► issued
 *       │                                  ▲
 *       ├──approve() ×N (distinct people)──┘
 *       ├──reject()──► rejected
 *       └──void()  ──► voided                (also from approved)
 *
 * `approved` deliberately does not pay anybody: an accountant approves and then
 * issues, so a single click can never both decide and pay a family out.
 *
 * The columns here (requested_by/at, approved_by/at, rejected_by/at,
 * issued_by/at) are the fast index; the durable record is
 * {@see FeeRefundDecision} — one row per lifecycle event, written inside the
 * same transaction as the transition.
 *
 * Tenant + branch scoped, soft-deleting. NOT session-scoped: a refund is
 * routinely raised in a later session than the receipt it reverses, and a
 * SessionScope would hide exactly those. Money = decimal(15,2).
 */
class FeeRefund extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    public const STATUSES = [
        'requested' => 'Requested',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'issued' => 'Issued',
        'voided' => 'Voided',
    ];

    public const STATUS_COLORS = [
        'requested' => 'warning',
        'approved' => 'info',
        'rejected' => 'danger',
        'issued' => 'success',
        'voided' => 'gray',
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'student_id',
        'fee_payment_id',
        'amount',
        'reason_code',
        'reason_detail',
        'mode',
        'original_mode',
        'reference_no',
        'restore_dues',
        'status',
        'required_approvals',
        'approvals_received',
        'threshold_at_request',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'issued_by',
        'issued_at',
        'credit_note_no',
        'credit_note_seq',
        'student_credit_id',
        'payout_status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'threshold_at_request' => 'decimal:2',
            'restore_dues' => 'boolean',
            'required_approvals' => 'integer',
            'approvals_received' => 'integer',
            'credit_note_seq' => 'integer',
            'student_credit_id' => 'integer',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class, 'fee_payment_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FeeRefundLine::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'rejected_by');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'issued_by');
    }

    /** The decision trail for this refund, oldest first. */
    public function decisions(): HasMany
    {
        return $this->hasMany(FeeRefundDecision::class)->orderBy('id');
    }

    // ---------------------------------------------------------------- state

    /** Still waiting for a human (or for the second human). */
    public function isPendingApproval(): bool
    {
        return $this->status === 'requested'
            && (int) $this->approvals_received < (int) $this->required_approvals;
    }

    /** Fully approved and waiting to be issued. */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }

    /** Has this refund ended without money moving? */
    public function isClosedUnpaid(): bool
    {
        return in_array($this->status, ['rejected', 'voided'], true);
    }

    /** Does this refund still count against the receipt's refundable balance? */
    public function countsAgainstReceipt(): bool
    {
        return ! $this->isClosedUnpaid();
    }

    /** Refunds that still consume a receipt's refundable balance. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['rejected', 'voided']);
    }

    // ---------------------------------------------------------------- labels

    public function reasonLabel(): string
    {
        return FeeRefunds::reasonLabel($this->reason_code);
    }

    public function modeLabel(): string
    {
        return FeeRefunds::modeLabel($this->mode);
    }

    /** Credit note number, or a placeholder for a refund that has not been issued. */
    public function creditNoteLabel(): string
    {
        return $this->credit_note_no ?: '—';
    }
}

<?php

namespace Classivo\Fees\Events;

use Classivo\Fees\Models\FeeRefund;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * FeeRefunded — fired once, at the moment a fee refund is ISSUED (credit note
 * minted, dues restored). Plain scalars only, so a listener in a separate
 * accounting module can post the reversing entry for the refunded portion
 * without importing anything from this package. The fees package itself posts
 * nothing to any ledger.
 */
class FeeRefunded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $refundId,
        public string $creditNoteNo,
        public int $feePaymentId,
        public string $receiptNo,
        public int $studentId,
        public float $amount,
        public string $date,
        public string $head,
        public string $mode,
        public string $reasonCode,
        public bool $restoreDues,
        public ?int $issuedBy,
        public int $schoolId,
        public int $branchId,
        public ?int $sessionId,
    ) {}

    /** Build the event from an issued refund (+ its lines, for the head summary). */
    public static function fromRefund(FeeRefund $refund): self
    {
        return new self(
            refundId: (int) $refund->id,
            creditNoteNo: (string) $refund->credit_note_no,
            feePaymentId: (int) $refund->fee_payment_id,
            receiptNo: (string) ($refund->payment?->receipt_no ?? ''),
            studentId: (int) $refund->student_id,
            amount: (float) $refund->amount,
            date: $refund->issued_at?->toDateString() ?? now()->toDateString(),
            head: self::headSummary($refund),
            mode: (string) $refund->mode,
            reasonCode: (string) $refund->reason_code,
            restoreDues: (bool) $refund->restore_dues,
            issuedBy: $refund->issued_by !== null ? (int) $refund->issued_by : null,
            schoolId: (int) $refund->school_id,
            branchId: (int) $refund->branch_id,
            sessionId: $refund->session_id !== null ? (int) $refund->session_id : null,
        );
    }

    /** A comma-joined summary of the fee heads refunded — the twin of FeePaymentRecorded's. */
    protected static function headSummary(FeeRefund $refund): string
    {
        $lines = $refund->relationLoaded('lines') ? $refund->lines : $refund->lines()->with('feeType')->get();

        $heads = $lines
            ->map(fn ($l): ?string => $l->feeType?->name ?? $l->label)
            ->filter()
            ->unique()
            ->values();

        return $heads->isNotEmpty() ? $heads->implode(', ') : 'Fee refund';
    }
}

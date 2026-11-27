<?php

namespace Classivo\Fees\Events;

use Classivo\Fees\Models\FeePayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * FeePaymentRecorded — fired after a fee receipt is committed. Plain scalars, so
 * an accounting module (or any other listener: notifications, analytics) can
 * react without the fees package knowing it exists.
 */
class FeePaymentRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $paymentId,
        public string $receiptNo,
        public int $studentId,
        public float $amount,
        public string $date,
        public string $head,
        public string $mode,
        public int $schoolId,
        public int $branchId,
        public ?int $sessionId,
    ) {}

    /** Build the event from a persisted receipt (+ its detail lines for the head summary). */
    public static function fromPayment(FeePayment $payment): self
    {
        return new self(
            paymentId: (int) $payment->id,
            receiptNo: (string) $payment->receipt_no,
            studentId: (int) $payment->student_id,
            amount: (float) $payment->paid_amount,
            date: $payment->payment_date?->toDateString() ?? now()->toDateString(),
            head: self::headSummary($payment),
            mode: (string) $payment->mode,
            schoolId: (int) $payment->school_id,
            branchId: (int) $payment->branch_id,
            sessionId: $payment->session_id !== null ? (int) $payment->session_id : null,
        );
    }

    /** A comma-joined summary of the fee heads collected on this receipt. */
    protected static function headSummary(FeePayment $payment): string
    {
        $details = $payment->relationLoaded('details') ? $payment->details : $payment->details()->with('feeType')->get();

        $heads = $details
            ->map(fn ($d): ?string => $d->feeType?->name ?? $d->label)
            ->filter()
            ->unique()
            ->values();

        return $heads->isNotEmpty() ? $heads->implode(', ') : 'Fees Collection';
    }
}

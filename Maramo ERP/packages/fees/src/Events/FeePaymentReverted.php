<?php

namespace Classivo\Fees\Events;

use Classivo\Fees\Models\FeePayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * FeePaymentReverted — fired after a whole receipt is reverted. A listener that
 * posted the receipt should post the matching contra entry.
 */
class FeePaymentReverted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $paymentId,
        public string $receiptNo,
        public int $studentId,
        public float $amount,
        public string $date,
        public string $head,
        public ?int $revertedBy,
        public ?string $reason,
        public int $schoolId,
        public int $branchId,
        public ?int $sessionId,
    ) {}

    public static function fromPayment(FeePayment $payment): self
    {
        return new self(
            paymentId: (int) $payment->id,
            receiptNo: (string) $payment->receipt_no,
            studentId: (int) $payment->student_id,
            amount: (float) $payment->paid_amount,
            date: $payment->payment_date?->toDateString() ?? now()->toDateString(),
            head: FeePaymentRecorded::fromPayment($payment)->head,
            revertedBy: $payment->reverted_by !== null ? (int) $payment->reverted_by : null,
            reason: $payment->revert_reason,
            schoolId: (int) $payment->school_id,
            branchId: (int) $payment->branch_id,
            sessionId: $payment->session_id !== null ? (int) $payment->session_id : null,
        );
    }
}

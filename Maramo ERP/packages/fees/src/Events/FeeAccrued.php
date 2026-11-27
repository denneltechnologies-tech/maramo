<?php

namespace Classivo\Fees\Events;

use Classivo\Fees\Services\FeeAccrualService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * FeeAccrued — fired when a late-payment fine is accrued onto a student's due
 * (by {@see FeeAccrualService}). `delta` is the change
 * in the allocation's fine since the previous accrual (it may be negative when
 * a fine is recomputed downwards or waived). Plain scalars only, so a closed
 * accounting module can book the receivable without importing this package.
 */
class FeeAccrued
{
    use Dispatchable;

    public function __construct(
        public int $allocationId,
        public int $studentId,
        public float $fineAmount,
        public float $delta,
        public string $date,
        public int $schoolId,
        public ?int $branchId,
        public ?int $sessionId,
    ) {}
}

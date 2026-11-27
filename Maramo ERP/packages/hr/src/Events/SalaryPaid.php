<?php

namespace Classivo\Hr\Events;

use Carbon\CarbonInterface;
use Classivo\Hr\Models\Payroll;
use Classivo\Hr\Services\PayrollService;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * SalaryPaid — the LOOSELY-COUPLED finance seam for Phase 5 (HR & Payroll).
 *
 * Dispatched by {@see PayrollService::markPaid()} whenever a payroll is
 * marked PAID. Phase 4B (Accounting) — built independently in another worktree — can
 * register a listener that posts a salary expense to the ledger WITHOUT this module
 * ever referencing an accounting model. See INTEGRATION.md for the
 * listener-registration snippet.
 *
 * Payload (per spec): staff_id, amount (net salary paid), month ("YYYY-MM"), date.
 */
class SalaryPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $staffId,
        public float $amount,
        public string $month,
        public CarbonInterface $date,
        public ?int $payrollId = null,
    ) {}

    /** Build the event from a paid payroll. */
    public static function fromPayroll(Payroll $payroll): self
    {
        return new self(
            staffId: (int) $payroll->staff_id,
            amount: (float) $payroll->net_salary,
            month: $payroll->period_key,
            date: $payroll->paid_at ?? now(),
            payrollId: (int) $payroll->id,
        );
    }
}

<?php

namespace Classivo\Hr\Events;

use Classivo\Hr\Models\Payroll;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * SalaryPaying — dispatched by PayrollService::markPaid() BEFORE a payroll is
 * paid and locked. It is a veto seam: a listener (for example an accounting
 * module with closed periods, or a cash-book day-close check) may throw an
 * exception to refuse the payment. The HR package has no accounting dependency;
 * it only emits this event and {@see SalaryPaid}.
 */
class SalaryPaying
{
    use Dispatchable;

    public function __construct(
        public Payroll $payroll,
        public ?string $paymentMode,
        public string $date,
    ) {}
}

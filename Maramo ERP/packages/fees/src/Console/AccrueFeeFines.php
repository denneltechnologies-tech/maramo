<?php

namespace Classivo\Fees\Console;

use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Services\FeeAccrualService;
use Illuminate\Console\Command;

/**
 * fees:accrue-fines — charge every overdue fee line the late fine its own frozen
 * rule supports as of today. Idempotent by arithmetic: a second run the same day
 * adds nothing. Schedule it nightly. Each charge fires FeeAccrued.
 */
class AccrueFeeFines extends Command
{
    protected $signature = 'fees:accrue-fines
        {--dry-run : Compute without writing}
        {--school= : Only this school id}';

    protected $description = 'Accrue late-payment fines onto overdue fee lines (idempotent; --dry-run computes only).';

    public function handle(FeeAccrualService $accrual): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $schoolId = $this->option('school') === null ? null : (int) $this->option('school');
        $lines = 0;
        $total = 0.0;

        $accrual->dueAllocations(now(), $schoolId)
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($accrual, $dryRun, &$lines, &$total): void {
                foreach ($chunk as $allocation) {
                    /** @var FeeStudentAllocation $allocation */
                    $added = $accrual->accrue($allocation, now(), $dryRun);

                    if ($added > 0) {
                        $lines++;
                        $total += $added;
                    }
                }
            });

        $this->info(sprintf('%sFines accrued on %d line(s), total %s.', $dryRun ? '[dry run] ' : '', $lines, number_format($total, 2)));

        return self::SUCCESS;
    }
}

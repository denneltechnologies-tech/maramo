<?php

namespace Classivo\Fees\Support;

use Classivo\Fees\Contracts\CollectionGuard;

/** Default {@see CollectionGuard}: no period locks, every date is open. */
class NullCollectionGuard implements CollectionGuard
{
    public function assertMayRecord(string $date, int $branchId, string $mode): void
    {
        // Intentionally empty.
    }
}

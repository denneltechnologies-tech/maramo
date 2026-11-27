<?php

namespace Classivo\Fees\Contracts;

use Classivo\Fees\Support\NullCollectionGuard;

/**
 * CollectionGuard — asked before any receipt is written. The default binding
 * ({@see NullCollectionGuard}) allows everything. A
 * separate accounting module can bind its own implementation to refuse money
 * dated into a closed accounting period or a closed cash book, by throwing a
 * RuntimeException with a message the cashier can read.
 */
interface CollectionGuard
{
    /**
     * @throws \RuntimeException when money may not be recorded on that date
     */
    public function assertMayRecord(string $date, int $branchId, string $mode): void;
}

<?php

namespace Classivo\Fees\Exceptions;

use Classivo\Fees\Events\FeePaymentRecorded;
use Classivo\Fees\Services\FeeCollectionService;
use RuntimeException;

/**
 * Raised when a posting path asks {@see FeeCollectionService::collect()}
 * to mint a receipt for nothing at all.
 *
 * WHY THIS IS AN EXCEPTION AND NOT A NO-OP
 * The old shape was worse than either: Collect Fees called collect() first —
 * which minted a receipt sequence, committed the FeePayment row and fired
 * {@see FeePaymentRecorded} — and only THEN force-deleted the row if
 * it turned out nothing had been collected. By that point the receipt number was
 * permanently burned (a hole an auditor flags), a zero-value ledger entry had
 * been posted, and the guardian had already been sent "We have received 0.00
 * towards fees."
 *
 * A receipt number is a scarce, ordered, audited resource. Nothing may be minted
 * speculatively and cleaned up afterwards, so the check has to happen BEFORE the
 * transaction opens — and a service cannot "return no receipt" from a method
 * whose whole contract is to return one. Callers that can be handed an empty
 * basket by a human (the Collect Fees screen, the admin API) check first and say
 * so in their own words; this is the invariant the paths that cannot check —
 * a gateway webhook replaying stored lines, an approved offline payment of zero
 * — hit instead of writing a meaningless receipt.
 */
class NothingToCollectException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Nothing to collect — a receipt needs at least one line carrying an amount, a discount or a fine.');
    }
}

<?php

namespace Classivo\Fees\Observers;

use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Services\ConcessionService;

/**
 * FeeStudentAllocationObserver — makes a RECURRING concession actually recur.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY A MODEL OBSERVER AND NOT A HOOK IN FeeAllocationService
 * ─────────────────────────────────────────────────────────────────────────────
 * `FeeAllocationService::allocate()` is today the only writer of
 * `fee_student_allocations` — the bulk class sweep, the admission wizard, the
 * per-student assign and the seeders all funnel through its one `firstOrCreate()`.
 * A hook there would work, and would be invisible to any future writer that does
 * not go through it. A concession that silently stops applying because someone
 * added a second allocation path is precisely the failure this feature exists to
 * end, so the hook is put on the MODEL, where nothing can route around it.
 *
 * It is also the idiom this codebase already uses for exactly this shape of
 * problem: {@see ExpenseObserver} and {@see IncomeObserver} keep the ledger in step
 * with a money row the same way.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ONLY ON CREATE, AND ONLY FOR REAL RECURRING GRANTS
 * ─────────────────────────────────────────────────────────────────────────────
 * `created` and nothing else. An allocation whose amount is later EDITED does not
 * re-attach: the cycle already raised against it is a real, decided grant, and
 * silently regranting a concession because a clerk corrected a figure would stack
 * two concessions on one line. Correcting the concession is the register's job, in
 * the open, with an actor's name on it.
 *
 * The whole decision — which instructions are live, what a cycle is worth, whether
 * one has already been raised — belongs to {@see ConcessionService::attachRecurringTo()},
 * which swallows its own failures: raising the fee line is the primary act and must
 * never be broken by a concession that could not attach.
 *
 * NOTE: seeders run with model events muted (DatabaseSeeder uses
 * WithoutModelEvents), so seeded allocations attach nothing — the same caveat
 * ExpenseObserver carries, and the same reason the fee seeders apply their
 * concessions explicitly.
 */
class FeeStudentAllocationObserver
{
    public function __construct(private readonly ConcessionService $concessions) {}

    public function created(FeeStudentAllocation $allocation): void
    {
        $this->concessions->attachRecurringTo($allocation);
    }
}

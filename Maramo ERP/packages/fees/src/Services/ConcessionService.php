<?php

namespace Classivo\Fees\Services;

use Classivo\Fees\Models\FeeDiscount;
use Classivo\Fees\Models\FeeDiscountStudent;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Observers\FeeStudentAllocationObserver;
use Classivo\Fees\Support\DiscountApprovalPolicy;
use Classivo\Students\Models\Student;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ConcessionService — granting a fee concession, and keeping a RECURRING one going.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE PROBLEM THIS SOLVES
 * ─────────────────────────────────────────────────────────────────────────────
 * A concession applied ONCE, by hand, reaches only the allocations that happen to
 * exist at that instant. So a staff-ward or
 * sibling concession, which is a standing fact about a family for years, had to be
 * re-found and re-typed against every new fee line the school raised. In practice
 * that means it is applied in April, forgotten in July, and the school discovers in
 * March that it has been over-billing a member of its own staff.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A STANDING INSTRUCTION, AND THE CYCLES IT RAISES
 * ─────────────────────────────────────────────────────────────────────────────
 * A recurring grant is a row in the concession register that names NO allocation
 * and moves no money by itself. Every time a fee line is raised for the student,
 * {@see attachRecurringTo()} spawns a one-time CHILD of it against that line and
 * applies it. Two properties fall out of that shape and both are deliberate:
 *
 *   1. THE REGISTER STAYS AUDITABLE. The standing instruction and every cycle it
 *      produced are separate rows, so "who holds which concession" and "what came
 *      off which bill" are both answerable. A single self-updating row would answer
 *      neither.
 *   2. A CYCLE IS NOT RE-APPROVED. The children of an approved standing instruction
 *      are born approved and apply immediately. Re-asking the principal every month
 *      for a concession they already agreed to is exactly the re-typing this
 *      feature exists to remove; the decision was taken once, on the instruction,
 *      and {@see DiscountApprovalPolicy::breachOfShare()} is what makes taking it
 *      once sufficient — a percentage instruction is weighed as a percentage, so
 *      the approver knew what they were agreeing to for every future cycle.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHERE THE THRESHOLD IS APPLIED
 * ─────────────────────────────────────────────────────────────────────────────
 * Exactly once, in {@see grant()}, which is the only way a register row is created
 * outside the pre-existing admission path. Over the school's limit → the row is
 * born `pending` and reduces nothing until an approvals screen (or the register's
 * own Approve action) decides it. Within the limit → born approved and applied on
 * the spot, which is the behaviour this install has always had.
 *
 * The admission path is NOT routed through here, on purpose: it is a working,
 * tested writer, its rows are born approved by the column default,
 * and re-pointing it would be a change to another module's service for no gain to
 * this feature. Route it here if admission-time concessions should be gated too.
 */
class ConcessionService
{
    /**
     * Grant a concession and — when it is within the school's limits — apply it.
     *
     * `$data` takes: student_id, fee_discount_id (nullable — an ad-hoc concession
     * with no catalogue entry is legitimate), fee_student_allocation_id (nullable;
     * always null for a recurring instruction), amount (nullable; computed from the
     * catalogue entry when omitted), reason, source, recurrence, valid_from,
     * valid_to.
     *
     * @param  array<string, mixed>  $data
     */
    public function grant(array $data, ?User $actor = null): FeeDiscountStudent
    {
        $actor ??= auth()->user();

        return DB::transaction(function () use ($data, $actor): FeeDiscountStudent {
            $student = $data['student'] ?? Student::query()->findOrFail($data['student_id']);
            $recurrence = $data['recurrence'] ?? FeeDiscountStudent::RECURRENCE_ONE_TIME;
            $recurring = $recurrence === FeeDiscountStudent::RECURRENCE_RECURRING;

            $discount = ! empty($data['fee_discount_id'])
                ? FeeDiscount::query()->find($data['fee_discount_id'])
                : null;

            // A standing instruction never names one line — it applies to all of
            // them, through its children. Accepting an allocation id here would
            // produce a row that is both a template and a grant, which nothing
            // downstream could interpret.
            $allocation = ! $recurring && ! empty($data['fee_student_allocation_id'])
                ? FeeStudentAllocation::query()->find($data['fee_student_allocation_id'])
                : null;

            $amount = $this->resolveAmount($data, $discount, $allocation, $recurring);
            $breach = $this->breachFor($amount, $discount, $allocation, $recurring);

            $grant = new FeeDiscountStudent([
                'school_id' => $student->school_id,
                'branch_id' => $student->branch_id,
                'fee_discount_id' => $discount?->id,
                'student_id' => $student->id,
                'fee_student_allocation_id' => $allocation?->id,
                'amount' => $amount,
                'reason' => $data['reason'] ?? null,
                'source' => $data['source'] ?? FeeDiscountStudent::SOURCE_MANUAL,
                'recurrence' => $recurrence,
                'parent_id' => $data['parent_id'] ?? null,
                'valid_from' => $data['valid_from'] ?? null,
                'valid_to' => $data['valid_to'] ?? null,
                'assigned_by' => $actor?->getAuthIdentifier(),
                // The gate. Over the limit and the row is a REQUEST: it reduces
                // nothing, and the student's balance still shows the full amount.
                'approval_status' => $breach !== null
                    ? FeeDiscountStudent::STATUS_PENDING
                    : FeeDiscountStudent::STATUS_APPROVED,
                'status' => 'assigned',
            ]);

            $grant->save();

            // Approved on creation → the money moves now. apply() is a no-op for a
            // recurring instruction (no allocation) and for a pending one, so this
            // one call is correct for all three shapes.
            $grant->apply();

            return $grant;
        });
    }

    /**
     * Attach every live recurring concession the student holds to a NEWLY CREATED
     * allocation. Returns the number of cycles raised.
     *
     * Called from {@see FeeStudentAllocationObserver}, which fires on
     * the model rather than in {@see FeeAllocationService::allocate()} because that
     * service is not the only conceivable writer and a model event cannot be routed
     * around. Every current path — the bulk class sweep, the admission wizard, the
     * per-student assign, the seeders — funnels through one `firstOrCreate()`, so
     * one hook covers all of them.
     *
     * IDEMPOTENT. A cycle that already exists for this (instruction, allocation)
     * pair is skipped, so re-running an assignment sweep cannot stack concessions on
     * the same line. This matters because the sweep's own idempotency is
     * `wasRecentlyCreated`, and an allocation that already existed does not fire
     * `created` at all — but a restored soft-deleted one can.
     */
    public function attachRecurringTo(FeeStudentAllocation $allocation): int
    {
        $raised = 0;

        // NEVER let a concession failure break an assignment. Raising a fee line is
        // the primary act; a concession that could not attach is a visible gap in
        // the register (and in the log), whereas a half-created allocation is a
        // student who cannot be billed at all. Same reasoning as the try/catch
        // around StudentAdmissionService::allocateFees().
        try {
            $standing = FeeDiscountStudent::query()
                ->with('discount')
                ->where('student_id', $allocation->student_id)
                ->recurring()
                ->activeOn()
                ->get();

            foreach ($standing as $instruction) {
                // The catalogue window is checked in PHP, not SQL — see the WHY on
                // FeeDiscountStudent::scopeActiveOn().
                if (! $instruction->isActiveOn()) {
                    continue;
                }

                if ($this->alreadyRaised($instruction, $allocation)) {
                    continue;
                }

                $amount = $this->cycleAmount($instruction, $allocation);

                if ($amount <= 0) {
                    continue;
                }

                $child = new FeeDiscountStudent([
                    'school_id' => $allocation->school_id,
                    'branch_id' => $allocation->branch_id,
                    'fee_discount_id' => $instruction->fee_discount_id,
                    'student_id' => $allocation->student_id,
                    'fee_student_allocation_id' => $allocation->id,
                    'amount' => $amount,
                    'reason' => $instruction->reason,
                    'source' => FeeDiscountStudent::SOURCE_RECURRING,
                    'recurrence' => FeeDiscountStudent::RECURRENCE_ONE_TIME,
                    'parent_id' => $instruction->id,
                    'valid_from' => $instruction->valid_from,
                    'valid_to' => $instruction->valid_to,
                    'assigned_by' => $instruction->assigned_by,
                    // Inherited, not re-decided — see the class docblock.
                    'approval_status' => FeeDiscountStudent::STATUS_APPROVED,
                    'approved_by_id' => $instruction->approved_by_id,
                    'approved_at' => $instruction->approved_at,
                    'status' => 'assigned',
                ]);

                $child->save();
                $child->apply();

                $raised++;
            }
        } catch (\Throwable $e) {
            Log::warning('Recurring concession attach failed: '.$e->getMessage(), [
                'allocation' => $allocation->id,
                'student' => $allocation->student_id,
            ]);
        }

        return $raised;
    }

    /**
     * Apply a standing instruction to the student's ALREADY-OPEN allocations.
     *
     * The observer only catches lines raised from now on, which leaves the ordinary
     * case — "grant the staff-ward concession, and yes, this term too" — needing a
     * second click. This is that click, offered as an action on the register rather
     * than done silently at grant time: retro-applying a concession to bills a
     * family may have already been shown is a decision, not a default.
     *
     * Only allocations with something still owed are touched; a settled line is
     * history and a concession cannot be granted backwards against money already
     * taken (that is what a refund is for).
     */
    public function applyStandingToOpenAllocations(FeeDiscountStudent $instruction): int
    {
        if (! $instruction->isRecurring() || ! $instruction->isActiveOn()) {
            return 0;
        }

        $raised = 0;

        $allocations = FeeStudentAllocation::query()
            ->where('student_id', $instruction->student_id)
            ->where('status', '!=', 'paid')
            ->get();

        foreach ($allocations as $allocation) {
            if ($allocation->balance() <= 0) {
                continue;
            }

            $raised += $this->attachRecurringTo($allocation);
        }

        return $raised;
    }

    // ----------------------------------------------------------------- internals

    /** Has this instruction already raised a cycle against this allocation? */
    private function alreadyRaised(FeeDiscountStudent $instruction, FeeStudentAllocation $allocation): bool
    {
        return FeeDiscountStudent::query()
            ->where('parent_id', $instruction->id)
            ->where('fee_student_allocation_id', $allocation->id)
            ->exists();
    }

    /**
     * What one cycle of a standing instruction is worth against one fee line.
     *
     * A catalogue-backed instruction is RE-COMPUTED per cycle, which is the whole
     * value of a percentage concession: 50% of a ₹12,000 term is not 50% of a
     * ₹3,000 one, and copying the instruction's stored figure forward would grant
     * the wrong money every time the fee changed. An ad-hoc instruction with no
     * catalogue entry has only its own figure, so that is carried across.
     */
    private function cycleAmount(FeeDiscountStudent $instruction, FeeStudentAllocation $allocation): float
    {
        $discount = $instruction->discount;

        $amount = $discount instanceof FeeDiscount
            ? $discount->computeFor((float) $allocation->amount)
            : round((float) $instruction->amount, 2);

        return round(min($amount, $allocation->balance()), 2);
    }

    /**
     * The money value of a grant being created.
     *
     * A caller may state it outright (the register's form lets an operator override
     * a catalogue figure — a half concession on a full-fee head is a real thing a
     * school does). Otherwise it is computed from the catalogue entry, against the
     * allocation's own amount for a one-time grant.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveAmount(
        array $data,
        ?FeeDiscount $discount,
        ?FeeStudentAllocation $allocation,
        bool $recurring,
    ): float {
        if (isset($data['amount']) && $data['amount'] !== null && $data['amount'] !== '') {
            return round(max(0.0, (float) $data['amount']), 2);
        }

        if (! $discount instanceof FeeDiscount) {
            return 0.0;
        }

        // A recurring PERCENTAGE instruction has no rupee value until a fee line
        // exists; 0 is the honest figure to store, and cycleAmount() computes the
        // real one each time. A recurring FIXED instruction does have one, and
        // storing it lets the register show what the family gets per line.
        if ($recurring) {
            return $discount->type === 'percent'
                ? 0.0
                : round((float) $discount->value, 2);
        }

        return $allocation !== null
            ? $discount->computeFor((float) $allocation->amount)
            : round((float) $discount->value, 2);
    }

    /**
     * Is this grant over the school's limit? Returns the reason, or null.
     *
     * A recurring percentage instruction is weighed as a PERCENTAGE (see
     * {@see DiscountApprovalPolicy::breachOfShare()}), because that is the only
     * figure that exists at the moment it is granted and it is the figure the
     * approver is actually agreeing to for every future cycle.
     */
    private function breachFor(
        float $amount,
        ?FeeDiscount $discount,
        ?FeeStudentAllocation $allocation,
        bool $recurring,
    ): ?string {
        if ($recurring && $discount instanceof FeeDiscount && $discount->type === 'percent') {
            return DiscountApprovalPolicy::breachOfShare((float) $discount->value);
        }

        // Base 0 for a grant with no fee line to measure against — the policy skips
        // its percentage test rather than dividing by nothing.
        return DiscountApprovalPolicy::breach($amount, (float) ($allocation?->amount ?? 0));
    }
}

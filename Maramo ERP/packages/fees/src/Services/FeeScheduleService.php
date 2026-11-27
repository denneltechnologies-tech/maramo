<?php

namespace Classivo\Fees\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Classivo\Fees\Models\FeeMaster;
use Illuminate\Support\Collection;

/**
 * FeeScheduleService — the two things a fee STRUCTURE does over time:
 *
 *   • {@see generate()} expands one recurring DEFINITION into its dated
 *     instalments, and re-expands it after a revision without disturbing money
 *     already billed.
 *   • {@see revise()} schedules a price change for a future date instead of
 *     editing a live row in place.
 *
 * *** WHY A DEFINITION AND NOT TWELVE HAND-TYPED ROWS ***
 * ---------------------------------------------------------------------------
 * Monthly billing in this system was twelve near-identical `fee_masters` rows per
 * class, written out by hand, so one grade's tuition is twelve rows whose
 * only difference is a due date. Nothing links them, so a mid-year revision means
 * finding and editing each one, and getting it wrong on one row is invisible.
 *
 * A definition is ONE row (`is_schedule_parent`) carrying the cycle, the count and
 * the first due date; this service writes the instalments beneath it. The
 * instalments are ordinary fee masters — see the FeeMaster class docblock for why
 * that matters — so nothing downstream had to learn a new billable identity.
 *
 * *** WHAT MAKES RE-RUNNING SAFE ***
 * An instalment that has already been allocated to a student is a DEBT SOMEBODY
 * OWES, not a draft. Regeneration therefore:
 *   • creates instalments that do not exist yet;
 *   • updates the price and date of instalments that exist but have NOT been
 *     allocated to anybody;
 *   • leaves ALLOCATED instalments exactly as they are, and reports them, so a
 *     revision never rewrites a bill that has gone out;
 *   • retires surplus instalments (the count was reduced) only when unallocated,
 *     and only through FeeMaster::delete(), so the lifecycle guards decide.
 *
 * That last point is the rule throughout this class: it never writes
 * `deleted_at` itself and never bypasses a guard. If a master refuses to be
 * archived because money has been taken against it, this service reports it and
 * moves on.
 */
class FeeScheduleService
{
    /**
     * Expand (or re-expand) a schedule definition into its dated instalments.
     *
     * Returns a tally: how many instalments were created, updated, retired, and
     * how many were left alone because they are already billed.
     *
     * @return array{created: int, updated: int, retired: int, skipped: int}
     */
    public function generate(FeeMaster $definition): array
    {
        $tally = ['created' => 0, 'updated' => 0, 'retired' => 0, 'skipped' => 0];

        if (! $definition->isScheduleParent()) {
            return $tally;
        }

        $count = $this->installmentCount($definition);
        $start = $definition->first_due_date ?? $definition->due_date;

        if ($count < 1 || $start === null) {
            return $tally;
        }

        $interval = FeeMaster::BILLING_INTERVAL_MONTHS[$definition->billing_frequency] ?? null;

        if ($interval === null) {
            // 'one_time' is not a schedule; it is a single dated charge and the
            // definition row itself would be the only instalment. Refusing here
            // rather than generating one child keeps "a parent is never billed"
            // and "a one-time master is billed" from ever meeting.
            return $tally;
        }

        // Existing instalments, keyed by number, read unpinned exactly as the
        // lifecycle cascade reads them — a definition and its instalments must be
        // found together regardless of which session the request is pinned to.
        $existing = $definition->allChildren()->get()->keyBy('installment_number');

        for ($number = 1; $number <= $count; $number++) {
            // addMonthsNoOverflow: a schedule starting 31 January must fall on 28
            // February, not spill into 3 March and then drift for the rest of the
            // year. Due dates are what parents are told; they do not get to slide.
            $due = $start->copy()->addMonthsNoOverflow($interval * ($number - 1));

            $child = $existing->get($number);

            if ($child === null) {
                $this->createInstallment($definition, $number, $due);
                $tally['created']++;

                continue;
            }

            // Already billed to somebody: leave the debt alone. Both live and
            // archived allocations count — an archived one is recoverable history
            // that must still describe the price it was issued at.
            if ($child->allAllocations()->withTrashed()->exists()) {
                $tally['skipped']++;

                continue;
            }

            $child->forceFill($this->installmentAttributes($definition, $number, $due))->save();
            $tally['updated']++;
        }

        // The count was reduced: retire the instalments past the new end.
        foreach ($existing as $number => $child) {
            if ((int) $number <= $count) {
                continue;
            }

            if ($child->allAllocations()->withTrashed()->exists()) {
                $tally['skipped']++;

                continue;
            }

            // delete(), never a direct update: FeeMaster's guards get the final
            // say, and its cascade retires the unpaid allocations with it.
            if ($child->delete()) {
                $tally['retired']++;
            } else {
                $tally['skipped']++;
            }
        }

        return $tally;
    }

    /**
     * Schedule a price revision. Returns the NEW master, which takes over on
     * `$from`; the original is closed off the day before.
     *
     * *** WHY NOT JUST EDIT THE AMOUNT ***
     * Editing a master's `amount` in place changes what every FUTURE allocation
     * costs — which is usually what the operator wanted — but it also silently
     * rewrites the price shown for the master behind dues ALREADY issued, in every
     * listing that joins back to it, with no record that a different number was
     * ever charged. And it cannot be dated: the new price applies from the moment
     * of the save, so "the transport fee goes up in July" has to be remembered and
     * done by hand in July.
     *
     * A revision is instead a SECOND master covering the same thing from a later
     * date. {@see FeeAllocationService::resolveMastersFor()} picks whichever row is
     * effective on the day the allocation is made, so the changeover happens by
     * itself, and both prices remain on the record.
     *
     * The two rows deliberately share group + type + class + section + category,
     * so they collide on the resolver's billing key. That makes them ONE CHARGE
     * priced twice, and the resolver hands a student whichever is effective on the
     * day — but the collision is only settled at RESOLUTION time. Allocation has to
     * settle it too, or a sweep that runs before the changeover and again after it
     * raises both: {@see Classivo\Fees\Services\FeeAllocationService::sweepClass()} is where
     * that is enforced.
     *
     * *** THE REPLACEMENT'S WINDOW IS DERIVED, NEVER COPIED BLIND ***
     * `effective_to` is inherited so that revising a price which was already due to
     * END on a date keeps that end date. But it is only inheritable when it is
     * still IN THE FUTURE relative to the changeover. Copying it unconditionally
     * off an already-SUPERSEDED row minted a replacement whose window ran
     * BACKWARDS — "in force from 01 Dec 2026 until 30 Sep 2026" — which
     * {@see FeeMaster::isEffectiveOn()} is false for on every date there is. The
     * operator got a green "₹1,500 takes over on 01 Dec" and the price silently
     * never changed. Same for the source: a row already closed on an EARLIER date
     * must not have its window pushed forward, which would re-open a dead price for
     * the weeks in between.
     */
    public function revise(FeeMaster $master, float $amount, CarbonInterface $from, ?array $overrides = null): FeeMaster
    {
        $from = $from->copy()->startOfDay();
        $closedAt = $master->effective_to?->copy()->startOfDay();

        // Already closed BEFORE the changeover: there is no window left to hand on,
        // so the replacement opens ended-by-nothing rather than ended-in-the-past.
        $inheritedTo = ($closedAt !== null && $closedAt->gte($from))
            ? $closedAt->toDateString()
            : null;

        $replacement = $master->replicate([
            'parent_fee_master_id',
            'installment_number',
            'created_at',
            'updated_at',
            'deleted_at',
        ]);

        $replacement->forceFill(array_merge([
            'amount' => round($amount, 2),
            'effective_from' => $from->toDateString(),
            'effective_to' => $inheritedTo,
            // A revision of one instalment is a revision of THAT instalment, so
            // the link back to the definition is kept when there is one.
            'parent_fee_master_id' => $master->parent_fee_master_id,
            'installment_number' => $master->installment_number,
        ], $overrides ?? []))->save();

        // Close the outgoing row the day before the new one opens, so the two
        // windows touch without overlapping. An overlap would not break the
        // resolver (it tie-breaks on the later effective_from) but it would make
        // the schedule unreadable to a human, which is half the point of dating.
        //
        // A row that already closed EARLIER than that keeps its own end date:
        // moving it forward would extend a price that has already stopped
        // applying, over days it was never in force for.
        if ($closedAt === null || $closedAt->gte($from)) {
            $master->forceFill(['effective_to' => $from->copy()->subDay()->toDateString()])->save();
        }

        return $replacement;
    }

    /**
     * Is this row already superseded — closed off on a date that has passed?
     * Revising one is almost always a mis-click on a list that shows the old price
     * and the live price as two near-identical lines; the row the operator means is
     * the one in force. {@see Classivo\Fees\Filament\Resources\FeeMasters\Actions\FeeScheduleActions::revise()}
     * refuses it and says which row to revise instead.
     */
    public function isSuperseded(FeeMaster $master, ?CarbonInterface $asOf = null): bool
    {
        $asOf = ($asOf ?? Carbon::now())->copy()->startOfDay();

        return $master->effective_to !== null
            && $master->effective_to->copy()->startOfDay()->lt($asOf);
    }

    /**
     * The row that IS in force on `$asOf` for the same charge this master prices —
     * same billing key, same applicability, effective today. What the operator
     * should have been revising when they picked a superseded row.
     */
    public function inForceSibling(FeeMaster $master, ?CarbonInterface $asOf = null): ?FeeMaster
    {
        return FeeMaster::query()
            ->where('fee_group_id', $master->fee_group_id)
            ->where('fee_type_id', $master->fee_type_id)
            ->where('session_id', $master->session_id)
            ->when(
                $master->installment_number === null,
                fn ($q) => $q->whereNull('installment_number'),
                fn ($q) => $q->where('installment_number', $master->installment_number),
            )
            ->where('class_id', $master->class_id)
            ->where('section_id', $master->section_id)
            ->where('student_category_id', $master->student_category_id)
            ->whereKeyNot($master->getKey())
            ->where('status', true)
            ->effectiveOn($asOf)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The dates a definition's instalments fall on — the preview a form can show
     * before anything is written.
     *
     * @return Collection<int, Carbon>
     */
    public function installmentDates(FeeMaster $definition): Collection
    {
        $start = $definition->first_due_date ?? $definition->due_date;
        $interval = FeeMaster::BILLING_INTERVAL_MONTHS[$definition->billing_frequency] ?? null;

        $count = $this->installmentCount($definition);

        if ($start === null || $interval === null || $count < 1) {
            return collect();
        }

        return collect(range(1, $count))
            ->map(fn (int $number): Carbon => $start->copy()->addMonthsNoOverflow($interval * ($number - 1)));
    }

    /**
     * How many instalments a definition asks for, defaulting to the number that
     * fills one year of its cycle (12 monthly, 4 quarterly, 2 half-yearly, 1
     * annual) when the operator left the field empty.
     */
    public function installmentCount(FeeMaster $definition): int
    {
        $stated = (int) ($definition->installment_count ?? 0);

        if ($stated > 0) {
            return min($stated, 60);
        }

        $interval = FeeMaster::BILLING_INTERVAL_MONTHS[$definition->billing_frequency] ?? null;

        return $interval === null ? 0 : (int) (12 / $interval);
    }

    private function createInstallment(FeeMaster $definition, int $number, Carbon $due): FeeMaster
    {
        $child = new FeeMaster;
        $child->forceFill($this->installmentAttributes($definition, $number, $due))->save();

        return $child;
    }

    /**
     * One instalment's full attribute set, copied off the definition. Everything
     * that prices or scopes the charge comes from the parent, so re-running the
     * generator after a revision brings every unbilled instalment back into line
     * with the definition in one pass.
     *
     * @return array<string, mixed>
     */
    private function installmentAttributes(FeeMaster $definition, int $number, Carbon $due): array
    {
        return [
            'school_id' => $definition->school_id,
            'branch_id' => $definition->branch_id,
            'session_id' => $definition->session_id,
            'fee_group_id' => $definition->fee_group_id,
            'fee_type_id' => $definition->fee_type_id,
            'class_id' => $definition->class_id,
            'section_id' => $definition->section_id,
            'student_category_id' => $definition->student_category_id,
            // Coerced rather than copied straight across: a definition written by
            // tinker, a seeder or the API can carry no value at all for a column
            // whose DEFAULT the database would have supplied, and passing that
            // null on to the child's INSERT is a NOT NULL violation. The child
            // must land on the same value the definition itself ended up with.
            'amount' => (float) $definition->amount,
            'due_date' => $due->toDateString(),
            // Informational on a child: it says which cycle produced this row.
            // Nothing branches on it — the allocation path filters on
            // `is_schedule_parent`, which is what keeps a definition unbillable.
            'billing_frequency' => $definition->billing_frequency,
            'installment_count' => null,
            'first_due_date' => null,
            'is_schedule_parent' => false,
            'parent_fee_master_id' => $definition->getKey(),
            'installment_number' => $number,
            'effective_from' => $definition->effective_from?->toDateString(),
            'effective_to' => $definition->effective_to?->toDateString(),
            'fine_type' => $definition->fine_type ?? 'none',
            'fine_amount' => (float) $definition->fine_amount,
            'fine_frequency' => $definition->fine_frequency ?? 'one_time',
            'fine_after_days' => (int) $definition->fine_after_days,
            'fee_fine_id' => $definition->fee_fine_id,
            'is_optional' => (bool) $definition->is_optional,
            'status' => (bool) $definition->status,
        ];
    }
}

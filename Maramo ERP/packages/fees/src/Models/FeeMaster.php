<?php

namespace Classivo\Fees\Models;

use Carbon\CarbonInterface;
use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Tenancy\Scopes\BranchScope;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Core\Tenancy\Scopes\TenantScope;
use Classivo\Fees\Services\FeeScheduleService;
use Classivo\Fees\Support\StudentCategories;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeMaster — a priced fee line: fee group + fee type +
 * amount + due date + built-in fine rule, optionally scoped to one class (null = all
 * classes), one section and/or one student category. Allocated to students as
 * {@see FeeStudentAllocation}. Tenant + branch + session scoped, soft-deleting. Money = decimal(15,2).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A MASTER IS NOW ONE OF TWO THINGS
 * ─────────────────────────────────────────────────────────────────────────────
 *  • A BILLABLE LINE — what a master has always been. One amount, one due date,
 *    allocated to students.
 *  • A SCHEDULE DEFINITION (`is_schedule_parent`) — "₹2,000 a month, ten times,
 *    from 10 April". It is NEVER allocated to anybody. {@see FeeScheduleService}
 *    expands it into billable CHILD masters (`parent_fee_master_id` +
 *    `installment_number`), one per instalment, and those are what students owe.
 *
 * The alternative was one master per month typed out by hand, which is how
 * many schools start: twelve near-identical rows per class whose only
 * difference is a due date, and twelve rows to find and edit for one revision.
 * Children being ordinary masters is what keeps every downstream surface
 * (allocations, receipts, statements, reports, an API client) working unchanged.
 *
 * `billing_frequency` IS NOT `fine_frequency` — the first is how often the FEE is
 * billed, the second how often an overdue FINE accrues. They are independent.
 */
class FeeMaster extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    public const FINE_TYPES = [
        'none' => 'None',
        'fixed' => 'Fixed',
        'percent' => 'Percentage',
    ];

    public const FINE_FREQUENCIES = [
        'one_time' => 'One time',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
    ];

    /**
     * How often the FEE is billed. `one_time` is a single dated charge — every
     * master that existed before billing frequency did. The rest are definitions
     * that expand into instalments; the value is the STEP between due dates.
     */
    public const BILLING_FREQUENCIES = [
        'one_time' => 'One time',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'half_yearly' => 'Half-yearly',
        'annual' => 'Annual',
    ];

    /** Months between two consecutive instalments of each recurring cycle. */
    public const BILLING_INTERVAL_MONTHS = [
        'monthly' => 1,
        'quarterly' => 3,
        'half_yearly' => 6,
        'annual' => 12,
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'fee_group_id',
        'fee_type_id',
        'class_id',
        'section_id',
        'student_category_id',
        'amount',
        'due_date',
        'billing_frequency',
        'installment_count',
        'first_due_date',
        'is_schedule_parent',
        'parent_fee_master_id',
        'installment_number',
        'effective_from',
        'effective_to',
        'fine_type',
        'fine_amount',
        'fine_frequency',
        'fine_after_days',
        'fee_fine_id',
        'is_optional',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fine_amount' => 'decimal:2',
            'due_date' => 'date',
            'first_due_date' => 'date',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'fine_after_days' => 'integer',
            'installment_count' => 'integer',
            'installment_number' => 'integer',
            'is_schedule_parent' => 'boolean',
            'is_optional' => 'boolean',
            'status' => 'boolean',
        ];
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * RETIRING A MASTER MUST RETIRE THE DEBTS IT CREATED
     * ─────────────────────────────────────────────────────────────────────────
     * A fee master is a PRICE-LIST TEMPLATE; a {@see FeeStudentAllocation} is a
     * DEBT a student owes. Soft-deleting the template used to leave the debts
     * standing: archived test masters kept billing real students for fees that
     * no longer existed. {@see FeeStudentAllocation::feeMaster()} was
     * widened so an orphan can at least NAME itself — that repairs the display,
     * not the billing. These hooks stop the orphaning at the source, so it
     * cannot happen from Filament, a console command, a seeder or any future
     * caller that never goes near the panel.
     *
     * THE FOUR HOOKS
     *  • deleting()      refuses a delete that would strand money, and refuses a
     *                    re-delete of an already-trashed master.
     *  • softDeleted()   retires the unpaid allocations, stamping them with the
     *                    master's OWN `deleted_at` to the second.
     *  • restoring()     un-retires exactly the rows carrying that stamp.
     *  • forceDeleting() refuses to destroy a master that anything points at.
     *
     * A SCHEDULE DEFINITION MOVES WITH ITS INSTALMENTS. A parent's children are
     * billable masters in their own right, so archiving the definition and leaving
     * ten dated instalments standing is the same orphaning one storey up. Each
     * hook therefore extends over the children as well — and the deleting() guard
     * asks the paid-money question of the WHOLE schedule before any of it moves,
     * so the answer is all-or-nothing rather than half an archived schedule.
     *
     * WHY THE WRITE IS IN softDeleted() AND NOT IN deleting()
     * `restoring()` has to reunite EXACTLY the rows this cascade retired and
     * leave alone rows that were already archived beforehand. The only marker
     * available without a schema change is the `deleted_at` value itself, so the
     * retired rows must carry the master's stamp EXACTLY. In `deleting()` the
     * master has no stamp yet — Laravel's `runSoftDelete()` calls
     * `freshTimestamp()` afterwards, and `deleted_at` is a second-precision
     * TIMESTAMP column, so a timestamp computed early can land in the previous
     * second and silently break every later restore. `softDeleted` (the
     * `trashed` model event) fires immediately after the master's own UPDATE,
     * with the written value in hand, so the two stamps are equal by
     * construction. It also fires ONLY on a soft delete, never on a force
     * delete — which is precisely the audit-trail guarantee wanted below.
     *
     * WHY `paid_amount > 0` AND `paid_amount <= 0` RATHER THAN `= 0`
     * The guard and the cascade are exact complements, so every live allocation
     * is either refused-on or retired. Splitting them as `> 0` / `= 0` would
     * leave a (nonsensical, but possible) negative `paid_amount` in neither set
     * — silently orphaned, which is the bug this whole file exists to stop.
     */
    protected static function booted(): void
    {
        /**
         * GUARD — a master that money has been taken against may never be
         * archived. Its allocations carry receipts (see FeePaymentDetail), so
         * they can neither be retired (that hides a paid line from statements
         * and receipts) nor left behind (that is the orphan). The only correct
         * answer is to refuse; reverse the payments or set `status` false.
         *
         * Also refuses a SECOND delete of an already-trashed master. Laravel is
         * happy to re-run `runSoftDelete()` on a trashed row, which would move
         * `deleted_at` forward and desynchronise it from the allocations retired
         * under the previous stamp — leaving them unrestorable. A second delete
         * is meaningless anyway, so it is a no-op returning false.
         */
        static::deleting(function (FeeMaster $master): ?bool {
            // A force delete is judged by the forceDeleting hook below; by the
            // time it reaches here the decision is already made and the master
            // is (normally) trashed, so neither test below applies.
            if ($master->isForceDeleting()) {
                return null;
            }

            if ($master->trashed()) {
                return false;
            }

            if ($master->paidAllocations()->exists()) {
                return false;
            }

            // A schedule is judged whole: if ANY instalment has been paid
            // against, the definition stays. Archiving it and then finding the
            // cascade blocked halfway would leave a definition trashed with live
            // instalments underneath it — a schedule nobody can revise and
            // nobody can see.
            foreach ($master->allChildren()->get() as $child) {
                if ($child->paidAllocations()->exists()) {
                    return false;
                }
            }

            return null;
        });

        /**
         * CASCADE — retire the unpaid allocations under this master's own stamp.
         *
         * Cannot run twice: the query carries the SoftDeletingScope, so rows
         * that are already archived (whether by this cascade a moment ago or by
         * a person last month) are invisible to it and keep the `deleted_at`
         * they already have.
         */
        static::softDeleted(function (FeeMaster $master): void {
            $stamp = $master->getAttributes()[$master->getDeletedAtColumn()] ?? null;

            if ($stamp === null) {
                return;
            }

            $master->allAllocations()
                ->where('paid_amount', '<=', 0)
                ->update([
                    'deleted_at' => $stamp,
                    'updated_at' => $stamp,
                ]);

            // …then the instalments this definition generated, each through its
            // OWN delete() so it runs its own guard and its own allocation
            // cascade. Their stamps land in the same second as the parent's (or
            // the one after), which is what restoring() matches on below.
            foreach ($master->allChildren()->get() as $child) {
                $child->delete();
            }
        });

        /**
         * TWIN OF THE CASCADE — bring back exactly what the cascade retired.
         *
         * Without this, restoring a master and re-running Assign Fees creates
         * DUPLICATE allocations: FeeAllocationService::allocate() uses
         * firstOrCreate(), which reads through the soft-delete scope and so
         * cannot see the retired original, and `fee_alloc_unique` was
         * deliberately made soft-delete-aware, so the database happily accepts
         * the second row. The student is then billed twice.
         *
         * `restore()` fires `restoring` BEFORE it nulls `deleted_at`, so the
         * stamp is still readable here. Matching on it exactly is what keeps
         * rows archived BEFORE the master (a different stamp) archived.
         */
        static::restoring(function (FeeMaster $master): void {
            $stamp = $master->getAttributes()[$master->getDeletedAtColumn()] ?? null;

            // Restoring a master that is not trashed (or restoring twice) has
            // nothing to reunite — leave every allocation exactly as it is.
            if ($stamp === null) {
                return;
            }

            // If a live allocation for this master already exists for a student
            // — the duplicate this hook exists to prevent, created before it
            // existed — restoring the archived twin would collide on
            // `fee_alloc_unique` and 500. Skip those students; a duplicate is a
            // data-repair job for a human, not something to crash a restore.
            $alreadyLive = $master->allAllocations()->pluck('student_id')->all();

            $master->allAllocations()
                ->withTrashed()
                ->where('deleted_at', $stamp)
                ->when($alreadyLive !== [], fn (Builder $query) => $query->whereNotIn('student_id', $alreadyLive))
                ->update([
                    'deleted_at' => null,
                    'updated_at' => $master->freshTimestamp(),
                ]);

            // Bring back the instalments the cascade took with it, each through
            // its own restore() so its own allocations come back too.
            //
            // WHY `>=` AND NOT `=` HERE. The parent is stamped first and each
            // child a moment later, so a child's stamp is the parent's or the
            // next second — never earlier. An instalment archived BEFORE the
            // definition therefore carries a strictly smaller stamp and is
            // correctly left alone, which is the same "restore exactly what this
            // cascade retired" guarantee the allocation match above gives.
            $children = $master->allChildren()
                ->onlyTrashed()
                ->where('deleted_at', '>=', $stamp)
                ->get();

            foreach ($children as $child) {
                $child->restore();
            }
        });

        /**
         * NO FORCE DELETE WHILE ANYTHING POINTS AT IT.
         *
         * `fee_student_allocations.fee_master_id` is ON DELETE CASCADE, so a
         * force delete does not merely orphan the allocations — MySQL hard
         * deletes every one of them, paid ones included, in a statement Eloquent
         * never sees: no model events, no activity log, no soft-delete row left
         * to recover from. `fee_payment_details.fee_student_allocation_id` is
         * ON DELETE SET NULL, so the receipt lines survive the purge pointing at
         * nothing — money in the ledger that can no longer be explained.
         *
         * Trashed allocations count too: they are exactly the recoverable
         * history a force delete would silently burn.
         */
        static::forceDeleting(function (FeeMaster $master): ?bool {
            if ($master->allAllocations()->withTrashed()->exists()) {
                return false;
            }

            // Instalments count as well: `parent_fee_master_id` is ON DELETE SET
            // NULL, so purging a definition would not destroy its children — it
            // would cut them loose as a dozen unexplained dated masters nobody
            // can trace back to the schedule that made them.
            return $master->allChildren()->withTrashed()->exists() ? false : null;
        });
    }

    public function feeGroup(): BelongsTo
    {
        return $this->belongsTo(FeeGroup::class);
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** The section this price is limited to (null = every section of the class). */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** The student category this price is limited to (null = every category). */
    public function studentCategory(): BelongsTo
    {
        // Falls back to a plain model on `student_categories` when the students
        // package does not ship one; callers check StudentCategories::available().
        return StudentCategories::available()
            ? $this->belongsTo(StudentCategories::model())
            : $this->belongsTo(FeeType::class, 'student_category_id')->whereRaw('0 = 1');
    }

    /**
     * The NAMED fine rule this master uses instead of its inline columns, if any.
     * Soft-deleted rules still resolve: a master must keep naming the policy it
     * was authored under even after the policy is retired from the pick list.
     */
    public function feeFine(): BelongsTo
    {
        return $this->belongsTo(FeeFine::class)->withTrashed();
    }

    /** The schedule definition this instalment was generated from (null if none). */
    public function parentFeeMaster(): BelongsTo
    {
        return $this->belongsTo(FeeMaster::class, 'parent_fee_master_id')->withTrashed();
    }

    /** The generated instalments of this schedule definition, in due order. */
    public function installments(): HasMany
    {
        return $this->hasMany(FeeMaster::class, 'parent_fee_master_id')
            ->orderBy('installment_number');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FeeStudentAllocation::class);
    }

    /**
     * Every LIVE instalment generated from this definition, read outside the
     * request's tenancy pins — the exact counterpart of {@see allAllocations()},
     * and unpinned for the same reason: a cascade that only half-runs (because
     * the current session pin happens not to match) is worse than one that does
     * not run at all. Keyed on this master's own primary key, with the school
     * re-asserted, so it cannot reach another tenant's rows.
     */
    public function allChildren(): Builder
    {
        return static::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class, SessionScope::class])
            ->where('parent_fee_master_id', $this->getKey())
            ->when($this->school_id !== null, fn (Builder $query) => $query->where('school_id', $this->school_id));
    }

    /**
     * Every LIVE allocation made from this master, read outside the request's
     * tenancy pins. Add `->withTrashed()` for the archived ones too.
     *
     * WHY THE PINS COME OFF. This is not a listing ("which allocations may I
     * see right now?") — it is the set of debts that must move WITH the master,
     * and a cascade that only half-runs is worse than one that does not run at
     * all: it retires the allocations the current session/branch happens to be
     * pinned to and orphans the rest. Session is the live risk — an allocation
     * legitimately outlives the session pin (see FeeStatementService), while
     * the master stays in its own session, so under a mismatched pin the
     * cascade would find nothing at all.
     *
     * SAFETY. The query is keyed on this master's own primary key, which no
     * other tenant's allocation can point at (`fee_master_id` is a foreign key
     * into `fee_masters`), and the school is re-asserted explicitly below, so
     * removing the pins cannot reach another school's or campus's rows.
     */
    public function allAllocations(): Builder
    {
        return FeeStudentAllocation::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class, SessionScope::class])
            ->where('fee_master_id', $this->getKey())
            ->when($this->school_id !== null, fn (Builder $query) => $query->where('school_id', $this->school_id));
    }

    /** Live allocations of this master that money has already been taken against. */
    public function paidAllocations(): Builder
    {
        return $this->allAllocations()->where('paid_amount', '>', 0);
    }

    /**
     * How many live allocations have been paid against this master, and how much
     * has been collected on them — the two figures the delete guard reports.
     *
     * @return array{count: int, total: float}
     */
    public function paidAllocationSummary(): array
    {
        $row = $this->paidAllocations()
            ->toBase()
            ->selectRaw('COUNT(*) as allocation_count, COALESCE(SUM(paid_amount), 0) as paid_total')
            ->first();

        return [
            'count' => (int) ($row->allocation_count ?? 0),
            'total' => (float) ($row->paid_total ?? 0),
        ];
    }

    /**
     * This master's EFFECTIVE fine rule in the canonical shape, taken from the
     * named {@see FeeFine} when `fee_fine_id` is set and from the inline columns
     * when it is not.
     *
     * The two used to be separate engines that agreed on nothing (see the FeeFine
     * class docblock); this method is the single place that decides which of a
     * master's two possible authorings applies, and {@see FeeFine::evaluate()} is
     * the single place that turns the answer into money.
     *
     * @return array{type: string, amount: float, frequency: string, grace_days: int}
     */
    public function fineRule(): array
    {
        if ($this->fee_fine_id !== null && $this->relationLoaded('feeFine') === false) {
            $this->load('feeFine');
        }

        $named = $this->fee_fine_id !== null ? $this->feeFine : null;

        if ($named instanceof FeeFine) {
            return $named->toRule();
        }

        return FeeFine::normalise(
            $this->fine_type,
            $this->fine_frequency,
            (float) $this->fine_amount,
            (int) $this->fine_after_days,
        );
    }

    /**
     * The effective fine rule as the columns {@see FeeStudentAllocation} freezes it
     * into at allocation time. Named apart from the allocation's `fine_amount`
     * (which is MONEY CHARGED, not a rule) on purpose — see the migration
     * 2026_10_09_000003 docblock.
     *
     * @return array{fine_type: string, fine_rule_amount: float, fine_rule_frequency: string, fine_grace_days: int}
     */
    public function fineRuleSnapshot(): array
    {
        $rule = $this->fineRule();

        return [
            'fine_type' => $rule['type'],
            'fine_rule_amount' => $rule['amount'],
            'fine_rule_frequency' => $rule['frequency'],
            'fine_grace_days' => $rule['grace_days'],
        ];
    }

    /**
     * The fine this master's rule produces for its `amount` as of `$asOf`
     * (default: today). Returns 0 when there is no due date, no fine rule, or it
     * is not yet overdue.
     *
     * STILL HERE, AND STILL THE MASTER'S LIVE RULE. Nothing that bills a student
     * should call this any more — an issued due carries its OWN frozen rule and is
     * costed by {@see Classivo\Fees\Services\FeeAccrualService}. This remains for the
     * places that legitimately ask about the TEMPLATE rather than a debt: the
     * admin API's fee-master reference listing, and a preview of what a rule would
     * cost. Callers that were using it as a stand-in for a student's fine are
     * listed in the accrual service's docblock.
     */
    public function computeFine(?CarbonInterface $asOf = null, ?float $base = null): float
    {
        if (! $this->due_date) {
            return 0.0;
        }

        $asOf ??= now();
        $daysOverdue = (int) $this->due_date->copy()->startOfDay()
            ->diffInDays($asOf->copy()->startOfDay(), false);

        return FeeFine::evaluate($this->fineRule(), $base ?? (float) $this->amount, $daysOverdue);
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * PRECEDENCE — HOW SPECIFIC THIS PRICE IS
     * ─────────────────────────────────────────────────────────────────────────
     * A bitmask, so that each dimension outranks EVERY combination of the ones
     * below it and ties are impossible between differently-scoped rules:
     *
     *      student category   4      "RTE students pay nothing"
     *      section            2      "10-A pays the lab fee, 10-B does not"
     *      class              1      "Grade 10 tuition is ₹2,000"
     *      (nothing set)      0      "everyone pays the library fee"
     *
     * WHY CATEGORY OUTRANKS THE STRUCTURAL DIMENSIONS. A category price is a
     * CONCESSION OR AN EXEMPTION — RTE, staff ward, scholarship, sibling — and it
     * is normally a legal or contractual promise the school has made to one
     * family. A class or section price is ordinary structure. When a school has
     * said both "Grade 10-A pays ₹2,400" and "RTE students pay ₹0", the answer a
     * parent is entitled to is ₹0; charging the structural price because it named
     * two dimensions to the concession's one would be the wrong way round.
     * Combining still wins over either alone (class + category = 5 beats category
     * = 4), so a school that wants "RTE in Grade 10 pays ₹500" says exactly that.
     *
     * {@see Classivo\Fees\Services\FeeAllocationService::resolveMastersFor()} is where this
     * score is used, and it is the ONLY consumer.
     */
    public function specificity(): int
    {
        return ($this->student_category_id !== null ? 4 : 0)
            + ($this->section_id !== null ? 2 : 0)
            + ($this->class_id !== null ? 1 : 0);
    }

    /**
     * The key that identifies WHAT IS BEING PRICED, independent of who it is
     * priced for. Two masters sharing this key are competing prices for one thing
     * and exactly one of them may reach a student; two masters with different keys
     * are different charges and both may.
     *
     * Group AND type, because a head repeats across groups by design — a school may model
     * every monthly cycle as its own fee group carrying the same
     * "Tuition Fee" type, and collapsing them would bill one month of tuition a
     * year. Plus the instalment number, so instalment 3 of a schedule does not
     * suppress instalment 4.
     */
    public function billingKey(): string
    {
        return implode('|', [
            (int) $this->fee_group_id,
            (int) $this->fee_type_id,
            (int) ($this->installment_number ?? 0),
        ]);
    }

    /** True when `$on` (default: today) falls inside this master's effective window. */
    public function isEffectiveOn(?CarbonInterface $on = null): bool
    {
        $on = ($on ?? now())->copy()->startOfDay();

        if ($this->effective_from !== null && $this->effective_from->copy()->startOfDay()->gt($on)) {
            return false;
        }

        return $this->effective_to === null || ! $this->effective_to->copy()->startOfDay()->lt($on);
    }

    /** A schedule DEFINITION — priced and dated, but never billed to anybody. */
    public function isScheduleParent(): bool
    {
        return (bool) $this->is_schedule_parent;
    }

    /**
     * Masters that may actually be billed: everything except schedule
     * definitions. Every allocation path goes through this — a student billed
     * both a definition and its instalments would owe the year twice.
     */
    public function scopeBillable(Builder $query): Builder
    {
        return $query->where('is_schedule_parent', false);
    }

    /** Masters whose effective window contains `$on` (default: today). */
    public function scopeEffectiveOn(Builder $query, ?CarbonInterface $on = null): Builder
    {
        $date = ($on ?? now())->copy()->startOfDay()->toDateString();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}

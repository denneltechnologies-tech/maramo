<?php

namespace Classivo\Fees\Services;

use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Tenancy\Scopes\BranchScope;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Core\Tenancy\Scopes\TenantScope;
use Classivo\Fees\Events\FeePaymentRecorded;
use Classivo\Fees\Events\FeePaymentReverted;
use Classivo\Fees\Filament\Pages\CollectFees;
use Classivo\Fees\Filament\Pages\SearchDueFees;
use Classivo\Fees\Models\FeeCarryForward;
use Classivo\Fees\Models\FeeGroup;
use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Models\FeeType;
use Classivo\Students\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FeeCarryForwardService — makes a carried-forward balance a REAL DEBT instead of a
 * note in a register.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS: `fee_carry_forwards` WAS WRITE-ONLY
 * ─────────────────────────────────────────────────────────────────────────────
 * The Fees Carry Forward page wrote {@see FeeCarryForward} rows and NOTHING read
 * them into a student's dues. Grep proves it: the model, one read-only admin API
 * listing (whose own docblock admits "nothing currently CONSUMES a carry-forward
 * row into a student's dues") and a promotion remark — that is the entire consumer
 * set. {@see FeeStatementService}, {@see FeeCollectionService},
 * {@see CollectFees} and {@see SearchDueFees}
 * contained zero references. So a balance carried forward from last year never
 * appeared in the dues, could not be collected, and never reached a receipt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE FIX: MATERIALISE THE CARRY-FORWARD AS A {@see FeeStudentAllocation}
 * ─────────────────────────────────────────────────────────────────────────────
 * An allocation is ALREADY the one unit every part of this app understands as
 * "money this student owes in this session". Teaching five separate readers about
 * a second, parallel kind of debt would have meant editing the statement service,
 * the collection service, the collect screen, the due-fees search, the reports and
 * an API client — six places to forget. Raising the opening balance AS an
 * allocation instead means every one of those surfaces picks it up with NO code
 * change at all:
 *
 *   • {@see SearchDueFees}  — reads allocations → it appears;
 *   • {@see CollectFees}    — reads allocations → it is payable;
 *   • {@see FeeCollectionService::collect()}    — advances paid_amount, prints a
 *                                                receipt line, posts to the ledger;
 *   • {@see FeeStatementService}                — statement, installments, PDF;
 *   • an API client's /fees endpoints          — parent/student app dues.
 *
 * The `fee_carry_forwards` row survives as what it is genuinely good at: the
 * REGISTER — where the balance came from, who carried it, the note, and (new)
 * `fee_student_allocation_id`, the debt it raised. Its `status` is now derived from
 * that allocation, so "collected" means the money actually arrived rather than
 * someone having typed it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE BACKING FEE MASTER — A HEAD, NOT A PRICE
 * ─────────────────────────────────────────────────────────────────────────────
 * `fee_student_allocations.fee_master_id` is NOT NULL, so the opening balance needs
 * a master to hang off. One is minted per (branch, target session, source session):
 *
 *   fee type  — one per branch, `code` = PREV_SESSION_DUES, `is_system` = true;
 *   fee group — "Carry Forward — <source session name>", in the TARGET session;
 *   master    — that group + that type, class_id NULL, amount 0, fine_type none.
 *
 * `amount` on the master is 0 ON PURPOSE and the allocation carries the real
 * figure: a carried balance is per student, so there is no single price to put on
 * the template. This is the one place in the module where the two legitimately
 * differ, which is exactly why it gets its own system head rather than borrowing a
 * real one — a reader that sums master amounts is not silently given a fake price.
 *
 * `status` = false and `is_optional` = true keep the bulk sweep away from it:
 * {@see FeeAllocationService::mastersForClass()} filters `status = true` and drops
 * optional masters, so one press of "Assign Fees" on a class can never raise a
 * ₹0 opening-balance line against thirty students who do not owe one.
 *
 * `fine_type` = none because an opening balance must not start silently accruing
 * last year's fine rule the day the new session opens; a school that wants to fine
 * old dues does it deliberately on the Collect screen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IDEMPOTENCE — A DOUBLE-CLICKED ROLLOVER MUST NOT DOUBLE-BILL
 * ─────────────────────────────────────────────────────────────────────────────
 * One master per (target, source) session pair means `fee_alloc_unique`
 * (student_id, fee_master_id, session_id) admits EXACTLY ONE opening-balance
 * allocation per student per rollover direction. Re-running therefore UPDATES that
 * one row instead of adding a second, and the amount it writes is recomputed from
 * the SOURCE session — which this service never writes to — so the second run
 * lands on the same figure. Running it twice is a no-op, not a second bill.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS DELIBERATELY DOES *NOT* DO: CLOSE THE SOURCE-SESSION LINES
 * ─────────────────────────────────────────────────────────────────────────────
 * Carrying a balance forward leaves last year's unpaid allocations exactly as they
 * are. That is a judgement call, and it has a cost worth stating plainly:
 *
 *   • Right: history stays true. Those lines carry receipts, discounts and fines
 *     that a past-session statement, an old receipt PDF and every finance report
 *     already render. Marking them paid would fabricate money that never arrived;
 *     writing the difference off as a discount would corrupt concession reporting;
 *     soft-deleting them would orphan the receipt lines that point at them (the
 *     exact damage {@see FeeMaster}'s force-delete guard exists to prevent).
 *   • Cost: the same rupees are visible in TWO sessions at once — as an open line
 *     in the source session and as an opening balance in the target. A per-session
 *     view (every screen in the app, and an API client, which always pick one) is
 *     unaffected; only an explicit ALL-SESSIONS total double-counts.
 *
 * So the debt is COLLECTED in the target session and the source session keeps the
 * record of where it came from. {@see carriedOutOf()} reports, per student, how
 * much of a session's balance has already been carried elsewhere, which is what a
 * cross-session total must subtract to stay honest — {@see FeeStatementService}
 * surfaces it, and the Fees Carry Forward preview shows it per row so an operator
 * re-running a rollover can see it is a no-op rather than new money.
 */
class FeeCarryForwardService
{
    /** `fee_types.code` of the per-branch system head opening balances hang off. */
    public const SYSTEM_TYPE_CODE = 'PREV_SESSION_DUES';

    /** Display name of that head — what a parent sees on the receipt. */
    public const SYSTEM_TYPE_NAME = 'Previous Session Dues';

    // ------------------------------------------------------------------ Preview

    /**
     * What a carry-forward from `$fromSessionId` into `$toSessionId` WOULD do —
     * the same query the write uses, so the preview can never promise more than
     * the commit delivers.
     *
     * `$classId` is a class of the SOURCE session and is matched against the
     * student's enrolment IN THAT SESSION ({@see studentIdsInSourceClass()}), not
     * against `students.class_id`. That column is the student's CURRENT class, so
     * filtering on it selected the wrong cohort the moment anybody was promoted —
     * and because `classes` is itself session-scoped, a current-session class id
     * never matches a previous-session one in the first place.
     *
     * Row shape: student_id, name, admission_no, class, prev_balance,
     * already_carried, amount (the proposal = prev_balance), note.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(int $fromSessionId, int $toSessionId, ?int $classId = null): Collection
    {
        $studentIds = $classId !== null
            ? $this->studentIdsInSourceClass($fromSessionId, (int) $classId)
            : null;

        if ($studentIds !== null && $studentIds === []) {
            return collect();
        }

        $allocations = FeeStudentAllocation::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('session_id', $fromSessionId)
            ->when($studentIds !== null, fn ($q) => $q->whereIn('student_id', $studentIds))
            ->with('student')
            ->get()
            ->filter(fn (FeeStudentAllocation $a): bool => $a->balance() > 0)
            ->groupBy('student_id');

        if ($allocations->isEmpty()) {
            return collect();
        }

        // What a previous run of this same direction already raised, so a re-run
        // preview is honest about being a no-op instead of looking like new money.
        $carried = FeeCarryForward::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('session_id', $toSessionId)
            ->where('from_session_id', $fromSessionId)
            ->whereIn('student_id', $allocations->keys())
            ->pluck('amount', 'student_id');

        $fromName = AcademicSession::query()->find($fromSessionId)?->name;

        return $allocations
            ->map(function (Collection $group, int|string $studentId) use ($carried, $fromName): array {
                $student = $group->first()->student;
                $balance = round($group->sum(fn (FeeStudentAllocation $a): float => $a->balance()), 2);

                return [
                    'student_id' => (int) $studentId,
                    'name' => $student?->full_name ?? '—',
                    'admission_no' => $student?->admission_no ?? '—',
                    'prev_balance' => number_format($balance, 2, '.', ''),
                    'already_carried' => number_format((float) ($carried[$studentId] ?? 0), 2, '.', ''),
                    'amount' => number_format($balance, 2, '.', ''),
                    'note' => $fromName !== null
                        ? "Opening balance carried from {$fromName}"
                        : 'Carried from previous session',
                ];
            })
            ->values();
    }

    /**
     * A student's total outstanding balance in one session — the figure a
     * carry-forward proposes. Deliberately INCLUDES an opening balance that was
     * itself carried into that session: an unpaid 2025 due that survived 2026 is
     * still owed in 2027, and dropping it would quietly forgive it.
     */
    public function outstandingBalance(int $studentId, int $sessionId): float
    {
        return round(
            FeeStudentAllocation::query()
                ->withoutGlobalScope(SessionScope::class)
                ->where('session_id', $sessionId)
                ->where('student_id', $studentId)
                ->get()
                ->sum(fn (FeeStudentAllocation $a): float => $a->balance()),
            2,
        );
    }

    /**
     * How much of `$sessionId`'s balance has already been carried OUT of it for a
     * student — the figure a cross-session total must subtract so the same rupees
     * are not counted in both the session that raised them and the session that
     * carried them (see the class docblock). Null student = the whole cohort.
     */
    public function carriedOutOf(int $sessionId, ?int $studentId = null): float
    {
        return round((float) FeeCarryForward::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class, SessionScope::class])
            ->where('from_session_id', $sessionId)
            ->when($studentId !== null, fn ($q) => $q->where('student_id', $studentId))
            ->sum('amount'), 2);
    }

    /**
     * The students enrolled in a class IN A GIVEN SESSION, read from
     * `student_session_records` — the only table that knows which class a student
     * sat in during a session that is now over.
     *
     * @return array<int, int>
     */
    public function studentIdsInSourceClass(int $sessionId, int $classId): array
    {
        // The students package records per-session enrolment in
        // `student_session_records`; without it, fall back to the current class.
        $query = Schema::hasTable('student_session_records')
            ? DB::table('student_session_records')->where('session_id', $sessionId)->where('class_id', $classId)->select('student_id')
            : DB::table('students')->where('class_id', $classId)->select('id as student_id');

        return $query
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    // -------------------------------------------------------------------- Write

    /**
     * Carry one student's balance forward: write (or refresh) the register row AND
     * the {@see FeeStudentAllocation} that makes it payable. Returns the register
     * row, or null when there is nothing to carry.
     *
     * Idempotent in both halves — `updateOrCreate` on the register's
     * (student, session, from_session) key and on the allocation's
     * `fee_alloc_unique` key. A second run rewrites the same two rows.
     *
     * `$options`: note (string), due_date (Y-m-d, default the target session's
     * start date), created_by (int).
     */
    public function carryForward(Student $student, float $amount, int $fromSessionId, int $toSessionId, array $options = []): ?FeeCarryForward
    {
        $amount = round(max(0, $amount), 2);

        if ($amount <= 0 || $student->school_id === null || $student->branch_id === null) {
            return null;
        }

        return DB::transaction(function () use ($student, $amount, $fromSessionId, $toSessionId, $options): FeeCarryForward {
            $master = $this->ensureBackingMaster(
                (int) $student->school_id,
                (int) $student->branch_id,
                $fromSessionId,
                $toSessionId,
            );

            $dueDate = $options['due_date'] ?? $this->defaultDueDate($toSessionId);

            // The DEBT. Written outside the tenancy pins because a rollover is run
            // from the OLD session's context as often as the new one — under the
            // session pin `updateOrCreate` would fail to see the row it just wrote
            // and try to insert a second, colliding on fee_alloc_unique.
            $allocation = FeeStudentAllocation::query()
                ->withoutGlobalScopes([TenantScope::class, BranchScope::class, SessionScope::class])
                ->updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'fee_master_id' => $master->id,
                        'session_id' => $toSessionId,
                    ],
                    [
                        'school_id' => $student->school_id,
                        'branch_id' => $student->branch_id,
                        'amount' => $amount,
                        'due_date' => $dueDate,
                    ],
                );

            // NEVER touch discount/fine/paid here. Those are money that has already
            // moved against this line on a previous run's receipt; resetting them
            // would resurrect a debt the school has been paid for. Only `amount`
            // and `due_date` are ours to restate.
            $allocation->syncStatus()->save();

            // The REGISTER row — provenance, note, and the debt it raised.
            $carry = FeeCarryForward::query()
                ->withoutGlobalScopes([TenantScope::class, BranchScope::class, SessionScope::class])
                ->updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'session_id' => $toSessionId,
                        'from_session_id' => $fromSessionId,
                    ],
                    [
                        'school_id' => $student->school_id,
                        'branch_id' => $student->branch_id,
                        'fee_student_allocation_id' => $allocation->id,
                        'amount' => $amount,
                        'note' => $options['note'] ?? null,
                        'created_by' => $options['created_by'] ?? auth()->id(),
                    ],
                );

            $carry->setRelation('allocation', $allocation);

            return $this->syncStatus($carry);
        });
    }

    /**
     * Carry a whole cohort forward in one pass — the bulk path behind the Fees
     * Carry Forward page and the session rollover.
     *
     * `$rows` are preview rows (or anything carrying student_id + amount + note).
     * Returns ['students' => int, 'amount' => float].
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return array{students: int, amount: float}
     */
    public function carryForwardMany(iterable $rows, int $fromSessionId, int $toSessionId, array $options = []): array
    {
        $students = 0;
        $total = 0.0;

        foreach ($rows as $row) {
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $student = ($row['student_id'] ?? null) ? Student::query()->withoutGlobalScopes()->find($row['student_id']) : null;

            if (! $student || $amount <= 0) {
                continue;
            }

            $carried = $this->carryForward($student, $amount, $fromSessionId, $toSessionId, $options + [
                'note' => $row['note'] ?? null,
            ]);

            if ($carried !== null) {
                $students++;
                $total = round($total + $amount, 2);
            }
        }

        return ['students' => $students, 'amount' => $total];
    }

    // ----------------------------------------------------------- Backing master

    /**
     * The system fee master opening balances from `$fromSessionId` hang off in
     * `$toSessionId`, minting the type/group/master on first use. See the class
     * docblock for why each field is what it is.
     */
    public function ensureBackingMaster(int $schoolId, int $branchId, int $fromSessionId, int $toSessionId): FeeMaster
    {
        $type = $this->ensureSystemFeeType($schoolId, $branchId);
        $group = $this->ensureCarryForwardGroup($schoolId, $branchId, $fromSessionId, $toSessionId);

        $master = FeeMaster::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class, SessionScope::class])
            ->withTrashed()
            ->firstOrNew([
                'school_id' => $schoolId,
                'branch_id' => $branchId,
                'session_id' => $toSessionId,
                'fee_group_id' => $group->id,
                'fee_type_id' => $type->id,
            ]);

        if ($master->exists && $master->trashed()) {
            // Restoring rather than minting a twin: FeeMaster::restoring() reunites
            // the allocations its own soft delete retired, which is precisely the
            // opening balances of an earlier run.
            $master->restore();
        }

        if (! $master->exists) {
            $master->fill([
                'class_id' => null,
                'amount' => 0,
                'due_date' => $this->defaultDueDate($toSessionId),
                'fine_type' => 'none',
                'fine_amount' => 0,
                'fine_frequency' => 'one_time',
                'fine_after_days' => 0,
                // Both flags keep FeeAllocationService's class sweep away from a
                // ₹0 system head — see the class docblock.
                'is_optional' => true,
                'status' => false,
            ])->save();
        }

        return $master;
    }

    /** The per-branch PREV_SESSION_DUES head (created, or un-trashed, on first use). */
    public function ensureSystemFeeType(int $schoolId, int $branchId): FeeType
    {
        $type = FeeType::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class])
            ->withTrashed()
            ->firstOrNew([
                'branch_id' => $branchId,
                'code' => self::SYSTEM_TYPE_CODE,
            ]);

        if ($type->exists && $type->trashed()) {
            $type->restore();
        }

        if (! $type->exists) {
            $type->fill([
                'school_id' => $schoolId,
                'name' => self::SYSTEM_TYPE_NAME,
                'description' => 'Opening balance carried forward from an earlier academic session.',
                'is_system' => true,
                'status' => true,
            ])->save();
        }

        return $type;
    }

    /** The target-session group that names where the balance came from. */
    public function ensureCarryForwardGroup(int $schoolId, int $branchId, int $fromSessionId, int $toSessionId): FeeGroup
    {
        $fromName = AcademicSession::query()->withoutGlobalScopes()->find($fromSessionId)?->name ?? "session {$fromSessionId}";

        $group = FeeGroup::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class, SessionScope::class])
            ->withTrashed()
            ->firstOrNew([
                'school_id' => $schoolId,
                'branch_id' => $branchId,
                'session_id' => $toSessionId,
                'name' => "Carry Forward — {$fromName}",
            ]);

        if ($group->exists && $group->trashed()) {
            $group->restore();
        }

        if (! $group->exists) {
            $group->fill([
                'description' => 'System group holding opening balances carried into this session.',
                'status' => true,
            ])->save();
        }

        return $group;
    }

    /** A session's start date (the natural due date for its opening balance). */
    public function defaultDueDate(int $sessionId): ?string
    {
        return AcademicSession::query()
            ->withoutGlobalScopes()
            ->find($sessionId)?->start_date?->toDateString();
    }

    // ------------------------------------------------------------- Settlement

    /**
     * Re-derive one register row's `status` from the debt it raised and persist it.
     * "collected" now means the money actually arrived.
     */
    public function syncStatus(FeeCarryForward $carry): FeeCarryForward
    {
        $status = $carry->derivedStatus();

        if ((string) $carry->getRawOriginal('status') !== $status) {
            $carry->forceFill(['status' => $status])->save();
        }

        return $carry;
    }

    /**
     * Re-derive every register row of a session (null = every session). The repair
     * path for rows written before this service existed, and the safety net for
     * any settlement that reached the allocation without passing the listener.
     * Returns how many rows changed status.
     */
    public function reconcile(?int $sessionId = null): int
    {
        $changed = 0;

        FeeCarryForward::query()
            ->withoutGlobalScope(SessionScope::class)
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->with('allocation')
            ->chunkById(200, function (Collection $rows) use (&$changed): void {
                foreach ($rows as $carry) {
                    $before = (string) $carry->getRawOriginal('status');
                    $this->syncStatus($carry);

                    if ((string) $carry->getRawOriginal('status') !== $before) {
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    /**
     * Settle (or re-open) every register row a receipt touched — the seam
     * {@see FeePaymentRecorded} / {@see FeePaymentReverted}
     * hang off, so paying an opening balance flips its register row to "collected"
     * the moment the receipt is written and a revert flips it back.
     *
     * Takes a payment id rather than the model so the listener can stay a one-line
     * closure with no fee-model imports of its own. Returns rows touched.
     */
    public function settleFromPayment(int $paymentId): int
    {
        $payment = FeePayment::query()
            ->withoutGlobalScopes()
            ->with('details')
            ->find($paymentId);

        if (! $payment) {
            return 0;
        }

        $allocationIds = $payment->details
            ->pluck('fee_student_allocation_id')
            ->filter()
            ->unique()
            ->values();

        if ($allocationIds->isEmpty()) {
            return 0;
        }

        $rows = FeeCarryForward::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class, SessionScope::class])
            ->whereIn('fee_student_allocation_id', $allocationIds)
            ->with('allocation')
            ->get();

        foreach ($rows as $carry) {
            $this->syncStatus($carry);
        }

        return $rows->count();
    }
}

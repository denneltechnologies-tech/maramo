<?php

namespace Classivo\Fees\Services;

use Carbon\CarbonInterface;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Students\Models\Student;
use Illuminate\Support\Collection;

/**
 * FeeAllocationService — assigns fee masters to students.
 *
 * allocate(): idempotently assigns one master to one student for the master's session,
 * copying amount, due date AND THE FINE RULE onto the allocation.
 * autoAllocateByClass(): assigns the fee structure that applies to a class to every
 * student currently enrolled in it — resolved per student, because a price can now
 * depend on the student's section and category. Transport/hostel auto-attach is a
 * Phase-5 placeholder (those masters carry a `fee_type.is_system` head only).
 *
 * THE OPTIONAL/COMPULSORY SPLIT LIVES IN EXACTLY ONE PLACE: the bulk path. Assigning is
 * either a JUDGEMENT ABOUT ONE STUDENT (allocate() — "this child takes the bus") or a
 * SWEEP OVER A CLASS (autoAllocateByClass() — "everyone owes the term fee"). Only the
 * sweep chooses its own masters, so only the sweep can be wrong about an optional one;
 * allocate()/allocateMany() take what they are given and never second-guess the caller.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * MATCHING IS NOT THE SAME AS CHOOSING
 * ─────────────────────────────────────────────────────────────────────────────
 * The old sweep took every master that matched a class and allocated ALL of them.
 * That was safe while `class_id` was the only dimension: a master is scoped to one
 * class or to all of them, so at most two rows matched a student and both were
 * meant — Grade 10's tuition AND the whole-school library fee are different
 * CHARGES, not competing prices.
 *
 * Sections and student categories break that. "Grade 10 tuition ₹2,000" and "10-A
 * tuition ₹2,400" and "RTE tuition ₹0" all match one child, and they are three
 * prices for ONE thing. Allocating the union bills the child three times.
 * {@see resolveMastersFor()} is the resolver that collapses them to one price per
 * charge; the precedence it applies is documented on {@see FeeMaster::specificity()}
 * and the identity of "one charge" on {@see FeeMaster::billingKey()}.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RESOLVING WITHIN A RUN IS NOT ENOUGH — THE SWEEP MUST READ WHAT IT ALREADY DID
 * ─────────────────────────────────────────────────────────────────────────────
 * The resolver picks one winner per billing key out of the CANDIDATES IN FRONT OF
 * IT. {@see allocate()} then deduplicates on (student, master, session), which asks
 * "does this child already hold THIS ROW?" — never "does this child already hold a
 * line for this CHARGE?". A due raised under a master that has since lost the
 * precedence contest, or under a price that has since been revised, is therefore
 * invisible to the next sweep, and the sweep raises the new winner ALONGSIDE it:
 *
 *   • add a 10-A price after the 10 price, press Assign Fees again → every child in
 *     10-A holds two live unpaid lines for one head (₹2,000 AND ₹2,400);
 *   • no operator error needed at all: schedule a revision, let the sweep run once
 *     before the changeover date and once after → the whole class is billed twice,
 *     once at the old price and once at the new one.
 *
 * {@see existingBillingKeys()} closes it. The sweep loads, in one query, every
 * billing key each student ALREADY holds a live allocation for, and
 * {@see sweepClass()} skips a winner whose charge is already on the child's account
 * under a different row — and REPORTS the skip, because "your new section price did
 * not reach 30 students who were already billed" is exactly what the operator needs
 * to know.
 *
 * WHY SKIP AND NOT RE-PRICE. See {@see sweepClass()}.
 */
class FeeAllocationService
{
    /**
     * Assign a fee master to a student (idempotent per student+master+session).
     * Returns the allocation (existing or newly created).
     *
     * *** THE IDEMPOTENCE HERE IS PER ROW, NOT PER CHARGE ***
     * `firstOrCreate` is keyed on (student, master, session), so this answers "does
     * the student already hold THIS ROW?" and nothing else. It does NOT know that
     * two different masters can price the SAME charge — a class price and the 10-A
     * price that outranks it, or a price and the revision that replaced it. Any
     * caller that CHOOSES its own masters must therefore consult
     * {@see duplicateBillingKeyAllocation()} (or use {@see sweepClass()}, which does
     * it in bulk) before calling this, or it will raise a second live line for a
     * head the child is already billed for.
     *
     * That check is deliberately not made here. This is the path that takes what it
     * is given — "this child takes the bus" — and an explicit per-student
     * instruction is not something to second-guess.
     *
     * *** THE FINE RULE IS FROZEN HERE ***
     * `amount` and `due_date` have always been snapshotted, because an issued due
     * is a debt and a debt does not change price when next year's price list is
     * written. The FINE was not: every fine figure in the application was
     * recomputed from the master's CURRENT rule, so raising a fine from ₹50 to
     * ₹200 a month retroactively raised it on dues issued months earlier, for
     * every student, with nothing recording that a different rule had ever
     * applied. {@see FeeMaster::fineRuleSnapshot()} is copied onto the allocation
     * alongside the amount, and {@see FeeAccrualService} costs the debt from THAT.
     *
     * WHY forceFill AND NOT THE firstOrCreate PAYLOAD. The snapshot columns are
     * deliberately not in FeeStudentAllocation::$fillable — they are terms of a
     * debt, written once by this method at issue time, and nothing that accepts
     * user input should be able to mass-assign them. firstOrCreate() fills through
     * the guard and would silently drop them, so the snapshot is written straight
     * onto the new row instead. It runs ONLY for a row this call created; an
     * existing allocation keeps the rule it was issued under, which is the entire
     * point.
     */
    public function allocate(FeeMaster $master, Student $student): FeeStudentAllocation
    {
        $allocation = FeeStudentAllocation::firstOrCreate(
            [
                'student_id' => $student->id,
                'fee_master_id' => $master->id,
                'session_id' => $master->session_id,
            ],
            [
                'school_id' => $master->school_id,
                'branch_id' => $master->branch_id,
                'amount' => $master->amount,
                'discount_amount' => 0,
                'fine_amount' => 0,
                'paid_amount' => 0,
                'due_date' => $master->due_date,
                'status' => 'unpaid',
            ],
        );

        if ($allocation->wasRecentlyCreated) {
            $allocation->forceFill($master->fineRuleSnapshot())->save();
        }

        return $allocation;
    }

    /**
     * Assign a collection of masters to a collection of students. Returns the number of
     * allocations newly created (skips ones that already exist).
     *
     * @param  Collection<int, FeeMaster>  $masters
     * @param  Collection<int, Student>  $students
     */
    public function allocateMany(Collection $masters, Collection $students): int
    {
        $created = 0;

        foreach ($masters as $master) {
            foreach ($students as $student) {
                if ($this->allocate($master, $student)->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * The active masters a BULK class run may CONSIDER: masters scoped to that class OR
     * to all classes, effective on `$asOf`, MINUS the optional ones unless the caller
     * asks for them, MINUS schedule definitions.
     *
     * These are CANDIDATES, not the answer. A section- or category-scoped master is
     * included here even though it applies to only some of the class; which of the
     * candidates a given child is actually billed is decided per student by
     * {@see resolveMastersFor()}.
     *
     * `fee_masters.is_optional` means "student can opt out" (that is the label on the
     * form toggle) — a bus fare, a hostel seat, an admission charge in a year the
     * student was not admitted. Such a charge is only ever true for SOME of a class, so
     * the one thing a whole-class sweep must not do is raise it against all of them.
     *
     * A SCHEDULE DEFINITION IS NEVER A CANDIDATE. It is a price list entry that
     * expands into instalments (see {@see FeeScheduleService}); billing it as well
     * as its instalments would charge the year twice.
     *
     * @return Collection<int, FeeMaster>
     */
    public function mastersForClass(SchoolClass $class, bool $includeOptional = false, ?CarbonInterface $asOf = null): Collection
    {
        return FeeMaster::query()
            ->where('status', true)
            ->billable()
            ->effectiveOn($asOf)
            ->where(fn ($q) => $q->whereNull('class_id')->orWhere('class_id', $class->id))
            ->unless($includeOptional, fn ($q) => $q->where('is_optional', false))
            ->with(['feeFine'])
            ->get();
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * THE PRECEDENCE RESOLVER — EXACTLY ONE PRICE PER CHARGE, PER STUDENT
     * ─────────────────────────────────────────────────────────────────────────
     * Narrow `$candidates` (or the whole active price list, if none is given) to
     * the masters that apply to THIS student on `$asOf`, then keep the single most
     * specific one for each thing being charged.
     *
     * STEP 1 — APPLICABILITY. A master applies when each of its three dimensions
     * either is unset (meaning "any") or equals the student's:
     *      class_id            ↔ students.class_id
     *      section_id          ↔ students.section_id
     *      student_category_id ↔ students.student_category_id
     * A dimension the student themselves has not got (no section on record, no
     * category) can only be matched by a master that leaves it unset — an
     * uncategorised child is not silently swept into the RTE price.
     *
     * STEP 2 — CHOICE. Candidates are grouped by {@see FeeMaster::billingKey()}
     * (fee group + fee type + instalment number: what is being charged, not who it
     * is charged to) and within each group the winner is:
     *
     *      highest {@see FeeMaster::specificity()}      category ▸ section ▸ class
     *      then the LATEST `effective_from`             the scheduled revision
     *      then the highest id                          deterministic, newest wins
     *
     * The second tie-break is what makes effective dating work: two rows pricing
     * the same charge for the same students are a revision, and on any given day
     * the one that took effect most recently is the current price. A row whose
     * `effective_from` is still in the future never gets this far — it is filtered
     * out in step 1 — so a revision applies itself on the day it was scheduled for
     * and not a day earlier.
     *
     * WHY THIS RUNS PER STUDENT AND NOT PER CLASS. The result genuinely differs
     * between two children sitting in the same room: one is in 10-A and one in
     * 10-B, one is RTE and one is not. Callers sweeping a class should fetch the
     * candidates ONCE with {@see mastersForClass()} and pass them in, so the extra
     * cost is a loop in PHP rather than a query per child.
     *
     * @param  Collection<int, FeeMaster>|null  $candidates
     * @return Collection<int, FeeMaster>
     */
    public function resolveMastersFor(
        Student $student,
        ?Collection $candidates = null,
        bool $includeOptional = false,
        ?CarbonInterface $asOf = null,
    ): Collection {
        return $this->resolveMastersForProfile(
            $student->class_id !== null ? (int) $student->class_id : null,
            $student->section_id !== null ? (int) $student->section_id : null,
            $student->student_category_id !== null ? (int) $student->student_category_id : null,
            $candidates,
            $includeOptional,
            $asOf,
        );
    }

    /**
     * The same resolution as {@see resolveMastersFor()}, expressed against the three
     * dimensions instead of against a Student row.
     *
     * WHY THIS OVERLOAD EXISTS. Two callers legitimately need the answer for a child
     * that is not in the database yet: the admission wizard's fee preview (the
     * student is still being typed) and {@see StudentAdmissionService}, which must
     * resolve the structure the admission will raise. The alternative — a second
     * query with its own copy of the four rules — is exactly how the admission path
     * came to bill schedule DEFINITIONS on top of their instalments, expired prices,
     * future revisions and all four competing prices for one head. There is one
     * resolver and this is it; everything else is a thin wrapper around it.
     *
     * A dimension passed as NULL means "not known / not held", which is the same
     * thing the student table says when the column is null: a master that names that
     * dimension cannot match. So a class-only preview shows the class and all-class
     * prices and never a section or category price the child may not qualify for.
     *
     * @param  Collection<int, FeeMaster>|null  $candidates
     * @return Collection<int, FeeMaster>
     */
    public function resolveMastersForProfile(
        ?int $classId,
        ?int $sectionId = null,
        ?int $studentCategoryId = null,
        ?Collection $candidates = null,
        bool $includeOptional = false,
        ?CarbonInterface $asOf = null,
    ): Collection {
        $candidates ??= FeeMaster::query()
            ->where('status', true)
            ->billable()
            ->effectiveOn($asOf)
            ->unless($includeOptional, fn ($q) => $q->where('is_optional', false))
            ->with(['feeFine'])
            ->get();

        /** @var array<string, FeeMaster> $winners */
        $winners = [];

        foreach ($candidates as $master) {
            if (! $includeOptional && $master->is_optional) {
                continue;
            }

            if (! $this->appliesToProfile($master, $classId, $sectionId, $studentCategoryId, $asOf)) {
                continue;
            }

            $key = $master->billingKey();
            $held = $winners[$key] ?? null;

            if ($held === null || $this->beats($master, $held)) {
                $winners[$key] = $master;
            }
        }

        return collect(array_values($winners));
    }

    /**
     * Does this master price anything for this student on `$asOf`? Applicability
     * only — see {@see resolveMastersFor()} for the choice between two that do.
     */
    public function appliesTo(FeeMaster $master, Student $student, ?CarbonInterface $asOf = null): bool
    {
        return $this->appliesToProfile(
            $master,
            $student->class_id !== null ? (int) $student->class_id : null,
            $student->section_id !== null ? (int) $student->section_id : null,
            $student->student_category_id !== null ? (int) $student->student_category_id : null,
            $asOf,
        );
    }

    /**
     * {@see appliesTo()} against the three dimensions rather than a Student row.
     * A master that NAMES a dimension the profile has not got never matches — an
     * uncategorised child is not silently swept into the RTE price.
     */
    public function appliesToProfile(
        FeeMaster $master,
        ?int $classId,
        ?int $sectionId = null,
        ?int $studentCategoryId = null,
        ?CarbonInterface $asOf = null,
    ): bool {
        if ($master->isScheduleParent() || ! $master->status) {
            return false;
        }

        if (! $master->isEffectiveOn($asOf)) {
            return false;
        }

        if ($master->class_id !== null && (int) $master->class_id !== (int) $classId) {
            return false;
        }

        if ($master->section_id !== null && (int) $master->section_id !== (int) $sectionId) {
            return false;
        }

        return $master->student_category_id === null
            || (int) $master->student_category_id === (int) $studentCategoryId;
    }

    /**
     * Auto-allocate the resolved fee structure of a class to every student enrolled in
     * it in the current session. Returns the number of allocations created.
     *
     * WHY OPTIONAL MASTERS ARE EXCLUDED. `is_optional` was stored, editable in the
     * FeeMaster form and published in the admin API, but nothing in the application
     * read it — this query filtered on `status` and `class_id` alone. So one press of
     * "Assign Fees" on a class raised every optional head against every enrolled
     * student: a transport charge billed to students who walk, and an admission fee billed to students admitted in an earlier year.
     * Optional heads are therefore allocated per student instead.
     *
     * An optional head is still perfectly assignable: {@see allocate()} and
     * {@see allocateMany()} are UNCHANGED and take whatever master they are handed, so
     * every deliberate per-student path (the admission wizard's Fees step, the seeders'
     * per-student loops) still works. Only the class-wide sweep, which picks the
     * masters ITSELF, now declines to guess. `$includeOptional` is the explicit
     * opt-back-in for a caller that really does mean the whole class.
     */
    public function autoAllocateByClass(SchoolClass $class, bool $includeOptional = false, ?CarbonInterface $asOf = null): int
    {
        return $this->sweepClass($class, $includeOptional, $asOf)['created'];
    }

    /**
     * The class sweep, with the whole tally instead of just the created count.
     *
     *      created     new fee lines written
     *      already     the student already holds THIS master (a plain re-run)
     *      superseded  the student already holds a DIFFERENT master for the SAME
     *                  charge, so the winner was NOT raised
     *      students / candidates / resolved   what the run looked at
     *
     * ─────────────────────────────────────────────────────────────────────────
     * WHY A COLLISION IS SKIPPED AND NOT RE-PRICED
     * ─────────────────────────────────────────────────────────────────────────
     * When the resolver's winner collides with a line the student already holds,
     * there are only two honest answers: leave the existing line alone, or move it
     * onto the new master and re-price it. This sweep leaves it alone, because:
     *
     *  • AN ISSUED DUE IS A DEBT. That is already this service's stated rule — it
     *    is why {@see allocate()} snapshots `amount`, `due_date` and the fine rule
     *    onto the allocation instead of reading them back off the master. Silently
     *    restating what a parent has already been told they owe is the same class
     *    of mistake as retroactively raising their fine.
     *  • RE-PRICING A PARTLY-PAID LINE IS INDEFENSIBLE. Payments, receipts and
     *    ledger entries point at the allocation; changing the amount underneath
     *    them either invents a balance or destroys one. Re-pricing only the unpaid
     *    lines would leave one class half-revised, which is worse than either.
     *  • A SWEEP IS NOT AN INSTRUCTION TO CORRECT ANYTHING. "Assign Fees to class"
     *    means "make sure everyone has their fee lines". A price CORRECTION is a
     *    deliberate act with its own workflow (Revise price, or editing the
     *    student's allocation), and it should leave a trace that says who did it.
     *  • SKIPPING KEEPS THE PAGE'S PROMISE. The confirmation modal says existing
     *    allocations are skipped; before this change that promise was false for
     *    every superseded price.
     *
     * What the sweep DOES owe the operator is the truth: `superseded` is reported
     * so "I added a 10-A price and pressed Assign Fees" does not look like it
     * worked when 30 of the 45 children kept the class price.
     *
     * @return array{created: int, already: int, superseded: int, students: int, candidates: int, resolved: int}
     */
    public function sweepClass(SchoolClass $class, bool $includeOptional = false, ?CarbonInterface $asOf = null): array
    {
        $students = $this->studentsInClass($class);
        $candidates = $this->mastersForClass($class, $includeOptional, $asOf);

        $tally = [
            'created' => 0,
            'already' => 0,
            'superseded' => 0,
            'students' => $students->count(),
            'candidates' => $candidates->count(),
            'resolved' => 0,
        ];

        if ($candidates->isEmpty() || $students->isEmpty()) {
            return $tally;
        }

        // ONE query for every billing key the class already holds, so the guard
        // costs no queries inside the loop.
        $held = $this->existingBillingKeys($students);

        // One query for the candidates, then the resolver per child: the answer
        // differs between two students in the same class, so the choice cannot be
        // hoisted out of this loop even though the query can.
        foreach ($students as $student) {
            $studentId = (int) $student->getKey();

            foreach ($this->resolveMastersFor($student, $candidates, $includeOptional, $asOf) as $master) {
                $tally['resolved']++;
                $key = $this->heldKey($master);
                $incumbent = $held[$studentId][$key] ?? null;

                // Already billed for this CHARGE under a different row — a losing
                // competitor, or the price this one revised. Never bill it twice.
                if ($incumbent !== null && $incumbent !== (int) $master->getKey()) {
                    $tally['superseded']++;

                    continue;
                }

                $allocation = $this->allocate($master, $student);

                if ($allocation->wasRecentlyCreated) {
                    $tally['created']++;
                    $held[$studentId][$key] = (int) $master->getKey();
                } else {
                    $tally['already']++;
                }
            }
        }

        return $tally;
    }

    /**
     * Every billing key each of `$students` already holds a LIVE allocation for,
     * as `[student_id => [session|billingKey => fee_master_id]]`.
     *
     * Read straight off the join rather than through the models: the sweep needs
     * this for a whole class before it starts, and the master behind an existing
     * allocation may be soft-deleted, out of session, or no longer effective —
     * none of which stops the debt from existing, so none of which may hide it
     * here. Soft-deleted ALLOCATIONS are excluded: an archived line is not a debt.
     *
     * The key carries the session because a billing key identifies a charge WITHIN
     * a year; last year's tuition instalment 3 must not suppress this year's.
     *
     * @param  Collection<int, Student>  $students
     * @return array<int, array<string, int>>
     */
    public function existingBillingKeys(Collection $students): array
    {
        $ids = $students->map(fn (Student $s): int => (int) $s->getKey())->all();

        if ($ids === []) {
            return [];
        }

        $map = [];

        FeeStudentAllocation::query()
            ->withoutGlobalScope(SessionScope::class)
            ->join('fee_masters', 'fee_masters.id', '=', 'fee_student_allocations.fee_master_id')
            ->whereIn('fee_student_allocations.student_id', $ids)
            ->select([
                'fee_student_allocations.student_id',
                'fee_student_allocations.session_id',
                'fee_student_allocations.fee_master_id',
                'fee_masters.fee_group_id',
                'fee_masters.fee_type_id',
                'fee_masters.installment_number',
            ])
            ->cursor()
            ->each(function ($row) use (&$map): void {
                $key = implode('|', [
                    (int) $row->session_id,
                    (int) $row->fee_group_id,
                    (int) $row->fee_type_id,
                    (int) ($row->installment_number ?? 0),
                ]);

                $map[(int) $row->student_id][$key] = (int) $row->fee_master_id;
            });

        return $map;
    }

    /**
     * The live allocation this student already holds for the charge `$master`
     * prices, when it was raised under a DIFFERENT master — i.e. the row that
     * makes allocating `$master` a double bill. Null when there is none.
     *
     * The public form of the guard {@see sweepClass()} applies in bulk, for the
     * single-student callers (a preview, a per-student assign screen) that want to
     * ask before they act. {@see allocate()} deliberately does NOT consult it: it
     * is the path that takes what it is given, and second-guessing an explicit
     * per-student instruction is not its job.
     */
    public function duplicateBillingKeyAllocation(Student $student, FeeMaster $master): ?FeeStudentAllocation
    {
        return FeeStudentAllocation::query()
            ->withoutGlobalScope(SessionScope::class)
            ->join('fee_masters', 'fee_masters.id', '=', 'fee_student_allocations.fee_master_id')
            ->where('fee_student_allocations.student_id', $student->getKey())
            ->where('fee_student_allocations.session_id', $master->session_id)
            ->where('fee_student_allocations.fee_master_id', '!=', $master->getKey())
            ->where('fee_masters.fee_group_id', $master->fee_group_id)
            ->where('fee_masters.fee_type_id', $master->fee_type_id)
            ->when(
                $master->installment_number === null,
                fn ($q) => $q->whereNull('fee_masters.installment_number'),
                fn ($q) => $q->where('fee_masters.installment_number', $master->installment_number),
            )
            ->select('fee_student_allocations.*')
            ->first();
    }

    /** `session|billingKey` — the key {@see existingBillingKeys()} is indexed by. */
    private function heldKey(FeeMaster $master): string
    {
        return ((int) $master->session_id).'|'.$master->billingKey();
    }

    /** Active students enrolled in a class in the current session. */
    public function studentsInClass(SchoolClass $class): Collection
    {
        $query = Student::query()->where('class_id', $class->id);

        // The students package may expose an enrolment scope; use it when present.
        if ($query->hasNamedScope('enrolledInSession')) {
            $query->enrolledInSession();
        }

        return $query->get();
    }

    /**
     * Every allocation for a student in the current session, with its master + type
     * eager-loaded (for the Collect page and the profile Fees tab). Bypasses the
     * session scope only to stay explicit about the session it targets.
     *
     * @return Collection<int, FeeStudentAllocation>
     */
    public function allocationsFor(Student $student, ?int $sessionId = null): Collection
    {
        $sessionId ??= current_session_id();

        return FeeStudentAllocation::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('student_id', $student->id)
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->with(['feeMaster.feeType', 'feeMaster.feeGroup'])
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Is `$candidate` a better answer than the one currently held? Specificity
     * first, then the later effective date, then the newer row. A master with no
     * `effective_from` has been in force since the beginning of time, so it loses
     * to any dated revision — which is what makes a scheduled revision take over.
     */
    private function beats(FeeMaster $candidate, FeeMaster $held): bool
    {
        $candidateScore = $candidate->specificity();
        $heldScore = $held->specificity();

        if ($candidateScore !== $heldScore) {
            return $candidateScore > $heldScore;
        }

        $candidateFrom = $candidate->effective_from?->timestamp ?? PHP_INT_MIN;
        $heldFrom = $held->effective_from?->timestamp ?? PHP_INT_MIN;

        if ($candidateFrom !== $heldFrom) {
            return $candidateFrom > $heldFrom;
        }

        return (int) $candidate->getKey() > (int) $held->getKey();
    }
}

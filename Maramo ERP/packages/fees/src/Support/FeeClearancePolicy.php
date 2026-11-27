<?php

namespace Classivo\Fees\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Classivo\Fees\Models\FeeClearanceExemption;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Students\Models\Student;
use Illuminate\Support\Collection;

/**
 * FeeClearancePolicy — whether a student's fees are clear enough to be handed a
 * document, and if not, what to do about it.
 *
 * ── WHAT IT IS BUILT ON ─────────────────────────────────────────────────────
 * Nothing here re-derives what a student owes. {@see FeeDues::currentDue()}
 * already answers that, and it answers it the way the school actually means it:
 * THIS MONTH'S instalment plus every arrear, with future months excluded. That
 * exclusion is the reason this policy uses it rather than a raw balance — a
 * family that has paid everything due so far is not a defaulter merely because
 * the March instalment already sits on the ledger, and blocking a child's admit
 * card over money that is not yet payable would be indefensible. It is also the
 * same figure the parent sees in their own app, so the number in the refusal
 * message and the number on their phone are one number.
 *
 * The age of the debt comes from {@see FeeStudentAllocation::daysOverdue()},
 * likewise already written.
 *
 * ── THE THRESHOLD ───────────────────────────────────────────────────────────
 * Two halves, both configurable, combined by `config('fees.clearance.match')`:
 * an amount and an age. `any` (the default) holds a document when EITHER trips —
 * "₹5,000 owed, or anything at all more than 30 days late" — and `all` holds it
 * only when both do. Either half set to 0 means "any", so a school can gate on
 * age alone, or on amount alone, without a code change. Nothing is held while
 * the currently-due figure is zero, whatever the settings say.
 *
 * ── THE OVERRIDE LIST ───────────────────────────────────────────────────────
 * {@see FeeClearanceExemption} rows release a document without touching the
 * debt. Consulted only once a hold would otherwise apply, so the ordinary
 * clear-fees path costs no extra query.
 *
 * A user holding {@see OVERRIDE_ABILITY} bypasses the gate entirely. Note that
 * this ability does not have to exist as a seeded permission for that to be
 * useful: Shield's super-admin gate returns true for every ability, so the
 * principal can always print, while an unseeded permission simply denies
 * everyone else — which is the safe direction to fail.
 *
 * ── WHY THE REFUSAL IS A PARAGRAPH ──────────────────────────────────────────
 * The person who hits this gate is usually a parent at 11pm the night before an
 * exam, or a clerk with a queue. "Access denied" sends them to the school gate
 * in the morning with no idea what to bring. The message therefore names the
 * amount, the age of the debt, the two ways to pay, the fact that the document
 * releases itself the moment the payment posts, and who to talk to if a
 * concession was already agreed.
 */
final class FeeClearancePolicy
{
    /** The per-student documents this policy can hold. */
    public const MARKSHEET = 'marksheet';

    public const ADMIT_CARD = 'admit_card';

    /** Holding a whole class's document over one child's debt is not on offer. */
    public const SERVICE_LABELS = [
        self::MARKSHEET => 'marksheet',
        self::ADMIT_CARD => 'admit card',
    ];

    /**
     * The ability that walks past the gate. Deliberately not seeded by this
     * change: an unseeded permission denies every ordinary role (the safe
     * direction) while Shield's super-admin gate still passes, so a school gets a
     * working escape hatch on day one and can grant it deliberately later.
     */
    public const OVERRIDE_ABILITY = 'fees.clearance_override';

    /**
     * Decide, without throwing. Used by the public lookup, which has to render an
     * explanation rather than an error page.
     */
    public function check(Student $student, string $service, ?CarbonInterface $asOf = null): FeeClearance
    {
        if (! $this->gates($service)) {
            return FeeClearance::released($service);
        }

        if ($this->hasOverrideAbility()) {
            return FeeClearance::released($service);
        }

        $asOf ??= CarbonImmutable::now();

        $allocations = $this->allocations($student);

        // The parent-facing definition of "owed now", not a raw balance — see the
        // class docblock. Reads the relation loaded just above rather than issuing
        // its own query.
        $outstanding = FeeDues::currentDue($student, $asOf);

        if ($outstanding <= 0) {
            return FeeClearance::released($service);
        }

        $daysOverdue = $this->oldestOverdueDays($allocations, $asOf);

        if (! $this->trips($outstanding, $daysOverdue)) {
            return FeeClearance::released($service, $outstanding, $daysOverdue);
        }

        $exemption = $this->exemption($student, $service, $asOf);

        if ($exemption !== null) {
            return FeeClearance::exempted($service, $outstanding, $daysOverdue, $exemption);
        }

        return FeeClearance::held(
            $service,
            $outstanding,
            $daysOverdue,
            $this->explain($student, $service, $outstanding, $daysOverdue),
        );
    }

    /**
     * Decide, and refuse the request outright when the document is held.
     *
     * 403 rather than 404: the document exists and the caller is entitled to ask
     * for it — what is missing is the payment, and saying so is the entire point
     * of the message. Hiding behind a 404 would send a parent to the office to
     * report a broken website.
     */
    public function enforce(Student $student, string $service, ?CarbonInterface $asOf = null): FeeClearance
    {
        $decision = $this->check($student, $service, $asOf);

        abort_if($decision->held, 403, $decision->message);

        return $decision;
    }

    /** True when the feature is on AND this particular service is listed. */
    public function gates(string $service): bool
    {
        return (bool) config('fees.clearance.enabled', false)
            && (bool) config('fees.clearance.services.'.$service, false);
    }

    /**
     * The student's allocations, loaded ONCE and pinned onto the model so that
     * {@see FeeDues::currentDue()} — which prefers a loaded relation — and the age
     * calculation below read the same rows rather than querying twice.
     *
     * ── WHY THE SCOPES COME OFF, AND WHY A LOADED RELATION IS REPLACED ──────
     * Two reasons, and both are about the gate giving the SAME answer to
     * everybody:
     *
     *  1. The public lookup is anonymous. It resolves its own student with
     *     `withoutGlobalScopes()` and an explicit `school_id` precisely because
     *     there is no authenticated user to hang a tenant, branch or session
     *     context on. Reading allocations through the ordinary scopes there can
     *     legitimately return NOTHING — and a gate that computes "owes nothing"
     *     when it cannot see the debt is a gate that opens itself for exactly
     *     the caller who needs no login. That is not a hypothetical: it is the
     *     bypass this whole feature exists to close.
     *  2. SessionScope would drop last year's arrears. A debt does not stop
     *     being a debt when the academic year rolls over, and the family whose
     *     arrears are oldest is the one a clearance rule is aimed at.
     *
     * The tenant boundary is not weakened: `school_id` is pinned from the
     * student's own row, and `student_id` is the key the caller already holds.
     *
     * An already-loaded relation is REPLACED rather than trusted, because what a
     * caller happened to eager-load — one session, one branch, or a filtered
     * subset — would otherwise decide whether a document is released. A gate
     * whose answer depends on the caller's eager-loading is a coin flip.
     *
     * @return Collection<int, FeeStudentAllocation>
     */
    private function allocations(Student $student): Collection
    {
        $allocations = FeeStudentAllocation::query()
            ->withoutGlobalScopes()
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->getKey())
            ->get();

        $student->setRelation('feeAllocations', $allocations);

        return $allocations;
    }

    /**
     * Whole days the OLDEST unpaid line is past its due date, or 0 when nothing is
     * overdue. Lines that are settled are skipped: a paid invoice from last term
     * is not evidence of anything.
     *
     * @param  Collection<int, FeeStudentAllocation>  $allocations
     */
    private function oldestOverdueDays(Collection $allocations, CarbonInterface $asOf): int
    {
        $days = 0;

        foreach ($allocations as $allocation) {
            if ($allocation->balance() <= 0 || $allocation->due_date === null) {
                continue;
            }

            $days = max($days, $allocation->daysOverdue($asOf));
        }

        return $days;
    }

    /**
     * Does this debt cross the configured threshold?
     *
     * ── 0 SWITCHES A HALF OFF; IT DOES NOT MAKE IT ALWAYS TRUE ──────────────
     * This was written the other way round first, and a probe showed why it is
     * wrong: reading `min_amount = 0` as "any amount satisfies the amount half"
     * makes that half permanently true, and under `match: any` the age half then
     * cannot matter at all. The shipped default — no amount floor, 30 days —
     * would have held a document over a debt seven days old, which is the
     * opposite of what the setting says.
     *
     * So an unset half is a half that is NOT A CRITERION. Setting only `min_days`
     * gates on age, setting only `min_amount` gates on size, and setting neither
     * means "anything outstanding at all" — which is the honest reading of a
     * school that has configured no threshold but switched the feature on.
     * `$outstanding > 0` is already established by the caller, so that last case
     * is a hold rather than a free pass.
     */
    private function trips(float $outstanding, int $daysOverdue): bool
    {
        $minAmount = max(0.0, (float) config('fees.clearance.min_amount', 0));
        $minDays = max(0, (int) config('fees.clearance.min_days', 0));

        $criteria = [];

        if ($minAmount > 0) {
            $criteria[] = $outstanding >= $minAmount;
        }

        if ($minDays > 0) {
            $criteria[] = $daysOverdue >= $minDays;
        }

        if ($criteria === []) {
            return true;
        }

        return config('fees.clearance.match', 'any') === 'all'
            ? ! in_array(false, $criteria, true)
            : in_array(true, $criteria, true);
    }

    /** An approved hardship override in force for this student and service today. */
    private function exemption(Student $student, string $service, CarbonInterface $asOf): ?FeeClearanceExemption
    {
        return FeeClearanceExemption::query()
            // The public admit-card lookup runs with no authenticated user and no
            // branch context, and resolves its student the same way: scopes off,
            // school pinned explicitly. An exemption that only applied when
            // somebody happened to be logged in would be a gate that reopens
            // itself for the anonymous path — the exact hole this feature has to
            // avoid.
            ->withoutGlobalScopes()
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->getKey())
            ->inForce($service, $asOf->toDateString())
            ->orderByDesc('id')
            ->first();
    }

    /** Whoever is logged in, if anyone, may hold the bypass ability. */
    private function hasOverrideAbility(): bool
    {
        return (bool) auth()->user()?->can(self::OVERRIDE_ABILITY);
    }

    /**
     * The refusal, written to be acted on. Names the figure, the age, both ways to
     * pay, the fact that no unblocking step is needed once it is paid, and the
     * fallback for a concession that was agreed but never recorded.
     */
    private function explain(Student $student, string $service, float $outstanding, int $daysOverdue): string
    {
        $label = self::SERVICE_LABELS[$service] ?? str_replace('_', ' ', $service);
        $amount = Money::symbol().' '.number_format($outstanding, 2);

        $age = $daysOverdue > 0
            ? ', the oldest '.$daysOverdue.' '.($daysOverdue === 1 ? 'day' : 'days').' past its due date'
            : '';

        return sprintf(
            "%s's %s is on hold because %s of fees is currently due%s. "
            .'Clear the dues at the school fee counter, and the %s becomes available straight away — there is no unblocking step to wait for. %s',
            $student->full_name,
            $label,
            $amount,
            $age,
            $label,
            trim((string) config('fees.clearance.contact', '')),
        );
    }
}

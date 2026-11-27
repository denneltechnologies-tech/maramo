<?php

namespace Classivo\Fees\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeFine — a reusable, NAMED overdue-fine rule. `type`:
 *   • fixed   → a flat `value` once overdue,
 *   • percent → `value`% of the outstanding base once overdue,
 *   • daily   → `value` per day overdue.
 * `grace_days` suppresses the fine until that many days past due. Tenant + branch
 * scoped, soft-deleting.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS FILE NOW HOLDS THE ONLY FINE ARITHMETIC IN THE APPLICATION
 * ─────────────────────────────────────────────────────────────────────────────
 * There were TWO fine engines, and they had already diverged:
 *
 *   1. THIS TABLE — a named rule with `grace_days` and a `compute()` method,
 *      seeded by the demo fee seeder as "the school's published policy" … and read by
 *      NOTHING. No resource, no service, no controller, no report.
 *   2. THE INLINE COLUMNS on `fee_masters` — `fine_type` / `fine_amount` /
 *      `fine_after_days` / `fine_frequency`, with their own copy of the maths in
 *      FeeMaster::computeFine(). This is what the Collect screen, the payment
 *      controller and an API client actually used.
 *
 * They agreed on nothing. `fee_fines` had a `daily` TYPE; `fee_masters` had a
 * `daily` FREQUENCY that multiplies a fixed OR percent amount, plus `weekly` and
 * `monthly` rates this table never had. `fee_fines` called its grace `grace_days`;
 * `fee_masters` called it `fine_after_days`. A school editing its published policy
 * changed nothing anybody was charged.
 *
 * THE RESOLUTION (deliberately not a third path):
 *   • {@see evaluate()} below is the single calculation. Every fine figure in the
 *     system — the master's live rule, an allocation's frozen snapshot, the
 *     nightly accrual, the Collect screen's suggestion — comes out of this one
 *     function.
 *   • {@see compute()} keeps this model's own published signature and semantics
 *     and now delegates, so the legacy `daily` type is defined as exactly
 *     (fixed × daily) rather than being a second implementation of it.
 *   • `fee_masters.fee_fine_id` (added 2026_10_09_000002) lets a master POINT at
 *     a rule here instead of retyping it. {@see FeeMaster::fineRule()} returns the
 *     linked rule when set and the inline columns when it is not — one shape,
 *     one evaluator, two places to author it.
 *
 * The inline columns are NOT deprecated: they are the per-line override, they are
 * what every existing row and every seeder carries, and dropping them would
 * rewrite live money. Named rules are for policy a school states once.
 */
class FeeFine extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    public const TYPES = [
        'fixed' => 'Fixed',
        'percent' => 'Percentage',
        'daily' => 'Daily',
    ];

    /**
     * The canonical rule vocabulary, shared with FeeMaster: a TYPE (what the value
     * means) crossed with a FREQUENCY (how often it is charged while overdue).
     * `fee_fines.type = 'daily'` is the single legacy spelling of (fixed × daily)
     * and is normalised into it by {@see normalise()}.
     */
    public const CANONICAL_TYPES = ['none', 'fixed', 'percent'];

    public const CANONICAL_FREQUENCIES = ['one_time', 'daily', 'weekly', 'monthly'];

    protected $fillable = [
        'school_id',
        'branch_id',
        'name',
        'type',
        'value',
        'grace_days',
        'description',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'grace_days' => 'integer',
            'status' => 'boolean',
        ];
    }

    /** The fee masters that name this rule instead of carrying their own inline one. */
    public function feeMasters(): HasMany
    {
        return $this->hasMany(FeeMaster::class);
    }

    /**
     * Compute the fine for a `$base` amount that is `$daysOverdue` days past due.
     * Returns 0 until the grace period has elapsed.
     *
     * Behaviour is unchanged from the original hand-written version; it is simply
     * expressed through {@see evaluate()} now, so this model and FeeMaster can no
     * longer drift apart.
     */
    public function compute(float $base, int $daysOverdue): float
    {
        $rule = $this->toRule();

        return self::evaluate($rule, $base, $daysOverdue);
    }

    /**
     * This named rule in the canonical shape every fine consumer speaks:
     * ['type' => none|fixed|percent, 'amount' => float, 'frequency' =>
     * one_time|daily|weekly|monthly, 'grace_days' => int].
     *
     * @return array{type: string, amount: float, frequency: string, grace_days: int}
     */
    public function toRule(): array
    {
        return self::normalise($this->type, null, (float) $this->value, (int) $this->grace_days);
    }

    /**
     * Fold either vocabulary into the canonical one.
     *
     * `fee_fines.type = 'daily'` carries its accrual rate IN THE TYPE and has no
     * frequency column; `fee_masters` carries the two apart. Normalising here is
     * what lets one evaluator serve both without either caller knowing.
     *
     * @return array{type: string, amount: float, frequency: string, grace_days: int}
     */
    public static function normalise(?string $type, ?string $frequency, float $amount, int $graceDays): array
    {
        $frequency = in_array($frequency, self::CANONICAL_FREQUENCIES, true) ? $frequency : 'one_time';

        // The legacy spelling: a `daily` TYPE is a fixed amount on a daily rate.
        if ($type === 'daily') {
            $type = 'fixed';
            $frequency = 'daily';
        }

        return [
            'type' => in_array($type, self::CANONICAL_TYPES, true) ? $type : 'none',
            'amount' => round($amount, 2),
            'frequency' => $frequency,
            'grace_days' => max(0, $graceDays),
        ];
    }

    /**
     * THE fine calculation. Given a canonical rule (see {@see normalise()}), the
     * money the fine is measured against, and how many whole days the debt is past
     * due, return the fine as of that moment.
     *
     *   effective days = days overdue − grace days   (≤ 0 → no fine at all)
     *   unit           = fixed: the amount | percent: that % of `$base`
     *   multiplier     = one_time: 1 | daily: every effective day
     *                    | weekly: each started week | monthly: each started 30 days
     *   fine           = unit × multiplier
     *
     * `$base` IS THE CALLER'S CHOICE AND IT MATTERS. A percentage fine on a
     * ₹20,000 term fee is a very different number depending on whether it is
     * charged against the fee or against what the family still owes on it. The
     * accrual service passes the OUTSTANDING principal; a family that has paid
     * four fifths of a bill is fined on the fifth they have not.
     *
     * `$holidayDays` are days inside the overdue window on which the school was
     * shut (see FeeAccrualService, which reads them off the `holiday` events in
     * the academic calendar). They are removed from a DAILY accrual only: "₹20 for
     * every day this is late" is a charge per school day, and running the meter
     * through the Diwali break fines a family for an office that was closed. A
     * weekly, monthly or one-time fine is measured in CALENDAR time — the month
     * passed whether or not the school was open — so holidays leave it alone, and
     * so does the grace period, which is likewise stated in calendar days.
     *
     * @param  array{type?: string, amount?: float|string|null, frequency?: string, grace_days?: int}  $rule
     */
    public static function evaluate(array $rule, float $base, int $daysOverdue, int $holidayDays = 0): float
    {
        $type = $rule['type'] ?? 'none';

        if ($type === 'none' || $type === null) {
            return 0.0;
        }

        $frequency = $rule['frequency'] ?? 'one_time';
        $effectiveDays = $daysOverdue - (int) ($rule['grace_days'] ?? 0);

        if ($frequency === 'daily') {
            $effectiveDays -= max(0, $holidayDays);
        }

        if ($effectiveDays <= 0) {
            return 0.0;
        }

        $amount = (float) ($rule['amount'] ?? 0);

        $unit = $type === 'percent'
            ? $base * $amount / 100
            : $amount;

        $multiplier = match ($frequency) {
            'daily' => $effectiveDays,
            'weekly' => (int) ceil($effectiveDays / 7),
            'monthly' => (int) ceil($effectiveDays / 30),
            default => 1,
        };

        return round($unit * $multiplier, 2);
    }
}

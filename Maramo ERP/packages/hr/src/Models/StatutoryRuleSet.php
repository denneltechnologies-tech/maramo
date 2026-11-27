<?php

namespace Classivo\Hr\Models;

use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\Branch;
use Classivo\Hr\Services\Payroll\StatutoryCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * StatutoryRuleSet (spec §15 Payroll "Statutory deductions") — one VERSION of the
 * statutory rules, for one financial year, for one place.
 *
 * ── WHY THE RATES ARE ROWS ───────────────────────────────────────────────────
 * PF rates, the ESI threshold, professional-tax slabs and income-tax slabs
 * change on a budget's schedule and differ by state — professional tax runs from
 * "Karnataka's ladder" to "some states do not levy it". A constant in a
 * service means a school in another state, or the same school next April, needs
 * a deploy to be paid correctly, and being wrong here is the school failing a
 * statutory obligation rather than mis-drawing a chart.
 *
 * So a set is a row, its rates and slabs are {@see StatutoryRule} rows, and a new
 * financial year is data entry.
 *
 * ── TENANCY, AND THE ONE DELIBERATE DEPARTURE ────────────────────────────────
 * Tenant scoped on `school_id` like every other payroll table. It does NOT use
 * BelongsToBranch, and that is the whole design of `branch_id` being nullable:
 * null means "every campus", and BranchScope would hide precisely those rows the
 * moment a branch was selected — so the school-wide set would vanish for every
 * user who had picked a campus. Branch preference is therefore applied by
 * {@see resolveFor()} as an ORDER, not as a filter.
 *
 * ── EVERYTHING ARRIVES SWITCHED OFF ──────────────────────────────────────────
 * The set created by migration has every kind disabled, so deploying the
 * statutory engine deducts nothing from anybody. Enabling PF changes 38 people's
 * take-home pay; that is the school's decision to make, on a date of their
 * choosing, and not a migration's.
 */
class StatutoryRuleSet extends Model
{
    use BelongsToTenant, SoftDeletes;

    /**
     * Every kind the engine dispatches on.
     *
     * `payroll` is not a statutory levy. It lives here because loss of pay has
     * the same two properties the levies have — it is a rule the school sets
     * rather than a constant, and it must be versioned by year so that changing
     * the basis next April does not restate this April.
     */
    public const KINDS = [
        'pf' => 'Provident fund (EPF)',
        'esi' => 'Employees’ State Insurance',
        'professional_tax' => 'Professional tax',
        'income_tax' => 'Income tax (TDS)',
        'payroll' => 'Loss of pay & payroll basis',
    ];

    /** The kinds that are genuinely statutory deductions (i.e. not `payroll`). */
    public const STATUTORY_KINDS = ['pf', 'esi', 'professional_tax', 'income_tax'];

    protected $fillable = [
        'school_id',
        'branch_id',
        'name',
        'financial_year',
        'effective_from',
        'effective_to',
        'country_code',
        'state_code',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'status' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(StatutoryRule::class)->orderBy('sequence')->orderBy('id');
    }

    public function params(): HasMany
    {
        return $this->rules()->where('row_type', 'param');
    }

    public function slabs(): HasMany
    {
        return $this->rules()->where('row_type', 'slab');
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    /**
     * The set governing a payroll period.
     *
     * Matched on the LAST DAY of the month, not the first: a set that comes into
     * force mid-month governs the month it comes into force in, which is how a
     * budget change reads and how the school will have been told to apply it.
     *
     * A set pinned to $branchId beats the school-wide one — a campus in another
     * state has its own professional tax and must not inherit head office's.
     * Ties beyond that go to the most recently effective set.
     *
     * ── THE school_id PREDICATE IS NOT REDUNDANT ─────────────────────────────
     * {@see BelongsToTenant} documents that TenantScope
     * leaves reads UNSCOPED when the context is unresolved — which is exactly
     * what a console command or a queued job is. Combined with the
     * `orWhereNull('branch_id')` arm below, which matches any school's
     * school-wide set, an unresolved context could return ANOTHER SCHOOL'S PF
     * rates, ESI threshold and professional-tax slabs and pay 38 people against
     * them.
     *
     * Nothing schedules payroll today, so this is latent — but payroll is
     * precisely the thing somebody eventually puts on a schedule, and by then
     * the bug would be a wrong statutory remittance rather than a wrong screen.
     * Callers pass the school explicitly and the predicate is applied whether or
     * not the global scope happens to be active.
     */
    public static function resolveFor(?int $schoolId, ?int $branchId, int $month, int $year): ?self
    {
        if ($schoolId === null) {
            return null;
        }

        $periodEnd = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));

        return static::query()
            ->where('school_id', $schoolId)
            ->where('status', true)
            ->when(
                $branchId !== null,
                fn (Builder $q): Builder => $q->where(fn (Builder $inner): Builder => $inner
                    ->where('branch_id', $branchId)
                    ->orWhereNull('branch_id')),
                fn (Builder $q): Builder => $q->whereNull('branch_id'),
            )
            ->whereDate('effective_from', '<=', $periodEnd)
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $periodEnd))
            // Branch-specific first — MySQL sorts NULL low, so descending puts a
            // real branch_id above the school-wide null.
            ->orderByRaw('branch_id IS NULL ASC')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Params for one kind as key => raw string value.
     *
     * @return array<string, string>
     */
    public function paramsFor(string $kind): array
    {
        return $this->rules
            ->where('row_type', 'param')
            ->where('kind', $kind)
            ->filter(fn (StatutoryRule $rule): bool => $rule->param_key !== null)
            ->mapWithKeys(fn (StatutoryRule $rule): array => [$rule->param_key => (string) $rule->param_value])
            ->all();
    }

    /**
     * Slabs for one kind, in ladder order, optionally narrowed to a variant
     * (income-tax regime) and to a calendar month (a state that levies a
     * different professional tax in February).
     *
     * A slab with no `month` applies to every month; a month-pinned slab applies
     * only to that one. When ANY slab is pinned to the requested month, the
     * month-pinned ladder REPLACES the general one rather than adding to it —
     * that is what "₹200 every month except February, ₹300 in February" means,
     * and summing the two would charge ₹500.
     *
     * @return Collection<int, StatutoryRule>
     */
    public function slabsFor(string $kind, ?string $variant = null, ?int $month = null)
    {
        $slabs = $this->rules
            ->where('row_type', 'slab')
            ->where('kind', $kind)
            ->when($variant !== null, fn ($c) => $c->where('variant', $variant));

        if ($month !== null && $slabs->where('month', $month)->isNotEmpty()) {
            return $slabs->where('month', $month)->sortBy('sequence')->values();
        }

        return $slabs->whereNull('month')->sortBy('sequence')->values();
    }

    /** Is this kind switched on for this set? Everything is off until told otherwise. */
    public function enabled(string $kind): bool
    {
        return StatutoryCalculator::truthy($this->paramsFor($kind)['enabled'] ?? '0');
    }
}

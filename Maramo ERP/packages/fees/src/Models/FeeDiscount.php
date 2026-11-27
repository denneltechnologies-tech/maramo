<?php

namespace Classivo\Fees\Models;

use Carbon\CarbonInterface;
use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Fees\Filament\Pages\CollectFees;
use Classivo\Students\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeDiscount — a reusable concession, fixed amount or
 * percentage, applied to a student's allocation (gated `fees.discount`). Tenant +
 * branch scoped, soft-deleting.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * `valid_from` / `valid_to` ARE NOW ENFORCED. THEY WERE NOT.
 * ─────────────────────────────────────────────────────────────────────────────
 * Both columns have existed since the table was created, both are on the form, and
 * until now NOTHING in the application read either of them. An administrator who
 * set "valid to 31 March" watched that concession keep coming off bills in April,
 * and the only screen that could have told them otherwise — the discounts list —
 * showed the expiry in a column beside a discount that was still working.
 *
 * The enforcement is put in {@see computeFor()} rather than in the four call sites,
 * because computeFor() is the one place the money value of a concession is decided:
 * the Collect Fees action, the admission wizard, the concession register and
 * {@see FeeStudentAllocation::applyDiscount()} all reach it. An expired or
 * not-yet-started concession is therefore worth 0.00 everywhere at once, and no
 * future call site can forget the rule.
 *
 * WHAT THAT MEANS FOR A CALLER: 0.00 is a legitimate answer, not an error, so a
 * screen that reports "discount applied" without looking at the return value will
 * cheerfully report applying nothing. Every caller a user is watching therefore
 * asks {@see isUsableOn()} FIRST and refuses in words — see
 * {@see CollectFees::applyDiscount()}. Machine paths (the
 * admission service) get the silent zero, which is the correct outcome for them:
 * an expired concession grants nothing and the admission still completes.
 *
 * NULL means unbounded at that end — a concession with no `valid_to` never expires.
 */
class FeeDiscount extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    public const TYPES = [
        'fixed' => 'Fixed',
        'percent' => 'Percentage',
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'name',
        'code',
        'type',
        'value',
        'description',
        'valid_from',
        'valid_to',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'status' => 'boolean',
        ];
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'fee_discount_students')
            ->withPivot(['amount', 'status', 'fee_student_allocation_id', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * The concession register rows granted from this catalogue entry.
     *
     * Distinct from {@see students()}: that is the many-to-many for counting heads,
     * this is the grant records themselves — with their decision, recurrence and
     * reason — which is what the register screen lists.
     */
    public function grants(): HasMany
    {
        return $this->hasMany(FeeDiscountStudent::class, 'fee_discount_id');
    }

    /**
     * The money value of this discount against a `$base` amount: the fixed value, or
     * `value`% of the base — never more than the base itself, and NEVER anything at
     * all outside the concession's validity window (see the class docblock).
     */
    public function computeFor(float $base, ?CarbonInterface $on = null): float
    {
        if (! $this->isUsableOn($on)) {
            return 0.0;
        }

        $value = (float) $this->value;

        $amount = $this->type === 'percent'
            ? $base * $value / 100
            : $value;

        return round(min($amount, $base), 2);
    }

    /**
     * Is this concession live on `$on` (default: today)?
     *
     * Three conditions, and `status` is one of them: a deactivated discount that is
     * still inside its dates is no more usable than an expired one, and the two used
     * to be checked in different places (the pickers filtered `status`, nothing
     * checked the dates).
     */
    public function isUsableOn(?CarbonInterface $on = null): bool
    {
        $on = ($on ?? now())->copy()->startOfDay();

        if (! $this->status) {
            return false;
        }

        if ($this->valid_from !== null && $on->lt($this->valid_from->copy()->startOfDay())) {
            return false;
        }

        return $this->valid_to === null || $on->lte($this->valid_to->copy()->startOfDay());
    }

    /** Why this concession cannot be used right now, or null when it can. */
    public function unusableReason(?CarbonInterface $on = null): ?string
    {
        $on = ($on ?? now())->copy()->startOfDay();

        if (! $this->status) {
            return $this->name.' is not active.';
        }

        if ($this->valid_from !== null && $on->lt($this->valid_from->copy()->startOfDay())) {
            return $this->name.' does not start until '.$this->valid_from->format('d M Y').'.';
        }

        if ($this->valid_to !== null && $on->gt($this->valid_to->copy()->startOfDay())) {
            return $this->name.' expired on '.$this->valid_to->format('d M Y').'.';
        }

        return null;
    }

    /**
     * Active AND inside the validity window on `$on` — the query behind every
     * concession picker.
     *
     * The pickers used to filter on `status` alone, which is why an expired
     * concession stayed selectable. Applying the window in SQL keeps an option
     * the operator cannot use off the list entirely, rather than letting them
     * choose it and discover it is worth zero.
     */
    public function scopeUsableOn(Builder $query, ?CarbonInterface $on = null): Builder
    {
        $date = ($on ?? now())->toDateString();

        return $query->where('status', true)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }
}

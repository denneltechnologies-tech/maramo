<?php

namespace Classivo\Hr\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StaffSalaryStructure (spec §15 Payroll "Salary structure") — WHAT ONE EMPLOYEE
 * DRAWS UNDER ONE HEAD, FROM WHEN.
 *
 * The staff-side half of {@see SalaryComponent}. Tenant + branch scoped,
 * soft-deleting, activity-logged.
 *
 * ── EFFECTIVE DATING IS THE POINT ────────────────────────────────────────────
 * `staff.allowances` was one mutable JSON blob with no history, so payroll had
 * exactly one structure available to it: today's. Regenerating March after an
 * April raise restated March at the April figure, and a payslip somebody had
 * already been given changed underneath them.
 *
 * A raise is therefore a NEW ROW — close the old one with `effective_to`, open a
 * new one — and never an edit. {@see inForce()} asks for the structure that
 * applied during the payroll period, so March keeps paying March's salary no
 * matter how often it is regenerated.
 *
 * `effective_to` null means still in force.
 */
class StaffSalaryStructure extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'staff_id',
        'salary_component_id',
        'legacy_name',
        'amount',
        'percentage',
        'effective_from',
        'effective_to',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'percentage' => 'decimal:4',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    /**
     * Rows in force on $date — `effective_from` on or before it, and either open
     * ended or `effective_to` on or after it.
     *
     * The boundary is INCLUSIVE at both ends: a row dated "to 31 March" is still
     * in force on 31 March, which is how a human reads it and how the school
     * writes it on the letter.
     */
    public function scopeInForce(Builder $query, string $date): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date));
    }

    /**
     * The free-text head this row was migrated FROM, lower-cased for matching.
     *
     * Coverage of the legacy `staff.allowances` / `staff.deductions` JSON used to
     * be re-derived on every generation from the component's CURRENT code and
     * name, so renaming "House Rent Allowance" to "HRA" made the JSON entry look
     * unmigrated and payroll paid it a second time on top of this row. The
     * association is a historical fact, so it is written down once rather than
     * guessed from mutable text.
     */
    public function legacyKey(): ?string
    {
        $name = trim((string) $this->legacy_name);

        return $name === '' ? null : strtolower($name);
    }

    /** The rupee value of this row, ignoring percentage heads (resolver's job). */
    public function fixedAmount(): float
    {
        return (float) ($this->amount ?? 0);
    }
}

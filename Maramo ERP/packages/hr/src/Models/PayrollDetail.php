<?php

namespace Classivo\Hr\Models;

use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Support\HrAuth;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PayrollDetail (spec §15 Payroll allowance/deduction lines / §32 Finance) — a single
 * allowance or deduction row of a payroll. Child of Payroll (cascade-deleted); not
 * independently tenant-scoped.
 *
 * ── THE OVERRIDE, AND THE HOLE IT FILLS ──────────────────────────────────────
 * There was no edit path to a payroll line at all. The only way to change one
 * amount for one person for one month was to edit the staff master and
 * regenerate — which deleted every line and rebuilt them from the current
 * structure, so the correction hit everybody and every future month, and any
 * other hand-correction on that payroll was destroyed on the way past.
 *
 * `is_override` marks a line a human has set deliberately.
 * {@see PayrollService::generate()} rebuilds the computed lines and LEAVES the
 * overridden ones alone, and `computed_amount` keeps what the engine would have
 * paid — so the size of a correction is always visible rather than being a
 * number somebody has to take on trust, and so the line can be reverted.
 *
 * ── HISTORY ──────────────────────────────────────────────────────────────────
 * Every column added here is nullable or defaulted, and the payslip template
 * reads only `name`, `amount` and `type`. A payroll generated before any of this
 * existed reads back as source `legacy`, no component, not an override, sequence
 * 0 — and reprints byte-identically.
 */
class PayrollDetail extends Model
{
    public const TYPE_ALLOWANCE = 'allowance';

    public const TYPE_DEDUCTION = 'deduction';

    /**
     * The school's own PF/EPS/ESI cost.
     *
     * A third type rather than a deduction, deliberately: the payslip template
     * filters lines to `allowance` and `deduction`, so an employer contribution
     * is excluded from the employee's payslip by the filter that was already
     * there. It is a cost to the school, never a deduction from the employee,
     * and it must never reach net pay.
     */
    public const TYPE_EMPLOYER_CONTRIBUTION = 'employer_contribution';

    /** Where a line came from — decides whether generation may rebuild it. */
    public const SOURCES = [
        'legacy' => 'Legacy structure',
        'structure' => 'Salary structure',
        'statutory' => 'Statutory',
        'lop' => 'Loss of pay',
        'advance' => 'Advance recovery',
        'manual' => 'Added by hand',
    ];

    protected $fillable = [
        'payroll_id',
        'salary_component_id',
        'type',
        'name',
        'source',
        'amount',
        'computed_amount',
        'is_override',
        'override_reason',
        'overridden_by',
        'overridden_at',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'computed_amount' => 'decimal:2',
            'is_override' => 'boolean',
            'overridden_at' => 'datetime',
            'sequence' => 'integer',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(HrAuth::userModel(), 'overridden_by');
    }

    /**
     * How far the override moved the line — signed, so a reduction reads
     * negative. Null when the line is not an override or the engine never
     * priced it.
     */
    protected function overrideDelta(): Attribute
    {
        return Attribute::get(fn (): ?float => $this->is_override && $this->computed_amount !== null
            ? round((float) $this->amount - (float) $this->computed_amount, 2)
            : null);
    }

    /** Is this line the engine's to rebuild on the next generation? */
    public function isEngineOwned(): bool
    {
        return ! $this->is_override;
    }
}

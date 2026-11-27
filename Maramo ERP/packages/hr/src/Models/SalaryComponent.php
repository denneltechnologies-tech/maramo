<?php

namespace Classivo\Hr\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Hr\Services\Payroll\SalaryStructureResolver;
use Classivo\Hr\Services\Payroll\StatutoryCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SalaryComponent (spec §15 Payroll "Salary structure") — the salary HEAD master.
 *
 * The head itself (HRA, Conveyance, PF employee share); what a given employee
 * draws under it lives on {@see StaffSalaryStructure}. Tenant + branch scoped,
 * soft-deleting, activity-logged — the same shape as `staff` and `payrolls`.
 *
 * ── WHY A MASTER AT ALL ──────────────────────────────────────────────────────
 * A head used to be free text retyped per employee, so "HRA" and "H.R.A." were
 * two heads, nothing could total the school's HRA bill, and — the expensive
 * one — nothing could be a PERCENTAGE of anything, because a string is not an
 * operand. PF cannot be computed without knowing which heads make up PF wages.
 *
 * ── CALCULATION MODES ────────────────────────────────────────────────────────
 *   base_salary — value IS `staff.basic_salary`. One component (BASIC) uses it
 *                 and it does not emit a payslip line, because
 *                 `payrolls.basic_salary` already carries that figure and the
 *                 payslip prints it from there. It exists so other heads can
 *                 name BASIC as a percentage base.
 *   fixed       — the rupee amount on the employee's structure row.
 *   percentage  — `percentage`% of the summed value of {@see baseCodes()}, with
 *                 the employee's structure row allowed to override the rate.
 *   statutory   — computed by {@see StatutoryCalculator} for {@see $statutory_kind}.
 *   system      — supplied by the payroll engine: loss of pay, advance recovery.
 *
 * Resolution of all of these for one employee, one period, is
 * {@see SalaryStructureResolver}.
 */
class SalaryComponent extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    /**
     * `type` doubles as the value written into `payroll_details.type`, so the
     * first two ARE the vocabulary the payslip template already filters on. A
     * third was added rather than reusing one: employer contributions are the
     * school's cost, not the employee's deduction, and the payslip's existing
     * `where('type', 'deduction')` filter correctly leaves them off it.
     */
    public const TYPE_ALLOWANCE = 'allowance';

    public const TYPE_DEDUCTION = 'deduction';

    public const TYPE_EMPLOYER_CONTRIBUTION = 'employer_contribution';

    public const TYPES = [
        self::TYPE_ALLOWANCE => 'Allowance',
        self::TYPE_DEDUCTION => 'Deduction',
        self::TYPE_EMPLOYER_CONTRIBUTION => 'Employer contribution',
    ];

    public const CALCULATIONS = [
        'base_salary' => 'Basic salary',
        'fixed' => 'Fixed amount',
        'percentage' => 'Percentage of base',
        'statutory' => 'Statutory (computed)',
        'system' => 'System (computed)',
    ];

    /** The statutory kinds a component may bind to, and their engine keys. */
    public const STATUTORY_KINDS = [
        'pf_employee' => 'Provident fund — employee share',
        'pf_employer' => 'Provident fund — employer share',
        'pf_eps' => 'Pension scheme (EPS) — employer',
        'pf_edli' => 'EDLI — employer',
        'pf_admin' => 'EPF administration charges — employer',
        'esi_employee' => 'ESI — employee share',
        'esi_employer' => 'ESI — employer share',
        'professional_tax' => 'Professional tax',
        'income_tax' => 'Income tax (TDS)',
    ];

    /**
     * Codes the payroll engine looks up by name. Renaming or deleting one of
     * these breaks generation, which is why they carry `is_system`.
     */
    public const CODE_BASIC = 'BASIC';

    public const CODE_LOP = 'LOP';

    public const CODE_ADVANCE = 'ADVANCE';

    public const CODE_PF_EMPLOYEE = 'PF_EMPLOYEE';

    public const CODE_PF_EMPLOYER = 'PF_EMPLOYER';

    public const CODE_PF_EPS = 'PF_EPS';

    public const CODE_PF_EDLI = 'PF_EDLI';

    public const CODE_PF_ADMIN = 'PF_ADMIN';

    public const CODE_ESI_EMPLOYEE = 'ESI_EMPLOYEE';

    public const CODE_ESI_EMPLOYER = 'ESI_EMPLOYER';

    public const CODE_PT = 'PT';

    public const CODE_TDS = 'TDS';

    protected $fillable = [
        'school_id',
        'branch_id',
        'code',
        'name',
        'type',
        'calculation',
        'percentage',
        'base_codes',
        'statutory_kind',
        'supersedes_statutory_kind',
        'is_taxable',
        'pf_wage',
        'esi_wage',
        'prorates_on_lop',
        'emit_as_line',
        'display_order',
        'is_system',
        'status',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:4',
            'base_codes' => 'array',
            'is_taxable' => 'boolean',
            'pf_wage' => 'boolean',
            'esi_wage' => 'boolean',
            'prorates_on_lop' => 'boolean',
            'emit_as_line' => 'boolean',
            'display_order' => 'integer',
            'is_system' => 'boolean',
            'status' => 'boolean',
        ];
    }

    /**
     * Codes are machine keys, so they are normalised on the way in rather than
     * being left to whoever typed them. Without this, `hra` and `HRA` are two
     * rows and the unique index does not stop them.
     */
    protected static function booted(): void
    {
        $normalise = function (SalaryComponent $component): void {
            $code = strtoupper(trim((string) $component->getAttribute('code')));
            $component->setAttribute('code', preg_replace('/[^A-Z0-9_]+/', '_', $code));
        };

        static::creating($normalise);
        static::updating($normalise);
    }

    public function structures(): HasMany
    {
        return $this->hasMany(StaffSalaryStructure::class);
    }

    public function payrollDetails(): HasMany
    {
        return $this->hasMany(PayrollDetail::class);
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    /** Master ordering: display_order, then code, so the payslip reads sensibly. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('code');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** The base component codes for a percentage head (never null). */
    public function baseCodes(): array
    {
        return array_values(array_filter(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            (array) ($this->base_codes ?? []),
        )));
    }

    /**
     * Free-text head name → the code that head would merge into.
     *
     * The canonical copy of the rule the backfill migration applied: punctuation
     * inside an abbreviation is dropped, so "HRA", "H.R.A." and "hra." are one
     * head; word gaps become underscores so a real phrase stays readable.
     *
     * It lives here, and not only in the migration, because
     * {@see SalaryStructureResolver} needs it at RUNTIME to
     * decide whether a legacy JSON entry has already been migrated to a structure
     * row. Without that it cannot tell a half-migrated employee from a fully
     * migrated one. (The migration keeps its own private copy, as migrations must
     * be self-contained, and it has already run everywhere.)
     */
    public static function codeFor(string $name): string
    {
        // Punctuation inside/around a word disappears entirely …
        $code = preg_replace("/['`.\\-\\/]+/", '', trim($name)) ?? '';
        // … everything else that is not alphanumeric becomes a single gap.
        $code = preg_replace('/[^A-Za-z0-9]+/', '_', $code) ?? '';

        return substr(strtoupper(trim($code, '_')), 0, 50);
    }

    /**
     * The statutory obligation this HAND-ENTERED head stands in for, if any.
     *
     * A school that worked provident fund out on a spreadsheet arrives here with
     * a fixed `PROVIDENT_FUND` deduction head on every employee. Switching the
     * statutory engine on then emits `PF_EMPLOYEE` as well and the employee is
     * deducted twice for the same obligation. This says "these two are the same
     * money", so the engine can drop the manual one — and say that it did —
     * rather than charging both.
     *
     * Only meaningful on a non-system head whose amount a human types; the
     * engine's own statutory heads compute their own value and supersede nothing.
     */
    public function supersededStatutoryKind(): ?string
    {
        if ($this->is_system || $this->calculation === 'statutory') {
            return null;
        }

        $kind = trim((string) $this->supersedes_statutory_kind);

        return $kind === '' ? null : $kind;
    }

    /** Is this the BASIC pseudo-head that mirrors `staff.basic_salary`? */
    public function isBaseSalary(): bool
    {
        return $this->calculation === 'base_salary';
    }

    /** Does the rules engine own this head's amount? */
    public function isStatutory(): bool
    {
        return $this->calculation === 'statutory' && $this->statutory_kind !== null;
    }

    /** Employer-side cost that must never reach the employee's net pay. */
    public function isEmployerCost(): bool
    {
        return $this->type === self::TYPE_EMPLOYER_CONTRIBUTION;
    }

    /** Does an employee's structure carry a per-person amount for this head? */
    public function needsStructureAmount(): bool
    {
        return $this->calculation === 'fixed';
    }
}

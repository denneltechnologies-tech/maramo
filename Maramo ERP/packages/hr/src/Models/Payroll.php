<?php

namespace Classivo\Hr\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Hr\Events\SalaryPaid;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Support\HrAuth;
use Classivo\Hr\Support\HrLabels;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Payroll (spec §15 "Payroll" / §32 Finance) — one generated salary record per staff
 * per month/year. net_salary = basic + allowance_total − deduction_total − tax.
 * Tenant + branch scoped, soft-deleting, activity-logged, money decimal(15,2).
 *
 * Generated via {@see PayrollService::generate()} (gated `payroll.generate`)
 * and marked paid via {@see PayrollService::markPaid()} (gated `payroll.pay`,
 * which dispatches {@see SalaryPaid} for the finance seam).
 *
 * ── THE LOCK ─────────────────────────────────────────────────────────────────
 * Regenerating an unpaid payroll used to restate it at TODAY's salary, so a
 * payslip an employee had already downloaded could change afterwards with
 * nothing recording that it had. {@see lock()} freezes a payroll the moment it
 * is ISSUED — before any money moves — and generation refuses a locked row
 * exactly as it has always refused a paid one. Paying locks automatically.
 *
 * (The lock is the guard, not the whole fix. The fix is that
 * `staff_salary_structures` is effective-dated, so March resolves March's salary
 * however late it is regenerated. Both are needed: the lock alone would still
 * let a pre-lock regeneration restate the month, and the dating alone would
 * still let a corrected structure row rewrite an issued payslip.)
 *
 * ── EMPLOYER CONTRIBUTIONS ───────────────────────────────────────────────────
 * `employer_contribution_total` is the school's PF/EPS/ESI cost for the month.
 * It is deliberately NOT part of net_salary and does not appear on the payslip —
 * it is a cost and a liability, never a payment to the employee.
 */
class Payroll extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    public const STATUS_GENERATED = 'generated';

    public const STATUS_PAID = 'paid';

    public const STATUSES = [
        self::STATUS_GENERATED => 'Generated',
        self::STATUS_PAID => 'Paid',
    ];

    public const STATUS_COLORS = [
        self::STATUS_GENERATED => 'warning',
        self::STATUS_PAID => 'success',
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'staff_id',
        'month',
        'year',
        'month_days',
        'working_days',
        'payable_days',
        'lop_days',
        'basic_salary',
        'allowance_total',
        'deduction_total',
        'gross',
        'tax',
        'employer_contribution_total',
        'statutory_rule_set_id',
        'net_salary',
        'status',
        'payment_mode',
        'payment_date',
        'bank_account',
        'note',
        'generated_by',
        'paid_by',
        'paid_at',
        'locked_at',
        'locked_by',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'year' => 'integer',
            'month_days' => 'integer',
            'working_days' => 'decimal:2',
            'payable_days' => 'decimal:2',
            'lop_days' => 'decimal:2',
            'basic_salary' => 'decimal:2',
            'allowance_total' => 'decimal:2',
            'deduction_total' => 'decimal:2',
            'gross' => 'decimal:2',
            'tax' => 'decimal:2',
            'employer_contribution_total' => 'decimal:2',
            'net_salary' => 'decimal:2',
            'payment_date' => 'date:Y-m-d',
            'paid_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * The payslip lines.
     *
     * Ordered by `sequence` and then by id. Historical rows all carry sequence 0
     * (the column's default), so they fall back to insertion order — which is
     * exactly the order they have always rendered in, and is why introducing an
     * ordering here cannot reshuffle an old payslip.
     */
    public function details(): HasMany
    {
        return $this->hasMany(PayrollDetail::class)->orderBy('sequence')->orderBy('id');
    }

    public function allowances(): HasMany
    {
        return $this->details()->where('type', PayrollDetail::TYPE_ALLOWANCE);
    }

    public function deductions(): HasMany
    {
        return $this->details()->where('type', PayrollDetail::TYPE_DEDUCTION);
    }

    /**
     * The school's own PF/EPS/ESI cost for this month.
     *
     * A separate `type` rather than a deduction, so the payslip template's
     * existing `where('type', 'deduction')` filter leaves them off the
     * employee's payslip without needing to know they exist.
     */
    public function employerContributions(): HasMany
    {
        return $this->details()->where('type', PayrollDetail::TYPE_EMPLOYER_CONTRIBUTION);
    }

    /** Lines a human has set by hand, with the reason they gave. */
    public function overriddenDetails(): HasMany
    {
        return $this->details()->where('is_override', true);
    }

    public function statutoryRuleSet(): BelongsTo
    {
        return $this->belongsTo(StatutoryRuleSet::class, 'statutory_rule_set_id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(HrAuth::userModel(), 'locked_by');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(HrAuth::userModel(), 'generated_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(HrAuth::userModel(), 'paid_by');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Frozen against regeneration. Paying locks; issuing a payslip may lock earlier. */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Freeze this payroll so regeneration will not restate it.
     *
     * The point of locking BEFORE payment is that a payslip is issued before the
     * money moves: once the employee has seen the figure, the figure has to stop
     * changing, whatever anybody later edits on the staff master. Idempotent —
     * re-locking keeps the original timestamp, because when it was frozen is the
     * fact worth keeping.
     */
    public function lock(?int $actorId = null): self
    {
        if ($this->isLocked()) {
            return $this;
        }

        $this->update(['locked_at' => now(), 'locked_by' => $actorId]);

        return $this;
    }

    /**
     * Release the freeze so the payroll can be regenerated.
     *
     * A PAID payroll is never unlocked here: paying is the end of the line, and
     * a correction after payment belongs in the next month rather than in a
     * rewrite of a payslip the employee already holds.
     */
    public function unlock(): self
    {
        if ($this->isPaid()) {
            return $this;
        }

        $this->update(['locked_at' => null, 'locked_by' => null]);

        return $this;
    }

    /** Can generation still touch this row? */
    public function isRegenerable(): bool
    {
        return ! $this->isPaid() && ! $this->isLocked();
    }

    /** "August 2026" style label for the payroll period. */
    protected function periodLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->month
            ? HrLabels::monthYear((int) $this->month, (int) $this->year)
            : (string) $this->year);
    }

    /** "YYYY-MM" period key used by the SalaryPaid event payload. */
    protected function periodKey(): Attribute
    {
        return Attribute::get(fn (): string => sprintf('%04d-%02d', (int) $this->year, (int) $this->month));
    }
}

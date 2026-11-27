<?php

namespace Classivo\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StatutoryRule (spec §15 Payroll "Statutory deductions") — one rate, or one rung
 * of one ladder, belonging to a {@see StatutoryRuleSet}.
 *
 * Child of the rule set (cascade-deleted); not independently tenant-scoped, the
 * same arrangement {@see PayrollDetail} has with {@see Payroll}.
 *
 * ── TWO SHAPES ───────────────────────────────────────────────────────────────
 *   param — a named scalar. `pf.employee_rate = 12`, `esi.wage_threshold =
 *           21000`, `pf.enabled = 0`. Stored as text because one column has to
 *           carry rates, ceilings, flags and words ('nearest'); read back
 *           through {@see numericValue()} / {@see StatutoryCalculator::truthy()}.
 *   slab  — one rung: from_amount … to_amount (null = no upper bound) at
 *           `rate_percent`, or `fixed_amount`, or both. `month` pins a rung to
 *           one calendar month, which real professional-tax schedules need.
 */
class StatutoryRule extends Model
{
    public const ROW_TYPE_PARAM = 'param';

    public const ROW_TYPE_SLAB = 'slab';

    public const ROW_TYPES = [
        self::ROW_TYPE_PARAM => 'Rate / parameter',
        self::ROW_TYPE_SLAB => 'Slab',
    ];

    protected $fillable = [
        'statutory_rule_set_id',
        'kind',
        'row_type',
        'variant',
        'param_key',
        'param_value',
        'sequence',
        'from_amount',
        'to_amount',
        'rate_percent',
        'fixed_amount',
        'month',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'from_amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'rate_percent' => 'decimal:4',
            'fixed_amount' => 'decimal:2',
            'month' => 'integer',
        ];
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(StatutoryRuleSet::class, 'statutory_rule_set_id');
    }

    /** `param_value` read as a number; 0.0 for anything non-numeric. */
    public function numericValue(): float
    {
        return is_numeric($this->param_value) ? (float) $this->param_value : 0.0;
    }

    /**
     * Does $amount fall on this rung?
     *
     * The lower bound is EXCLUSIVE and the upper INCLUSIVE — "over ₹15,000 and
     * up to ₹20,000" is how every published slab table in this domain is
     * worded, and reading it the other way double-charges the boundary rupee.
     */
    public function covers(float $amount): bool
    {
        $from = (float) ($this->from_amount ?? 0);

        if ($amount <= $from) {
            return false;
        }

        return $this->to_amount === null || $amount <= (float) $this->to_amount;
    }

    /** Human label for the rung, for the panel and the audit trail. */
    public function slabLabel(): string
    {
        $from = number_format((float) ($this->from_amount ?? 0), 0);
        $to = $this->to_amount === null ? 'above' : number_format((float) $this->to_amount, 0);

        return $this->to_amount === null ? "Over {$from}" : "{$from} – {$to}";
    }
}

<?php

namespace Classivo\Hr\Services\Payroll;

use Classivo\Core\Support\Preset;
use Illuminate\Support\Facades\DB;

/**
 * StatutoryRuleSetProvisioner — gives a school a starting statutory rule set for
 * the current financial year, with EVERY KIND SWITCHED OFF.
 *
 * ── WHY A SCHOOL SHOULD NOT START FROM AN EMPTY SCREEN ───────────────────────
 * The rates below are a form somebody checks and edits, not a form somebody
 * fills in from nothing. Without this, configuring payroll for a new school
 * would mean knowing that a rule set is needed at all, then hand-typing
 * twenty-odd parameter rows with the right keys — and a missed key does not
 * announce itself, it just makes a deduction quietly wrong.
 *
 * ── AND WHY IT ARRIVES INERT ─────────────────────────────────────────────────
 * Every `enabled` flag is 0. Deploying this deducts nothing from anybody, and
 * switching PF on — which changes every employee's take-home pay — stays a
 * decision the school makes on a date of its choosing.
 *
 * Two kinds ship with no ladder at all, and that is the honest state rather than
 * an omission:
 *   • PROFESSIONAL TAX is a state levy whose slabs differ in every state that
 *     charges it, and several (including Uttar Pradesh) do not charge it.
 *   • INCOME TAX slabs change with each Finance Act, and TDS additionally
 *     depends on investment declarations this system does not capture.
 * A ladder with no rungs deducts nothing and reports why.
 *
 * The definition lives here rather than inside the migration so that a school
 * created later gets exactly the same rows — see {@see SalaryComponentProvisioner}
 * for the same reasoning applied to the salary-head master.
 */
class StatutoryRuleSetProvisioner
{
    /**
     * Scalars seeded per kind: [kind, param_key, param_value, note].
     *
     * Jurisdiction-free payroll params (hr.core_params) plus the active preset's
     * statutory params (hr.presets.<preset>.statutory_params). A preset with no
     * statutory params yields a rule set that deducts nothing.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: ?string}>
     */
    public static function params(): array
    {
        return array_merge(
            (array) config('hr.presets.'.Preset::name().'.statutory_params', []),
            (array) config('hr.core_params', []),
        );
    }

    /**
     * Ensure one school has a school-wide rule set for the financial year that
     * contains $on (default: today). Returns the set's id.
     *
     * Idempotent: a school that already has a set for that year keeps it
     * untouched, rates and edits and all.
     */
    public function ensureFor(int $schoolId, ?string $on = null): int
    {
        [$startYear, $label] = self::financialYearOf($on);

        $existing = DB::table('statutory_rule_sets')
            ->where('school_id', $schoolId)
            ->where('financial_year', $label)
            ->whereNull('branch_id')
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $now = now();

        $setId = (int) DB::table('statutory_rule_sets')->insertGetId([
            'school_id' => $schoolId,
            'branch_id' => null,
            'name' => 'Statutory rules '.$label,
            'financial_year' => $label,
            'effective_from' => sprintf('%04d-%02d-01', $startYear, self::startMonth()),
            'effective_to' => date('Y-m-d', strtotime(sprintf('%04d-%02d-01 +1 year -1 day', $startYear, self::startMonth()))),
            'country_code' => Preset::get('country') ?? config('hr.presets.'.Preset::name().'.country_code') ?? 'ZZ',
            'state_code' => null,
            'status' => true,
            'notes' => 'Created with the payroll statutory engine. Every kind is DISABLED: '
                .'switch one on only after checking its rates against the current rules, and enter '
                .'professional-tax and income-tax slabs (neither is pre-filled) before enabling those.',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $rows = [];

        foreach (self::params() as [$kind, $key, $value, $note]) {
            $rows[] = [
                'statutory_rule_set_id' => $setId,
                'kind' => $kind,
                'row_type' => 'param',
                'variant' => null,
                'param_key' => $key,
                'param_value' => $value,
                'sequence' => 0,
                'from_amount' => null,
                'to_amount' => null,
                'rate_percent' => null,
                'fixed_amount' => null,
                'month' => null,
                'note' => $note,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('statutory_rules')->insert($rows);

        return $setId;
    }

    /** Ensure EVERY school has one — what the creating migration runs. */
    public function ensureAllSchools(?string $on = null): void
    {
        DB::table('schools')->orderBy('id')->select('id')->chunk(100, function ($schools) use ($on): void {
            foreach ($schools as $school) {
                $this->ensureFor((int) $school->id, $on);
            }
        });
    }

    /** First month of the financial year for the active preset (India: April). */
    public static function startMonth(): int
    {
        return max(1, min(12, (int) config('hr.presets.'.Preset::name().'.fy_start_month', config('hr.fy_start_month', 4))));
    }

    /**
     * The financial year containing a date, for the active preset. January-start
     * presets use the calendar year; otherwise months before the start month
     * belong to the year that began the previous calendar year.
     *
     * @return array{0: int, 1: string} [start year, '2026-2027']
     */
    public static function financialYearOf(?string $on = null): array
    {
        $date = $on !== null ? strtotime($on) : time();
        $month = (int) date('n', $date);
        $year = (int) date('Y', $date);
        $start = self::startMonth();
        $startYear = $month >= $start ? $year : $year - 1;

        return [$startYear, $start === 1 ? (string) $startYear : $startYear.'-'.($startYear + 1)];
    }
}

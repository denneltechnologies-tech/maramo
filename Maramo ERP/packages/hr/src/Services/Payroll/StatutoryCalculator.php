<?php

namespace Classivo\Hr\Services\Payroll;

use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StatutoryRule;
use Classivo\Hr\Models\StatutoryRuleSet;
use Illuminate\Support\Collection;

/**
 * StatutoryCalculator — provident fund, ESI, professional tax and income tax,
 * COMPUTED FROM DATA rather than typed by a clerk or hard-coded by me.
 *
 * Every rate, ceiling, threshold and slab this class uses comes out of a
 * {@see StatutoryRuleSet}. Nothing statutory is a constant in this file. That is
 * the requirement and not a stylistic preference: a school in another state has
 * different professional tax, the same school has different rates next April,
 * and neither should need a deploy.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHAT IS FINISHED, AND WHAT IS DELIBERATELY NOT — READ THIS BEFORE ENABLING
 * ═════════════════════════════════════════════════════════════════════════════
 * Getting a statutory deduction wrong has a legal consequence for the school, so
 * this class states its own limits rather than producing a plausible number and
 * letting somebody find out later.
 *
 * ── PROVIDENT FUND — COMPLETE ────────────────────────────────────────────────
 * Employee share, employer share, the EPS split out of the employer share, and
 * the wage ceiling, all driven by params. EDLI and administration charges are
 * supported and ship at 0: they are employer costs whose current rates and
 * minimums the school must confirm, and a wrong number in an employer cost is
 * still a wrong challan.
 *
 * The one thing PF genuinely cannot compute on its own is WHICH HEADS ARE PF
 * WAGES. That is the `pf_wage` flag on the component master, and a school
 * enabling PF must set it head by head. It ships false on every migrated head,
 * which understates the base — visible and correctable, where an over-stated
 * base would silently over-deduct from 38 people.
 *
 * ── ESI — COMPLETE, WITH ONE NAMED GAP ───────────────────────────────────────
 * Employee and employer shares against the monthly wage threshold, params
 * driven, with ESI's round-up convention.
 *
 * THE GAP: ESI runs in six-month contribution periods (April–September,
 * October–March), and an employee whose wages rise above the threshold PART WAY
 * THROUGH one continues contributing until that period ends. This class tests
 * the threshold month by month, so it will stop deducting in the month the rise
 * happens rather than at the period boundary. Implementing it properly needs a
 * contribution-period ledger per employee, which is a module and not a branch in
 * an if-statement. A school with staff near the threshold must check this
 * manually until that exists.
 *
 * ── PROFESSIONAL TAX — ENGINE COMPLETE, NO SLABS SHIPPED ─────────────────────
 * The slab ladder works, including month-pinned rungs for the states that levy a
 * different amount in one month of the year. NO STATE'S SLABS ARE SEEDED, on
 * purpose. PT differs in every state that charges it, and the school this runs
 * for is in Uttar Pradesh, which does not levy it at all — so the correct
 * shipped state is empty. With no slabs the calculator deducts nothing and says
 * why. Entering a state's ladder is a screen, not a deploy.
 *
 * ── INCOME TAX — ENGINE COMPLETE, DELIBERATELY LEFT OFF ──────────────────────
 * The projection mechanism below is finished and correct as a mechanism:
 * annualise, allow the standard deduction, run the slab ladder marginally, apply
 * the rebate, add cess, spread the balance over the months left in the year, and
 * credit what has already been deducted.
 *
 * It ships DISABLED and with no slabs, and it should stay that way until two
 * things exist, because neither is a rounding error:
 *
 *   1. AN INVESTMENT-DECLARATION MODULE. Real TDS depends on what each employee
 *      declares — 80C, health insurance, housing-loan interest, HRA exemption
 *      against rent actually paid. None of that is captured anywhere in this
 *      system. Projecting on gross alone OVER-DEDUCTS from most employees, every
 *      month, and the employee only gets it back a year later by filing.
 *   2. THE CURRENT YEAR'S SLABS, entered by someone who has checked them against
 *      the Finance Act for that year. I have not seeded any, and would rather
 *      ship an empty ladder that deducts nothing and says so than a remembered
 *      one that deducts confidently and wrongly.
 *
 * Surcharge on high incomes and marginal relief are also not implemented; at
 * school-payroll salary levels they do not arise, and pretending otherwise would
 * be the same mistake in a different place.
 * ═════════════════════════════════════════════════════════════════════════════
 */
class StatutoryCalculator
{
    /** Rounding modes a `rounding` param may name. */
    public const ROUND_NEAREST = 'nearest';

    public const ROUND_UP = 'up';

    public const ROUND_NONE = 'none';

    /**
     * Provident fund for one employee for one month.
     *
     * @param  float  $pfWages  the summed value of the heads flagged `pf_wage`
     * @return array{
     *     applied: bool, reason: string, wages: float,
     *     employee: float, employer: float, pension: float, edli: float, admin: float
     * }
     */
    public function providentFund(StatutoryRuleSet $set, Staff $staff, float $pfWages): array
    {
        $nil = [
            'applied' => false, 'reason' => '', 'wages' => 0.0,
            'employee' => 0.0, 'employer' => 0.0, 'pension' => 0.0, 'edli' => 0.0, 'admin' => 0.0,
        ];

        $params = $set->paramsFor('pf');

        if (! self::truthy($params['enabled'] ?? '0')) {
            return ['reason' => __('hr::messages.calc.pf_off')] + $nil;
        }

        // `epf_no` has been captured on every staff record since the module
        // shipped and has never been used as anything but a label. It is the
        // school's own statement that this person is a PF member, so it is the
        // membership test — rather than a second flag meaning the same thing.
        if (self::truthy($params['require_member_no'] ?? '1') && blank($staff->epf_no)) {
            return ['reason' => __('hr::messages.calc.pf_no_member')] + $nil;
        }

        if ($pfWages <= 0) {
            return ['reason' => __('hr::messages.calc.pf_no_wages')] + $nil;
        }

        $ceiling = self::number($params['wage_ceiling'] ?? null);
        $wages = self::truthy($params['apply_ceiling'] ?? '1') && $ceiling > 0
            ? min($pfWages, $ceiling)
            : $pfWages;

        $mode = $params['rounding'] ?? self::ROUND_NEAREST;

        // EPS is capped at its own ceiling even for an establishment that has
        // opted to contribute PF on full wages — the two ceilings are separate
        // rules and collapsing them over-funds the pension side.
        $pensionCeiling = self::number($params['pension_ceiling'] ?? null);
        $pensionWages = $pensionCeiling > 0 ? min($wages, $pensionCeiling) : $wages;

        $employee = self::round($wages * self::number($params['employee_rate'] ?? null) / 100, $mode);
        $employerTotal = self::round($wages * self::number($params['employer_rate'] ?? null) / 100, $mode);
        $pension = self::round($pensionWages * self::number($params['pension_rate'] ?? null) / 100, $mode);

        // The employer's contribution is ONE payment split two ways; the EPF
        // slice is whatever the pension slice leaves. max() guards a rule set
        // where somebody has typed a pension rate above the employer rate.
        $employerPf = max(0.0, $employerTotal - $pension);

        return [
            'applied' => true,
            'reason' => '',
            'wages' => round($wages, 2),
            'employee' => $employee,
            'employer' => $employerPf,
            'pension' => $pension,
            'edli' => self::round($pensionWages * self::number($params['edli_rate'] ?? null) / 100, $mode),
            'admin' => self::round($wages * self::number($params['admin_rate'] ?? null) / 100, $mode),
        ];
    }

    /**
     * ESI for one employee for one month.
     *
     * See the class note for the contribution-period gap: this tests the
     * threshold in the month being paid, and does not carry an employee through
     * to the end of a six-month period after their wages rise.
     *
     * @param  float  $esiWages  the summed value of the heads flagged `esi_wage`
     * @return array{applied: bool, reason: string, wages: float, employee: float, employer: float}
     */
    public function esi(StatutoryRuleSet $set, Staff $staff, float $esiWages): array
    {
        $nil = ['applied' => false, 'reason' => '', 'wages' => 0.0, 'employee' => 0.0, 'employer' => 0.0];

        $params = $set->paramsFor('esi');

        if (! self::truthy($params['enabled'] ?? '0')) {
            return ['reason' => __('hr::messages.calc.esi_off')] + $nil;
        }

        if (self::truthy($params['require_member_no'] ?? '1') && blank($staff->esi_no)) {
            return ['reason' => __('hr::messages.calc.esi_no_member')] + $nil;
        }

        if ($esiWages <= 0) {
            return ['reason' => __('hr::messages.calc.esi_no_wages')] + $nil;
        }

        $threshold = self::number($params['wage_threshold'] ?? null);

        if ($threshold > 0 && $esiWages > $threshold) {
            return ['reason' => 'Wages exceed the ESI threshold of '.number_format($threshold, 0).'.'] + $nil;
        }

        $mode = $params['rounding'] ?? self::ROUND_UP;

        return [
            'applied' => true,
            'reason' => '',
            'wages' => round($esiWages, 2),
            'employee' => self::round($esiWages * self::number($params['employee_rate'] ?? null) / 100, $mode),
            'employer' => self::round($esiWages * self::number($params['employer_rate'] ?? null) / 100, $mode),
        ];
    }

    /**
     * Professional tax for one month.
     *
     * A flat slab charge, not a marginal ladder: PT slabs name an AMOUNT for a
     * wage band, so the covering rung's `fixed_amount` (or its percentage of
     * wages, for the few schedules expressed that way) is the whole answer.
     *
     * @return array{applied: bool, reason: string, amount: float, slab: ?string}
     */
    public function professionalTax(StatutoryRuleSet $set, float $wages, int $month): array
    {
        $nil = ['applied' => false, 'reason' => '', 'amount' => 0.0, 'slab' => null];

        $params = $set->paramsFor('professional_tax');

        if (! self::truthy($params['enabled'] ?? '0')) {
            return ['reason' => __('hr::messages.calc.pt_off')] + $nil;
        }

        $slabs = $set->slabsFor('professional_tax', null, $month);

        if ($slabs->isEmpty()) {
            return ['reason' => __('hr::messages.calc.pt_no_slabs')] + $nil;
        }

        foreach ($slabs as $slab) {
            if (! $slab->covers($wages)) {
                continue;
            }

            // Same mutually-exclusive reading of the two columns as
            // {@see applyLadder()}, with the precedence the other way round
            // because a PT schedule is normally a flat monthly amount per band.
            $amount = $slab->fixed_amount !== null
                ? (float) $slab->fixed_amount
                : $wages * (float) ($slab->rate_percent ?? 0) / 100;

            return [
                'applied' => true,
                'reason' => '',
                'amount' => self::round($amount, $params['rounding'] ?? self::ROUND_NEAREST),
                'slab' => $slab->slabLabel(),
            ];
        }

        // Every published PT schedule starts with an exempt band, so falling off
        // the bottom is a normal outcome and not a misconfiguration.
        return ['reason' => __('hr::messages.calc.pt_below')] + $nil;
    }

    /**
     * Income tax (TDS) for one month, by annual projection.
     *
     * THE MECHANISM, which is what is finished here:
     *   annual taxable  = already earned this financial year
     *                     + this month's taxable pay × months remaining
     *   less the standard deduction
     *   → the slab ladder, applied MARGINALLY (each rung taxes only the slice of
     *     income inside it, which is what a slab table means and what a flat
     *     "find the band, multiply the lot" reading gets wrong)
     *   less the rebate, when taxable income is at or below its limit
     *   plus cess
     *   → this month's deduction = (annual tax − tax already deducted this year)
     *     ÷ months remaining, so a mid-year change spreads over what is left
     *     rather than landing in one payslip.
     *
     * WHAT IS NOT HERE is in the class note, and it is the part that decides
     * whether the number is right: there is no investment-declaration module, so
     * this projects on gross taxable pay and will OVER-deduct from most people.
     * It ships disabled and with no slabs for exactly that reason.
     *
     * @param  float  $monthlyTaxable  taxable earnings in the month being paid
     * @param  float  $earnedToDate  taxable earnings already paid this financial year
     * @param  float  $taxPaidToDate  TDS already deducted this financial year
     * @param  int  $monthsRemaining  months left in the financial year, INCLUDING this one
     * @return array{applied: bool, reason: string, amount: float, annual_taxable: float, annual_tax: float}
     */
    public function incomeTax(
        StatutoryRuleSet $set,
        float $monthlyTaxable,
        float $earnedToDate,
        float $taxPaidToDate,
        int $monthsRemaining,
    ): array {
        $nil = ['applied' => false, 'reason' => '', 'amount' => 0.0, 'annual_taxable' => 0.0, 'annual_tax' => 0.0];

        $params = $set->paramsFor('income_tax');

        if (! self::truthy($params['enabled'] ?? '0')) {
            return ['reason' => __('hr::messages.calc.it_off')] + $nil;
        }

        $regime = $params['regime'] ?? null;
        $slabs = $set->slabsFor('income_tax', $regime);

        if ($slabs->isEmpty()) {
            return ['reason' => __('hr::messages.calc.it_no_slabs', ['regime' => $regime ? __('hr::messages.calc.regime_suffix', ['regime' => $regime]) : ''])] + $nil;
        }

        $monthsRemaining = max(1, $monthsRemaining);

        $annualGross = $earnedToDate + ($monthlyTaxable * $monthsRemaining);
        $annualTaxable = max(0.0, $annualGross - self::number($params['standard_deduction'] ?? null));

        $tax = $this->applyLadder($slabs, $annualTaxable);

        // The rebate zeroes small liabilities outright; it is capped, and it is
        // tested against TAXABLE income, not against the tax.
        $rebateLimit = self::number($params['rebate_taxable_limit'] ?? null);
        $rebateMax = self::number($params['rebate_max'] ?? null);

        if ($rebateLimit > 0 && $annualTaxable <= $rebateLimit) {
            $tax = max(0.0, $tax - min($tax, $rebateMax));
        }

        $tax += $tax * self::number($params['cess_rate'] ?? null) / 100;

        // Spread only what is still owed. A negative balance (someone over-
        // deducted earlier in the year) stops at zero rather than refunding
        // through payroll, which is not this system's call to make.
        $monthly = max(0.0, $tax - $taxPaidToDate) / $monthsRemaining;

        return [
            'applied' => true,
            'reason' => '',
            'amount' => self::round($monthly, $params['rounding'] ?? self::ROUND_NEAREST),
            'annual_taxable' => round($annualTaxable, 2),
            'annual_tax' => round($tax, 2),
        ];
    }

    /**
     * Run a slab ladder MARGINALLY over $income.
     *
     * Each rung charges only the slice of income that falls inside it. Reading a
     * slab table as "find the band your income lands in and charge that rate on
     * everything" is the classic error and over-charges every income just above
     * a boundary.
     *
     * ── THE TWO COLUMNS ARE MUTUALLY EXCLUSIVE, PER RUNG ─────────────────────
     * `rate_percent` and `fixed_amount` are alternatives, not addends, and this
     * is the SAME reading {@see professionalTax()} applies — the two used to
     * disagree inside one class, which is how a ladder ends up charging twice.
     *
     *   rate_percent set → charge that rate on the slice inside this rung.
     *   otherwise        → charge `fixed_amount` flat for reaching this rung.
     *
     * THE TRAP THIS AVOIDS: published Indian income-tax tables state a rung as
     * "₹15,000 + 20% of the amount above ₹9,00,000", where the ₹15,000 is the
     * CUMULATIVE tax of every rung below. Adding that figure on top of a
     * marginal ladder counts the lower rungs twice. Enter only the marginal rate
     * for each band and let the ladder accumulate — it computes the "₹15,000 +"
     * part itself. The Statutory Rules form says the same thing beside the field.
     *
     * @param  Collection<int, StatutoryRule>  $slabs
     */
    protected function applyLadder($slabs, float $income): float
    {
        $tax = 0.0;

        foreach ($slabs as $slab) {
            $from = (float) ($slab->from_amount ?? 0);

            if ($income <= $from) {
                continue;
            }

            if ($slab->rate_percent !== null) {
                $to = $slab->to_amount === null ? $income : min($income, (float) $slab->to_amount);
                $tax += max(0.0, $to - $from) * (float) $slab->rate_percent / 100;

                continue;
            }

            $tax += (float) ($slab->fixed_amount ?? 0);
        }

        return $tax;
    }

    // ---------------------------------------------------------------------
    // Param reading
    // ---------------------------------------------------------------------

    /**
     * A param read as a flag.
     *
     * Deliberately strict about what counts as ON: '1', 'true', 'yes', 'on'.
     * Anything else — including an absent param, a typo and an empty string — is
     * OFF, so a mis-typed rule set fails towards deducting nothing rather than
     * towards deducting something nobody sanctioned.
     */
    public static function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** A param read as a number; 0.0 for absent or non-numeric. */
    public static function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** Apply a rule set's rounding convention to a computed contribution. */
    public static function round(float $amount, string $mode): float
    {
        return match ($mode) {
            self::ROUND_UP => (float) ceil($amount),
            self::ROUND_NONE => round($amount, 2),
            default => (float) round($amount),
        };
    }

    /**
     * The financial year (start month from the active preset; India: April) a
     * payroll period belongs to, as a '2026-2027' label and the months elapsed/remaining.
     *
     * The start month opens the year, so earlier months belong to the year that began
     * the PREVIOUS calendar year — the off-by-one that makes a January projection wrong
     * if it is not handled.
     *
     * @return array{label: string, start_year: int, months_remaining: int, months_elapsed: int}
     */
    public static function financialYear(int $month, int $year): array
    {
        $start = StatutoryRuleSetProvisioner::startMonth();
        $startYear = $month >= $start ? $year : $year - 1;
        // Position of this month within the financial year, 1-based.
        $index = ($month - $start + 12) % 12 + 1;

        return [
            'label' => $start === 1 ? (string) $startYear : $startYear.'-'.($startYear + 1),
            'start_year' => $startYear,
            'months_remaining' => 12 - $index + 1,
            'months_elapsed' => $index - 1,
        ];
    }
}

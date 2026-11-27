<?php

namespace Classivo\Hr\Services;

use Classivo\Hr\Events\SalaryPaid;
use Classivo\Hr\Events\SalaryPaying;
use Classivo\Hr\Http\Controllers\PayrollPdfController;
use Classivo\Hr\Models\AdvanceSalary;
use Classivo\Hr\Models\Payroll;
use Classivo\Hr\Models\PayrollDetail;
use Classivo\Hr\Models\SalaryComponent;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffSalaryStructure;
use Classivo\Hr\Models\StatutoryRuleSet;
use Classivo\Hr\Services\Payroll\LossOfPayCalculator;
use Classivo\Hr\Services\Payroll\SalaryComponentProvisioner;
use Classivo\Hr\Services\Payroll\SalaryStructureResolver;
use Classivo\Hr\Services\Payroll\StatutoryCalculator;
use Classivo\Hr\Support\Currency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PayrollService (spec §15 "Payroll") — the single code path shared by the Payroll
 * resource, the Generate Payroll page, the payslip PDF and the test suite.
 *
 * Salary model (documented once, used everywhere):
 *   • gross           = basic_salary + Σ allowances
 *   • deduction_total = Σ deduction lines (structure, loss of pay, statutory
 *                       employee shares, advance recovery)
 *   • net_salary      = gross − deduction_total − tax
 *   • employer_contribution_total = Σ employer_contribution lines. NOT part of
 *                       net pay and NOT on the employee's payslip — it is the
 *                       school's own cost and the basis of its monthly challan.
 *
 * Income tax is carried by `payrolls.tax` and emits no detail line, because the
 * payslip template already prints that column on its own row; a line as well
 * would deduct it twice. Same reasoning keeps BASIC out of the lines.
 *
 * ── WHAT CHANGED, AND WHAT DID NOT ───────────────────────────────────────────
 * Generation used to read two free-text JSON blobs off the staff record, add
 * approved advances, and write the result. It now resolves a real salary
 * structure ({@see SalaryStructureResolver}), consults attendance
 * ({@see LossOfPayCalculator}) and computes statutory deductions from versioned
 * data ({@see StatutoryCalculator}).
 *
 * All three of those arrive SWITCHED OFF. Loss of pay ships disabled, every
 * statutory kind ships disabled, and an employee with no structure rows falls
 * back to the legacy JSON priced exactly as before. Running generation on the
 * day this deploys therefore produces the same numbers it produced the day
 * before — which is the only acceptable behaviour on a live payroll with real
 * staff, and it means each capability can be turned on deliberately, one at a
 * time, by somebody who can check the result.
 *
 * ── THREE THINGS GENERATION WILL NOT DO ──────────────────────────────────────
 *   1. TOUCH A PAID PAYROLL. It never did.
 *   2. TOUCH A LOCKED PAYROLL. New. Regenerating an unpaid payroll used to
 *      restate it at TODAY's salary, so a payslip an employee had already been
 *      shown could change underneath them with nothing recording that it had.
 *      Effective-dated structures are the fix — March resolves March's salary
 *      however late it is regenerated — and {@see Payroll::lock()} is the guard
 *      for a payslip already issued.
 *   3. DISCARD AN OVERRIDE. Also new, and the reason there was no edit path at
 *      all before: the only way to change one amount was to edit the staff
 *      master and regenerate, which rebuilt every line for everybody. Overridden
 *      lines are now left exactly where they are while the computed ones are
 *      rebuilt around them.
 *
 * ── THE LEDGER SEAM ──────────────────────────────────────────────────────────
 * Salary is NOT posted to the double-entry ledger from here. {@see markPaid()}
 * dispatches {@see SalaryPaid}, which is the one seam; see the note on that
 * method for what a posting listener needs and what it must read off the
 * payroll to balance an entry.
 */
class PayrollService
{
    public function __construct(
        private readonly SalaryStructureResolver $structures,
        private readonly StatutoryCalculator $statutory,
        private readonly LossOfPayCalculator $lossOfPay,
    ) {}

    /**
     * Generate (or regenerate) the payroll for a staff member for a month/year.
     * Returns the persisted Payroll with its allowance/deduction detail lines. A
     * row already marked PAID — or LOCKED — is returned unchanged.
     *
     * $tax keeps its original meaning as an explicit override and its original
     * default behaviour. It is now NULLABLE, and null (the default, and what
     * every caller has always effectively passed) means "let the statutory
     * engine decide" — which, with income tax shipped disabled, still comes to
     * zero. A caller that passes a float still wins outright.
     */
    public function generate(Staff $staff, int $month, int $year, ?int $generatedBy = null, ?float $tax = null): Payroll
    {
        return DB::transaction(function () use ($staff, $month, $year, $generatedBy, $tax): Payroll {
            $existing = Payroll::where('staff_id', $staff->id)
                ->where('month', $month)
                ->where('year', $year)
                ->first();

            if ($existing && ($existing->isPaid() || $existing->isLocked())) {
                return $existing->load('details');
            }

            $computation = $this->compute($staff, $month, $year, $tax);

            $payroll = Payroll::updateOrCreate(
                ['staff_id' => $staff->id, 'month' => $month, 'year' => $year],
                [
                    'school_id' => $staff->school_id,
                    'branch_id' => $staff->branch_id,
                    'basic_salary' => $computation['basic'],
                    'allowance_total' => $computation['allowance_total'],
                    'deduction_total' => $computation['deduction_total'],
                    'gross' => $computation['gross'],
                    'tax' => $computation['tax'],
                    'net_salary' => $computation['net'],
                    'employer_contribution_total' => $computation['employer_total'],
                    'month_days' => $computation['lop']['month_days'],
                    'working_days' => $computation['lop']['working_days'],
                    'payable_days' => $computation['lop']['payable_days'],
                    'lop_days' => $computation['lop']['lop_days'],
                    'statutory_rule_set_id' => $computation['rule_set_id'],
                    'status' => Payroll::STATUS_GENERATED,
                    'generated_by' => $generatedBy,
                ],
            );

            $this->writeDetails($payroll, $computation['lines']);

            // Totals are re-read from what was actually persisted rather than
            // trusted from the computation, because an overridden line may have
            // survived with an amount the engine did not choose. The payslip and
            // the header must agree or the employee is looking at two answers.
            $this->recalculateTotals($payroll);

            return $payroll->load('details');
        });
    }

    /**
     * The whole calculation for one employee/period, with nothing persisted.
     *
     * Public because the Generate Payroll page previews it before writing, and
     * because it is the honest unit to test: every branch of the salary,
     * attendance and statutory logic is reachable without a payroll row.
     *
     * @return array<string, mixed>
     */
    public function compute(Staff $staff, int $month, int $year, ?float $tax = null): array
    {
        // The school is passed explicitly: TenantScope is a documented no-op in an
        // unresolved (console / queue) context, and the set lookup has a
        // "school-wide" arm that would otherwise match another tenant's rates.
        $ruleSet = StatutoryRuleSet::resolveFor($staff->school_id, $staff->branch_id, $month, $year);
        $payrollParams = $ruleSet?->paramsFor('payroll') ?? [];

        $structure = $this->structures->resolve($staff, $month, $year);
        $lop = $this->lossOfPay->calculate($staff, $month, $year, $payrollParams);

        $lines = $structure['lines'];

        // ── Loss of pay ──────────────────────────────────────────────────────
        // `factor` is the share of the month actually earned. It is 1.0 whenever
        // loss of pay is switched off, which is how the default install behaves
        // and why enabling the engine changes nobody's pay on its own.
        $factor = 1.0;

        // The one case that ignores the loss-of-pay switch: an employee who was
        // not employed for ANY of this period. That is not "loss of pay", it is
        // "there was no employment to pay for", and paying a full month to
        // somebody who starts next month is wrong whatever the flags say.
        $applyProration = $lop['enabled'] || ($lop['not_employed_at_all'] ?? false);

        if ($applyProration && $lop['lop_days'] > 0 && $lop['basis_days'] > 0) {
            $factor = max(0.0, 1 - ($lop['lop_days'] / $lop['basis_days']));
        }

        // `prorates_on_lop` is ignored entirely when the employee was not employed
        // for ANY of the period. That flag answers "does this head shrink when
        // somebody misses days of a month they worked" — a fixed reimbursement
        // reasonably says no. It does not answer "should we pay it to somebody who
        // does not start until next month", where the answer is always no, and
        // honouring it there left a full non-prorating allowance on the payslip.
        $notEmployedAtAll = (bool) ($lop['not_employed_at_all'] ?? false);

        // Nor are DEDUCTIONS taken from a period nobody was employed for. A
        // welfare-fund subscription or a legacy PF line deducted against pay that
        // does not exist produced a NEGATIVE net salary — the payroll owing the
        // employee money for a month before they started.
        if ($notEmployedAtAll) {
            $lines = array_values(array_filter(
                $lines,
                static fn (array $line): bool => $line['type'] === SalaryComponent::TYPE_ALLOWANCE,
            ));
        }

        $prorated = 0.0;

        foreach ($lines as $line) {
            // A head that emits no payslip line is not PAID, so it cannot be
            // docked either. Including one here charged loss of pay against money
            // the employee never received — and the same head was inflating the
            // PF and ESI wage bases, so it was over-deducting twice over.
            if (($line['emit_as_line'] ?? true) === false) {
                continue;
            }

            if ($line['type'] === SalaryComponent::TYPE_ALLOWANCE && ($notEmployedAtAll || $line['prorates_on_lop'])) {
                $prorated += $line['amount'];
            }
        }

        // BASIC is not among the lines (it emits none) but is the head that
        // matters most for loss of pay, so it joins the prorated base here.
        //
        // Resolved through basicComponent(), which finds a DEACTIVATED OR
        // SOFT-DELETED BASIC head too. componentByCode() does not, and losing the
        // row lost its `pf_wage` / `esi_wage` flags — see the note on that method
        // for what that did to the contribution bases.
        $basicComponent = $this->structures->basicComponent($staff);

        if ($notEmployedAtAll || $basicComponent === null || $basicComponent->prorates_on_lop) {
            $prorated += $structure['basic'];
        }

        $lopAmount = round($prorated * (1 - $factor), 2);

        if ($lopAmount > 0) {
            $lines[] = $this->systemLine($staff, SalaryComponent::CODE_LOP, $this->lopLineName($lop), $lopAmount, 'lop', 500);
        }

        // ── Statutory ────────────────────────────────────────────────────────
        // Wage bases are taken AFTER loss of pay: provident fund and ESI are
        // contributions on wages actually earned, so a fortnight unpaid is a
        // fortnight uncontributed. With loss of pay off, factor is 1 and this is
        // simply the full wage.
        $pfWages = $this->wageBase($lines, $structure, $basicComponent, 'pf_wage', $factor);
        $esiWages = $this->wageBase($lines, $structure, $basicComponent, 'esi_wage', $factor);

        // Professional tax is assessed on the month's EARNINGS, and needs a base
        // of its own rather than borrowing ESI's.
        //
        // It borrowed it before, and that was silently wrong in the common case:
        // a school that has not gone through the head master ticking `esi_wage`
        // has an ESI base of zero, so professional tax was assessed on ₹0, fell
        // off the bottom of every slab and deducted nothing — with no error and
        // nothing on the payslip to say so. PT is not conditional on ESI in any
        // state, and it must not be conditional on an ESI FLAG here.
        $grossWages = $this->earnedWages($lines, $structure, $basicComponent, $factor);

        $statutory = ['pf' => null, 'esi' => null, 'professional_tax' => null, 'income_tax' => null];
        $computedTax = 0.0;
        $statutoryNotes = [];

        if ($ruleSet !== null) {
            $statutory['pf'] = $this->statutory->providentFund($ruleSet, $staff, $pfWages);
            $statutory['esi'] = $this->statutory->esi($ruleSet, $staff, $esiWages);

            foreach ([
                [SalaryComponent::CODE_PF_EMPLOYEE, 'pf', 'employee', 520],
                [SalaryComponent::CODE_ESI_EMPLOYEE, 'esi', 'employee', 530],
                [SalaryComponent::CODE_PF_EMPLOYER, 'pf', 'employer', 700],
                [SalaryComponent::CODE_PF_EPS, 'pf', 'pension', 710],
                [SalaryComponent::CODE_PF_EDLI, 'pf', 'edli', 715],
                [SalaryComponent::CODE_PF_ADMIN, 'pf', 'admin', 718],
                [SalaryComponent::CODE_ESI_EMPLOYER, 'esi', 'employer', 720],
            ] as [$code, $kind, $key, $order]) {
                $amount = (float) ($statutory[$kind][$key] ?? 0);

                if ($amount > 0) {
                    $lines[] = $this->statutoryLine($staff, $code, $amount, $order);
                }
            }

            $pt = $this->statutory->professionalTax($ruleSet, $grossWages, $month);
            $statutory['professional_tax'] = $pt;

            if (($pt['amount'] ?? 0) > 0) {
                $lines[] = $this->statutoryLine($staff, SalaryComponent::CODE_PT, (float) $pt['amount'], 540);
            }

            $statutory['income_tax'] = $this->projectIncomeTax($ruleSet, $staff, $lines, $structure, $factor, $month, $year, $basicComponent);
            $computedTax = (float) ($statutory['income_tax']['amount'] ?? 0);

            // ── ONE OBLIGATION, ONE LINE ─────────────────────────────────────
            // Everything above this point can now have produced a computed PF,
            // ESI or professional-tax line for an employee who ALREADY carries a
            // hand-entered head for the same thing — which is every employee at
            // every school that came through the salary-structure migration,
            // because that is how they used to do it. Both lines were emitted and
            // both came out of the same person's pay.
            [$lines, $supersededNotes] = $this->dropSupersededManualLines($lines, $staff);
            $statutoryNotes = array_merge($statutoryNotes, $supersededNotes);
        }

        // ── Advance recovery ─────────────────────────────────────────────────
        // Nothing is recovered from a period the employee was not employed for;
        // the instalment stays due and the schedule picks it up next month.
        $advance = $notEmployedAtAll ? 0.0 : $this->advanceRecoveryFor($staff, $month, $year);

        if ($advance > 0) {
            // Name comes from the component master, which the migration seeded as
            // 'Advance salary' — the exact string this line has always carried, so
            // a payslip reprinted after all this reads identically.
            $lines[] = $this->systemLine($staff, SalaryComponent::CODE_ADVANCE, null, $advance, 'advance', 510);
        }

        // ── Totals ───────────────────────────────────────────────────────────
        $allowanceTotal = $this->sumType($lines, SalaryComponent::TYPE_ALLOWANCE);
        $deductionTotal = $this->sumType($lines, SalaryComponent::TYPE_DEDUCTION);
        $employerTotal = $this->sumType($lines, SalaryComponent::TYPE_EMPLOYER_CONTRIBUTION);

        $gross = round($structure['basic'] + $allowanceTotal, 2);
        $resolvedTax = round($tax ?? $computedTax, 2);

        return [
            'basic' => round($structure['basic'], 2),
            'lines' => $lines,
            'allowance_total' => $allowanceTotal,
            'deduction_total' => $deductionTotal,
            'employer_total' => $employerTotal,
            'gross' => $gross,
            'tax' => $resolvedTax,
            'net' => round($gross - $deductionTotal - $resolvedTax, 2),
            'lop' => $lop,
            'statutory' => $statutory,
            'pf_wages' => $pfWages,
            'esi_wages' => $esiWages,
            'structure_source' => $structure['source'],
            // 'mixed'/'legacy' plus these notes are how an operator sees which
            // employees are still being priced from the historyless JSON blob and
            // are therefore still exposed to retroactive restatement.
            'structure_notes' => array_values(array_merge($structure['notes'] ?? [], $statutoryNotes)),
            'basic_source' => $structure['basic_source'] ?? 'staff_record',
            'rule_set_id' => $ruleSet?->id,
            'rule_set' => $ruleSet,
        ];
    }

    /**
     * Generate payroll for every active staff member (optionally a supplied subset)
     * for a month/year. Returns the number of payrolls written.
     *
     * @param  Collection<int, Staff>|null  $staff
     */
    public function generateForMonth(int $month, int $year, ?int $generatedBy = null, ?Collection $staff = null, ?int $schoolId = null): int
    {
        return $this->generateBatch($month, $year, $generatedBy, $staff, $schoolId)['written'];
    }

    /**
     * The same run, but reporting what it did rather than only how many rows it
     * touched.
     *
     * Bulk generation used to be silent about the rows it left alone, so an
     * operator regenerating a month could not tell "38 payrolls updated" from
     * "6 updated and 32 refused because they were already paid". That difference
     * is the whole question they are asking.
     *
     * ── THE SCHOOL IS NAMED, NOT ASSUMED ─────────────────────────────────────
     * `Staff::where('status', 'active')` looks tenant-safe and is not. TenantScope
     * is a documented NO-OP in an unresolved (console, queue, scheduler) context,
     * so that query returned every school's staff: one `generateBatch(12, 2026)`
     * in tinker wrote 42 payrolls across school_ids 1 AND 4 — one school's payroll
     * run creating another school's salary records. It is latent only because
     * nothing schedules payroll yet, and "latent" is not a property anybody should
     * rely on for the queue worker somebody adds next.
     *
     * Exactly the bug that was already fixed on {@see StatutoryRuleSet::resolveFor()},
     * still live in its neighbour. The school is therefore a parameter, defaulting
     * to the current context's, and the predicate is spelled out so the query is
     * still correct if the scope is ever dropped. A caller that supplies $staff
     * has already chosen the rows and is trusted with them.
     *
     * ── THE EXPOSURE COUNTS ──────────────────────────────────────────────────
     * `exposed_legacy` and `exposed_basic` report how many of the payrolls just
     * written are still priced from something with NO HISTORY — a free-text JSON
     * head, or `staff.basic_salary` with no dated BASIC row behind it. Those are
     * the rows that a later edit to the staff record can silently restate, which
     * is the failure effective dating exists to prevent, and until now nothing
     * anywhere told the operator which employees were still in that state. The
     * resolver has emitted a note about it all along; nothing read it.
     *
     * @param  Collection<int, Staff>|null  $staff
     * @return array{written: int, skipped_paid: int, skipped_locked: int, overrides_kept: int, total: int, exposed_legacy: int, exposed_basic: int}
     */
    public function generateBatch(int $month, int $year, ?int $generatedBy = null, ?Collection $staff = null, ?int $schoolId = null): array
    {
        $staff ??= Staff::query()
            ->where('school_id', $schoolId ?? current_school_id())
            ->where('status', 'active')
            ->get();

        $summary = [
            'written' => 0, 'skipped_paid' => 0, 'skipped_locked' => 0,
            'overrides_kept' => 0, 'total' => 0,
            'exposed_legacy' => 0, 'exposed_basic' => 0,
        ];

        // One query rather than one per employee: who has a dated BASIC row.
        $datedBasic = StaffSalaryStructure::query()
            ->withoutGlobalScopes()
            ->whereIn('staff_id', $staff->pluck('id')->all())
            ->whereHas('component', fn ($q) => $q->withoutGlobalScopes()->where('calculation', 'base_salary'))
            ->pluck('staff_id')
            ->flip();

        foreach ($staff as $member) {
            $summary['total']++;

            $before = Payroll::where('staff_id', $member->id)
                ->where('month', $month)
                ->where('year', $year)
                ->first();

            if ($before?->isPaid()) {
                $summary['skipped_paid']++;

                continue;
            }

            if ($before?->isLocked()) {
                $summary['skipped_locked']++;

                continue;
            }

            $payroll = $this->generate($member, $month, $year, $generatedBy);

            $summary['written']++;
            $summary['overrides_kept'] += $payroll->details->where('is_override', true)->count();

            if ($payroll->details->where('source', 'legacy')->isNotEmpty()) {
                $summary['exposed_legacy']++;
            }

            if (! $datedBasic->has($member->id)) {
                $summary['exposed_basic']++;
            }
        }

        return $summary;
    }

    /**
     * Mark a payroll PAID (gated `payroll.pay` at the call site) and dispatch the
     * loosely-coupled {@see SalaryPaid} finance-seam event. Idempotent: a payroll
     * already paid is returned unchanged without re-dispatching.
     *
     * ── THE LEDGER SEAM ──────────────────────────────────────────────────────
     * This event is the ONLY thing payroll offers accounting, and it stays that
     * way deliberately — payroll must not reference a ledger model. A posting
     * listener registered against SalaryPaid can load the payroll by
     * `payrollId` and read everything an entry needs off it:
     *
     *   DEBIT   salary expense           `gross`
     *   DEBIT   employer contributions   `employer_contribution_total`
     *   CREDIT  statutory payable        the `deduction` lines whose component is
     *                                    statutory, plus `tax`, plus the whole of
     *                                    `employer_contribution_total`
     *   CREDIT  staff advance recovered  the `ADVANCE` line
     *   CREDIT  bank / salary payable    `net_salary`
     *
     * `$payroll->details` carries `salary_component_id`, and the component tells
     * the listener which of those buckets a line belongs in — `is_system`,
     * `type` and `statutory_kind` are all it needs to map a line to an account
     * without payroll knowing any account exists. `employer_contribution_total`
     * is deliberately excluded from `net_salary`: it is a cost and a liability,
     * never a payment to the employee.
     *
     * A payroll is LOCKED as it is paid. Paying is the point after which the
     * figures must stop moving, and locking here means no caller has to remember
     * to.
     */
    public function markPaid(Payroll $payroll, ?int $paidBy = null, ?string $paymentMode = null, mixed $paymentDate = null): Payroll
    {
        if ($payroll->isPaid()) {
            return $payroll;
        }

        // BEFORE the money moves and before the payroll is LOCKED. There is no
        // transaction here, so the update commits and the SalaryPaid listener's
        // refusal would arrive too late and be swallowed.
        //
        // The date checked is the one the POSTING carries: SalaryPaid::date is
        // `paid_at` (always now()), NOT the backdatable `payment_date`. If that
        // argument is ever wired through to the ledger, this must move with it.
        $gateDate = now()->toDateString();

        event(new SalaryPaying($payroll, $paymentMode, $gateDate));

        $payroll->update([
            'status' => Payroll::STATUS_PAID,
            'paid_by' => $paidBy,
            'paid_at' => now(),
            'payment_mode' => $paymentMode,
            'payment_date' => $paymentDate ?: now()->toDateString(),
            'locked_at' => $payroll->locked_at ?? now(),
            'locked_by' => $payroll->locked_by ?? $paidBy,
        ]);

        event(SalaryPaid::fromPayroll($payroll->refresh()));

        return $payroll;
    }

    /**
     * Record that a payslip has been ISSUED, and freeze it.
     *
     * ── WHY ISSUE, AND NOT ONLY PAY, IS THE LOCK POINT ───────────────────────
     * A payslip is handed over — downloaded, emailed, opened in the staff app —
     * days before the money moves. From that moment the employee is holding a
     * figure, and any later regeneration that changes it produces two documents
     * claiming to be the same month's payslip. Locking only at payment leaves
     * exactly that window open, and it is the window in which somebody edits a
     * salary and re-runs generation.
     *
     * Idempotent and cheap enough to call on every render: a payroll already
     * locked keeps its original timestamp, because WHEN it was frozen is the
     * fact worth keeping.
     *
     * Call this from anywhere a payslip is served. {@see PayrollPdfController}
     * is the single choke point through which every payslip PDF passes — panel,
     * API and the teacher portal — so wiring it there covers all of them.
     */
    public function markIssued(Payroll $payroll, ?int $actorId = null): Payroll
    {
        if ($payroll->isLocked()) {
            return $payroll;
        }

        return $payroll->lock($actorId);
    }

    /**
     * Set one line to an amount a human has chosen, with the reason recorded.
     *
     * The engine's own figure is kept in `computed_amount`, so the size of the
     * correction is always visible rather than being a number somebody has to
     * take on trust — and so that the line can be reverted to it later.
     * Regeneration will now leave this line alone.
     *
     * Refused on a paid or locked payroll: after issue, a correction belongs in
     * the next month, not silently in a payslip already handed over.
     */
    public function overrideLine(PayrollDetail $detail, float $amount, string $reason, ?int $actorId = null): PayrollDetail
    {
        $payroll = $detail->payroll;

        if ($payroll !== null && ($payroll->isPaid() || $payroll->isLocked())) {
            return $detail;
        }

        $detail->update([
            'computed_amount' => $detail->is_override ? $detail->computed_amount : $detail->amount,
            'amount' => round($amount, 2),
            'is_override' => true,
            'override_reason' => $reason,
            'overridden_by' => $actorId,
            'overridden_at' => now(),
        ]);

        if ($payroll !== null) {
            $this->recalculateTotals($payroll->refresh());
        }

        return $detail->refresh();
    }

    /**
     * Drop an override and put the engine's figure back.
     *
     * The line is restored rather than deleted, because the next generation will
     * rebuild it anyway and deleting would make the payslip briefly wrong.
     */
    public function clearOverride(PayrollDetail $detail): PayrollDetail
    {
        $payroll = $detail->payroll;

        if ($payroll !== null && ($payroll->isPaid() || $payroll->isLocked())) {
            return $detail;
        }

        $detail->update([
            'amount' => $detail->computed_amount ?? $detail->amount,
            'is_override' => false,
            'override_reason' => null,
            'overridden_by' => null,
            'overridden_at' => null,
        ]);

        if ($payroll !== null) {
            $this->recalculateTotals($payroll->refresh());
        }

        return $detail->refresh();
    }

    /**
     * Recompute the header totals from the lines that are actually stored.
     *
     * Called after every write, including after an override, so the payslip's
     * lines and its totals can never tell the employee two different stories.
     */
    public function recalculateTotals(Payroll $payroll): Payroll
    {
        $details = $payroll->details()->get();

        $allowance = round((float) $details->where('type', PayrollDetail::TYPE_ALLOWANCE)->sum('amount'), 2);
        $deduction = round((float) $details->where('type', PayrollDetail::TYPE_DEDUCTION)->sum('amount'), 2);
        $employer = round((float) $details->where('type', PayrollDetail::TYPE_EMPLOYER_CONTRIBUTION)->sum('amount'), 2);

        $gross = round((float) $payroll->basic_salary + $allowance, 2);

        $payroll->update([
            'allowance_total' => $allowance,
            'deduction_total' => $deduction,
            'employer_contribution_total' => $employer,
            'gross' => $gross,
            'net_salary' => round($gross - $deduction - (float) $payroll->tax, 2),
        ]);

        return $payroll;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Rebuild the computed lines around whatever a human has overridden.
     *
     * The old behaviour was `$payroll->details()->delete()` followed by a full
     * rebuild, which is exactly why an override could not exist: any correction
     * was destroyed by the next generation, for everybody, silently.
     *
     * Overridden rows are matched to computed lines by component (falling back
     * to name + type for a legacy or hand-typed row) so that a rebuilt line
     * still refreshes its `computed_amount` — the operator can then see that the
     * engine now says something different from the correction still standing.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function writeDetails(Payroll $payroll, array $lines): void
    {
        $overrides = $payroll->details()->where('is_override', true)->get();

        // Everything the engine owns goes; everything a human owns stays.
        //
        // reorder() strips the display ordering the relation carries: a DELETE has
        // no use for an ORDER BY, and not every database will accept one without a
        // LIMIT beside it.
        $payroll->details()->reorder()->where('is_override', false)->delete();

        $emitted = array_values(array_filter(
            $lines,
            static fn (array $line): bool => ($line['emit_as_line'] ?? true) !== false,
        ));

        // ── MATCHING IS TWO PASSES, AND THE ORDER IS LOAD-BEARING ────────────
        // A single pass that fell through to name+type whenever either side had
        // no component could BOTH keep an override and create a computed line
        // for the same head — paying or deducting it twice. Two real edits
        // produced exactly that: migrating a head from the legacy JSON to a
        // structure row (override has no component, line does), and the loss-of-
        // pay line changing its name between "Loss of pay" and "Pro-rata (part
        // month)" (same component, different name).
        //
        //   pass 1 — component to component. Survives a rename.
        //   pass 2 — name + type, for whatever pass 1 could not pair. Survives a
        //            head gaining a component it did not have before.
        //
        // Every override is claimed at most once and every line pairs with at
        // most one override, so neither duplication is reachable.
        $claimed = [];
        $pairs = [];

        foreach ($emitted as $index => $line) {
            if ($line['component_id'] === null) {
                continue;
            }

            $match = $overrides->first(fn (PayrollDetail $d): bool => ! isset($claimed[$d->id])
                && $d->salary_component_id !== null
                && $d->salary_component_id === $line['component_id']);

            if ($match !== null) {
                $claimed[$match->id] = true;
                $pairs[$index] = $match;
            }
        }

        foreach ($emitted as $index => $line) {
            if (isset($pairs[$index])) {
                continue;
            }

            $match = $overrides->first(fn (PayrollDetail $d): bool => ! isset($claimed[$d->id])
                && $d->name === $line['name']
                && $d->type === $line['type']);

            if ($match !== null) {
                $claimed[$match->id] = true;
                $pairs[$index] = $match;
            }
        }

        foreach ($emitted as $index => $line) {
            $existing = $pairs[$index] ?? null;

            if ($existing !== null) {
                // Keep the human's amount; refresh what the engine would say.
                $existing->update([
                    'salary_component_id' => $line['component_id'],
                    'source' => $line['source'],
                    'computed_amount' => $line['amount'],
                    'sequence' => $line['sequence'],
                ]);

                continue;
            }

            $payroll->details()->create([
                'salary_component_id' => $line['component_id'],
                'type' => $line['type'],
                'name' => $line['name'],
                'source' => $line['source'],
                'amount' => $line['amount'],
                'computed_amount' => $line['amount'],
                'is_override' => false,
                'sequence' => $line['sequence'],
            ]);
        }

        // ── ORPHANED OVERRIDES ───────────────────────────────────────────────
        // An override whose engine counterpart has gone — the head was removed
        // from the employee's structure, or a statutory kind was switched off —
        // matches nothing and is rebuilt by nothing. It used to sit there as a
        // live amount on the payslip with no explanation and no way to notice it.
        //
        // It is NOT deleted: a human set that figure deliberately and quietly
        // reversing them is how a correction gets lost. It is re-labelled
        // `manual` and its `computed_amount` cleared, which is the truth about it
        // — nothing computes this any more — and which is what the payslip screen
        // reads to flag it for review.
        $orphans = $overrides->reject(fn (PayrollDetail $d): bool => isset($claimed[$d->id]));

        foreach ($orphans as $orphan) {
            $orphan->update([
                'source' => 'manual',
                'computed_amount' => null,
            ]);
        }
    }

    /**
     * Drop the hand-entered deduction lines that the statutory engine has just
     * calculated for itself, and say which ones went.
     *
     * ── THE FAILURE THIS PREVENTS ────────────────────────────────────────────
     * Every school that came through the salary-structure migration is carrying
     * its old hand-worked statutory heads as ORDINARY FIXED DEDUCTIONS — on one real school
     * all 38 staff had a `PROVIDENT_FUND` row of ₹3,840 and a
     * `PROFESSIONAL_TAX` row of ₹200, because that is how the school did it
     * before there was an engine. Those are perfectly good structure lines and
     * the engine pays them because somebody put them on the employee.
     *
     * Nothing collided while the statutory kinds shipped switched off. The first
     * time an administrator does the thing the Statutory Rules screen exists to
     * invite — set `pf.enabled` to 1 — the engine ALSO emits `PF_EMPLOYEE`, and
     * ₹3,840 + ₹1,800 comes out of the same person's pay for the same
     * obligation. There was no dedupe and no warning: the module's own headline
     * feature, used exactly as documented, quietly over-deducted every employee.
     *
     * ── WHY DROP THE MANUAL ONE AND NOT THE COMPUTED ONE ─────────────────────
     * The computed one is the answer the school has just asked for, from rates
     * it entered, against a wage base it defined. The manual one is a number
     * somebody typed before any of that existed. Keeping the manual one would
     * make switching the engine on a no-op that still looked like it worked.
     *
     * Nothing is dropped silently: each removal produces a note naming both
     * figures, so the payslip's owner can see that ₹3,840 became ₹1,800 and why.
     * And nothing is dropped unless the engine actually PRODUCED a line — an
     * employee with no EPF number, or a school with PF still off, keeps their
     * manual head exactly as before.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    protected function dropSupersededManualLines(array $lines, Staff $staff): array
    {
        // Which statutory obligations actually produced an employee-side line
        // this month, and for how much.
        $computed = [];

        foreach ($lines as $line) {
            if (($line['source'] ?? null) !== 'statutory'
                || $line['type'] !== SalaryComponent::TYPE_DEDUCTION
                || (float) $line['amount'] <= 0) {
                continue;
            }

            $kind = $line['component']?->statutory_kind
                ?? SalaryComponentProvisioner::statutoryKindForCode((string) $line['code']);

            if ($kind !== null) {
                $computed[$kind] = ['name' => $line['name'], 'amount' => (float) $line['amount']];
            }
        }

        if ($computed === []) {
            return [$lines, []];
        }

        $map = $this->structures->supersessions($staff);

        $kept = [];
        $notes = [];

        foreach ($lines as $line) {
            $supersedes = $this->supersededKind($line, $map);

            if ($supersedes === null || ! isset($computed[$supersedes])) {
                $kept[] = $line;

                continue;
            }

            $notes[] = __('hr::messages.payroll.superseded', [
                'name' => $line['name'],
                'amount_a' => Currency::symbol().number_format((float) $line['amount'], 2),
                'other' => $computed[$supersedes]['name'],
                'amount_b' => Currency::symbol().number_format($computed[$supersedes]['amount'], 2),
            ]);
        }

        return [$kept, $notes];
    }

    /**
     * The statutory kind a hand-entered DEDUCTION line stands in for, or null.
     *
     * A structure line carries the answer on itself. A legacy JSON line has no
     * component at all, so its free text is normalised the same way the migration
     * normalised it and looked up against the branch's heads — which is the case
     * that actually matters, because an unmigrated school is precisely the one
     * still deducting provident fund by hand.
     *
     * @param  array<string, mixed>  $line
     * @param  array{codes: array<string, string>, names: array<string, string>}  $map
     */
    protected function supersededKind(array $line, array $map): ?string
    {
        if ($line['type'] !== SalaryComponent::TYPE_DEDUCTION) {
            return null;
        }

        if (! in_array($line['source'] ?? null, ['structure', 'legacy'], true)) {
            return null;
        }

        if (($line['supersedes_statutory_kind'] ?? null) !== null) {
            return (string) $line['supersedes_statutory_kind'];
        }

        $name = trim((string) ($line['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        return $map['names'][strtolower($name)]
            ?? $map['codes'][SalaryComponent::codeFor($name)]
            ?? null;
    }

    /**
     * The wage base for a statutory contribution: the summed value of the lines
     * flagged into it, reduced by loss of pay where the head prorates.
     *
     * BASIC is added separately because it emits no line — and it is almost
     * always the largest part of both bases, so forgetting it would understate
     * every contribution.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $structure
     */
    protected function wageBase(array $lines, array $structure, ?SalaryComponent $basic, string $flag, float $factor): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            // Same rule as loss of pay: a head that emits no line is not paid, so
            // it is not wages and cannot be part of a contribution base.
            if (($line['emit_as_line'] ?? true) === false) {
                continue;
            }

            if ($line['type'] !== SalaryComponent::TYPE_ALLOWANCE || ! ($line[$flag] ?? false)) {
                continue;
            }

            $total += $line['amount'] * ($line['prorates_on_lop'] ? $factor : 1.0);
        }

        // NO BASIC HEAD AT ALL — never provisioned, or hard-deleted — falls back
        // to the only defensible reading of basic pay: it is PF wages, it is ESI
        // wages, and it prorates. The old `$basic !== null && …` simply DROPPED
        // basic from both bases in that case, which is not a conservative
        // default, it is a wrong one in both directions at once: provident fund
        // stops entirely, and the ESI base collapses far enough under the
        // threshold that ESI STARTS for people who are nowhere near eligible.
        // (A head that merely deactivated or was soft-deleted no longer arrives
        // here as null — see SalaryStructureResolver::basicComponent().)
        $includeBasic = $basic === null ? true : (bool) $basic->{$flag};
        $basicProrates = $basic === null ? true : (bool) $basic->prorates_on_lop;

        if ($includeBasic) {
            $total += $structure['basic'] * ($basicProrates ? $factor : 1.0);
        }

        return round($total, 2);
    }

    /**
     * The month's earnings after loss of pay: basic plus every allowance the
     * payslip actually shows, prorated where the head prorates.
     *
     * Unlike {@see wageBase()} this is not filtered by any flag — it is simply
     * what the employee earned — which is what professional tax is assessed on
     * and what the income-tax projection annualises.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $structure
     */
    protected function earnedWages(array $lines, array $structure, ?SalaryComponent $basic, float $factor, bool $taxableOnly = false): float
    {
        $total = $structure['basic'] * (($basic === null || $basic->prorates_on_lop) ? $factor : 1.0);

        if ($taxableOnly && $basic !== null && ! $basic->is_taxable) {
            $total = 0.0;
        }

        foreach ($lines as $line) {
            if (($line['emit_as_line'] ?? true) === false || $line['type'] !== SalaryComponent::TYPE_ALLOWANCE) {
                continue;
            }

            if ($taxableOnly && ! ($line['is_taxable'] ?? false)) {
                continue;
            }

            $total += $line['amount'] * ($line['prorates_on_lop'] ? $factor : 1.0);
        }

        return round($total, 2);
    }

    /**
     * Project income tax for the month.
     *
     * ── A NAMED APPROXIMATION ────────────────────────────────────────────────
     * "Taxable pay already drawn this financial year" is taken from the `gross`
     * of the earlier payrolls in the year, because no column records taxable
     * earnings separately. Where an employee draws a non-taxable head, gross
     * OVERSTATES that figure and the projection over-deducts. (The CURRENT
     * month is computed properly — loss of pay applied, non-taxable heads
     * excluded — so the approximation is confined to history.)
     *
     * That is one of several reasons income tax ships disabled and with no slabs
     * — see the long note on {@see StatutoryCalculator}. It is recorded here
     * rather than quietly tolerated so that whoever finishes the TDS work knows
     * this is one of the things to finish.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $structure
     * @return array<string, mixed>
     */
    protected function projectIncomeTax(
        StatutoryRuleSet $ruleSet,
        Staff $staff,
        array $lines,
        array $structure,
        float $factor,
        int $month,
        int $year,
        ?SalaryComponent $basicComponent = null,
    ): array {
        // Taxable pay for the month, after loss of pay and excluding heads
        // flagged non-taxable — the same earnings basis the rest of the engine
        // uses, rather than a second hand-rolled sum that could drift from it.
        $monthlyTaxable = $this->earnedWages($lines, $structure, $basicComponent, $factor, taxableOnly: true);

        $fy = StatutoryCalculator::financialYear($month, $year);

        // Payrolls for this financial year — April of the starting year through
        // March of the next.
        $rows = Payroll::query()
            ->where('staff_id', $staff->id)
            ->where(fn ($q) => $q
                ->where(fn ($y) => $y->where('year', $fy['start_year'])->where('month', '>=', 4))
                ->orWhere(fn ($y) => $y->where('year', $fy['start_year'] + 1)->where('month', '<=', 3)))
            ->get(['month', 'year', 'gross', 'tax']);

        // Only the months BEFORE this one count as "already earned".
        //
        // The filter used to be "every month in the year except this one", which
        // silently included months AFTER it — and forward generation is not
        // hypothetical, the Generate Payroll year picker offers next year. A
        // later month counted as earned-to-date AND again inside
        // (monthly × months remaining), inflating the projection and
        // over-deducting for the rest of the year.
        $index = static fn (int $m): int => $m >= 4 ? $m - 3 : $m + 9;
        $current = $index($month);

        $prior = $rows->filter(fn ($row): bool => $index((int) $row->month) < $current);

        return $this->statutory->incomeTax(
            $ruleSet,
            $monthlyTaxable,
            round((float) $prior->sum('gross'), 2),
            round((float) $prior->sum('tax'), 2),
            $fy['months_remaining'],
        );
    }

    /**
     * Advance-salary recovery due this month.
     *
     * Delegates to {@see AdvanceSalary::installmentFor()}, which computes the
     * instalment from the schedule alone and reads no running total — so
     * regenerating a month recovers the same amount rather than a second one.
     * A single-instalment advance, which is every advance that existed before
     * the schedule columns were added, still recovers its full amount in its own
     * month exactly as it always did.
     *
     * The settled test lives on the model, not in this query, so that every route
     * to an instalment honours it rather than only this one; the predicate here
     * is the same list, spelled out so the query does not load rows it will
     * certainly discard. It covers BOTH terminal states — "fully recovered" as
     * well as "written off" — because the first is the one an administrator
     * actually reaches for when an employee has repaid an advance in cash, and
     * honouring only the second went on deducting the instalment every month
     * afterwards, taking the money twice.
     */
    protected function advanceRecoveryFor(Staff $staff, int $month, int $year): float
    {
        return round((float) AdvanceSalary::query()
            ->where('staff_id', $staff->id)
            ->where('status', 'approved')
            ->whereNotIn('recovery_status', AdvanceSalary::SETTLED_RECOVERY_STATUSES)
            ->get()
            ->sum(fn (AdvanceSalary $advance): float => $advance->installmentFor($month, $year)), 2);
    }

    /**
     * What to call the proration line.
     *
     * One line, named for what it actually is. A new starter's first fortnight
     * and a teacher's three absences are arithmetically the same reduction, but
     * telling somebody they "lost pay" for days before they were employed is
     * simply untrue, and it is the kind of untrue thing that turns into a
     * conversation with the principal.
     *
     * @param  array<string, mixed>  $lop
     */
    protected function lopLineName(array $lop): string
    {
        $notEmployed = (float) ($lop['breakdown']['not_employed'] ?? 0);
        $attendance = (float) $lop['lop_days'] - $notEmployed;

        if ($lop['not_employed_at_all'] ?? false) {
            return __('hr::messages.payroll.period_not_employed');
        }

        if ($notEmployed > 0 && $attendance <= 0) {
            return __('hr::messages.payroll.period_prorata');
        }

        if ($notEmployed > 0) {
            return __('hr::messages.payroll.period_lop_part');
        }

        return __('hr::messages.payroll.period_lop');
    }

    /**
     * A line the engine owns outright — loss of pay, advance recovery.
     *
     * The component is looked up by code so the line carries a real head where
     * one exists; a school that has deleted the head still gets a correctly
     * named and correctly totalled line rather than a silently missing
     * deduction.
     *
     * $name overrides the component's own name for the one head whose label
     * depends on WHY it was charged (see {@see lopLineName()}); pass null
     * everywhere else so the component master stays the source of display names.
     *
     * @return array<string, mixed>
     */
    protected function systemLine(Staff $staff, string $code, ?string $name, float $amount, string $source, int $order): array
    {
        $component = $this->structures->componentByCode($staff, $code);

        return [
            'component' => $component,
            'component_id' => $component?->id,
            'code' => $code,
            'name' => $name ?? $component?->name ?? $code,
            'type' => SalaryComponent::TYPE_DEDUCTION,
            'amount' => round($amount, 2),
            'source' => $source,
            'is_taxable' => false,
            'pf_wage' => false,
            'esi_wage' => false,
            'prorates_on_lop' => false,
            'emit_as_line' => $component?->emit_as_line ?? true,
            'sequence' => $component?->display_order ?? $order,
        ];
    }

    /**
     * A statutory line, taking its type (deduction vs employer contribution)
     * from the component master so that an employer share can never land in the
     * employee's deductions.
     *
     * @return array<string, mixed>
     */
    protected function statutoryLine(Staff $staff, string $code, float $amount, int $order): array
    {
        $component = $this->structures->componentByCode($staff, $code);

        return [
            'component' => $component,
            'component_id' => $component?->id,
            'code' => $code,
            'name' => $component?->name ?? $code,
            // The fallback is looked up BY CODE, never defaulted to "deduction".
            // A deleted PF_EMPLOYER head defaulting to a deduction would take the
            // SCHOOL'S contribution out of the EMPLOYEE'S pay — the worst thing
            // this module could do quietly, and not something that should depend
            // on a master row still being there.
            'type' => $component?->type ?? SalaryComponentProvisioner::typeForCode($code),
            'amount' => round($amount, 2),
            'source' => 'statutory',
            'is_taxable' => false,
            'pf_wage' => false,
            'esi_wage' => false,
            'prorates_on_lop' => false,
            'emit_as_line' => $component?->emit_as_line ?? true,
            'sequence' => $component?->display_order ?? $order,
        ];
    }

    /**
     * Sum the emitted lines of one type.
     *
     * Lines that emit nothing are excluded, because a total must describe what
     * the payslip actually shows.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function sumType(array $lines, string $type): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            if ($line['type'] === $type && ($line['emit_as_line'] ?? true)) {
                $total += $line['amount'];
            }
        }

        return round($total, 2);
    }

    /**
     * Normalize a raw allowances/deductions structure into a clean list of
     * ['name' => string, 'amount' => float] rows, dropping blank/zero lines.
     *
     * Kept because it is the documented shape of the legacy JSON columns and
     * other callers read it; the resolver applies the identical filter when it
     * falls back to those columns.
     *
     * @return array<int, array{name: string, amount: float}>
     */
    public function normalizeLines(mixed $lines): array
    {
        return collect($lines ?? [])
            ->map(fn ($line): array => [
                'name' => (string) ($line['name'] ?? ''),
                'amount' => (float) ($line['amount'] ?? 0),
            ])
            ->filter(fn (array $line): bool => $line['name'] !== '' && $line['amount'] != 0.0)
            ->values()
            ->all();
    }
}

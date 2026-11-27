<?php

namespace Classivo\Hr\Tests\Feature;

use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Hr\Models\AdvanceSalary;
use Classivo\Hr\Models\SalaryComponent;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffSalaryStructure;
use Classivo\Hr\Models\StatutoryRule;
use Classivo\Hr\Models\StatutoryRuleSet;
use Classivo\Hr\Services\Payroll\SalaryComponentProvisioner;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The payroll salary engine, defect by defect.
 *
 * Everything here was found by end-to-end testing against real Demo School data and was
 * reachable with the flags the module SHIPS with. Each test names the failure it
 * pins down; the point of the file is that none of them can come back quietly.
 *
 * The fixture is deliberately the shape live data is in: an employee migrated
 * onto {@see StaffSalaryStructure} whose old free-text `staff.allowances` /
 * `staff.deductions` blobs are still sitting on the record, which is the state
 * every school is in after the backfill and the state in which most of these
 * bugs lived.
 */
class PayrollSalaryEngineTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Branch $branch;

    private Staff $staff;

    private PayrollService $payroll;

    protected function setUp(): void
    {
        parent::setUp();

        SalaryComponentProvisioner::flush();

        $this->school = School::create(['name' => 'Engine School', 'code' => 'ENG', 'status' => true]);
        $this->branch = Branch::create([
            'school_id' => $this->school->id,
            'name' => 'Main', 'code' => 'MAIN', 'is_default' => true, 'status' => true,
        ]);
        app(CurrentContext::class)->setSchoolId($this->school->id);

        $this->payroll = app(PayrollService::class);

        $this->staff = Staff::create([
            'school_id' => $this->school->id,
            'branch_id' => $this->branch->id,
            'first_name' => 'Anita', 'last_name' => 'Sharma',
            'status' => 'active',
            'joining_date' => '2015-06-15',
            'basic_salary' => 32000,
            'epf_no' => 'EPF/1000005',
            'allowances' => [
                ['name' => 'House Rent Allowance', 'amount' => 12800],
                ['name' => 'Dearness Allowance', 'amount' => 6400],
            ],
            'deductions' => [
                ['name' => 'Provident Fund', 'amount' => 3840],
            ],
        ]);

        // The heads are provisioned lazily by the resolver; asking for them once
        // here means the branch has its system heads before anything is created.
        $this->payroll->compute($this->staff, 9, 2026);

        $this->salaryHead('HOUSE_RENT_ALLOWANCE', 'House Rent Allowance', 'allowance');
        $this->salaryHead('DEARNESS_ALLOWANCE', 'Dearness Allowance', 'allowance');
        $this->salaryHead('PROVIDENT_FUND', 'Provident Fund', 'deduction', ['supersedes_statutory_kind' => 'pf_employee']);

        $this->structure('HOUSE_RENT_ALLOWANCE', 12800, 'House Rent Allowance');
        $this->structure('DEARNESS_ALLOWANCE', 6400, 'Dearness Allowance');
        $this->structure('PROVIDENT_FUND', 3840, 'Provident Fund');
    }

    // ---------------------------------------------------------------------
    // Defect: renaming a head paid it twice
    // ---------------------------------------------------------------------

    public function test_renaming_a_head_does_not_pay_the_legacy_entry_a_second_time(): void
    {
        $before = $this->payroll->compute($this->staff, 9, 2026);

        $this->assertSame(51200.0, $before['gross'], 'basic 32000 + HRA 12800 + DA 6400');

        // Exactly what the Salary Components screen invites: rename the head.
        $this->salaryHead('HOUSE_RENT_ALLOWANCE')->update(['code' => 'HRA', 'name' => 'HRA']);

        $after = $this->payroll->compute($this->staff->fresh(), 9, 2026);

        $this->assertSame(51200.0, $after['gross'], 'a rename must not resurrect the legacy JSON entry');
        $this->assertCount(
            1,
            array_filter($after['lines'], fn (array $l): bool => str_contains(strtolower($l['name']), 'hra')
                || str_contains(strtolower($l['name']), 'house rent')),
            'exactly one house-rent line, whatever it is called',
        );
    }

    public function test_a_legacy_entry_with_no_structure_row_is_still_paid(): void
    {
        $this->staff->update(['allowances' => array_merge($this->staff->allowances, [
            ['name' => 'Uniform Allowance', 'amount' => 900],
        ])]);

        $computation = $this->payroll->compute($this->staff->fresh(), 9, 2026);

        $this->assertSame(52100.0, $computation['gross'], 'the unmigrated head is still owed');
        $this->assertSame('mixed', $computation['structure_source']);
        $this->assertNotEmpty($computation['structure_notes']);
    }

    public function test_closing_a_structure_row_stops_the_head_instead_of_resurrecting_the_legacy_entry(): void
    {
        StaffSalaryStructure::where('staff_id', $this->staff->id)
            ->whereHas('component', fn ($q) => $q->where('code', 'HOUSE_RENT_ALLOWANCE'))
            ->update(['effective_to' => '2026-08-31']);

        $computation = $this->payroll->compute($this->staff->fresh(), 9, 2026);

        $this->assertSame(38400.0, $computation['gross'], 'basic 32000 + DA 6400; HRA has genuinely stopped');
    }

    public function test_a_future_dated_structure_row_does_not_stop_the_legacy_entry_yet(): void
    {
        $this->staff->update(['allowances' => array_merge($this->staff->allowances, [
            ['name' => 'Uniform Allowance', 'amount' => 900],
        ])]);

        $this->salaryHead('UNIFORM_ALLOWANCE', 'Uniform Allowance', 'allowance');
        $this->structure('UNIFORM_ALLOWANCE', 1200, 'Uniform Allowance', '2026-11-01');

        $september = $this->payroll->compute($this->staff->fresh(), 9, 2026);
        $november = $this->payroll->compute($this->staff->fresh(), 11, 2026);

        $this->assertSame(52100.0, $september['gross'], 'September still owes the ₹900 legacy entry');
        $this->assertSame(52400.0, $november['gross'], 'November pays the ₹1,200 structure row instead');
    }

    // ---------------------------------------------------------------------
    // Defect: coverage was type-blind
    // ---------------------------------------------------------------------

    public function test_a_legacy_deduction_is_not_swallowed_by_a_same_named_allowance(): void
    {
        $this->staff->update(['deductions' => array_merge($this->staff->deductions, [
            ['name' => 'Dearness Allowance', 'amount' => 500],
        ])]);

        $computation = $this->payroll->compute($this->staff->fresh(), 9, 2026);

        $this->assertSame(4340.0, $computation['deduction_total'], 'PF 3840 + the 500 deduction that was being dropped');
    }

    // ---------------------------------------------------------------------
    // Defect: BASIC was not effective-dated
    // ---------------------------------------------------------------------

    public function test_basic_falls_back_to_the_staff_record_and_says_so(): void
    {
        $computation = $this->payroll->compute($this->staff, 9, 2026);

        $this->assertSame(32000.0, $computation['basic']);
        $this->assertSame('staff_record', $computation['basic_source']);
        $this->assertNotEmpty(array_filter(
            $computation['structure_notes'],
            fn (string $n): bool => str_contains($n, 'keeps no history'),
        ), 'the exposure has to be visible, not implied');
    }

    public function test_a_dated_basic_row_holds_a_past_month_against_a_later_raise(): void
    {
        $this->structure('BASIC', 32000, null, '2015-06-15', '2026-08-31');
        $this->structure('BASIC', 42000, null, '2026-09-01');

        $august = $this->payroll->compute($this->staff->fresh(), 8, 2026);
        $september = $this->payroll->compute($this->staff->fresh(), 9, 2026);

        $this->assertSame(32000.0, $august['basic']);
        $this->assertSame(42000.0, $september['basic']);
        $this->assertSame('structure', $september['basic_source']);

        // The staff master is now irrelevant to both months.
        $this->staff->update(['basic_salary' => 99999]);

        $this->assertSame(32000.0, $this->payroll->compute($this->staff->fresh(), 8, 2026)['basic']);
        $this->assertSame(42000.0, $this->payroll->compute($this->staff->fresh(), 9, 2026)['basic']);
    }

    public function test_without_a_dated_row_a_staff_master_raise_still_restates_an_unlocked_month(): void
    {
        // Documents the residual exposure the note warns about, so that anybody
        // who "fixes" the note by deleting it has to break a test first.
        $before = $this->payroll->compute($this->staff, 9, 2026)['gross'];

        $this->staff->update(['basic_salary' => 42000]);

        $this->assertSame($before + 10000, $this->payroll->compute($this->staff->fresh(), 9, 2026)['gross']);
    }

    // ---------------------------------------------------------------------
    // Defect: soft-deleting BASIC gutted the statutory wage bases
    // ---------------------------------------------------------------------

    public function test_soft_deleting_the_basic_head_leaves_the_wage_bases_intact(): void
    {
        $before = $this->payroll->compute($this->staff, 9, 2026);

        $this->assertSame(32000.0, $before['pf_wages']);
        $this->assertSame(51200.0, $before['esi_wages']);

        $this->salaryHead('BASIC')->delete();

        $after = $this->payroll->compute($this->staff->fresh(), 9, 2026);

        $this->assertSame(32000.0, $after['pf_wages'], 'PF stopped entirely when the head went');
        $this->assertSame(
            51200.0,
            $after['esi_wages'],
            'the ESI base fell under the threshold and started deducting from someone far above it',
        );
    }

    // ---------------------------------------------------------------------
    // Defect: enabling PF double-deducted
    // ---------------------------------------------------------------------

    public function test_enabling_provident_fund_replaces_the_hand_entered_head_rather_than_adding_to_it(): void
    {
        $this->enableProvidentFund();

        $computation = $this->payroll->compute($this->staff->fresh(), 9, 2026);

        $names = array_column($computation['lines'], 'name');

        $this->assertNotContains('Provident Fund', $names, 'the hand-worked head must go');
        $this->assertContains('Provident fund (employee)', $names);
        $this->assertSame(1800.0, $computation['deduction_total'], '12% of the ₹15,000 ceiling, and nothing else');
        $this->assertNotEmpty(array_filter(
            $computation['structure_notes'],
            fn (string $n): bool => str_contains($n, 'not deducted'),
        ), 'dropping a line silently would be its own bug');
    }

    public function test_the_hand_entered_head_stays_when_the_engine_computes_nothing(): void
    {
        $this->enableProvidentFund();

        // No EPF number: the statutory engine declines, so there is nothing for
        // the manual head to be superseded by and it must keep being deducted.
        $this->staff->update(['epf_no' => null]);

        $computation = $this->payroll->compute($this->staff->fresh(), 9, 2026);

        $this->assertContains('Provident Fund', array_column($computation['lines'], 'name'));
        $this->assertSame(3840.0, $computation['deduction_total']);
    }

    public function test_clearing_the_statutory_link_puts_the_manual_head_back(): void
    {
        $this->enableProvidentFund();
        $this->salaryHead('PROVIDENT_FUND')->update(['supersedes_statutory_kind' => null]);

        $computation = $this->payroll->compute($this->staff->fresh(), 9, 2026);

        $this->assertSame(5640.0, $computation['deduction_total'], 'the school has said these are different money');
    }

    // ---------------------------------------------------------------------
    // Defect: a recovered advance kept deducting
    // ---------------------------------------------------------------------

    public function test_an_advance_marked_fully_recovered_stops_deducting(): void
    {
        $advance = AdvanceSalary::create([
            'school_id' => $this->school->id,
            'branch_id' => $this->branch->id,
            'staff_id' => $this->staff->id,
            'month' => 9, 'year' => 2026, 'amount' => 6000, 'installments' => 1,
            'status' => 'approved', 'recovery_status' => 'pending',
            'request_date' => '2026-09-01',
        ]);

        $this->assertSame(6000.0, $advance->installmentFor(9, 2026));
        $this->assertSame(9840.0, $this->payroll->compute($this->staff->fresh(), 9, 2026)['deduction_total']);

        $advance->update(['recovery_status' => 'recovered', 'recovered_amount' => 6000]);

        $this->assertSame(0.0, $advance->fresh()->installmentFor(9, 2026));
        $this->assertSame(3840.0, $this->payroll->compute($this->staff->fresh(), 9, 2026)['deduction_total']);
    }

    public function test_a_written_off_advance_still_stops_deducting(): void
    {
        $advance = AdvanceSalary::create([
            'school_id' => $this->school->id,
            'branch_id' => $this->branch->id,
            'staff_id' => $this->staff->id,
            'month' => 9, 'year' => 2026, 'amount' => 6000, 'installments' => 1,
            'status' => 'approved', 'recovery_status' => 'written_off',
            'request_date' => '2026-09-01',
        ]);

        $this->assertSame(0.0, $advance->installmentFor(9, 2026));
    }

    // ---------------------------------------------------------------------
    // The lock, which is the guard behind all of the above
    // ---------------------------------------------------------------------

    public function test_generation_refuses_a_locked_payroll(): void
    {
        $payroll = $this->payroll->generate($this->staff, 9, 2026);

        $this->assertSame(51200.0, (float) $payroll->gross);

        $payroll->lock();
        $this->staff->update(['basic_salary' => 42000]);

        $again = $this->payroll->generate($this->staff->fresh(), 9, 2026);

        $this->assertSame(51200.0, (float) $again->gross, 'a locked payslip must not move');
    }

    // ---------------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------------

    private function salaryHead(string $code, ?string $name = null, ?string $type = null, array $extra = []): SalaryComponent
    {
        $component = SalaryComponent::withoutGlobalScopes()
            ->where('branch_id', $this->branch->id)
            ->where('code', $code)
            ->first();

        if ($component === null) {
            $component = SalaryComponent::create([
                'school_id' => $this->school->id,
                'branch_id' => $this->branch->id,
                'code' => $code,
                'name' => $name ?? $code,
                'type' => $type ?? SalaryComponent::TYPE_ALLOWANCE,
                'calculation' => 'fixed',
                'is_taxable' => $type !== SalaryComponent::TYPE_DEDUCTION,
                'esi_wage' => $type !== SalaryComponent::TYPE_DEDUCTION,
                'emit_as_line' => true,
                'display_order' => 150,
                'status' => true,
            ] + $extra);
        } elseif ($extra !== []) {
            $component->update($extra);
        }

        return $component;
    }

    private function structure(
        string $code,
        float $amount,
        ?string $legacyName = null,
        string $from = '2015-06-15',
        ?string $to = null,
    ): StaffSalaryStructure {
        return StaffSalaryStructure::create([
            'school_id' => $this->school->id,
            'branch_id' => $this->branch->id,
            'staff_id' => $this->staff->id,
            'salary_component_id' => $this->salaryHead($code)->id,
            'legacy_name' => $legacyName,
            'amount' => $amount,
            'effective_from' => $from,
            'effective_to' => $to,
        ]);
    }

    /** Turn the provident-fund engine on, the way the Statutory Rules screen does. */
    private function enableProvidentFund(): void
    {
        $set = StatutoryRuleSet::create([
            'school_id' => $this->school->id,
            'branch_id' => null,
            'name' => 'Rules 2026-27',
            'financial_year' => '2026-2027',
            'effective_from' => '2026-04-01',
            'effective_to' => '2027-03-31',
            'status' => true,
        ]);

        foreach ([
            'enabled' => '1', 'employee_rate' => '12', 'employer_rate' => '12',
            'pension_rate' => '8.33', 'wage_ceiling' => '15000', 'apply_ceiling' => '1',
            'pension_ceiling' => '15000', 'require_member_no' => '1',
        ] as $key => $value) {
            StatutoryRule::create([
                'statutory_rule_set_id' => $set->id,
                'kind' => 'pf', 'row_type' => 'param',
                'param_key' => $key, 'param_value' => $value,
            ]);
        }

        $this->salaryHead('BASIC')->update(['pf_wage' => true]);
    }
}

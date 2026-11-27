<?php

namespace Classivo\Hr\Tests\Feature;

use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Hr\Models\Payroll;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Services\Payroll\SalaryComponentProvisioner;
use Classivo\Hr\Services\Payroll\SalaryDisbursementService;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Payroll integrity guarantees:
 *
 *   • salary history survives a staff member being deleted,
 *   • a payroll run cannot reach another school's employees.
 */
class PayrollIntegrityTest extends TestCase
{
    private School $school;

    private Branch $branch;

    private School $otherSchool;

    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        SalaryComponentProvisioner::flush();

        $this->school = School::create(['name' => 'School A', 'code' => 'SCHA', 'status' => true]);
        $this->branch = Branch::create([
            'school_id' => $this->school->id, 'name' => 'A Main', 'code' => 'AMAIN',
            'is_default' => true, 'status' => true,
        ]);

        $this->otherSchool = School::create(['name' => 'School B', 'code' => 'SCHB', 'status' => true]);
        $this->otherBranch = Branch::create([
            'school_id' => $this->otherSchool->id, 'name' => 'B Main', 'code' => 'BMAIN',
            'is_default' => true, 'status' => true,
        ]);
        app(CurrentContext::class)->setSchoolId($this->school->id);
    }

    // ---------------------------------------------------------------------
    // Defect: force-deleting a staff member CASCADE-destroyed paid payrolls
    // ---------------------------------------------------------------------

    public function test_force_deleting_a_staff_member_with_payroll_history_is_refused(): void
    {
        $staff = $this->staffFor($this->school, $this->branch);
        $payroll = app(PayrollService::class)->generate($staff, 9, 2026);
        app(PayrollService::class)->markPaid($payroll, null, 'bank');

        $this->assertTrue($staff->hasProtectedPayrollHistory());

        $staff->delete();

        $this->assertFalse($staff->forceDelete(), 'the purge must be refused, not merely warned about');
        $this->assertDatabaseHas('staff', ['id' => $staff->id]);
        $this->assertSame(1, DB::table('payrolls')->where('staff_id', $staff->id)->count());
        $this->assertGreaterThan(0, DB::table('payroll_details')->where('payroll_id', $payroll->id)->count());
    }

    public function test_the_guard_counts_trashed_payrolls_too(): void
    {
        $staff = $this->staffFor($this->school, $this->branch);
        $payroll = app(PayrollService::class)->generate($staff, 9, 2026);
        $payroll->delete();

        $staff->delete();

        $this->assertFalse($staff->forceDelete(), 'a soft-deleted payroll is exactly the history a purge would burn');
    }

    public function test_a_staff_member_with_no_salary_history_can_still_be_purged(): void
    {
        $staff = $this->staffFor($this->school, $this->branch);

        $this->assertFalse($staff->hasProtectedPayrollHistory());

        $staff->delete();

        $this->assertTrue((bool) $staff->forceDelete());
        $this->assertDatabaseMissing('staff', ['id' => $staff->id]);
    }

    public function test_the_summary_reports_what_would_be_destroyed(): void
    {
        $staff = $this->staffFor($this->school, $this->branch);
        app(PayrollService::class)->markPaid(app(PayrollService::class)->generate($staff, 8, 2026));
        app(PayrollService::class)->generate($staff, 9, 2026);

        $summary = $staff->payrollHistorySummary();

        $this->assertSame(2, $summary['payrolls']);
        $this->assertSame(1, $summary['paid']);
        $this->assertGreaterThan(0.0, $summary['paid_total']);
    }

    // ---------------------------------------------------------------------
    // Defect: batch generation and the pre-flight crossed tenants in console
    // ---------------------------------------------------------------------

    public function test_batch_generation_never_reaches_another_school(): void
    {
        $mine = $this->staffFor($this->school, $this->branch);
        $theirs = $this->staffFor($this->otherSchool, $this->otherBranch);

        // The unresolved (console / queue) context in which the bug lived: the
        // tenant scope is a documented no-op here.
        app(CurrentContext::class)->markResolved(false);

        $summary = app(PayrollService::class)->generateBatch(9, 2026, null, null, $this->school->id);

        $this->assertSame(1, $summary['written']);
        $this->assertDatabaseHas('payrolls', ['staff_id' => $mine->id, 'school_id' => $this->school->id]);
        $this->assertDatabaseMissing('payrolls', ['staff_id' => $theirs->id]);

        $this->assertSame(
            [$this->school->id],
            Payroll::withoutGlobalScopes()->pluck('school_id')->unique()->values()->all(),
        );
    }

    public function test_the_disbursement_preflight_never_reaches_another_school(): void
    {
        $mine = $this->staffFor($this->school, $this->branch);
        $theirs = $this->staffFor($this->otherSchool, $this->otherBranch);

        app(PayrollService::class)->generate($mine, 9, 2026);
        app(PayrollService::class)->generate($theirs, 9, 2026);

        app(CurrentContext::class)->markResolved(false);

        $report = app(SalaryDisbursementService::class)->preflight(9, 2026, null, $this->school->id);

        $this->assertCount(1, $report['rows']);
        $this->assertSame(
            $this->school->id,
            (int) $report['rows'][0]['payroll']->school_id,
        );
    }

    // ---------------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------------

    private function staffFor(School $school, Branch $branch): Staff
    {
        return Staff::withoutGlobalScopes()->create([
            'school_id' => $school->id,
            'branch_id' => $branch->id,
            'first_name' => 'Pat',
            'last_name' => 'Employee'.$branch->id,
            'status' => 'active',
            'joining_date' => '2020-01-01',
            'basic_salary' => 30000,
            'allowances' => [['name' => 'House Rent Allowance', 'amount' => 12000]],
            'deductions' => [],
        ]);
    }
}

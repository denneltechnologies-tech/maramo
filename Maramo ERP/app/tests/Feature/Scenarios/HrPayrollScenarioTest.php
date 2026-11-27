<?php

namespace Tests\Feature\Scenarios;

use App\Models\User;
use Classivo\Hr\HrPlugin;
use Classivo\Hr\Models\Payroll;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffAttendance;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Services\StaffAttendanceService;

/** Staff joins -> attendance -> payroll generated -> payslip PDF, with access limits. */
class HrPayrollScenarioTest extends ScenarioTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePackages(HrPlugin::class);
    }

    public function test_joiner_attendance_payroll_and_payslip_download(): void
    {
        $this->bootDemo();
        $admin = $this->login('admin@example.com');

        $teacher = User::where('email', 'teacher@example.com')->firstOrFail();
        $staff = Staff::create([
            'first_name' => 'Demo', 'last_name' => 'Teacher', 'joining_date' => '2030-01-01',
            'basic_salary' => 30000, 'status' => 'active', 'user_id' => $teacher->id,
        ]);
        $this->assertNotEmpty($staff->employee_id, 'employee id is generated on joining');

        $attendance = app(StaffAttendanceService::class);
        $this->assertTrue($attendance->roster()->pluck('id')->contains($staff->id));
        $attendance->saveDaily('2030-03-04', [['staff_id' => $staff->id, 'status' => 'present']], $admin->id);
        $this->assertSame('present', StaffAttendance::whereDate('date', '2030-03-04')->where('staff_id', $staff->id)->value('status'));

        $payroll = app(PayrollService::class)->generate($staff, 3, 2030, $admin->id);
        $this->assertSame($staff->id, $payroll->staff_id);
        $this->assertGreaterThan(0, (float) $payroll->net_salary);

        // Admin downloads the payslip.
        $response = $this->get(route('payroll.payslip', ['payroll' => $payroll->id, 'download' => 1]));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertTrue(Payroll::findOrFail($payroll->id)->isLocked(), 'downloading a payslip issues (locks) it');

        // The employee may fetch their own payslip even without payroll permission.
        $this->login('teacher@example.com');
        $this->get(route('payroll.payslip', ['payroll' => $payroll->id]))->assertOk();
    }

    public function test_a_colleague_cannot_read_someone_elses_payslip(): void
    {
        $this->bootDemo();
        $admin = $this->login('admin@example.com');

        $staff = Staff::create(['first_name' => 'Other', 'last_name' => 'Person', 'joining_date' => '2030-01-01', 'basic_salary' => 20000, 'status' => 'active']);
        $payroll = app(PayrollService::class)->generate($staff, 3, 2030, $admin->id);

        // Teacher login has no payslip.download permission and the payslip is not theirs.
        $this->login('teacher@example.com');
        $this->get(route('payroll.payslip', ['payroll' => $payroll->id]))->assertForbidden();

        $this->login('student@example.com');
        $this->get(route('payroll.payslip', ['payroll' => $payroll->id]))->assertForbidden();

        auth()->logout();
        $this->get(route('payroll.payslip', ['payroll' => $payroll->id]))
            ->assertRedirect(null); // BUG app/bootstrap/app.php:13 - guest gets 500 (no login route), see PermissionBoundariesTest
    }
}

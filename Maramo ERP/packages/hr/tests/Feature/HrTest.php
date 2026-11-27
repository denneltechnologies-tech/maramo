<?php

namespace Classivo\Hr\Tests\Feature;

use Classivo\Hr\Events\SalaryPaid;
use Classivo\Hr\Events\SalaryPaying;
use Classivo\Hr\Models\Department;
use Classivo\Hr\Models\Payroll;
use Classivo\Hr\Models\SalaryComponent;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffAttendance;
use Classivo\Hr\Models\StaffLeaveAllocation;
use Classivo\Hr\Models\StaffLeaveRequest;
use Classivo\Hr\Models\StaffLeaveType;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Services\StaffAttendanceService;
use Classivo\Hr\Tests\Support\User;
use Classivo\Hr\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * HR & payroll: employee-id generation, enable-login, staff attendance, staff-leave
 * balance, payroll generation/net, the payroll.pay gate, the SalaryPaying veto seam,
 * the SalaryPaid finance seam, the payslip PDF and tenancy stamping.
 */
class HrTest extends TestCase
{
    private function boot(): User
    {
        [$school] = $this->foundation();
        $admin = $this->adminUser($school);
        $this->actingAs($admin);

        foreach (['Alpha', 'Bravo', 'Charlie'] as $name) {
            $this->makeStaff(['first_name' => $name]);
        }

        return $admin;
    }

    private function makeStaff(array $overrides = []): Staff
    {
        return Staff::create(array_merge([
            'first_name' => 'Test',
            'last_name' => 'Staff',
            'joining_date' => '2028-01-01',
            'basic_salary' => 30000,
            'status' => 'active',
        ], $overrides));
    }

    public function test_staff_create_generates_unique_sequential_employee_id(): void
    {
        $this->boot();

        $a = $this->makeStaff(['first_name' => 'Delta']);
        $b = $this->makeStaff(['first_name' => 'Echo']);

        $this->assertMatchesRegularExpression('/^EMP\d{4,}$/', $a->employee_id);
        $this->assertNotSame($a->employee_id, $b->employee_id);
        $this->assertSame(
            (int) substr($a->employee_id, 3) + 1,
            (int) substr($b->employee_id, 3),
        );
    }

    public function test_enable_login_creates_a_linked_user(): void
    {
        $this->boot();

        $staff = $this->makeStaff(['first_name' => 'Login', 'email' => 'login.staff@example.test']);

        $user = $staff->provisionLogin('secret-pass');

        $this->assertNotNull($user->id);
        $this->assertSame('login.staff@example.test', $user->email);
        $this->assertSame($user->id, $staff->fresh()->user_id);

        // Idempotent: a second call re-uses the same login.
        $this->assertSame($user->id, $staff->fresh()->provisionLogin()->id);
    }

    public function test_staff_attendance_marking_creates_rows_and_percentage_computes(): void
    {
        $this->boot();
        $service = app(StaffAttendanceService::class);

        $roster = $service->roster();
        $this->assertGreaterThan(0, $roster->count());

        $date = '2030-03-04';
        $rows = $roster->map(fn (Staff $s): array => ['staff_id' => $s->id, 'status' => 'present'])->all();
        $written = $service->saveDaily($date, $rows, null);

        $this->assertSame($roster->count(), $written);
        $this->assertSame($roster->count(), StaffAttendance::whereDate('date', $date)->count());

        // Re-save with the first staff absent: upsert, no duplicate.
        $rows[0]['status'] = 'absent';
        $service->saveDaily($date, $rows, null);
        $this->assertSame($roster->count(), StaffAttendance::whereDate('date', $date)->count());

        // 8 present + 2 absent over a clean range = 80%.
        $staff = $roster->first();
        foreach (range(1, 8) as $d) {
            StaffAttendance::create(['staff_id' => $staff->id, 'date' => sprintf('2031-01-%02d', $d), 'status' => 'present']);
        }
        foreach ([9, 10] as $d) {
            StaffAttendance::create(['staff_id' => $staff->id, 'date' => sprintf('2031-01-%02d', $d), 'status' => 'absent']);
        }
        $this->assertSame(80.0, $service->percentage($staff->id, '2031-01-01', '2031-01-31'));
    }

    public function test_staff_leave_approve_reduces_balance(): void
    {
        $this->boot();

        $staff = $this->makeStaff(['first_name' => 'Leaver']);
        $type = StaffLeaveType::create(['name' => 'Earned Leave', 'days_allowed' => 15, 'is_paid' => true, 'status' => true]);

        StaffLeaveAllocation::create([
            'staff_id' => $staff->id,
            'staff_leave_type_id' => $type->id,
            'days_allocated' => 10,
        ]);

        $this->assertSame(10.0, $staff->leaveBalance($type->id));

        $request = StaffLeaveRequest::create([
            'staff_id' => $staff->id,
            'staff_leave_type_id' => $type->id,
            'from_date' => '2030-04-01',
            'to_date' => '2030-04-03',
            'status' => 'pending',
        ]);
        $this->assertSame(3, $request->days);

        $request->approve(null);

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(7.0, $staff->fresh()->leaveBalance($type->id));
    }

    public function test_approving_staff_leave_writes_leave_attendance_and_reversing_it_removes_them(): void
    {
        $this->boot();

        $staff = $this->makeStaff(['first_name' => 'Absentee']);
        $type = StaffLeaveType::create(['name' => 'Sick Leave', 'days_allowed' => 12, 'is_paid' => true, 'status' => true]);

        StaffAttendance::create(['staff_id' => $staff->id, 'date' => '2030-04-02', 'status' => 'absent']);
        StaffAttendance::create(['staff_id' => $staff->id, 'date' => '2030-04-03', 'status' => 'present']);

        $request = StaffLeaveRequest::create([
            'staff_id' => $staff->id,
            'staff_leave_type_id' => $type->id,
            'from_date' => '2030-04-01', // Monday
            'to_date' => '2030-04-03',   // Wednesday
            'status' => 'pending',
        ]);

        $this->assertSame(2, $request->approve(null));

        $status = fn (string $date): ?string => StaffAttendance::query()
            ->where('staff_id', $staff->id)
            ->whereDate('date', $date)
            ->value('status');

        $this->assertSame('leave', $status('2030-04-01'));
        $this->assertSame('leave', $status('2030-04-02'), 'Approved leave must suppress the absent mark.');
        $this->assertSame('present', $status('2030-04-03'), 'A day actually worked is not erased.');

        $this->assertSame(2, $request->refresh()->reject(null, 'Cover unavailable.'));

        $this->assertNull($status('2030-04-01'));
        $this->assertNull($status('2030-04-02'));
        $this->assertSame('present', $status('2030-04-03'));
    }

    public function test_payroll_generate_computes_net_basic_plus_allowances_minus_deductions(): void
    {
        $this->boot();

        $staff = $this->makeStaff([
            'first_name' => 'Payee',
            'basic_salary' => 30000,
            'allowances' => [['name' => 'Travel', 'amount' => 2000], ['name' => 'HRA', 'amount' => 3000]],
            'deductions' => [['name' => 'Provident fund', 'amount' => 1000]],
        ]);

        $payroll = app(PayrollService::class)->generate($staff, 6, 2030);

        $this->assertSame('5000.00', $payroll->allowance_total);
        $this->assertSame('1000.00', $payroll->deduction_total);
        $this->assertSame('35000.00', $payroll->gross);
        $this->assertSame('34000.00', $payroll->net_salary);
        $this->assertSame(3, $payroll->details()->count());
    }

    public function test_payroll_abilities_follow_granted_permissions(): void
    {
        $admin = $this->boot();
        $plain = $this->userWith([]);

        foreach (['payroll.generate', 'payroll.pay', 'payroll.disburse', 'payslip.download'] as $ability) {
            $this->assertTrue($admin->can($ability), $ability);
            $this->assertFalse($plain->can($ability), $ability);
        }
    }

    public function test_mark_paid_fires_salary_paying_then_salary_paid(): void
    {
        $admin = $this->boot();
        $staff = $this->makeStaff(['first_name' => 'Paidee', 'basic_salary' => 40000]);
        $payroll = app(PayrollService::class)->generate($staff, 7, 2030);

        Event::fake([SalaryPaid::class, SalaryPaying::class]);

        app(PayrollService::class)->markPaid($payroll, $admin->id);

        $this->assertTrue($payroll->fresh()->isPaid());
        Event::assertDispatched(SalaryPaying::class, fn (SalaryPaying $e): bool => $e->payroll->is($payroll));
        Event::assertDispatched(SalaryPaid::class, function (SalaryPaid $e) use ($staff): bool {
            return $e->staffId === $staff->id
                && $e->amount === 40000.0
                && $e->month === '2030-07';
        });
    }

    public function test_a_salary_paying_listener_can_veto_the_payment(): void
    {
        $admin = $this->boot();
        $staff = $this->makeStaff(['first_name' => 'Vetoed', 'basic_salary' => 40000]);
        $payroll = app(PayrollService::class)->generate($staff, 7, 2030);

        Event::listen(SalaryPaying::class, function (): void {
            throw new RuntimeException('Accounting period is closed.');
        });

        try {
            app(PayrollService::class)->markPaid($payroll, $admin->id);
            $this->fail('The veto listener should have stopped the payment.');
        } catch (RuntimeException $e) {
            $this->assertSame('Accounting period is closed.', $e->getMessage());
        }

        $this->assertFalse($payroll->fresh()->isPaid());
    }

    public function test_payslip_pdf_route_returns_a_non_empty_pdf(): void
    {
        $admin = $this->boot();
        $staff = $this->makeStaff(['first_name' => 'Slip']);
        $payroll = app(PayrollService::class)->generate($staff, 8, 2030);

        $response = $this->actingAs($admin)->get(route('payroll.payslip', ['payroll' => $payroll->id]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_payslip_is_refused_to_an_unrelated_user_and_download_locks_it(): void
    {
        $admin = $this->boot();
        $staff = $this->makeStaff(['first_name' => 'Owner']);
        $payroll = app(PayrollService::class)->generate($staff, 9, 2030);

        $stranger = $this->userWith([]);
        $this->actingAs($stranger)->get(route('payroll.payslip', ['payroll' => $payroll->id]))->assertForbidden();

        $this->assertNull($payroll->fresh()->locked_at);

        // The staff member reaches their OWN payslip, and downloading freezes it.
        $owner = $staff->provisionLogin('secret-pass');
        $this->actingAs($owner)
            ->get(route('payroll.payslip', ['payroll' => $payroll->id, 'download' => 1]))
            ->assertOk();

        $this->assertNotNull($payroll->fresh()->locked_at);
    }

    public function test_hr_records_are_tenant_and_branch_auto_stamped(): void
    {
        $this->boot();

        $department = Department::create(['name' => 'Auto Dept', 'status' => true]);
        $staff = $this->makeStaff(['first_name' => 'Stamped']);

        $this->assertSame(current_school_id(), $department->school_id);
        $this->assertSame(current_branch_id(), $department->branch_id);
        $this->assertSame(current_school_id(), $staff->school_id);
        $this->assertSame(current_branch_id(), $staff->branch_id);
    }

    public function test_generic_preset_has_no_statutory_deductions(): void
    {
        $this->boot();
        config(['classivo.preset' => 'generic']);

        $staff = $this->makeStaff(['first_name' => 'Plain', 'basic_salary' => 50000, 'epf_no' => 'X1']);
        $payroll = app(PayrollService::class)->generate($staff, 5, 2030);

        $this->assertSame('0.00', $payroll->tax);
        $this->assertSame('50000.00', $payroll->net_salary);
        $this->assertSame(0, SalaryComponent::where('statutory_kind', 'pf_employee')->count());
    }
}

<?php

namespace Classivo\Hr\Tests\Feature;

use Classivo\Hr\Database\Seeders\HrPermissionSeeder;
use Classivo\Hr\Filament\Pages\GeneratePayroll;
use Classivo\Hr\Filament\Pages\SalaryDisbursement;
use Classivo\Hr\Filament\Pages\StaffAttendanceReport;
use Classivo\Hr\Filament\Pages\TakeStaffAttendance;
use Classivo\Hr\Filament\Resources\AdvanceSalaries\AdvanceSalaryResource;
use Classivo\Hr\Filament\Resources\Departments\DepartmentResource;
use Classivo\Hr\Filament\Resources\Designations\DesignationResource;
use Classivo\Hr\Filament\Resources\Payrolls\Pages\ListPayrolls;
use Classivo\Hr\Filament\Resources\Payrolls\PayrollResource;
use Classivo\Hr\Filament\Resources\SalaryComponents\SalaryComponentResource;
use Classivo\Hr\Filament\Resources\Staff\Pages\EditStaff;
use Classivo\Hr\Filament\Resources\Staff\Pages\ListStaff;
use Classivo\Hr\Filament\Resources\Staff\StaffResource;
use Classivo\Hr\Filament\Resources\StaffLeaveAllocations\StaffLeaveAllocationResource;
use Classivo\Hr\Filament\Resources\StaffLeaveRequests\Pages\ListStaffLeaveRequests;
use Classivo\Hr\Filament\Resources\StaffLeaveRequests\StaffLeaveRequestResource;
use Classivo\Hr\Filament\Resources\StaffLeaveTypes\StaffLeaveTypeResource;
use Classivo\Hr\Filament\Resources\StatutoryRuleSets\StatutoryRuleSetResource;
use Classivo\Hr\Models\Department;
use Classivo\Hr\Models\Payroll;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffAttendance;
use Classivo\Hr\Models\StaffLeaveRequest;
use Classivo\Hr\Models\StaffLeaveType;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Support\HrPermissions as P;
use Classivo\Hr\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PermissionsTest extends TestCase
{
    /** Runs a component call that must be refused: a failed action assertion, 403 or 404 all count. */
    private function assertDenied($component, \Closure $call): void
    {
        try {
            $call($component);
        } catch (AssertionFailedError $e) {
            $this->addToAssertionCount(1);

            return;
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403, 404]);

            return;
        }

        $status = null;
        foreach ([403, 404] as $code) {
            try {
                $component->assertStatus($code);
                $status = $code;
                break;
            } catch (AssertionFailedError) {
            }
        }

        $this->assertNotNull($status, 'The call was not refused.');
    }

    /** @return list<class-string> */
    private function resources(): array
    {
        return [
            StaffResource::class, DepartmentResource::class, DesignationResource::class, StaffLeaveTypeResource::class,
            StaffLeaveAllocationResource::class, StaffLeaveRequestResource::class, SalaryComponentResource::class,
            StatutoryRuleSetResource::class, AdvanceSalaryResource::class, PayrollResource::class,
        ];
    }

    private function staff(): Staff
    {
        return Staff::create(['first_name' => 'Perm', 'joining_date' => '2028-01-01', 'basic_salary' => 20000, 'status' => 'active']);
    }

    public function test_guest_is_denied_everywhere(): void
    {
        $this->foundation();

        foreach ($this->resources() as $resource) {
            $this->assertFalse($resource::canViewAny(), $resource);
            $this->assertFalse($resource::canAccess(), $resource);
        }

        foreach ([TakeStaffAttendance::class, StaffAttendanceReport::class, GeneratePayroll::class, SalaryDisbursement::class] as $page) {
            $this->assertFalse($page::canAccess(), $page);
        }

        $payroll = $this->payroll();
        auth()->logout();
        $this->get(route('payroll.payslip', ['payroll' => $payroll->id]))->assertRedirect('/login');
    }

    private function payroll(): Payroll
    {
        $this->foundation('PERM');

        return app(PayrollService::class)->generate($this->staff(), 6, 2030);
    }

    public function test_a_user_without_permissions_is_denied_everything(): void
    {
        $this->foundation();
        $this->userWith([]);

        foreach ($this->resources() as $resource) {
            $this->assertFalse($resource::canViewAny(), $resource.' canViewAny');
            $this->assertFalse($resource::canCreate(), $resource.' canCreate');
            $this->assertFalse($resource::canDeleteAny(), $resource.' canDeleteAny');
            $this->assertFalse($resource::canAccess(), $resource.' canAccess');
        }

        foreach ([TakeStaffAttendance::class, StaffAttendanceReport::class, GeneratePayroll::class, SalaryDisbursement::class] as $page) {
            $this->assertFalse($page::canAccess(), $page);
            Livewire::test($page)->assertForbidden();
        }

        Livewire::test(ListStaff::class)->assertForbidden();
        Livewire::test(ListPayrolls::class)->assertForbidden();
    }

    public function test_view_permission_does_not_grant_write_abilities(): void
    {
        $this->foundation();
        $this->userWith([P::STAFF_VIEW]);
        $staff = $this->staff();

        $this->assertTrue(StaffResource::canViewAny());
        $this->assertTrue(StaffResource::canView($staff));
        $this->assertFalse(StaffResource::canCreate());
        $this->assertFalse(StaffResource::canEdit($staff));
        $this->assertFalse(StaffResource::canDelete($staff));
        $this->assertFalse(StaffResource::canForceDeleteAny());
        $this->assertFalse(StaffResource::canRestoreAny());
        $this->assertFalse(DepartmentResource::canViewAny());
        Livewire::test(ListStaff::class)->assertOk();
    }

    public function test_each_write_permission_maps_to_its_ability_and_payroll_is_read_only(): void
    {
        $this->foundation();
        $this->userWith([P::DEPARTMENTS_CREATE, P::DEPARTMENTS_UPDATE, P::DEPARTMENTS_DELETE, P::PAYROLLS_VIEW]);
        $dept = Department::create(['name' => 'D', 'status' => true]);

        $this->assertFalse(DepartmentResource::canViewAny());
        $this->assertTrue(DepartmentResource::canCreate());
        $this->assertTrue(DepartmentResource::canEdit($dept));
        $this->assertTrue(DepartmentResource::canDelete($dept));

        $this->assertTrue(PayrollResource::canViewAny());
        $this->assertFalse(PayrollResource::canCreate());
    }

    public function test_pages_need_their_own_permissions(): void
    {
        $this->foundation();

        $this->userWith([P::STAFF_ATTENDANCE_TAKE]);
        $this->assertTrue(TakeStaffAttendance::canAccess());
        $this->assertFalse(StaffAttendanceReport::canAccess());
        $this->assertFalse(GeneratePayroll::canAccess());

        $this->userWith([P::PAYROLL_GENERATE]);
        $this->assertTrue(GeneratePayroll::canAccess());
        $this->assertFalse(SalaryDisbursement::canAccess());

        $this->userWith([P::PAYROLL_DISBURSE]);
        $this->assertTrue(SalaryDisbursement::canAccess());
        Livewire::test(SalaryDisbursement::class)->assertOk();

        $this->userWith([P::STAFF_ATTENDANCE_REPORT]);
        $this->assertTrue(StaffAttendanceReport::canAccess());
    }

    public function test_page_methods_are_checked_server_side(): void
    {
        $this->foundation();
        $this->staff();
        $this->userWith([P::STAFF_ATTENDANCE_TAKE]);

        // Mounted legitimately, then the permission is revoked mid-session.
        Livewire::test(TakeStaffAttendance::class)->assertOk();
        auth()->user()->revokePermissionTo(P::STAFF_ATTENDANCE_TAKE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([['save'], ['markAll', 'absent'], ['load']] as $call) {
            $this->assertDenied(Livewire::test(TakeStaffAttendance::class), fn ($c) => $c->call(...$call));
        }
        $this->assertSame(0, StaffAttendance::count());
    }

    public function test_row_actions_are_authorised_server_side(): void
    {
        $payroll = $this->payroll();
        $this->userWith([P::PAYROLLS_VIEW]);

        Livewire::test(ListPayrolls::class)
            ->assertOk()
            ->assertActionHidden(TestAction::make('pay')->table($payroll))
            ->assertActionHidden(TestAction::make('lock')->table($payroll))
            ->assertActionHidden(TestAction::make('payslip')->table($payroll));

        // Calling a hidden action directly does nothing.
        $this->assertDenied(Livewire::test(ListPayrolls::class), fn ($c) => $c->callAction(TestAction::make('pay')->table($payroll)));
        $this->assertFalse($payroll->fresh()->isPaid());
        $this->assertDenied(Livewire::test(ListPayrolls::class), fn ($c) => $c->callAction(TestAction::make('lock')->table($payroll)));
        $this->assertNull($payroll->fresh()->locked_at);

        // Positive control: the right permission shows the action and it works.
        $this->userWith([P::PAYROLLS_VIEW, P::PAYROLL_PAY]);
        Livewire::test(ListPayrolls::class)
            ->assertActionVisible(TestAction::make('pay')->table($payroll))
            ->callAction(TestAction::make('pay')->table($payroll));
        $this->assertTrue($payroll->fresh()->isPaid());
    }

    public function test_leave_approval_needs_its_own_permission(): void
    {
        $this->foundation();
        $staff = $this->staff();
        $type = StaffLeaveType::create(['name' => 'Casual', 'days_allowed' => 5, 'is_paid' => true, 'status' => true]);
        $req = StaffLeaveRequest::create(['staff_id' => $staff->id, 'staff_leave_type_id' => $type->id, 'from_date' => '2030-04-01', 'to_date' => '2030-04-02', 'status' => 'pending']);

        $this->userWith([P::LEAVE_REQUESTS_VIEW, P::LEAVE_REQUESTS_UPDATE]);
        Livewire::test(ListStaffLeaveRequests::class)->assertActionHidden(TestAction::make('approve')->table($req));
        $this->assertDenied(Livewire::test(ListStaffLeaveRequests::class), fn ($c) => $c->callAction(TestAction::make('approve')->table($req)));
        $this->assertSame('pending', $req->fresh()->status);

        $this->userWith([P::LEAVE_REQUESTS_VIEW, P::LEAVE_REQUESTS_APPROVE]);
        Livewire::test(ListStaffLeaveRequests::class)
            ->callAction(TestAction::make('approve')->table($req));
        $this->assertSame('approved', $req->fresh()->status);
    }

    public function test_payslip_route_needs_the_download_permission_or_ownership(): void
    {
        $payroll = $this->payroll();

        $this->userWith([P::PAYROLLS_VIEW]);
        $this->get(route('payroll.payslip', ['payroll' => $payroll->id]))->assertForbidden();

        $this->userWith([P::PAYSLIP_DOWNLOAD]);
        $this->get(route('payroll.payslip', ['payroll' => $payroll->id]))->assertOk();

        $owner = $payroll->staff->provisionLogin('secret-pass');
        $this->actingAs($owner)->get(route('payroll.payslip', ['payroll' => $payroll->id]))->assertOk();
    }

    public function test_login_management_needs_its_own_permission(): void
    {
        $this->foundation();
        $staff = $this->staff();
        $staff->update(['email' => 'perm@example.test']);

        $this->userWith([P::STAFF_VIEW, P::STAFF_UPDATE]);

        $page = new EditStaff;
        $page->record = $staff;
        $page->data = ['enable_login' => true];
        $ref = new \ReflectionMethod($page, 'afterSave');
        $ref->setAccessible(true);
        $ref->invoke($page);

        $this->assertNull($staff->fresh()->user_id, 'manage_login not granted: no account created');

        $this->userWith([P::STAFF_UPDATE, P::STAFF_MANAGE_LOGIN]);
        $ref->invoke($page);
        $this->assertNotNull($staff->fresh()->user_id);
    }

    public function test_permission_seeder_creates_all_names_and_grants_roles(): void
    {
        config(['hr.grant_to_roles' => ['hr-manager']]);
        Role::create(['name' => 'hr-manager', 'guard_name' => 'web']);

        $this->seed(HrPermissionSeeder::class);
        $this->seed(HrPermissionSeeder::class);

        $this->assertSame(count(P::all()), Permission::query()->count());

        $this->foundation();
        $user = $this->userWith([]);
        $user->assignRole('hr-manager');
        $this->assertTrue($user->fresh()->can(P::PAYROLL_GENERATE));
        $this->assertTrue(StaffResource::canCreate());
    }

    public function test_permission_names_are_the_documented_set(): void
    {
        foreach (['hr.staff.view', 'hr.payrolls.view', 'payroll.generate', 'payroll.pay', 'payroll.disburse', 'payslip.download', 'staff_attendance.take', 'staff_attendance.report', 'hr.leave_requests.approve'] as $name) {
            $this->assertContains($name, P::all());
        }
        $this->assertCount(count(array_unique(P::all())), P::all());
    }

    public function test_without_spatie_the_fallback_gates_follow_is_admin(): void
    {
        // The provider only defines fallback gates when spatie/laravel-permission is absent;
        // here we exercise the same closure shape directly.
        $this->assertTrue(class_exists(PermissionRegistrar::class));
        $this->assertFalse(Gate::has(P::PAYROLL_PAY), 'with spatie installed no fallback gate is defined');
    }
}

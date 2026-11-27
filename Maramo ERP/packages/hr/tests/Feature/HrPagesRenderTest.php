<?php

namespace Classivo\Hr\Tests\Feature;

use Classivo\Hr\Database\Seeders\HrSeeder;
use Classivo\Hr\Filament\Pages\GeneratePayroll;
use Classivo\Hr\Filament\Pages\SalaryDisbursement;
use Classivo\Hr\Filament\Pages\StaffAttendanceReport;
use Classivo\Hr\Filament\Pages\TakeStaffAttendance;
use Classivo\Hr\Filament\Resources\AdvanceSalaries\Pages as AdvPages;
use Classivo\Hr\Filament\Resources\Departments\Pages as DeptPages;
use Classivo\Hr\Filament\Resources\Designations\Pages as DesigPages;
use Classivo\Hr\Filament\Resources\Payrolls\Pages as PayPages;
use Classivo\Hr\Filament\Resources\SalaryComponents\Pages as CompPages;
use Classivo\Hr\Filament\Resources\Staff\Pages as StaffPages;
use Classivo\Hr\Filament\Resources\StaffLeaveAllocations\Pages as AllocPages;
use Classivo\Hr\Filament\Resources\StaffLeaveRequests\Pages as ReqPages;
use Classivo\Hr\Filament\Resources\StaffLeaveTypes\Pages as TypePages;
use Classivo\Hr\Filament\Resources\StatutoryRuleSets\Pages as RulePages;
use Classivo\Hr\Models\AdvanceSalary;
use Classivo\Hr\Models\Department;
use Classivo\Hr\Models\Designation;
use Classivo\Hr\Models\SalaryComponent;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffLeaveAllocation;
use Classivo\Hr\Models\StaffLeaveRequest;
use Classivo\Hr\Models\StaffLeaveType;
use Classivo\Hr\Models\StatutoryRuleSet;
use Classivo\Hr\Services\Payroll\StatutoryRuleSetProvisioner;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/** Livewire render tests for all 10 HR resources (list/create/edit/view) and the 4 pages, in both locales. */
class HrPagesRenderTest extends TestCase
{
    private Staff $staff;

    private $payroll;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        [$school] = $this->foundation();
        $this->adminUser($school);

        $this->staff = Staff::create(['first_name' => 'Render', 'joining_date' => '2028-01-01', 'basic_salary' => 20000, 'status' => 'active']);
        $this->payroll = app(PayrollService::class)->generate($this->staff, 6, 2030);
    }

    /** @return array<string, array{0: string}> */
    public static function locales(): array
    {
        return ['english' => ['en'], 'hindi' => ['hi']];
    }

    /** @dataProvider locales */
    #[DataProvider('locales')]
    public function test_every_resource_list_page_renders(string $locale): void
    {
        app()->setLocale($locale);

        foreach ([
            StaffPages\ListStaff::class, DeptPages\ListDepartments::class, DesigPages\ListDesignations::class,
            TypePages\ListStaffLeaveTypes::class, AllocPages\ListStaffLeaveAllocations::class, ReqPages\ListStaffLeaveRequests::class,
            CompPages\ListSalaryComponents::class, RulePages\ListStatutoryRuleSets::class, AdvPages\ListAdvanceSalaries::class,
            PayPages\ListPayrolls::class,
        ] as $page) {
            Livewire::test($page)->assertOk();
        }
    }

    #[DataProvider('locales')]
    public function test_every_resource_create_page_renders(string $locale): void
    {
        app()->setLocale($locale);

        foreach ([
            StaffPages\CreateStaff::class, DeptPages\CreateDepartment::class, DesigPages\CreateDesignation::class,
            TypePages\CreateStaffLeaveType::class, AllocPages\CreateStaffLeaveAllocation::class, ReqPages\CreateStaffLeaveRequest::class,
            CompPages\CreateSalaryComponent::class, RulePages\CreateStatutoryRuleSet::class, AdvPages\CreateAdvanceSalary::class,
        ] as $page) {
            Livewire::test($page)->assertOk();
        }
    }

    #[DataProvider('locales')]
    public function test_edit_and_view_pages_render(string $locale): void
    {
        app()->setLocale($locale);

        $dept = Department::create(['name' => 'Dept', 'status' => true]);
        $desig = Designation::create(['name' => 'Desig', 'status' => true]);
        $type = StaffLeaveType::create(['name' => 'Casual', 'days_allowed' => 5, 'is_paid' => true, 'status' => true]);
        $alloc = StaffLeaveAllocation::create(['staff_id' => $this->staff->id, 'staff_leave_type_id' => $type->id, 'days_allocated' => 5]);
        $req = StaffLeaveRequest::create(['staff_id' => $this->staff->id, 'staff_leave_type_id' => $type->id, 'from_date' => '2030-04-01', 'to_date' => '2030-04-02', 'status' => 'pending']);
        $adv = AdvanceSalary::create(['staff_id' => $this->staff->id, 'amount' => 1000, 'month' => 6, 'year' => 2030, 'status' => 'pending']);
        $comp = SalaryComponent::withoutGlobalScopes()->where('is_system', false)->first()
            ?? SalaryComponent::create(['code' => 'HRA', 'name' => 'HRA', 'type' => 'allowance', 'calculation' => 'fixed']);
        $set = StatutoryRuleSet::withoutGlobalScopes()->find(app(StatutoryRuleSetProvisioner::class)->ensureFor(current_school_id()));

        Livewire::test(StaffPages\EditStaff::class, ['record' => $this->staff->getKey()])->assertOk();
        Livewire::test(StaffPages\ViewStaff::class, ['record' => $this->staff->getKey()])->assertOk();
        Livewire::test(DeptPages\EditDepartment::class, ['record' => $dept->getKey()])->assertOk();
        Livewire::test(DesigPages\EditDesignation::class, ['record' => $desig->getKey()])->assertOk();
        Livewire::test(TypePages\EditStaffLeaveType::class, ['record' => $type->getKey()])->assertOk();
        Livewire::test(AllocPages\EditStaffLeaveAllocation::class, ['record' => $alloc->getKey()])->assertOk();
        Livewire::test(ReqPages\EditStaffLeaveRequest::class, ['record' => $req->getKey()])->assertOk();
        Livewire::test(AdvPages\EditAdvanceSalary::class, ['record' => $adv->getKey()])->assertOk();
        Livewire::test(CompPages\EditSalaryComponent::class, ['record' => $comp->getKey()])->assertOk();
        Livewire::test(RulePages\EditStatutoryRuleSet::class, ['record' => $set->getKey()])->assertOk();
        Livewire::test(PayPages\ViewPayroll::class, ['record' => $this->payroll->getKey()])->assertOk();
    }

    #[DataProvider('locales')]
    public function test_the_four_custom_pages_render(string $locale): void
    {
        app()->setLocale($locale);

        foreach ([TakeStaffAttendance::class, StaffAttendanceReport::class, GeneratePayroll::class, SalaryDisbursement::class] as $page) {
            Livewire::test($page)->assertOk();
        }
    }

    public function test_demo_seeder_is_idempotent(): void
    {
        $this->seed(HrSeeder::class);
        $this->seed(HrSeeder::class);

        $this->assertSame(4, Staff::withoutGlobalScopes()->where('first_name', '!=', 'Render')->count());
        $this->assertSame(1, StatutoryRuleSet::withoutGlobalScopes()->count());
    }

    public function test_statutory_preset_command_creates_a_disabled_rule_set(): void
    {
        $this->artisan('hr:install-statutory-preset')->assertSuccessful();

        $set = StatutoryRuleSet::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('IN', $set->country_code);
        $this->assertFalse($set->enabled('pf'));
        $this->assertFalse($set->enabled('esi'));
    }
}

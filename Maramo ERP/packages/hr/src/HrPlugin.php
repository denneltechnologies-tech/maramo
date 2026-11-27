<?php

namespace Classivo\Hr;

use Classivo\Hr\Filament\Pages\GeneratePayroll;
use Classivo\Hr\Filament\Pages\SalaryDisbursement;
use Classivo\Hr\Filament\Pages\StaffAttendanceReport;
use Classivo\Hr\Filament\Pages\TakeStaffAttendance;
use Classivo\Hr\Filament\Resources\AdvanceSalaries\AdvanceSalaryResource;
use Classivo\Hr\Filament\Resources\Departments\DepartmentResource;
use Classivo\Hr\Filament\Resources\Designations\DesignationResource;
use Classivo\Hr\Filament\Resources\Payrolls\PayrollResource;
use Classivo\Hr\Filament\Resources\SalaryComponents\SalaryComponentResource;
use Classivo\Hr\Filament\Resources\Staff\StaffResource;
use Classivo\Hr\Filament\Resources\StaffLeaveAllocations\StaffLeaveAllocationResource;
use Classivo\Hr\Filament\Resources\StaffLeaveRequests\StaffLeaveRequestResource;
use Classivo\Hr\Filament\Resources\StaffLeaveTypes\StaffLeaveTypeResource;
use Classivo\Hr\Filament\Resources\StatutoryRuleSets\StatutoryRuleSetResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament plugin registering the HR & payroll screens.
 *
 *     $panel->plugin(\Classivo\Hr\HrPlugin::make())
 */
class HrPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'classivo-hr';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            StaffResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}

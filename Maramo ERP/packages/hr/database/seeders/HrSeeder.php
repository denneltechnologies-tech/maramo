<?php

namespace Classivo\Hr\Database\Seeders;

use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Hr\Models\Department;
use Classivo\Hr\Models\Designation;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffLeaveAllocation;
use Classivo\Hr\Models\StaffLeaveType;
use Classivo\Hr\Services\Payroll\StatutoryRuleSetProvisioner;
use Classivo\Hr\Services\PayrollService;
use Illuminate\Database\Seeder;

/**
 * HrSeeder — idempotent demo data with generic fake people:
 *   • 3 departments + 4 designations,
 *   • 4 staff (no logins),
 *   • a Casual Leave staff-leave type + a 12-day allocation for each staff,
 *   • one generated payroll for the current month.
 *
 * Needs a school + branch from the core package (and optionally a current session).
 * Also installs the active preset's statutory rule set (every kind disabled).
 */
class HrSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::orderBy('id')->first();

        if (! $school) {
            return;
        }

        $branch = Branch::where('school_id', $school->id)->orderByDesc('is_default')->orderBy('id')->first();

        if (! $branch) {
            return;
        }

        $session = AcademicSession::where('school_id', $school->id)->where('is_current', true)->first();

        app(CurrentContext::class)
            ->setSchoolId($school->id)
            ->setBranchId($branch->id)
            ->setSessionId($session?->id);

        app(StatutoryRuleSetProvisioner::class)->ensureFor((int) $school->id);

        // --- Departments -------------------------------------------------
        $departments = collect(['Teaching', 'Administration', 'Support'])
            ->mapWithKeys(fn (string $name): array => [
                $name => Department::updateOrCreate(
                    ['school_id' => $school->id, 'branch_id' => $branch->id, 'name' => $name],
                    ['status' => true],
                ),
            ]);

        // --- Designations ------------------------------------------------
        $designations = collect(['Principal', 'Senior Teacher', 'Teacher', 'Clerk'])
            ->mapWithKeys(fn (string $name): array => [
                $name => Designation::updateOrCreate(
                    ['school_id' => $school->id, 'branch_id' => $branch->id, 'name' => $name],
                    ['status' => true],
                ),
            ]);

        // --- Staff -------------------------------------------------------
        $definitions = [
            [
                'employee_id' => 'EMP0001', 'first_name' => 'Anita', 'last_name' => 'Rao',
                'department' => 'Teaching', 'designation' => 'Senior Teacher', 'email' => 'anita.rao@example.test',
                'basic_salary' => 45000,
                'allowances' => [['name' => 'House rent', 'amount' => 8000], ['name' => 'Travel', 'amount' => 2000]],
                'deductions' => [['name' => 'PF', 'amount' => 3000]],
            ],
            [
                'employee_id' => 'EMP0002', 'first_name' => 'Rakesh', 'last_name' => 'Menon',
                'department' => 'Teaching', 'designation' => 'Teacher', 'email' => 'rakesh.menon@example.test',
                'basic_salary' => 38000,
                'allowances' => [['name' => 'House rent', 'amount' => 6000]],
                'deductions' => [['name' => 'PF', 'amount' => 2500]],
            ],
            [
                'employee_id' => 'EMP0003', 'first_name' => 'Sunita', 'last_name' => 'Iyer',
                'department' => 'Administration', 'designation' => 'Principal', 'email' => 'sunita.iyer@example.test',
                'basic_salary' => 65000,
                'allowances' => [['name' => 'House rent', 'amount' => 12000], ['name' => 'Special', 'amount' => 5000]],
                'deductions' => [['name' => 'PF', 'amount' => 4000], ['name' => 'Tax', 'amount' => 3000]],
            ],
            [
                'employee_id' => 'EMP0004', 'first_name' => 'Manoj', 'last_name' => 'Kumar',
                'department' => 'Support', 'designation' => 'Clerk', 'email' => 'manoj.kumar@example.test',
                'basic_salary' => 22000,
                'allowances' => [['name' => 'Travel', 'amount' => 1500]],
                'deductions' => [],
            ],
        ];

        $staff = collect($definitions)->map(function (array $d) use ($school, $branch, $departments, $designations): Staff {
            return Staff::updateOrCreate(
                ['school_id' => $school->id, 'employee_id' => $d['employee_id']],
                [
                    'branch_id' => $branch->id,
                    'first_name' => $d['first_name'],
                    'last_name' => $d['last_name'],
                    'email' => $d['email'],
                    'department_id' => $departments[$d['department']]->id,
                    'designation_id' => $designations[$d['designation']]->id,                    'joining_date' => now()->subYears(2)->toDateString(),
                    'gender' => 'other',
                    'basic_salary' => $d['basic_salary'],
                    'contract_type' => 'permanent',
                    'allowances' => $d['allowances'],
                    'deductions' => $d['deductions'],
                    'status' => 'active',
                ],
            );
        });

        // --- Staff leave type + allocations ------------------------------
        $casual = StaffLeaveType::updateOrCreate(
            ['school_id' => $school->id, 'branch_id' => $branch->id, 'name' => 'Casual Leave'],
            ['days_allowed' => 12, 'is_paid' => true, 'status' => true],
        );

        foreach ($staff as $member) {
            StaffLeaveAllocation::updateOrCreate(
                [
                    'staff_id' => $member->id,
                    'staff_leave_type_id' => $casual->id,
                    'session_id' => $session?->id,
                ],
                [
                    'school_id' => $school->id,
                    'branch_id' => $branch->id,
                    'days_allocated' => 12,
                ],
            );
        }

        // --- One generated payroll (current month) -----------------------
        app(PayrollService::class)->generate(
            $staff->first(),
            (int) now()->format('n'),
            (int) now()->format('Y'),
        );
    }
}

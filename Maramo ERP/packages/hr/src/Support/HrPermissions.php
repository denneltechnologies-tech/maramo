<?php

namespace Classivo\Hr\Support;

/**
 * Permission names used by the HR UI. Plain Laravel ability strings, so they work
 * with spatie/laravel-permission (create them with HrPermissionSeeder and assign to
 * roles via config `hr.grant_to_roles`) or with your own Gate/policies.
 *
 * Deny by default. When spatie/laravel-permission is NOT installed, the provider
 * defines each ability as "user has a truthy is_admin attribute" (config
 * `hr.define_gates`); with spatie installed nothing is defined, so only explicitly
 * granted permissions pass.
 */
class HrPermissions
{
    public const STAFF_VIEW = 'hr.staff.view';

    public const STAFF_CREATE = 'hr.staff.create';

    public const STAFF_UPDATE = 'hr.staff.update';

    public const STAFF_DELETE = 'hr.staff.delete';

    public const STAFF_MANAGE_LOGIN = 'hr.staff.manage_login';

    public const STAFF_ASSIGN_ROLES = 'hr.staff.assign_roles';

    public const DEPARTMENTS_VIEW = 'hr.departments.view';

    public const DEPARTMENTS_CREATE = 'hr.departments.create';

    public const DEPARTMENTS_UPDATE = 'hr.departments.update';

    public const DEPARTMENTS_DELETE = 'hr.departments.delete';

    public const DESIGNATIONS_VIEW = 'hr.designations.view';

    public const DESIGNATIONS_CREATE = 'hr.designations.create';

    public const DESIGNATIONS_UPDATE = 'hr.designations.update';

    public const DESIGNATIONS_DELETE = 'hr.designations.delete';

    public const LEAVE_TYPES_VIEW = 'hr.leave_types.view';

    public const LEAVE_TYPES_CREATE = 'hr.leave_types.create';

    public const LEAVE_TYPES_UPDATE = 'hr.leave_types.update';

    public const LEAVE_TYPES_DELETE = 'hr.leave_types.delete';

    public const LEAVE_ALLOCATIONS_VIEW = 'hr.leave_allocations.view';

    public const LEAVE_ALLOCATIONS_CREATE = 'hr.leave_allocations.create';

    public const LEAVE_ALLOCATIONS_UPDATE = 'hr.leave_allocations.update';

    public const LEAVE_ALLOCATIONS_DELETE = 'hr.leave_allocations.delete';

    public const LEAVE_REQUESTS_VIEW = 'hr.leave_requests.view';

    public const LEAVE_REQUESTS_CREATE = 'hr.leave_requests.create';

    public const LEAVE_REQUESTS_UPDATE = 'hr.leave_requests.update';

    public const LEAVE_REQUESTS_DELETE = 'hr.leave_requests.delete';

    public const LEAVE_REQUESTS_APPROVE = 'hr.leave_requests.approve';

    public const SALARY_COMPONENTS_VIEW = 'hr.salary_components.view';

    public const SALARY_COMPONENTS_CREATE = 'hr.salary_components.create';

    public const SALARY_COMPONENTS_UPDATE = 'hr.salary_components.update';

    public const SALARY_COMPONENTS_DELETE = 'hr.salary_components.delete';

    public const STATUTORY_RULES_VIEW = 'hr.statutory_rules.view';

    public const STATUTORY_RULES_CREATE = 'hr.statutory_rules.create';

    public const STATUTORY_RULES_UPDATE = 'hr.statutory_rules.update';

    public const STATUTORY_RULES_DELETE = 'hr.statutory_rules.delete';

    public const ADVANCE_SALARIES_VIEW = 'hr.advance_salaries.view';

    public const ADVANCE_SALARIES_CREATE = 'hr.advance_salaries.create';

    public const ADVANCE_SALARIES_UPDATE = 'hr.advance_salaries.update';

    public const ADVANCE_SALARIES_DELETE = 'hr.advance_salaries.delete';

    public const ADVANCE_SALARIES_APPROVE = 'hr.advance_salaries.approve';

    public const PAYROLLS_VIEW = 'hr.payrolls.view';

    public const PAYROLL_GENERATE = 'payroll.generate';

    public const PAYROLL_PAY = 'payroll.pay';

    public const PAYROLL_DISBURSE = 'payroll.disburse';

    public const PAYSLIP_DOWNLOAD = 'payslip.download';

    public const STAFF_ATTENDANCE_TAKE = 'staff_attendance.take';

    public const STAFF_ATTENDANCE_REPORT = 'staff_attendance.report';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(static::class))->getConstants());
    }

    public static function can(string $permission): bool
    {
        return (bool) auth()->user()?->can($permission);
    }

    public static function authorize(string $permission): void
    {
        abort_unless(static::can($permission), 403);
    }
}

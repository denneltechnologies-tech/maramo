# classivo/hr

Staff records, leave, staff attendance and payroll with payslips.

## What it does

HR looks after the school's staff and their pay.

- Keep a staff directory with departments and designations. Staff can have documents and a linked login. When a staff member is deleted (soft), the linked login is switched off.
- Define leave types, give each staff member a yearly allowance, and handle leave requests with approval.
- Take daily staff attendance and see an attendance report.
- Set up salary components (basic pay, allowances, deductions, employer contributions). A raise is a new salary structure row, not an edit.
- Give advance salary with approval. It is taken back in payroll.
- Generate monthly payroll, lock it, mark it paid, and download a payslip PDF. A staff member can download their own payslip.
- Loss of pay can be worked out from attendance.
- Statutory deductions (provident fund, ESI, professional tax, income tax) are built in as rules. Every one ships switched off. Check the rates for your country and switch them on yourself.
- The payroll engine is country neutral. Presets `india` and `generic` are included.

## Screens

All screens are in the navigation group **Human Resource**.

| Screen | What it does | Where |
|---|---|---|
| Staff Directory | Staff records | Human Resource |
| Departments | Departments | Human Resource |
| Designations | Job titles | Human Resource |
| Staff Leave Types | Kinds of leave | Human Resource |
| Leave Allocations | Days of leave per person | Human Resource |
| Staff Leave Requests | Apply, approve or reject leave | Human Resource |
| Salary Components | Pay heads | Human Resource |
| Statutory Rules | Rule sets for PF, ESI, professional tax and income tax | Human Resource |
| Advance Salary | Advance requests and approval | Human Resource |
| Payroll | Payroll list, view, lock, pay, payslip | Human Resource |
| Staff Attendance | Take attendance for a date | Human Resource |
| Staff Attendance Report | Attendance report | Human Resource |
| Generate Payroll | Generate payroll for a month | Human Resource |
| Salary Disbursement | Pay out salaries | Human Resource |

Payslip route: `GET {route_prefix}/payroll/{payroll}/payslip` (route name `payroll.payslip`, default prefix `admin`).

## Permissions

Deny by default.

- Staff: `hr.staff.view`, `hr.staff.create`, `hr.staff.update`, `hr.staff.delete`, `hr.staff.manage_login`
- Departments: `hr.departments.view`, `.create`, `.update`, `.delete`
- Designations: `hr.designations.view`, `.create`, `.update`, `.delete`
- Leave types: `hr.leave_types.view`, `.create`, `.update`, `.delete`
- Leave allocations: `hr.leave_allocations.view`, `.create`, `.update`, `.delete`
- Leave requests: `hr.leave_requests.view`, `.create`, `.update`, `.delete`, `.approve`
- Salary components: `hr.salary_components.view`, `.create`, `.update`, `.delete`
- Statutory rules: `hr.statutory_rules.view`, `.create`, `.update`, `.delete`
- Advance salary: `hr.advance_salaries.view`, `.create`, `.update`, `.delete`, `.approve`
- Payroll: `hr.payrolls.view`, `payroll.generate`, `payroll.pay`, `payroll.disburse`, `payslip.download`
- Staff attendance: `staff_attendance.take`, `staff_attendance.report`

Default roles: none (`grant_to_roles` is empty). `HrPermissionSeeder` (needs `spatie/laravel-permission`) creates them and grants all to the roles you list. Without spatie, each falls back to a truthy `is_admin` attribute on the user (when `define_gates` is true).

## Configuration

Publish: `php artisan vendor:publish --tag=classivo-hr-config` (also `classivo-hr-lang`, `classivo-hr-views`). File: `config/hr.php`.

| Key | Default | Meaning |
|---|---|---|
| `define_gates` | `true` (env `HR_DEFINE_GATES`) | `is_admin` fallback gates when spatie is absent |
| `grant_to_roles` | `[]` | Roles the seeder grants every HR permission |
| `pdf_devanagari_locales` | `['hi']` | Locales whose payslip uses the bundled Devanagari font |
| `fy_start_month` | `4` | Financial year start month if a preset sets none |
| `core_params` | list | Payroll switches seeded for every preset: `lop_enabled` 0 (loss of pay is calculated but not deducted), `lop_basis` `calendar_days`, `unmarked_days_paid` 1, `prorate_joiners` 0 |
| `core_components` | BASIC, LOP, ADVANCE | Heads every payroll needs |
| `presets.india` | country IN, FY starts April | Statutory params, all disabled: PF (employee 12, employer 12, pension 8.33, ceiling 15000), ESI (0.75 / 3.25, threshold 21000), professional tax, income tax (regime `new`, cess 4). Plus the statutory components (PF_EMPLOYEE, ESI_EMPLOYEE, PT, TDS and employer contributions) |
| `presets.generic` | no country, FY starts January | No statutory levies. Loss of pay and advances still work |

The active preset comes from core config `classivo.preset` (env `CLASSIVO_PRESET`, default `india`). A preset with no statutory params deducts nothing.

The route prefix is read from `hr.route_prefix`, which is not in the shipped config. It defaults to `admin`. Add the key to your published config to change it.

Command: `php artisan hr:install-statutory-preset` with `--school=` (default: every school). It installs the preset rule set, every kind disabled, for the current financial year.

## Events

Emitted (in `Classivo\Hr\Events`):

| Class | When | Payload |
|---|---|---|
| `SalaryPaying` | Just before a payroll is marked paid. A listener may throw to stop it | payroll, paymentMode, date |
| `SalaryPaid` | After a payroll is marked paid (once per payroll) | staffId, amount (net salary), month (`YYYY-MM`), date, payrollId |

Listened: none. A model observer on `Staff` keeps the linked user's name and role in step and deactivates the login on delete.

## Install

```
composer require classivo/hr
php artisan migrate
```

Register the plugin:

```php
$panel->plugin(\Classivo\Hr\HrPlugin::make())
```

Also needs `classivo/core`, `classivo/pdf`, `barryvdh/laravel-dompdf`, `filament/spatie-laravel-media-library-plugin` and `spatie/laravel-medialibrary` (all required by the package). Use `spatie/laravel-permission` to grant roles. Optional demo data: `HrSeeder`. Run `php artisan hr:install-statutory-preset` once per install.

## Known limitations

- Statutory deductions ship off and the rates are samples. You must check and set them.
- Loss of pay and joiner proration ship off.
- No bank file or accounting posting here. Listen to `SalaryPaid` to post to finance.
- Bank transfer file wording appears in the UI. TODO: confirm what format the Salary Disbursement screen exports.
- TODO: confirm the income tax calculator limits (see notes in `StatutoryCalculator`).

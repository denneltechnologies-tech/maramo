<?php

/*
|--------------------------------------------------------------------------
| Classivo HR configuration
|--------------------------------------------------------------------------
|
| The payroll engine is country-neutral. Everything jurisdiction-specific
| (statutory rate defaults, statutory salary heads, the financial-year start)
| lives in the presets below. The active preset is classivo.preset
| (CLASSIVO_PRESET, default "india"). Add your own preset array to support
| another country; a preset that defines no statutory params deducts nothing.
|
| Every statutory kind ships DISABLED (`enabled` = 0). Check the rates against
| the current rules for your jurisdiction and switch them on yourself.
|
*/

return [

    // Without spatie/laravel-permission, define every HR ability as "user has a truthy
    // is_admin attribute". With spatie installed nothing is defined (deny by default).
    'define_gates' => env('HR_DEFINE_GATES', true),

    // Role names (guard from auth.defaults.guard) that HrPermissionSeeder grants every HR permission.
    'grant_to_roles' => [],

    // Locales whose payslip PDF uses the bundled Noto Sans Devanagari font (resources/fonts, SIL OFL 1.1).
    'pdf_devanagari_locales' => ['hi'],

    // Private disk for staff document uploads (served only through the authorised download route).
    'documents_disk' => env('HR_DOCUMENTS_DISK', 'local'),

    // Fallback financial-year start month when a preset does not set one.
    'fy_start_month' => 4,

    // Core (jurisdiction-free) payroll params seeded for every preset:
    // [kind, param_key, param_value, note]
    'core_params' => [
        ['payroll', 'lop_enabled', '0', 'Master switch. While 0, loss of pay is CALCULATED and recorded on the payroll but no deduction line is created.'],
        ['payroll', 'lop_basis', 'calendar_days', 'calendar_days | working_days — what a day of pay is divided out of.'],
        ['payroll', 'unmarked_days_paid', '1', 'A working day with no attendance mark is paid. Set 0 only if attendance is taken reliably every day.'],
        ['payroll', 'prorate_joiners', '0', 'Prorate the first month of somebody who joined mid-month. Ships 0 — which is what payroll has always done — so enabling loss of pay does not silently change two things at once.'],
    ],

    // Heads every payroll needs regardless of country.
    'core_components' => [
        // code, name, type, calculation, statutory_kind, taxable, prorates, emit, order, pf_wage, esi_wage
        ['BASIC', 'Basic salary', 'allowance', 'base_salary', null, true, true, false, 10, true, true],
        ['LOP', 'Loss of pay', 'deduction', 'system', null, false, false, true, 500, false, false],
        ['ADVANCE', 'Advance salary', 'deduction', 'system', null, false, false, true, 510, false, false],
    ],

    'presets' => [

        'india' => [
            'country_code' => 'IN',
            'fy_start_month' => 4, // April - March
            'statutory_params' => [
                ['pf', 'enabled', '0', 'Master switch. Nothing is deducted while this is 0.'],
                ['pf', 'employee_rate', '12', 'Employee share, % of PF wages.'],
                ['pf', 'employer_rate', '12', 'Total employer share, % of PF wages (EPS + EPF difference).'],
                ['pf', 'pension_rate', '8.33', 'The EPS slice of the employer share, % of PF wages.'],
                ['pf', 'wage_ceiling', '15000', 'Statutory monthly PF wage ceiling.'],
                ['pf', 'apply_ceiling', '1', 'Cap PF wages at the ceiling. Set 0 for an establishment that contributes on full wages.'],
                ['pf', 'pension_ceiling', '15000', 'EPS is capped at this even when PF itself is not.'],
                ['pf', 'edli_rate', '0', 'EDLI, % of PF wages. Employer cost — enter your establishment’s current rate.'],
                ['pf', 'admin_rate', '0', 'EPF administration charges, % of PF wages. Employer cost — enter your current rate.'],
                ['pf', 'require_member_no', '1', 'Only deduct for staff who have an EPF number on their record.'],
                ['pf', 'rounding', 'nearest', 'nearest | up | none.'],
                ['esi', 'enabled', '0', 'Master switch. Nothing is deducted while this is 0.'],
                ['esi', 'employee_rate', '0.75', 'Employee share, % of ESI wages.'],
                ['esi', 'employer_rate', '3.25', 'Employer share, % of ESI wages.'],
                ['esi', 'wage_threshold', '21000', 'Monthly gross at or below which ESI applies.'],
                ['esi', 'require_member_no', '1', 'Only deduct for staff who have an ESI number on their record.'],
                ['esi', 'rounding', 'up', 'ESI contributions are conventionally rounded up to the next rupee.'],
                ['professional_tax', 'enabled', '0', 'Master switch. Enter your state’s slabs before switching this on.'],
                ['income_tax', 'enabled', '0', 'Master switch. See the StatutoryCalculator notes before switching this on.'],
                ['income_tax', 'regime', 'new', 'Which slab variant to project against.'],
                ['income_tax', 'standard_deduction', '0', 'Annual standard deduction. Enter the figure for this financial year.'],
                ['income_tax', 'cess_rate', '4', 'Health & education cess, % of computed tax.'],
                ['income_tax', 'rebate_taxable_limit', '0', 'Taxable income at or below which the rebate applies. 0 disables the rebate.'],
                ['income_tax', 'rebate_max', '0', 'Maximum rebate amount.'],
            ],
            'statutory_components' => [
                ['PF_EMPLOYEE', 'Provident fund (employee)', 'deduction', 'statutory', 'pf_employee', false, false, true, 520, false, false],
                ['ESI_EMPLOYEE', 'ESI (employee)', 'deduction', 'statutory', 'esi_employee', false, false, true, 530, false, false],
                ['PT', 'Professional tax', 'deduction', 'statutory', 'professional_tax', false, false, true, 540, false, false],
                ['TDS', 'Income tax (TDS)', 'deduction', 'statutory', 'income_tax', false, false, false, 550, false, false],
                ['PF_EMPLOYER', 'Provident fund (employer)', 'employer_contribution', 'statutory', 'pf_employer', false, false, true, 700, false, false],
                ['PF_EPS', 'Pension scheme — EPS (employer)', 'employer_contribution', 'statutory', 'pf_eps', false, false, true, 710, false, false],
                ['PF_EDLI', 'EDLI (employer)', 'employer_contribution', 'statutory', 'pf_edli', false, false, true, 715, false, false],
                ['PF_ADMIN', 'EPF admin charges (employer)', 'employer_contribution', 'statutory', 'pf_admin', false, false, true, 718, false, false],
                ['ESI_EMPLOYER', 'ESI (employer)', 'employer_contribution', 'statutory', 'esi_employer', false, false, true, 720, false, false],
            ],
        ],

        // No statutory levies: loss-of-pay and advances still work.
        'generic' => [
            'country_code' => null,
            'fy_start_month' => 1, // calendar year
            'statutory_params' => [],
            'statutory_components' => [],
        ],

    ],

];

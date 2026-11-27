<?php

return [
    'staff' => [
        'genders' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'],
        'marital_statuses' => ['single' => 'Single', 'married' => 'Married', 'other' => 'Other'],
        'contract_types' => ['permanent' => 'Permanent', 'contract' => 'Contract', 'part_time' => 'Part-time'],
        'statuses' => ['active' => 'Active', 'inactive' => 'Inactive'],
        'academic_qualifications' => [
            'below_secondary' => 'Below Secondary', 'secondary' => 'Secondary', 'higher_secondary' => 'Higher Secondary',
            'graduate' => 'Graduate', 'post_graduate' => 'Post Graduate', 'mphil' => 'M.Phil', 'phd' => 'Ph.D',
        ],
        'professional_qualifications' => [
            'none' => 'None', 'diploma' => 'Diploma / Certificate in education', 'bed' => 'B.Ed', 'med' => 'M.Ed', 'other' => 'Other',
        ],
        'appointment_types' => ['regular' => 'Regular', 'contract' => 'Contract', 'para_teacher' => 'Para-teacher', 'other' => 'Other'],
    ],
    'payroll' => ['statuses' => ['generated' => 'Generated', 'paid' => 'Paid']],
    'advance_salary' => [
        'statuses' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'],
        'recovery_statuses' => ['pending' => 'Not started', 'recovering' => 'Recovering', 'recovered' => 'Fully recovered', 'written_off' => 'Written off'],
    ],
    'staff_attendance' => [
        'statuses' => ['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late', 'half_day' => 'Half Day', 'holiday' => 'Holiday', 'leave' => 'Leave'],
    ],
    'payroll_detail' => [
        'sources' => [
            'legacy' => 'Legacy structure', 'structure' => 'Salary structure', 'statutory' => 'Statutory',
            'lop' => 'Loss of pay', 'advance' => 'Advance recovery', 'manual' => 'Added by hand',
        ],
        'types' => ['allowance' => 'allowance', 'deduction' => 'deduction', 'employer_contribution' => 'employer cost'],
    ],
    'statutory_rule' => ['row_types' => ['param' => 'Rate / parameter', 'slab' => 'Slab']],
    'salary_component' => [
        'types' => ['allowance' => 'Allowance', 'deduction' => 'Deduction', 'employer_contribution' => 'Employer contribution'],
        'calculations' => [
            'base_salary' => 'Basic salary', 'fixed' => 'Fixed amount', 'percentage' => 'Percentage of base',
            'statutory' => 'Statutory (computed)', 'system' => 'System (computed)',
        ],
        'statutory_kinds' => [
            'pf_employee' => 'Provident fund — employee share', 'pf_employer' => 'Provident fund — employer share',
            'pf_eps' => 'Pension scheme (EPS) — employer', 'pf_edli' => 'EDLI — employer',
            'pf_admin' => 'EPF administration charges — employer', 'esi_employee' => 'ESI — employee share',
            'esi_employer' => 'ESI — employer share', 'professional_tax' => 'Professional tax', 'income_tax' => 'Income tax (TDS)',
        ],
    ],
    'staff_leave_request' => ['statuses' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']],
    'statutory_rule_set' => [
        'kinds' => [
            'pf' => 'Provident fund (EPF)', 'esi' => 'Employees’ State Insurance', 'professional_tax' => 'Professional tax',
            'income_tax' => 'Income tax (TDS)', 'payroll' => 'Loss of pay & payroll basis',
        ],
    ],
    'months' => [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ],
];

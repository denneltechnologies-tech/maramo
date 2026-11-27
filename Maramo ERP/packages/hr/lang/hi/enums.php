<?php

return [
    'staff' => [
        'genders' => ['male' => 'पुरुष', 'female' => 'महिला', 'other' => 'अन्य'],
        'marital_statuses' => ['single' => 'अविवाहित', 'married' => 'विवाहित', 'other' => 'अन्य'],
        'contract_types' => ['permanent' => 'स्थायी', 'contract' => 'अनुबंध', 'part_time' => 'अंशकालिक'],
        'statuses' => ['active' => 'सक्रिय', 'inactive' => 'निष्क्रिय'],
        'academic_qualifications' => [
            'below_secondary' => 'माध्यमिक से नीचे', 'secondary' => 'माध्यमिक', 'higher_secondary' => 'उच्च माध्यमिक',
            'graduate' => 'स्नातक', 'post_graduate' => 'स्नातकोत्तर', 'mphil' => 'एम.फिल', 'phd' => 'पीएच.डी',
        ],
        'professional_qualifications' => [
            'none' => 'कोई नहीं', 'diploma' => 'शिक्षा में डिप्लोमा / प्रमाणपत्र', 'bed' => 'बी.एड', 'med' => 'एम.एड', 'other' => 'अन्य',
        ],
        'appointment_types' => ['regular' => 'नियमित', 'contract' => 'अनुबंध', 'para_teacher' => 'पैरा-शिक्षक', 'other' => 'अन्य'],
    ],
    'payroll' => ['statuses' => ['generated' => 'तैयार', 'paid' => 'भुगतान किया']],
    'advance_salary' => [
        'statuses' => ['pending' => 'लंबित', 'approved' => 'स्वीकृत', 'rejected' => 'अस्वीकृत'],
        'recovery_statuses' => ['pending' => 'शुरू नहीं हुआ', 'recovering' => 'वसूली जारी', 'recovered' => 'पूरी वसूली', 'written_off' => 'बट्टे खाते में'],
    ],
    'staff_attendance' => [
        'statuses' => ['present' => 'उपस्थित', 'absent' => 'अनुपस्थित', 'late' => 'देर से', 'half_day' => 'आधा दिन', 'holiday' => 'अवकाश', 'leave' => 'छुट्टी'],
    ],
    'payroll_detail' => [
        'sources' => [
            'legacy' => 'पुरानी संरचना', 'structure' => 'वेतन संरचना', 'statutory' => 'वैधानिक',
            'lop' => 'वेतन कटौती (अनुपस्थिति)', 'advance' => 'अग्रिम वसूली', 'manual' => 'हाथ से जोड़ा गया',
        ],
        'types' => ['allowance' => 'भत्ता', 'deduction' => 'कटौती', 'employer_contribution' => 'नियोक्ता लागत'],
    ],
    'statutory_rule' => ['row_types' => ['param' => 'दर / पैरामीटर', 'slab' => 'स्लैब']],
    'salary_component' => [
        'types' => ['allowance' => 'भत्ता', 'deduction' => 'कटौती', 'employer_contribution' => 'नियोक्ता अंशदान'],
        'calculations' => [
            'base_salary' => 'मूल वेतन', 'fixed' => 'निश्चित राशि', 'percentage' => 'आधार का प्रतिशत',
            'statutory' => 'वैधानिक (गणना)', 'system' => 'सिस्टम (गणना)',
        ],
        'statutory_kinds' => [
            'pf_employee' => 'भविष्य निधि — कर्मचारी अंश', 'pf_employer' => 'भविष्य निधि — नियोक्ता अंश',
            'pf_eps' => 'पेंशन योजना (ईपीएस) — नियोक्ता', 'pf_edli' => 'ईडीएलआई — नियोक्ता',
            'pf_admin' => 'ईपीएफ प्रशासनिक शुल्क — नियोक्ता', 'esi_employee' => 'ईएसआई — कर्मचारी अंश',
            'esi_employer' => 'ईएसआई — नियोक्ता अंश', 'professional_tax' => 'व्यावसायिक कर', 'income_tax' => 'आयकर (टीडीएस)',
        ],
    ],
    'staff_leave_request' => ['statuses' => ['pending' => 'लंबित', 'approved' => 'स्वीकृत', 'rejected' => 'अस्वीकृत']],
    'statutory_rule_set' => [
        'kinds' => [
            'pf' => 'भविष्य निधि (ईपीएफ)', 'esi' => 'कर्मचारी राज्य बीमा', 'professional_tax' => 'व्यावसायिक कर',
            'income_tax' => 'आयकर (टीडीएस)', 'payroll' => 'वेतन कटौती और वेतन आधार',
        ],
    ],
    'months' => [
        1 => 'जनवरी', 2 => 'फ़रवरी', 3 => 'मार्च', 4 => 'अप्रैल', 5 => 'मई', 6 => 'जून',
        7 => 'जुलाई', 8 => 'अगस्त', 9 => 'सितंबर', 10 => 'अक्टूबर', 11 => 'नवंबर', 12 => 'दिसंबर',
    ],
];

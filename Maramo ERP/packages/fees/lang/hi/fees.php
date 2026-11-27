<?php

return [
    'navigation_group' => 'शुल्क संग्रह',
    'fee_type' => 'शुल्क प्रकार',
    'fee_group' => 'शुल्क समूह',
    'fee_master' => 'शुल्क मास्टर',
    'concession' => 'रियायत',
    'receipt' => 'रसीद',
    'refund' => 'धनवापसी',
    'credit_note' => 'क्रेडिट नोट',
    'offline_payment' => 'ऑफ़लाइन भुगतान',
    'amount' => 'राशि',
    'due_date' => 'देय तिथि',
    'paid' => 'भुगतान किया',
    'balance' => 'शेष',
    'fine' => 'विलंब शुल्क',
    'statuses' => [
        'unpaid' => 'अदत्त',
        'partial' => 'आंशिक भुगतान',
        'paid' => 'भुगतान हो गया',
    ],
    'modes' => [
        'cash' => 'नकद',
        'cheque' => 'चेक',
        'dd' => 'डिमांड ड्राफ्ट',
        'bank_transfer' => 'बैंक ट्रांसफ़र',
        'upi' => 'यूपीआई',
        'card' => 'कार्ड',
    ],
    'reminder' => [
        'subject' => 'शुल्क अनुस्मारक — :student',
        'body' => ':student का :amount शुल्क :days दिनों से बकाया है।',
    ],
];

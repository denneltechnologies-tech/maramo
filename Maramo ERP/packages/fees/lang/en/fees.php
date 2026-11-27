<?php

return [
    'navigation_group' => 'Fees & Payments',
    'fee_type' => 'Fee type',
    'fee_group' => 'Fee category',
    'fee_master' => 'Course Fees Setup',
    'concession' => 'Discounts & Concessions',
    'receipt' => 'Student Payments & Receipts',
    'refund' => 'Refund',
    'credit_note' => 'Credit note',
    'offline_payment' => 'Offline payment',
    'amount' => 'Amount',
    'due_date' => 'Due date',
    'paid' => 'Paid',
    'balance' => 'Balance',
    'fine' => 'Late fine',
    'statuses' => [
        'unpaid' => 'Unpaid',
        'partial' => 'Partly paid',
        'paid' => 'Paid',
    ],
    'modes' => [
        'cash' => 'Cash',
        'mobile_money' => 'Mobile Money (MoMo / Telecel / AT)',
        'bank_deposit' => 'Bank Deposit',
        'bank_transfer' => 'Bank Transfer',
        'card' => 'Card / POS',
        'cheque' => 'Cheque',
        'online' => 'Online (gateway)',
    ],
    'reminder' => [
        'subject' => 'Fee reminder — :student',
        'body' => 'Fee of :amount for :student is :days days overdue.',
    ],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    | Null = take it from the branch, then from the core regional preset
    | (config classivo.preset; the India preset gives INR / ₹).
    */
    'currency' => env('FEES_CURRENCY', 'GHS'),
    'currency_symbol' => env('FEES_CURRENCY_SYMBOL', 'GH₵'),

    // Words used by "amount in words" on receipts, per currency code.
    'amount_words' => [
        'GHS' => ['major' => 'cedis', 'minor' => 'pesewas'],
        'INR' => ['major' => 'rupees', 'minor' => 'paise'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Receipts
    |--------------------------------------------------------------------------
    | Receipt numbers are a gapless per-branch series: prefix + sequence.
    | A branch `invoice_prefix` / `invoice_start` column, when present, wins.
    */
    'receipts' => [
        'prefix' => env('FEES_RECEIPT_PREFIX', 'RCPT'),
        'start' => (int) env('FEES_RECEIPT_START', 1),
    ],

    // Day of the month monthly fees fall due (1-28), used for "due by" labels.
    'due_day' => (int) env('FEES_DUE_DAY', 10),

    /*
    |--------------------------------------------------------------------------
    | Concession approval
    |--------------------------------------------------------------------------
    | Off by default. When on, a concession above either ceiling lands
    | `pending` until someone with `fees.discount` approves it. 0 = that test off.
    */
    'concessions' => [
        'requires_approval' => (bool) env('FEES_CONCESSION_REQUIRES_APPROVAL', false),
        'threshold_amount' => (float) env('FEES_CONCESSION_THRESHOLD_AMOUNT', 0),
        'threshold_percent' => (float) env('FEES_CONCESSION_THRESHOLD_PERCENT', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Refunds
    |--------------------------------------------------------------------------
    | At or below approval_threshold a refund is auto-approved; above it one
    | approval is needed; above second_approval_threshold two DISTINCT approvers.
    */
    'refunds' => [
        'approval_threshold' => (float) env('FEES_REFUND_APPROVAL_THRESHOLD', 5000),
        'second_approval_threshold' => (float) env('FEES_REFUND_SECOND_APPROVAL_THRESHOLD', 25000),
        'credit_note_prefix' => env('FEES_CREDIT_NOTE_PREFIX', 'CN'),
        'credit_note_start' => (int) env('FEES_CREDIT_NOTE_START', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fee clearance (withholding documents from defaulters)
    |--------------------------------------------------------------------------
    | OFF by default — turning it on is a school's policy decision.
    */
    'clearance' => [
        'enabled' => (bool) env('FEES_CLEARANCE_ENABLED', false),
        'min_amount' => (float) env('FEES_CLEARANCE_MIN_AMOUNT', 0),
        'min_days' => (int) env('FEES_CLEARANCE_MIN_DAYS', 30),
        'match' => env('FEES_CLEARANCE_MATCH', 'any'), // any | all
        'services' => [
            'marksheet' => (bool) env('FEES_CLEARANCE_BLOCK_MARKSHEET', true),
            'admit_card' => (bool) env('FEES_CLEARANCE_BLOCK_ADMIT_CARD', true),
        ],
        'contact' => env('FEES_CLEARANCE_CONTACT', 'Please contact the school accounts office if you believe this is a mistake or a concession has already been approved.'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reminder ladder (e-mail only)
    |--------------------------------------------------------------------------
    | `php artisan fees:send-reminders` (schedule it daily) e-mails guardians at
    | 3 / 7 / 15 / 30 days overdue. Run with --dry-run after any change.
    */
    'reminders' => [
        'enabled' => (bool) env('FEES_REMINDERS_ENABLED', true),
        'cooldown_minutes' => (int) env('FEES_REMINDER_COOLDOWN_MINUTES', 180),
        'max_days_overdue' => (int) env('FEES_REMINDER_MAX_DAYS_OVERDUE', 120),
        'max_per_run' => (int) env('FEES_REMINDER_MAX_PER_RUN', 500),
        'escalate' => [
            'class_teacher_at' => (int) env('FEES_REMINDER_ESCALATE_TEACHER_AT', 15),
            'back_office_at' => (int) env('FEES_REMINDER_ESCALATE_OFFICE_AT', 30),
            'max_back_office' => (int) env('FEES_REMINDER_MAX_BACK_OFFICE', 3),
            // Office addresses that receive the escalation digest.
            'office_emails' => array_filter(explode(',', (string) env('FEES_REMINDER_OFFICE_EMAILS', ''))),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    | Every screen is deny-by-default (see Classivo\Fees\Support\FeesPermissions).
    | Without spatie/laravel-permission, abilities fall back to a truthy
    | `is_admin` attribute on the user when define_gates is true. With spatie
    | installed nothing is defined: grant permissions to roles explicitly.
    */
    'define_gates' => true,

    // Roles (by name) that FeesPermissionSeeder grants every fees permission.
    'grant_to_roles' => [],

    /*
    |--------------------------------------------------------------------------
    | Receipt routes
    |--------------------------------------------------------------------------
    */
    // Optional model for per-category pricing (shipped by the students package).
    'student_category_model' => 'Classivo\\Students\\Models\\StudentCategory',

    /*
    |--------------------------------------------------------------------------
    | Attachments (offline-payment proofs)
    |--------------------------------------------------------------------------
    | Stored on a private disk and served only through the authorised
    | `fees.offline-payment.proof` route. Only PDF, JPEG and PNG are accepted.
    */
    'attachments' => [
        'disk' => env('FEES_ATTACHMENT_DISK', 'local'),
        'max_kb' => (int) env('FEES_ATTACHMENT_MAX_KB', 5120),
        'mime_types' => ['application/pdf', 'image/jpeg', 'image/png'],
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'admin/fees',
        'middleware' => ['web', 'auth'],
    ],

];

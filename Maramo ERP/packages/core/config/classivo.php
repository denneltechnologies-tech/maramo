<?php

return [

    /*
    |--------------------------------------------------------------------------
    | School id (single-school installs)
    |--------------------------------------------------------------------------
    |
    | The default tenancy resolver, Classivo\Core\Tenancy\SingleSchoolResolver,
    | runs the whole install as ONE school whose id is read from here. A
    | multi-school product can bind its own TenantResolver implementation in a
    | service provider; nothing else in the packages needs to change.
    |
    */

    'school_id' => (int) env('CLASSIVO_SCHOOL_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    |
    | Teachers, "marked by" and similar references point at this model.
    |
    */

    'user_model' => env('CLASSIVO_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Regional preset
    |--------------------------------------------------------------------------
    |
    | The core is country-neutral. A preset only supplies DEFAULTS (board,
    | currency, academic-year start month, languages, timezone) used when new
    | branches and sessions are created. The India preset is on by default; set
    | CLASSIVO_PRESET=generic (or add your own preset) to switch.
    |
    */

    'preset' => env('CLASSIVO_PRESET', 'ghana'),

    'presets' => [

        'ghana' => [
            'country' => 'GH',
            'board' => 'CTVET / Technical & Vocational',
            'currency' => 'GHS',
            'currency_symbol' => 'GH₵',
            'timezone' => 'Africa/Accra',
            'date_format' => 'd/m/Y',
            'session_start_month' => 1, // January – December
            'locales' => ['en'],
            'weekend_days' => [0], // Sunday
        ],

        'india' => [
            'country' => 'IN',
            'board' => 'CBSE',
            'currency' => 'INR',
            'currency_symbol' => '₹',
            'timezone' => 'Asia/Kolkata',
            'date_format' => 'd-m-Y',
            'session_start_month' => 4, // April – March
            'locales' => ['en', 'hi'],
            'weekend_days' => [0], // Sunday
        ],

        'generic' => [
            'country' => null,
            'board' => null,
            'currency' => 'USD',
            'currency_symbol' => '$',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
            'session_start_month' => 9, // September – August
            'locales' => ['en'],
            'weekend_days' => [0, 6],
        ],

    ],

    /*
    | Roles (by name) that CorePermissionSeeder grants every core permission
    | (core.branches.*, core.academic_sessions.*, core.classes.*, ...).
    */
    'permissions' => [
        'grant_to_roles' => [],
    ],

];

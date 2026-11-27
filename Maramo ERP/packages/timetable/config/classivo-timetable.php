<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    |
    | Deny by default: unless the host app defines a Gate for the ability, it
    | is granted only to users holding the spatie/laravel-permission
    | permission of the same name (create it and assign it to a role).
    |
    |  view     — open the Class Timetable page
    |  edit     — save / clear a class timetable, edit weekly requirements & rooms
    |  generate — open the Generate Timetable page and save its result
    |  substitute — mark teachers absent and assign substitutes
    |
    */

    'abilities' => [
        'view' => 'timetable.view',
        'edit' => 'timetable.edit',
        'generate' => 'timetable.generate',
        'substitute' => 'timetable.substitute',
    ],

    /*
    |--------------------------------------------------------------------------
    | Teacher picker
    |--------------------------------------------------------------------------
    |
    | Users listed as teachers on the Class Timetable page. When the user model
    | has a `roles` relation (e.g. spatie/laravel-permission), only users with
    | one of these role names are listed; otherwise every user is listed.
    |
    */

    'teacher_roles' => ['Teacher', 'Class Teacher'],

    /*
    |--------------------------------------------------------------------------
    | Generator defaults (Classivo\Timetable\Actions\GenerateTimetables)
    |--------------------------------------------------------------------------
    */

    'generator' => [

        // Weekdays to schedule, 0 = Sunday .. 6 = Saturday. null = every day
        // except the active core preset's weekend_days (India: Mon–Sat).
        'days' => null,

        // The daily bell schedule. Rows with 'break' => true are saved as break
        // rows and never get a lesson. period_no = position in this list.
        'periods' => [
            ['start' => '08:00', 'end' => '08:40'],
            ['start' => '08:40', 'end' => '09:20'],
            ['start' => '09:20', 'end' => '10:00'],
            ['start' => '10:00', 'end' => '10:40'],
            ['start' => '10:40', 'end' => '11:00', 'break' => true],
            ['start' => '11:00', 'end' => '11:40'],
            ['start' => '11:40', 'end' => '12:20'],
            ['start' => '12:20', 'end' => '13:00'],
            ['start' => '13:00', 'end' => '13:40'],
        ],

        // Weekly periods for a subject when the caller gives no count for it.
        'default_weekly_periods' => 5,

        // HARD: most back-to-back periods a teacher may teach (null = no cap).
        'max_consecutive' => 4,

        // HARD: most periods of one subject per class per day (null = no cap;
        // "not twice a day" is still preferred as a soft goal).
        'max_same_subject_per_day' => null,

        'seed' => 1,

        'time_limit_seconds' => 10,
    ],

];

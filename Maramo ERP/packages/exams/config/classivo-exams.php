<?php

return [

    /*
    | Permission abilities checked by the marks register and the publish controls.
    | Deny by default: unless the host app defines a Gate for the ability, it is
    | granted only to users holding the spatie/laravel-permission permission of the
    | same name (create it and assign it to a role).
    */
    'abilities' => [
        'enter_marks' => 'exam.enter_marks',
        'publish_result' => 'exam.publish_result',
    ],

    /*
    | Default full / pass marks pre-filled in the schedule builder.
    */
    'default_full_marks' => 100,
    'default_pass_marks' => 35,

    /*
    | Marks entry scope. Teachers may enter marks only for the class-sections they
    | teach (class teacher, class_subjects subject teacher, or timetable teacher).
    | Users holding `exams.marks.any_class`, or one of these roles, may enter marks
    | for any class-section.
    */
    'marks_scope' => [
        'bypass_roles' => ['Admin', 'Super Admin'],
    ],

    /* Roles (by name) that ExamsPermissionSeeder grants every exam permission. */
    'grant_to_roles' => [],
];

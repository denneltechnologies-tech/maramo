<?php

return [

    /* Models the package points at. Defaults match the Classivo students and HR packages. */
    'student_model' => 'Classivo\\Students\\Models\\Student',
    'staff_model' => 'Classivo\\Hr\\Models\\Staff',

    /* Filesystem disk for uploaded files, and the private disk for sensitive uploads. */
    'disk' => env('CLASSIVO_CMS_DISK', 'public'),
    'private_disk' => env('CLASSIVO_CMS_PRIVATE_DISK', 'local'),

    /*
    | Without spatie/laravel-permission, abilities fall back to `is_admin` on the user.
    | With it installed, nothing is defined (deny by default).
    */
    /*
    | Public website routes. Set enabled to false to register your own. catch_all adds
    | GET /{slug} for CMS pages (registered last); turn it off if your app owns single-segment URLs.
    */
    'routes' => [
        'enabled' => true,
        'middleware' => ['web'],
        'prefix' => '',
        'catch_all' => true,
        // Single-segment URLs the catch-all must never claim (panel, framework, storage).
        'reserved_slugs' => [
            'admin', 'livewire', 'storage', 'api', 'login', 'logout', 'up', 'filament',
            // Demo app: portals and package pages that own single-segment URLs.
            'student', 'parent', 'admission', 'contact-us', 'invitation', 'lms', 'payments', 'build',
        ],
    ],

    'define_gates' => true,

    /* Roles (by name) that CmsPermissionSeeder grants every cms permission. */
    'grant_to_roles' => [],
];

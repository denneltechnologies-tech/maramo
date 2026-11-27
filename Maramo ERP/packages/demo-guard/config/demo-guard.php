<?php

use Classivo\Communication\Models\NotificationSetting;
use Classivo\Core\Models\School;
use Classivo\Payments\Models\SchoolPaymentCredential;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return [
    /*
    | null = enabled only when APP_ENV=demo. Set true/false to force.
    */
    'enabled' => env('DEMO_GUARD_ENABLED'),

    /* Let artisan / seeders / tinker through, so the nightly reset can reseed. */
    'allow_console' => true,

    'banner' => [
        'enabled' => true,
        'reset_time' => '03:00 IST',
    ],

    'uploads' => [
        'max_kb' => 2048,
        'max_files' => 3,
    ],

    /*
    | Models guarded per Eloquent event. Classes that are not installed are skipped.
    | 'all' = every event below; or list a subset of: creating, updating, deleting.
    | 'dirty' (User only) = block updates only when these attributes change.
    */
    'users' => [
        'model' => null, // null = auth.providers.users.model
        // Sign-in details of the shared demo accounts. The two-factor columns stop a
        // visitor from switching on an authenticator app and locking everyone else out.
        'protected_attributes' => [
            'password', 'email',
            'app_authentication_secret', 'app_authentication_recovery_codes', 'force_password_change',
        ],
        'block_delete' => true,
    ],

    'role_models' => [
        Role::class,
        Permission::class,
    ],

    'locked_models' => [
        // payment credentials: no create / update / delete
        SchoolPaymentCredential::class,
        // mail / SMS / WhatsApp switches
        NotificationSetting::class,
        // core school settings
        School::class,
    ],

    /*
    | Read-only demo roles. A signed-in user holding one of these roles cannot create,
    | update or delete any record, even where a permission was granted by mistake.
    | allow_models: records written as a side effect of browsing (login log, activity log,
    | notifications read-state, session list). allow_user_attributes: sign-in bookkeeping.
    */
    'read_only' => [
        'roles' => ['Viewer'],
        'allow_models' => [
            'Classivo\\Platform\\Models\\LoginLog',
            'Classivo\\Security\\Models\\UserSession',
            'Spatie\\Activitylog\\Models\\Activity',
            'Illuminate\\Notifications\\DatabaseNotification',
        ],
        'allow_user_attributes' => ['remember_token', 'updated_at', 'last_login_at', 'last_login_ip'],
    ],

    /*
    | Sign-in details shown above the login form of each Filament panel (by panel id).
    | Only shown while the demo guard is enabled. Use the seeded demo accounts only.
    */
    'logins' => [
        'admin' => [
            ['label' => 'Read-only demo', 'email' => 'viewer@example.com', 'password' => 'password'],
        ],
        'student' => [
            ['label' => 'Student demo', 'email' => 'student@example.com', 'password' => 'password'],
        ],
        'parent' => [
            ['label' => 'Parent demo', 'email' => 'parent@example.com', 'password' => 'password'],
        ],
    ],
];

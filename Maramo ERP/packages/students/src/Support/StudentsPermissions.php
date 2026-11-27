<?php

namespace Classivo\Students\Support;

use Classivo\Core\Support\Permissions;

/**
 * Permission names for the student screens. Plain Laravel ability strings, so they
 * work with spatie/laravel-permission (see StudentsPermissionSeeder) or your own
 * Gate. Deny by default: a user without the permission cannot see or use the screen.
 */
final class StudentsPermissions
{
    public const RESOURCES = [
        'students' => 'students.students',
        'guardians' => 'students.guardians',
        'categories' => 'students.categories',
    ];

    /** Promote / detain / transfer students into the next session. */
    public const PROMOTE = 'students.promotion.run';

    /** Mark daily and subject-wise attendance. */
    public const ATTENDANCE_TAKE = 'students.attendance.take';

    /** @return list<string> */
    public static function all(): array
    {
        return Permissions::build(self::RESOURCES, [self::PROMOTE, self::ATTENDANCE_TAKE]);
    }

    public static function can(string $permission): bool
    {
        return (bool) auth()->user()?->can($permission);
    }
}

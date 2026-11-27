<?php

namespace Classivo\Students\Database\Seeders;

use Classivo\Core\Support\Permissions;
use Classivo\Students\Support\StudentsPermissions;
use Illuminate\Database\Seeder;

/**
 * Creates the student permissions with spatie/laravel-permission and grants them
 * to the roles listed in `classivo-students.grant_to_roles` (roles must exist).
 */
class StudentsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        Permissions::seed(StudentsPermissions::all(), (array) config('classivo-students.grant_to_roles', []));
    }
}

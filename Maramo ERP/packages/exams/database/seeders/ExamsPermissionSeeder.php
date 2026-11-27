<?php

namespace Classivo\Exams\Database\Seeders;

use Classivo\Core\Support\Permissions;
use Classivo\Exams\Support\ExamsPermissions;
use Illuminate\Database\Seeder;

/**
 * Creates the exam permissions with spatie/laravel-permission and grants them to
 * the roles listed in `classivo-exams.grant_to_roles` (roles must exist).
 */
class ExamsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        Permissions::seed(ExamsPermissions::all(), (array) config('classivo-exams.grant_to_roles', []));
    }
}

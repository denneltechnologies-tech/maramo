<?php

namespace Classivo\Core\Database\Seeders;

use Classivo\Core\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * Creates the core permissions with spatie/laravel-permission (no-op without it) and
 * grants them to the roles listed in `classivo.permissions.grant_to_roles`.
 */
class CorePermissionSeeder extends Seeder
{
    public function run(): void
    {
        Permissions::seed(Permissions::all(), (array) config('classivo.permissions.grant_to_roles', []));
    }
}

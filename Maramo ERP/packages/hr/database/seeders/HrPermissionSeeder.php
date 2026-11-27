<?php

namespace Classivo\Hr\Database\Seeders;

use Classivo\Hr\Support\HrPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the HR permissions with spatie/laravel-permission and grants them to the
 * roles listed in `hr.grant_to_roles` (roles must exist).
 */
class HrPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissionModel = config('permission.models.permission');
        $roleModel = config('permission.models.role');

        if (! $permissionModel || ! class_exists($permissionModel)) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');

        foreach (HrPermissions::all() as $name) {
            $permissionModel::findOrCreate($name, $guard);
        }

        foreach ((array) config('hr.grant_to_roles', []) as $roleName) {
            $role = $roleModel::query()->where('name', $roleName)->where('guard_name', $guard)->first();
            $role?->givePermissionTo(HrPermissions::all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

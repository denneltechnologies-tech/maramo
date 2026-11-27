<?php

namespace Classivo\Fees\Database\Seeders;

use Classivo\Fees\Support\FeesPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the fees permissions with spatie/laravel-permission and grants them
 * to the roles listed in `fees.grant_to_roles` (the roles must already exist).
 * Nothing is granted to anybody else: the screens are deny-by-default.
 */
class FeesPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissionModel = config('permission.models.permission');
        $roleModel = config('permission.models.role');

        if (! $permissionModel || ! class_exists($permissionModel)) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');

        foreach (FeesPermissions::all() as $name) {
            $permissionModel::findOrCreate($name, $guard);
        }

        foreach ((array) config('fees.grant_to_roles', []) as $roleName) {
            $role = $roleModel::query()->where('name', $roleName)->where('guard_name', $guard)->first();
            $role?->givePermissionTo(FeesPermissions::all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

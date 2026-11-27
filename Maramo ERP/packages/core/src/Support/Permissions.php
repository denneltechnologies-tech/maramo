<?php

namespace Classivo\Core\Support;

use Spatie\Permission\PermissionRegistrar;

/**
 * Permission names and checks for the Classivo admin screens.
 *
 * Names follow `<package>.<resource>.<ability>` (e.g. `core.classes.update`) with
 * abilities view, create, update, delete. They are plain Laravel abilities, so they
 * work with spatie/laravel-permission or with Gates the host app defines. Deny by
 * default: a guest, or a user without the ability, is refused.
 *
 * all() lists core's own permissions; build() and seed() are shared by the other
 * packages' permission classes and seeders so every module names and seeds them alike.
 */
final class Permissions
{
    public const ABILITIES = ['view', 'create', 'update', 'delete'];

    /** Core resource key => permission prefix. */
    public const RESOURCES = [
        'branches' => 'core.branches',
        'academic_sessions' => 'core.academic_sessions',
        'classes' => 'core.classes',
        'sections' => 'core.sections',
        'subjects' => 'core.subjects',
    ];

    /** @return list<string> every core permission */
    public static function all(): array
    {
        return self::build(self::RESOURCES);
    }

    public static function allows(string $permission): bool
    {
        return auth()->user()?->can($permission) === true;
    }

    /** Ability for a Filament authorization action (viewAny, create, update, ...), or null when unknown. */
    public static function abilityFor(string $action): ?string
    {
        return match ($action) {
            'viewAny', 'view' => 'view',
            'create', 'replicate' => 'create',
            'update', 'reorder' => 'update',
            'delete', 'deleteAny', 'restore', 'restoreAny', 'forceDelete', 'forceDeleteAny' => 'delete',
            default => null,
        };
    }

    /**
     * `<prefix>.view|create|update|delete` for each resource, plus extra page/action permissions.
     *
     * @param  array<array-key, string>  $resources  permission prefixes
     * @param  list<string>  $extra
     * @return list<string>
     */
    public static function build(array $resources, array $extra = []): array
    {
        $names = [];

        foreach ($resources as $prefix) {
            foreach (self::ABILITIES as $ability) {
                $names[] = $prefix.'.'.$ability;
            }
        }

        return array_values(array_unique(array_merge($names, $extra)));
    }

    /**
     * Creates the permissions with spatie/laravel-permission (no-op when it is not
     * installed) and grants them to each named role that already exists.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $roles
     */
    public static function seed(array $permissions, array $roles = []): void
    {
        $permissionModel = config('permission.models.permission');
        $roleModel = config('permission.models.role');

        if (! $permissionModel || ! class_exists($permissionModel)) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');

        foreach ($permissions as $name) {
            $permissionModel::findOrCreate($name, $guard);
        }

        foreach ($roles as $roleName) {
            $roleModel::query()->where('name', $roleName)->where('guard_name', $guard)->first()?->givePermissionTo($permissions);
        }

        if (class_exists(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}

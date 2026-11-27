<?php

namespace Classivo\Hr\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Resolves the host application's user and (optional) role models so the HR
 * package never hard-codes an App\ namespace. Roles are optional: when
 * spatie/laravel-permission is not installed, role syncing is skipped.
 */
class HrAuth
{
    /** @return class-string<Model> */
    public static function userModel(): string
    {
        return (string) config('classivo.user_model', 'App\\Models\\User');
    }

    /** @return class-string<Model>|null */
    public static function roleModel(): ?string
    {
        $class = config('permission.models.role', Role::class);

        return is_string($class) && class_exists($class) ? $class : null;
    }
}

<?php

namespace Classivo\Hr\Support;

/**
 * Who may put which role on a staff record (and so on its linked login).
 *
 * A role may be granted only by an actor who holds `hr.staff.assign_roles` AND
 * already holds that role themselves, so no one can hand out (or give themselves)
 * Admin / Super Admin unless they are one. Code running with no authenticated
 * user (seeders, console) is trusted: a web guest can never reach these paths.
 */
class RoleGuard
{
    public static function actor(): ?object
    {
        return auth()->user();
    }

    public static function canAssignRoles(): bool
    {
        return static::actor() === null || HrPermissions::can(HrPermissions::STAFF_ASSIGN_ROLES);
    }

    /** @return list<int> */
    public static function grantableRoleIds(): array
    {
        $roleClass = HrAuth::roleModel();

        if ($roleClass === null) {
            return [];
        }

        if (static::actor() === null) {
            return $roleClass::query()->pluck('id')->map(fn ($i): int => (int) $i)->all();
        }

        if (! static::canAssignRoles() || ! method_exists(static::actor(), 'roles')) {
            return [];
        }

        return static::actor()->roles()->pluck('id')->map(fn ($i): int => (int) $i)->all();
    }

    public static function canGrant(?int $roleId): bool
    {
        if ($roleId === null) {
            return static::canAssignRoles();
        }

        return static::canAssignRoles() && in_array($roleId, static::grantableRoleIds(), true);
    }
}

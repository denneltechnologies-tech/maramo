<?php

namespace Classivo\Hr\Observers;

use Classivo\Hr\Models\Staff;
use Classivo\Hr\Support\HrAuth;
use Classivo\Hr\Support\RoleGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * StaffObserver — keeps a staff member and its linked login coherent (spec §15 /
 * §2). The Staff record is the single source of truth for a linked user's
 * identity and system role; this observer enforces that at runtime so the Users,
 * Roles and Staff screens can never drift apart:
 *
 *   • saved        → push the staff's name/email/phone onto the linked user and
 *                    reconcile the RBAC role when the staff's role_id changes
 *                    (additively — a manually granted role such as Super Admin is
 *                    never stripped).
 *   • deleted      → a soft-deleted staff's login is deactivated so it can no
 *                    longer authenticate (no live orphan account).
 *   • restored     → reactivate that login again.
 *   • forceDeleted → remove the orphaned login entirely (Super Admins are only
 *                    deactivated, never deleted).
 *
 * All writes to the linked user are quiet (no events) so there is no recursion,
 * and — because DatabaseSeeder mutes model events — seeders are unaffected (they
 * set the staff↔user↔role wiring explicitly).
 */
class StaffObserver
{
    public function saved(Staff $staff): void
    {
        $user = $this->linkedUser($staff);

        if (! $user) {
            return;
        }

        $this->syncIdentity($staff, $user);
        $this->syncRole($staff, $user);
    }

    /** Soft delete: block the now-orphaned login without destroying it. */
    public function deleted(Staff $staff): void
    {
        // A force delete fires `deleted` too; the login is removed in forceDeleted().
        if ($staff->isForceDeleting()) {
            return;
        }

        $user = $this->linkedUser($staff);

        if ($user && $user->is_active) {
            $user->forceFill(['is_active' => false])->saveQuietly();
            // A soft-deleted staff's login must not keep an API token alive.
            if (method_exists($user, 'revokeApiTokens')) {
                $user->revokeApiTokens();
            }
        }
    }

    /** Undo the soft-delete deactivation. */
    public function restored(Staff $staff): void
    {
        $user = $this->linkedUser($staff);

        if ($user && ! $user->is_active) {
            $user->forceFill(['is_active' => true])->saveQuietly();
        }
    }

    /** Hard delete: leave no orphaned login behind. */
    public function forceDeleted(Staff $staff): void
    {
        $user = $this->linkedUser($staff);

        if (! $user) {
            return;
        }

        // Never nuke a Super Admin from an HR action — just disable it.
        if ((method_exists($user, 'hasRole') && $user->hasRole('Super Admin'))) {
            $user->forceFill(['is_active' => false])->saveQuietly();
            if (method_exists($user, 'revokeApiTokens')) {
                $user->revokeApiTokens();
            }

            return;
        }

        if (method_exists($user, 'revokeApiTokens')) {
            $user->revokeApiTokens();
        }
        $user->delete();
    }

    /**
     * The linked login resolved by a FRESH query on user_id — never the cached
     * `user` relation. During a create-with-login the `saved` event fires while
     * user_id is still null (provisionLogin links it afterwards), which would
     * otherwise cache a stale null and defeat the later delete/force-delete hooks.
     */
    private function linkedUser(Staff $staff): ?Model
    {
        return $staff->user_id ? HrAuth::userModel()::find($staff->user_id) : null;
    }

    /** Push the staff's identity onto its login (staff is authoritative). */
    private function syncIdentity(Staff $staff, Model $user): void
    {
        $changes = [];

        if ($user->name !== $staff->full_name) {
            $changes['name'] = $staff->full_name;
        }

        if ($user->phone !== $staff->phone) {
            $changes['phone'] = $staff->phone;
        }

        // The login email is the account's credential and is deliberately NOT
        // rewritten from the staff record (it would let a staff editor redirect
        // password resets to an address they control).

        if ($changes !== []) {
            $user->forceFill($changes)->saveQuietly();
        }
    }

    /** Reconcile the RBAC role when the staff's role_id changes. */
    private function syncRole(Staff $staff, Model $user): void
    {
        $roleClass = HrAuth::roleModel();

        if ($roleClass === null || ! method_exists($user, 'hasRole')) {
            return;
        }

        if (! $staff->wasChanged('role_id')) {
            return;
        }

        // Drop the previous staff role (unless it is Super Admin, which stays).
        $previousId = $staff->getOriginal('role_id');

        if ($previousId && ($previous = $roleClass::find($previousId))
            && $previous->name !== 'Super Admin'
            && $user->hasRole($previous->name)) {
            $user->removeRole($previous->name);
        }

        // Ensure the new staff role is present (additive) — only if the actor may grant it.
        if ($staff->role_id && RoleGuard::canGrant((int) $staff->role_id) && ($role = $roleClass::find($staff->role_id)) && ! $user->hasRole($role->name)) {
            $user->assignRole($role->name);
        }
    }
}

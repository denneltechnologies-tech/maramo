<?php

namespace Classivo\Core\Filament\Concerns;

use Classivo\Core\Support\Permissions;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Deny-by-default authorization for a Filament resource. The resource declares
 * `protected static string $permissionPrefix` (e.g. `core.classes`). Every Filament
 * authorization question (canViewAny, canCreate, canEdit, canDelete, page mount by
 * direct URL, built-in table and header actions) goes through
 * getAuthorizationResponse(), which maps the action onto `<prefix>.<ability>`.
 * Unknown actions are denied.
 */
trait AuthorizesWithPermissions
{
    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        $ability = Permissions::abilityFor($action);

        return $ability !== null && Permissions::allows(static::$permissionPrefix.'.'.$ability)
            ? Response::allow()
            : Response::deny();
    }

    /** For inline-editable columns (toggles): true when the user may not update records. */
    public static function cannotUpdate(): bool
    {
        return ! Permissions::allows(static::$permissionPrefix.'.update');
    }
}

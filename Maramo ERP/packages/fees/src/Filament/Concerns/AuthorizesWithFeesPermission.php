<?php

namespace Classivo\Fees\Filament\Concerns;

use Classivo\Fees\Support\FeesPermissions;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Resource authorization from two fees permissions: one to see the resource
 * (`viewAny`, `view`) and one for every other action. Deny by default.
 *
 * The using resource implements viewPermission() and managePermission().
 */
trait AuthorizesWithFeesPermission
{
    abstract public static function viewPermission(): string;

    abstract public static function managePermission(): string;

    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        $permission = in_array($action, ['viewAny', 'view'], true)
            ? static::viewPermission()
            : static::managePermission();

        return FeesPermissions::can($permission) ? Response::allow() : Response::deny();
    }
}

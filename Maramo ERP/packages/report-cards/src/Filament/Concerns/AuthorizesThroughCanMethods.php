<?php

namespace Classivo\ReportCards\Filament\Concerns;

use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * For resources that declare their own can*() methods. Filament's create/edit/view
 * pages and its record and bulk actions authorise through getAuthorizationResponse(),
 * not through can*(), so without this a user could open /create or /{record}/edit by
 * URL with only the view permission. Each action maps to the resource's own can*()
 * method; an action whose method the resource does not declare is denied.
 */
trait AuthorizesThroughCanMethods
{
    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        $allowed = match ($action) {
            'viewAny' => static::ownCan('canViewAny'),
            'view' => $record && static::declares('canView') ? static::canView($record) : static::ownCan('canViewAny'),
            'create', 'replicate' => static::ownCan('canCreate'),
            'update' => $record !== null && static::ownCan('canEdit', $record),
            'delete' => $record ? static::ownCan('canDelete', $record) : static::ownCan('canDeleteAny'),
            'deleteAny' => static::ownCan('canDeleteAny'),
            'forceDelete' => $record ? static::ownCan('canForceDelete', $record) : static::ownCan('canForceDeleteAny'),
            'forceDeleteAny' => static::ownCan('canForceDeleteAny'),
            'restore' => $record ? static::ownCan('canRestore', $record) : static::ownCan('canRestoreAny'),
            'restoreAny' => static::ownCan('canRestoreAny'),
            'reorder' => static::ownCan('canReorder'),
            default => false,
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    /** Calls the resource's own can*() method; false when only Filament's default exists (avoids recursion). */
    private static function ownCan(string $method, ?Model $record = null): bool
    {
        if (! static::declares($method)) {
            return false;
        }

        return (bool) ($record ? static::$method($record) : static::$method());
    }

    private static function declares(string $method): bool
    {
        return (new \ReflectionMethod(static::class, $method))->getDeclaringClass()->getName() !== Resource::class;
    }
}

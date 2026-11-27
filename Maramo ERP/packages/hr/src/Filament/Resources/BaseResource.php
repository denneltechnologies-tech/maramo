<?php

namespace Classivo\Hr\Filament\Resources;

use Classivo\Hr\Support\HrPermissions;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the HR resources: every ability maps to a `<prefix>.view|create|update|delete`
 * permission and is denied by default. Subclasses set `$permissionPrefix`
 * (e.g. `hr.staff`). Read-only resources override canCreate()/canEdit().
 */
abstract class BaseResource extends Resource
{
    protected static string $permissionPrefix;

    protected static function allowed(string $ability): bool
    {
        return HrPermissions::can(static::$permissionPrefix.'.'.$ability);
    }

    /**
     * Filament's create and edit pages and its record actions authorise through this
     * method, not the can*() helpers, so every action is mapped here too. Without it a
     * user could open /create or /{record}/edit by URL with only the view permission.
     */
    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        $allowed = match ($action) {
            'viewAny' => static::canViewAny(),
            'view' => $record ? static::canView($record) : static::canViewAny(),
            'create' => static::canCreate(),
            'update' => $record ? static::canEdit($record) : static::allowed('update'),
            'delete' => $record ? static::canDelete($record) : static::canDeleteAny(),
            'deleteAny' => static::canDeleteAny(),
            'forceDelete' => $record ? static::canForceDelete($record) : static::canForceDeleteAny(),
            'forceDeleteAny' => static::canForceDeleteAny(),
            'restore' => $record ? static::canRestore($record) : static::canRestoreAny(),
            'restoreAny' => static::canRestoreAny(),
            'replicate' => static::allowed('create'),
            'reorder' => static::allowed('update'),
            default => static::allowed('delete'),
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function canViewAny(): bool
    {
        return static::allowed('view');
    }

    public static function canView(Model $record): bool
    {
        return static::allowed('view');
    }

    public static function canCreate(): bool
    {
        return static::allowed('create');
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowed('update');
    }

    public static function canDelete(Model $record): bool
    {
        return static::allowed('delete');
    }

    public static function canDeleteAny(): bool
    {
        return static::allowed('delete');
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::allowed('delete');
    }

    public static function canForceDeleteAny(): bool
    {
        return static::allowed('delete');
    }

    public static function canRestore(Model $record): bool
    {
        return static::allowed('delete');
    }

    public static function canRestoreAny(): bool
    {
        return static::allowed('delete');
    }
}

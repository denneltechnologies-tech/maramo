<?php

namespace Classivo\ReportCards\Filament\Concerns;

use Classivo\ReportCards\Support\Permissions;
use Illuminate\Database\Eloquent\Model;

/** Deny-by-default Filament resource authorisation keyed on `<permissionKey>.<action>`. */
trait ChecksPermissions
{
    abstract protected static function permissionKey(): string;

    public static function canViewAny(): bool
    {
        return Permissions::allows(static::permissionKey().'.view');
    }

    public static function canView(Model $record): bool
    {
        return Permissions::allows(static::permissionKey().'.view');
    }

    public static function canCreate(): bool
    {
        return Permissions::allows(static::permissionKey().'.create');
    }

    public static function canEdit(Model $record): bool
    {
        return Permissions::allows(static::permissionKey().'.update');
    }

    public static function canDelete(Model $record): bool
    {
        return Permissions::allows(static::permissionKey().'.delete');
    }

    public static function canDeleteAny(): bool
    {
        return Permissions::allows(static::permissionKey().'.delete');
    }
}

<?php

namespace Classivo\Hr\Filament\Concerns;

/**
 * Translated navigation group/label and model labels for HR resources. The using
 * class sets `$navKey` (a key under `nav`, `models` and `plurals` in lang/*\/hr.php).
 */
trait HrNavigation
{
    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('hr::hr.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('hr::hr.nav.'.static::$navKey);
    }

    public static function getModelLabel(): string
    {
        return __('hr::hr.models.'.static::$navKey);
    }

    public static function getPluralModelLabel(): string
    {
        return __('hr::hr.plurals.'.static::$navKey);
    }
}

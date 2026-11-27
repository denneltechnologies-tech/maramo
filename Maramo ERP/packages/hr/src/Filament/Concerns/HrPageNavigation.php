<?php

namespace Classivo\Hr\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;

/** Translated navigation and title for HR pages (`$navKey` under `nav` and `titles`). */
trait HrPageNavigation
{
    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('hr::hr.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('hr::hr.nav.'.static::$navKey);
    }

    public function getTitle(): string|Htmlable
    {
        return __('hr::hr.titles.'.static::$navKey);
    }
}

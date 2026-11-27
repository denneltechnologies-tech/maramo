<?php

namespace Classivo\Fees\Filament\Portal\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Guardian portal: fees for each linked child, with a child switcher. */
class ChildFees extends PortalFeesPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getNavigationLabel(): string
    {
        return __('Children\'s Fees');
    }

    public function getTitle(): string
    {
        return __('Children\'s Fees');
    }
}

<?php

namespace Classivo\Fees\Filament\Portal\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Student portal: my fees. */
class MyFees extends PortalFeesPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getNavigationLabel(): string
    {
        return __('My Fees');
    }

    public function getTitle(): string
    {
        return __('My Fees');
    }
}

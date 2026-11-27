<?php

namespace Classivo\Fees\Filament\Pages;

use Classivo\Fees\Support\FeesPermissions;
use Filament\Pages\Page;

/**
 * Base for the fees screens: navigation group, translated title and label, and
 * a single permission that gates both the menu entry and direct URL access.
 */
abstract class FeesPage extends Page
{
    /** The fees permission needed to open this page. */
    abstract public static function permission(): string;

    /** English title; translated through the package JSON files (lang/hi.json). */
    abstract protected static function englishTitle(): string;

    public static function canAccess(): bool
    {
        return FeesPermissions::can(static::permission());
    }

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-fees::fees.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __(static::englishTitle());
    }

    public function getTitle(): string
    {
        return __(static::englishTitle());
    }
}

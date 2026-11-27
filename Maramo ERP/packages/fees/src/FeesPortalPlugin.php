<?php

namespace Classivo\Fees;

use Classivo\Fees\Filament\Portal\Pages\ChildFees;
use Classivo\Fees\Filament\Portal\Pages\MyFees;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Read-only fee pages for the student and guardian portals.
 *
 *     $studentPanel->plugin(\Classivo\Fees\FeesPortalPlugin::student())
 *     $guardianPanel->plugin(\Classivo\Fees\FeesPortalPlugin::guardian())
 */
class FeesPortalPlugin implements Plugin
{
    public function __construct(protected string $audience = 'student') {}

    public static function make(): static
    {
        return new static;
    }

    public static function student(): static
    {
        return new static('student');
    }

    public static function guardian(): static
    {
        return new static('guardian');
    }

    public function getId(): string
    {
        return 'classivo-fees-portal';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([$this->audience === 'guardian' ? ChildFees::class : MyFees::class]);
    }

    public function boot(Panel $panel): void {}
}

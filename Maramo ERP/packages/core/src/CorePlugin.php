<?php

namespace Classivo\Core;

use Classivo\Core\Filament\Resources\AcademicSessionResource;
use Classivo\Core\Filament\Resources\BranchResource;
use Classivo\Core\Filament\Resources\SchoolClassResource;
use Classivo\Core\Filament\Resources\SectionResource;
use Classivo\Core\Filament\Resources\SubjectResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament plugin registering the academic-structure screens:
 * Branches, Academic Sessions, Classes, Sections, Subjects.
 *
 *     $panel->plugin(\Classivo\Core\CorePlugin::make())
 *
 * Remember to add the `classivo.tenant` middleware (ResolveTenantContext) to the
 * panel's middleware so the tenancy scopes are active.
 */
class CorePlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'classivo-core';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            BranchResource::class,
            AcademicSessionResource::class,
            SchoolClassResource::class,
            SectionResource::class,
            SubjectResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}

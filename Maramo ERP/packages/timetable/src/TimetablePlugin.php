<?php

namespace Classivo\Timetable;

use Classivo\Timetable\Filament\Pages\ClassTimetable;
use Classivo\Timetable\Filament\Pages\GenerateTimetable;
use Classivo\Timetable\Filament\Pages\TeacherSubstitution;
use Classivo\Timetable\Filament\Pages\TimetableRequirements;
use Classivo\Timetable\Filament\Resources\Substitutions\SubstitutionResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament plugin registering the Class Timetable, Timetable Requirements and
 * Generate Timetable pages (abilities timetable.view / .edit / .generate).
 *
 *     $panel->plugin(\Classivo\Timetable\TimetablePlugin::make())
 */
class TimetablePlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'classivo-timetable';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            ClassTimetable::class,
            TimetableRequirements::class,
            GenerateTimetable::class,
            TeacherSubstitution::class,
        ])->resources([
            SubstitutionResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}

<?php

namespace Classivo\Students;

use Classivo\Students\Filament\Pages\StudentPromotion;
use Classivo\Students\Filament\Pages\TakeAttendance;
use Classivo\Students\Filament\Pages\TakeSubjectAttendance;
use Classivo\Students\Filament\Resources\GuardianResource;
use Classivo\Students\Filament\Resources\StudentCategoryResource;
use Classivo\Students\Filament\Resources\StudentResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament plugin: Students, Guardians, Student Categories, Take Attendance,
 * Subject Attendance and Student Promotion.
 *
 *     $panel->plugin(\Classivo\Students\StudentsPlugin::make())
 */
class StudentsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'classivo-students';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                StudentResource::class,
                GuardianResource::class,
                StudentCategoryResource::class,
            ])
            ->pages([
                TakeAttendance::class,
                TakeSubjectAttendance::class,
                StudentPromotion::class,
            ]);
    }

    public function boot(Panel $panel): void {}
}

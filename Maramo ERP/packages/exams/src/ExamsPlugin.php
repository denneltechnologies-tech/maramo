<?php

namespace Classivo\Exams;

use Classivo\Exams\Filament\Pages\BuildExamSchedule;
use Classivo\Exams\Filament\Pages\ExamResults;
use Classivo\Exams\Filament\Pages\MarksRegister;
use Classivo\Exams\Filament\Resources\ExamAttendances\ExamAttendanceResource;
use Classivo\Exams\Filament\Resources\ExamGroups\ExamGroupResource;
use Classivo\Exams\Filament\Resources\Exams\ExamResource;
use Classivo\Exams\Filament\Resources\Grades\GradeResource;
use Classivo\Exams\Filament\Resources\MarksDistributions\MarksDistributionResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/** Registers the Examinations resources and pages on a Filament panel. */
class ExamsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'classivo-exams';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                ExamGroupResource::class,
                ExamResource::class,
                GradeResource::class,
                MarksDistributionResource::class,
                ExamAttendanceResource::class,
            ])
            ->pages([
                BuildExamSchedule::class,
                MarksRegister::class,
                ExamResults::class,
            ]);
    }

    public function boot(Panel $panel): void {}
}

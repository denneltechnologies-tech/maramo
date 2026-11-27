<?php

namespace Classivo\Students;

use Classivo\Students\Services\AdmissionNumberGenerator;
use Classivo\Students\Services\AttendanceService;
use Classivo\Students\Services\StudentAdmissionService;
use Classivo\Students\Services\StudentPromotionService;
use Illuminate\Support\ServiceProvider;

class StudentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdmissionNumberGenerator::class);
        $this->app->singleton(AttendanceService::class);
        $this->app->singleton(StudentAdmissionService::class);
        $this->app->singleton(StudentPromotionService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'classivo-students');
    }
}

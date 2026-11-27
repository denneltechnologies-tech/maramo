<?php

namespace Classivo\Hr;

use Classivo\Hr\Console\InstallStatutoryPreset;
use Classivo\Hr\Support\HrPermissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hr.php', 'hr');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'hr');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'hr');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->publishes([__DIR__.'/../config/hr.php' => config_path('hr.php')], 'classivo-hr-config');
        $this->publishes([__DIR__.'/../lang' => lang_path('vendor/hr')], 'classivo-hr-lang');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/hr')], 'classivo-hr-views');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallStatutoryPreset::class]);
        }

        $this->registerFallbackGates();
    }

    /**
     * Deny by default. With spatie/laravel-permission installed nothing is defined:
     * only explicitly granted permissions pass. Without it, each ability falls back
     * to "the user has a truthy is_admin attribute" (hr.define_gates = false turns
     * that off so you can define the abilities yourself).
     */
    protected function registerFallbackGates(): void
    {
        if (! config('hr.define_gates', true) || class_exists(PermissionRegistrar::class)) {
            return;
        }

        foreach (HrPermissions::all() as $ability) {
            if (! Gate::has($ability)) {
                Gate::define($ability, fn ($user): bool => (bool) ($user->is_admin ?? false));
            }
        }
    }
}

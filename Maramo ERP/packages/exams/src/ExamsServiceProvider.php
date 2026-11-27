<?php

namespace Classivo\Exams;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ExamsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/classivo-exams.php', 'classivo-exams');
    }

    public function boot(): void
    {
        $this->app->booted(fn () => $this->registerDefaultGates());

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'classivo-exams');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/classivo-exams.php' => config_path('classivo-exams.php'),
            ], 'classivo-exams-config');
            $this->publishes([
                __DIR__.'/../lang' => lang_path('vendor/classivo-exams'),
            ], 'classivo-exams-lang');
        }
    }

    /**
     * Deny by default: for every configured ability the host app has not defined, check
     * the spatie/laravel-permission permission of the same name (hasPermissionTo).
     * Users without that API, and guests, are denied.
     */
    protected function registerDefaultGates(): void
    {
        foreach ((array) config('classivo-exams.abilities', []) as $ability) {
            if (Gate::has($ability)) {
                continue;
            }

            Gate::define($ability, function ($user = null) use ($ability): bool {
                if ($user === null || ! method_exists($user, 'hasPermissionTo')) {
                    return false;
                }

                try {
                    return (bool) $user->hasPermissionTo($ability);
                } catch (\Throwable) {
                    return false; // permission does not exist yet
                }
            });
        }
    }
}

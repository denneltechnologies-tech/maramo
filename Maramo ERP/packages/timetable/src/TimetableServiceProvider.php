<?php

namespace Classivo\Timetable;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TimetableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/classivo-timetable.php', 'classivo-timetable');
    }

    public function boot(): void
    {
        $this->app->booted(fn () => $this->registerDefaultGates());

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'classivo-timetable');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'classivo-timetable');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/classivo-timetable.php' => config_path('classivo-timetable.php'),
            ], 'classivo-timetable-config');
            $this->publishes([
                __DIR__.'/../lang' => lang_path('vendor/classivo-timetable'),
            ], 'classivo-timetable-lang');
        }
    }

    /**
     * Deny-by-default Gates: each configured ability is granted only through
     * the spatie/laravel-permission permission of the same name
     * (hasPermissionTo). Guests and users without that API are denied. A Gate
     * the host app defined first wins.
     */
    protected function registerDefaultGates(): void
    {
        foreach ((array) config('classivo-timetable.abilities', []) as $ability) {
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

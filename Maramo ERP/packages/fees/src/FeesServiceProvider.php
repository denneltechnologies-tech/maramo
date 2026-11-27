<?php

namespace Classivo\Fees;

use Classivo\Fees\Console\AccrueFeeFines;
use Classivo\Fees\Console\SendFeeReminders;
use Classivo\Fees\Contracts\CollectionGuard;
use Classivo\Fees\Contracts\HolidayCalendar;
use Classivo\Fees\Contracts\ReminderRecipients;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Support\FeesPermissions;
use Classivo\Fees\Support\GuardianEmailRecipients;
use Classivo\Fees\Support\NullCollectionGuard;
use Classivo\Fees\Support\NullHolidayCalendar;
use Classivo\Fees\Support\Portal;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Fees package service provider.
 *
 * Extension points (bind your own in an app service provider):
 *   • CollectionGuard     — refuse receipts in closed periods (accounting).
 *   • HolidayCalendar     — school closures, excluded from DAILY late fines.
 *   • ReminderRecipients  — who a fee reminder is e-mailed to.
 *
 * Events for listeners (accounting, notifications, analytics):
 *   FeePaymentRecorded, FeePaymentReverted, FeeRefunded, FeeAccrued.
 */
class FeesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fees.php', 'fees');

        $this->app->bindIf(CollectionGuard::class, NullCollectionGuard::class);
        $this->app->bindIf(HolidayCalendar::class, NullHolidayCalendar::class);
        $this->app->bindIf(ReminderRecipients::class, GuardianEmailRecipients::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'classivo-fees');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'classivo-fees');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        if (config('fees.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        $this->publishes([
            __DIR__.'/../config/fees.php' => config_path('fees.php'),
        ], 'classivo-fees-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/classivo-fees'),
        ], 'classivo-fees-views');

        if ($this->app->runningInConsole()) {
            $this->commands([SendFeeReminders::class, AccrueFeeFines::class]);
        }

        $this->defineGates();
    }

    /**
     * Fallback gates, then `view-fee-receipt`: staff holding `fees.collect` or
     * `fees.view`, the student the receipt belongs to, or one of that student's
     * guardians (matched on `user_id`).
     */
    protected function defineGates(): void
    {
        // Without spatie/laravel-permission there is nothing to grant from, so
        // each fees ability falls back to an `is_admin` flag on the user.
        if (config('fees.define_gates', true) && ! class_exists(PermissionServiceProvider::class)) {
            foreach (FeesPermissions::all() as $ability) {
                if (! Gate::has($ability)) {
                    Gate::define($ability, fn ($user): bool => (bool) ($user->is_admin ?? false));
                }
            }
        }

        if (Gate::has('view-fee-receipt')) {
            return;
        }

        Gate::define('view-fee-receipt', function ($user, FeePayment $payment): bool {
            if ($user->can(FeesPermissions::COLLECT) || $user->can(FeesPermissions::VIEW)) {
                return true;
            }

            return Portal::canSeeStudent($user, (int) $payment->student_id);
        });
    }
}

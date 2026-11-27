<?php

namespace Classivo\Core;

use Classivo\Core\Http\Middleware\ResolveTenantContext;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Core\Tenancy\SingleSchoolResolver;
use Classivo\Core\Tenancy\TenantResolver;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/classivo.php', 'classivo');

        // bindIf: a multi-school product binds its own resolver first (or later
        // with bind()) and it wins over this single-school default.
        $this->app->bindIf(TenantResolver::class, SingleSchoolResolver::class);
        $this->app->singleton(CurrentContext::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'classivo-core');

        $this->publishes([
            __DIR__.'/../config/classivo.php' => config_path('classivo.php'),
        ], 'classivo-config');

        $this->app->make(Router::class)->aliasMiddleware('classivo.tenant', ResolveTenantContext::class);
    }
}

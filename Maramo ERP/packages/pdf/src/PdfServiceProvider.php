<?php

namespace Classivo\Pdf;

use Illuminate\Support\ServiceProvider;

class PdfServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/classivo-pdf.php', 'classivo-pdf');
        $this->app->singleton(PdfManager::class, fn ($app) => new PdfManager($app['config']->get('classivo-pdf', []), $app['log']));
        $this->app->alias(PdfManager::class, PdfRenderer::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/classivo-pdf.php' => config_path('classivo-pdf.php')], 'classivo-pdf-config');
    }
}

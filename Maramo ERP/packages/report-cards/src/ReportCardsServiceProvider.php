<?php

namespace Classivo\ReportCards;

use Barryvdh\DomPDF\ServiceProvider as DompdfServiceProvider;
use Classivo\Exams\Contracts\ResultDocumentLinks;
use Classivo\Exams\Models\Exam;
use Classivo\ReportCards\Renderer\CertificateRenderer;
use Classivo\ReportCards\Renderer\PdfRenderer;
use Illuminate\Support\ServiceProvider;

class ReportCardsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/report-cards.php', 'report-cards');
        $this->app->register(DompdfServiceProvider::class);
        $this->app->singleton(CertificateRenderer::class);
        $this->app->singleton(PdfRenderer::class);

        if (interface_exists(ResultDocumentLinks::class)) {
            $this->app->bind(ResultDocumentLinks::class, Adapters\ResultLinks::class);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'report-cards');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'report-cards');

        if (class_exists(Exam::class)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if (config('report-cards.persistence')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/report-cards.php' => config_path('report-cards.php')], 'report-cards-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/report-cards')], 'report-cards-views');
        }
    }
}

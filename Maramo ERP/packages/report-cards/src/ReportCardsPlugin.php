<?php

namespace Classivo\ReportCards;

use Classivo\ReportCards\Filament\Resources\CertificateTemplates\CertificateTemplateResource;
use Classivo\ReportCards\Filament\Resources\GeneratedCertificates\GeneratedCertificateResource;
use Classivo\ReportCards\Filament\Resources\IdCardTemplates\IdCardTemplateResource;
use Classivo\ReportCards\Filament\Resources\ReportCardTemplates\ReportCardTemplateResource;
use Classivo\ReportCards\Filament\Resources\TransferCertificates\TransferCertificateResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/** Registers the report card / certificate resources on a Filament panel. */
class ReportCardsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'classivo-report-cards';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            GeneratedCertificateResource::class,
            CertificateTemplateResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}

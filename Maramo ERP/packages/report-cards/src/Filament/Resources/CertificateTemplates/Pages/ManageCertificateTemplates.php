<?php

namespace Classivo\ReportCards\Filament\Resources\CertificateTemplates\Pages;

use Classivo\ReportCards\Filament\Resources\CertificateTemplates\CertificateTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCertificateTemplates extends ManageRecords
{
    protected static string $resource = CertificateTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->authorize(fn (): bool => CertificateTemplateResource::canCreate())];
    }
}

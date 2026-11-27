<?php

namespace Classivo\ReportCards\Filament\Resources\ReportCardTemplates\Pages;

use Classivo\ReportCards\Filament\Resources\ReportCardTemplates\ReportCardTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageReportCardTemplates extends ManageRecords
{
    protected static string $resource = ReportCardTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->authorize(fn (): bool => ReportCardTemplateResource::canCreate())];
    }
}

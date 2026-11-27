<?php

namespace Classivo\ReportCards\Filament\Resources\IdCardTemplates\Pages;

use Classivo\ReportCards\Filament\Resources\IdCardTemplates\IdCardTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageIdCardTemplates extends ManageRecords
{
    protected static string $resource = IdCardTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->authorize(fn (): bool => IdCardTemplateResource::canCreate())];
    }
}

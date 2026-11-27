<?php

namespace Classivo\Core\Filament\Resources\Pages;

use Classivo\Core\Filament\Resources\AcademicSessionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAcademicSessions extends ManageRecords
{
    protected static string $resource = AcademicSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

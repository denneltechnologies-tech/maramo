<?php

namespace Classivo\Students\Filament\Resources\StudentResource\Pages;

use Classivo\Students\Filament\Resources\StudentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStudent extends ViewRecord
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}

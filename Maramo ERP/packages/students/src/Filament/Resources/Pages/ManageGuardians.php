<?php

namespace Classivo\Students\Filament\Resources\Pages;

use Classivo\Students\Filament\Resources\GuardianResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGuardians extends ManageRecords
{
    protected static string $resource = GuardianResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

<?php

namespace Classivo\Hr\Filament\Resources\StatutoryRuleSets\Pages;

use Classivo\Hr\Filament\Resources\StatutoryRuleSets\StatutoryRuleSetResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditStatutoryRuleSet extends EditRecord
{
    protected static string $resource = StatutoryRuleSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}

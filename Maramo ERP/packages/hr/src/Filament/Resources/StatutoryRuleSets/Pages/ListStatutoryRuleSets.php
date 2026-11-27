<?php

namespace Classivo\Hr\Filament\Resources\StatutoryRuleSets\Pages;

use Classivo\Hr\Filament\Resources\StatutoryRuleSets\StatutoryRuleSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStatutoryRuleSets extends ListRecords
{
    protected static string $resource = StatutoryRuleSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('hr::ui.new_financial_year')),
        ];
    }
}

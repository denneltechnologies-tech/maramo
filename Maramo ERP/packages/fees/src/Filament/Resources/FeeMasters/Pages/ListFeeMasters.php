<?php

namespace Classivo\Fees\Filament\Resources\FeeMasters\Pages;

use Classivo\Fees\Filament\Resources\FeeMasters\FeeMasterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeeMasters extends ListRecords
{
    protected static string $resource = FeeMasterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

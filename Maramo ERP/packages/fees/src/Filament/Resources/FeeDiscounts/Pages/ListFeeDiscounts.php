<?php

namespace Classivo\Fees\Filament\Resources\FeeDiscounts\Pages;

use Classivo\Fees\Filament\Resources\FeeDiscounts\FeeDiscountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeeDiscounts extends ListRecords
{
    protected static string $resource = FeeDiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

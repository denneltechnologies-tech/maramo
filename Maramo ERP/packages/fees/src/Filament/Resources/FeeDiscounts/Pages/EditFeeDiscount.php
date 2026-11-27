<?php

namespace Classivo\Fees\Filament\Resources\FeeDiscounts\Pages;

use Classivo\Fees\Filament\Resources\FeeDiscounts\FeeDiscountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFeeDiscount extends EditRecord
{
    protected static string $resource = FeeDiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

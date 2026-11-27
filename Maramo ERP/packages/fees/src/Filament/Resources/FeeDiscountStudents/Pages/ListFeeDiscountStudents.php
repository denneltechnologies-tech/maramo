<?php

namespace Classivo\Fees\Filament\Resources\FeeDiscountStudents\Pages;

use Classivo\Fees\Filament\Resources\FeeDiscountStudents\FeeDiscountStudentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeeDiscountStudents extends ListRecords
{
    protected static string $resource = FeeDiscountStudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Granting is the money privilege; reading the register is not.
            CreateAction::make()
                ->label(__('Grant concession'))
                ->visible(fn (): bool => (bool) auth()->user()?->can('fees.discount')),
        ];
    }
}

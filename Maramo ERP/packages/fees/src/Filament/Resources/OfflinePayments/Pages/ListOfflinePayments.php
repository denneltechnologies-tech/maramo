<?php

namespace Classivo\Fees\Filament\Resources\OfflinePayments\Pages;

use Classivo\Fees\Filament\Resources\OfflinePayments\OfflinePaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOfflinePayments extends ListRecords
{
    protected static string $resource = OfflinePaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

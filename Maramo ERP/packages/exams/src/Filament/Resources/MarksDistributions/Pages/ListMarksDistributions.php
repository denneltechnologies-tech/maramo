<?php

namespace Classivo\Exams\Filament\Resources\MarksDistributions\Pages;

use Classivo\Exams\Filament\Resources\MarksDistributions\MarksDistributionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMarksDistributions extends ListRecords
{
    protected static string $resource = MarksDistributionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

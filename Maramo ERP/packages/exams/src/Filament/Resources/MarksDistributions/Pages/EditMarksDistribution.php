<?php

namespace Classivo\Exams\Filament\Resources\MarksDistributions\Pages;

use Classivo\Exams\Filament\Resources\MarksDistributions\MarksDistributionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditMarksDistribution extends EditRecord
{
    protected static string $resource = MarksDistributionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}

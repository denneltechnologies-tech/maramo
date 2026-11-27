<?php

namespace Classivo\Exams\Filament\Resources\ExamGroups\Pages;

use Classivo\Exams\Filament\Resources\ExamGroups\ExamGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditExamGroup extends EditRecord
{
    protected static string $resource = ExamGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}

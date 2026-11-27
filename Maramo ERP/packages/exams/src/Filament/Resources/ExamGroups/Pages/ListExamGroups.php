<?php

namespace Classivo\Exams\Filament\Resources\ExamGroups\Pages;

use Classivo\Exams\Filament\Resources\ExamGroups\ExamGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExamGroups extends ListRecords
{
    protected static string $resource = ExamGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

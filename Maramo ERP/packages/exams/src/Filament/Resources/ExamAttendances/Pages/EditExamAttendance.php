<?php

namespace Classivo\Exams\Filament\Resources\ExamAttendances\Pages;

use Classivo\Exams\Filament\Resources\ExamAttendances\ExamAttendanceResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditExamAttendance extends EditRecord
{
    protected static string $resource = ExamAttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}

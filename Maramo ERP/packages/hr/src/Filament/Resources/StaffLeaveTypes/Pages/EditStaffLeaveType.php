<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveTypes\Pages;

use Classivo\Hr\Filament\Resources\StaffLeaveTypes\StaffLeaveTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditStaffLeaveType extends EditRecord
{
    protected static string $resource = StaffLeaveTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}

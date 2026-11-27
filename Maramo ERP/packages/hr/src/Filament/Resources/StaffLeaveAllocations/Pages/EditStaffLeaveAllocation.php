<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveAllocations\Pages;

use Classivo\Hr\Filament\Resources\StaffLeaveAllocations\StaffLeaveAllocationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditStaffLeaveAllocation extends EditRecord
{
    protected static string $resource = StaffLeaveAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}

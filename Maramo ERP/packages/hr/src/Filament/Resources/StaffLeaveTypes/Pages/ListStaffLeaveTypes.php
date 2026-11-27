<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveTypes\Pages;

use Classivo\Hr\Filament\Resources\StaffLeaveTypes\StaffLeaveTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaffLeaveTypes extends ListRecords
{
    protected static string $resource = StaffLeaveTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

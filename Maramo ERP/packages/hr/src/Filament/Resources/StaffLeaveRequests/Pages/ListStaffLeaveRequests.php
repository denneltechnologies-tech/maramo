<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveRequests\Pages;

use Classivo\Hr\Filament\Resources\StaffLeaveRequests\StaffLeaveRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaffLeaveRequests extends ListRecords
{
    protected static string $resource = StaffLeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

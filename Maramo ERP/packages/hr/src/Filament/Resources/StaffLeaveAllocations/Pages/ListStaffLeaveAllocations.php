<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveAllocations\Pages;

use Classivo\Hr\Filament\Resources\StaffLeaveAllocations\StaffLeaveAllocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaffLeaveAllocations extends ListRecords
{
    protected static string $resource = StaffLeaveAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

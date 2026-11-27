<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveRequests\Pages;

use Classivo\Hr\Filament\Resources\StaffLeaveRequests\StaffLeaveRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStaffLeaveRequest extends CreateRecord
{
    protected static string $resource = StaffLeaveRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['applied_by'] ??= auth()->id();

        return $data;
    }
}

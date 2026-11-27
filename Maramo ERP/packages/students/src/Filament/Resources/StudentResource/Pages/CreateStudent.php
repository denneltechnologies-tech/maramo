<?php

namespace Classivo\Students\Filament\Resources\StudentResource\Pages;

use Classivo\Students\Filament\Resources\StudentResource;
use Classivo\Students\Services\StudentAdmissionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Admission goes through the shared StudentAdmissionService. */
class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(StudentAdmissionService::class)->admit($data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}

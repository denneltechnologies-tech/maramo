<?php

namespace Classivo\Students\Filament\Resources\Pages;

use Classivo\Students\Filament\Resources\StudentCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStudentCategories extends ManageRecords
{
    protected static string $resource = StudentCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

<?php

namespace Classivo\Core\Filament\Resources\Pages;

use Classivo\Core\Filament\Resources\SchoolClassResource;
use Classivo\Core\Models\SchoolClass;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Arr;

class ManageSchoolClasses extends ManageRecords
{
    protected static string $resource = SchoolClassResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data): SchoolClass {
                    $sectionIds = (array) Arr::pull($data, 'section_ids', []);
                    $class = SchoolClass::create($data);
                    $class->syncSections($sectionIds);

                    return $class;
                }),
        ];
    }
}

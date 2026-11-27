<?php

namespace Classivo\Hr\Filament\Resources\AdvanceSalaries\Pages;

use Classivo\Hr\Filament\Resources\AdvanceSalaries\AdvanceSalaryResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditAdvanceSalary extends EditRecord
{
    protected static string $resource = AdvanceSalaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}

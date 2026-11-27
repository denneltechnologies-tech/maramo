<?php

namespace Classivo\Hr\Filament\Resources\SalaryComponents\Pages;

use Classivo\Hr\Filament\Resources\SalaryComponents\SalaryComponentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditSalaryComponent extends EditRecord
{
    protected static string $resource = SalaryComponentResource::class;

    /**
     * A system head has no delete action at all — the payroll engine finds it by
     * code, so deleting one silently stops a deduction being calculated instead
     * of failing loudly. Deactivating it is the supported way to stop using one.
     */
    protected function getHeaderActions(): array
    {
        $isSystem = (bool) $this->getRecord()?->is_system;

        return [
            DeleteAction::make()->visible(! $isSystem),
            ForceDeleteAction::make()->visible(! $isSystem),
            RestoreAction::make(),
        ];
    }
}

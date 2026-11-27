<?php

namespace Classivo\Students\Filament\Resources\StudentResource\Pages;

use Classivo\Core\Models\ClassSection;
use Classivo\Students\Filament\Resources\StudentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()];
    }

    /** Keep the canonical class_section_id in step with class + section. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! empty($data['class_id']) && ! empty($data['section_id'])) {
            $data['class_section_id'] = ClassSection::where('class_id', $data['class_id'])
                ->where('section_id', $data['section_id'])
                ->value('id');
        }

        unset($data['admission_no_manual']);

        return $data;
    }

    protected function afterSave(): void
    {
        // Mirror the change onto the current-session enrolment record.
        $this->getRecord()->currentSessionRecord?->update([
            'class_id' => $this->getRecord()->class_id,
            'section_id' => $this->getRecord()->section_id,
            'class_section_id' => $this->getRecord()->class_section_id,
            'roll_no' => $this->getRecord()->roll_no,
        ]);
    }
}

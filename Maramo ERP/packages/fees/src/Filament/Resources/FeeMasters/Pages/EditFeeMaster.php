<?php

namespace Classivo\Fees\Filament\Resources\FeeMasters\Pages;

use Classivo\Fees\Filament\Resources\FeeMasters\Actions\FeeScheduleActions;
use Classivo\Fees\Filament\Resources\FeeMasters\Actions\GuardedFeeMasterDeleteActions;
use Classivo\Fees\Filament\Resources\FeeMasters\Concerns\SavesFeeSchedule;
use Classivo\Fees\Filament\Resources\FeeMasters\FeeMasterResource;
use Classivo\Fees\Models\FeeMaster;
use Filament\Resources\Pages\EditRecord;

class EditFeeMaster extends EditRecord
{
    use SavesFeeSchedule;

    protected static string $resource = FeeMasterResource::class;

    /**
     * The header delete comes from {@see GuardedFeeMasterDeleteActions} — the
     * same guarded action the list screen uses. A bare DeleteAction::make() here
     * would be a second, unguarded way to strand a paid fee line.
     *
     * Revise sits beside it for the same reason: editing an amount that students
     * have already been billed against is the money-losing version of the same
     * mistake, and this is the supported way to do it.
     */
    protected function getHeaderActions(): array
    {
        return [
            FeeScheduleActions::revise(),
            FeeScheduleActions::generateInstallments(),
            GuardedFeeMasterDeleteActions::record(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        return $this->applyScheduleRules($data, $record instanceof FeeMaster ? $record : null);
    }

    /**
     * Re-expand the definition after an edit. A schedule whose count or first due
     * date changed gains, loses or re-dates instalments here; instalments already
     * assigned to a student are left exactly as issued, and the notification says
     * how many that was.
     */
    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record instanceof FeeMaster) {
            $this->regenerateInstallments($record);
        }
    }
}

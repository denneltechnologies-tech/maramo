<?php

namespace Classivo\Fees\Filament\Resources\FeeMasters\Pages;

use Classivo\Fees\Filament\Resources\FeeMasters\Concerns\SavesFeeSchedule;
use Classivo\Fees\Filament\Resources\FeeMasters\FeeMasterResource;
use Classivo\Fees\Models\FeeMaster;
use Filament\Resources\Pages\CreateRecord;

/**
 * Creating a fee master. A one-time master saves and is done; a recurring one is
 * a DEFINITION, and saving it also writes the instalments beneath it — see
 * {@see SavesFeeSchedule}.
 */
class CreateFeeMaster extends CreateRecord
{
    use SavesFeeSchedule;

    protected static string $resource = FeeMasterResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->applyScheduleRules($data);
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof FeeMaster) {
            $this->regenerateInstallments($record);
        }
    }
}

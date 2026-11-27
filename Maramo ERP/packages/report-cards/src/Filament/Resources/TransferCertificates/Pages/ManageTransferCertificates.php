<?php

namespace Classivo\ReportCards\Filament\Resources\TransferCertificates\Pages;

use Classivo\ReportCards\Filament\Resources\TransferCertificates\TransferCertificateResource;
use Classivo\ReportCards\Models\TransferCertificate;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTransferCertificates extends ManageRecords
{
    protected static string $resource = TransferCertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->authorize(fn (): bool => TransferCertificateResource::canCreate())->mutateDataUsing(function (array $data): array {
                $data['tc_number'] = TransferCertificate::nextNumber();
                $data['issued_by'] = auth()->id();

                return $data;
            }),
        ];
    }
}

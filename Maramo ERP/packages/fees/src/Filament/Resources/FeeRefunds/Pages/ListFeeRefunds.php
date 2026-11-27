<?php

namespace Classivo\Fees\Filament\Resources\FeeRefunds\Pages;

use Classivo\Fees\Filament\Resources\FeeRefunds\FeeRefundResource;
use Filament\Resources\Pages\ListRecords;

/**
 * List fee refunds. No create action: a refund is raised from the receipt it
 * belongs to (the Refund row action on Search Fees Payment), because the figures
 * that bound it — what was paid, what each head took, what is still refundable —
 * only exist there.
 */
class ListFeeRefunds extends ListRecords
{
    protected static string $resource = FeeRefundResource::class;
}

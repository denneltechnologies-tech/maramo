<?php

namespace Classivo\Fees\Filament\Resources\FeeRefunds;

use BackedEnum;
use Classivo\Fees\Filament\Concerns\AuthorizesWithFeesPermission;
use Classivo\Fees\Filament\Resources\FeeRefunds\Pages\ListFeeRefunds;
use Classivo\Fees\Filament\Resources\FeeRefunds\Pages\ViewFeeRefund;
use Classivo\Fees\Filament\Resources\FeeRefunds\Tables\FeeRefundsTable;
use Classivo\Fees\Models\FeeRefund;
use Classivo\Fees\Support\FeesPermissions;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * FeeRefundResource — "Fee Refunds" (extending "Revert Payment" from
 * whole-receipt reversal to full and partial refunds).
 *
 * Read/search + decide. Refunds are RAISED from the receipt they belong to — the
 * Refund row action on Search Fees Payment — for the same reason receipts are
 * created on Collect Fees and not here: a refund without the receipt in front of
 * you is a figure with nothing to check it against. So there is no create form on
 * this resource, only the lifecycle: approve, reject, issue, mark paid, print the
 * credit note.
 *
 * Nav group "Fees Collection", after Search Fees Payment.
 */
class FeeRefundResource extends Resource
{
    use AuthorizesWithFeesPermission;

    public static function viewPermission(): string
    {
        return FeesPermissions::REFUND;
    }

    public static function managePermission(): string
    {
        return FeesPermissions::REFUND;
    }

    protected static ?string $model = FeeRefund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-fees::fees.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return 'Refunds & Adjustments';
    }

    public static function getModelLabel(): string
    {
        return __('Fee Refund');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Fee Refunds');
    }

    protected static ?int $navigationSort = 53;

    protected static ?string $recordTitleAttribute = 'credit_note_no';

    public static function table(Table $table): Table
    {
        return FeeRefundsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeeRefunds::route('/'),
            'view' => ViewFeeRefund::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // The soft-delete scope is lifted for the same reason FeePaymentResource
        // lifts it: a withdrawn refund is still part of the money story and an
        // auditor asking "what happened to credit note CN14" must not be told
        // "nothing". FeeRefund is NOT session-scoped in the first place — a
        // refund is routinely raised in a later session than the receipt it
        // reverses — so no SessionScope has to be lifted here.
        return parent::getEloquentQuery()
            ->with(['student', 'payment', 'lines.feeType', 'requestedBy', 'approvedBy', 'issuedBy'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}

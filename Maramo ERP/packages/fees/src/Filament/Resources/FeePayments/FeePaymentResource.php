<?php

namespace Classivo\Fees\Filament\Resources\FeePayments;

use BackedEnum;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Fees\Filament\Concerns\AuthorizesWithFeesPermission;
use Classivo\Fees\Filament\Resources\FeePayments\Pages\ListFeePayments;
use Classivo\Fees\Filament\Resources\FeePayments\Pages\ViewFeePayment;
use Classivo\Fees\Filament\Resources\FeePayments\Tables\FeePaymentsTable;
use Classivo\Fees\Filament\Resources\FeeRefunds\FeeRefundResource;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeRefund;
use Classivo\Fees\Support\FeesPermissions;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * FeePaymentResource — "Search Fees Payment". A read/search view over
 * collection receipts with Print Receipt + permission-gated Revert row actions.
 * Receipts are CREATED on the Collect Fees page, not here, so there is no create/edit.
 * Nav group "Fees Collection".
 *
 * IT IS ALSO WHERE A REFUND IS RAISED. A refund is bounded by figures that only
 * exist beside the receipt — what was paid, what each head took, what previous
 * partial refunds have already claimed — so the Refund action lives on the row
 * rather than on a create form somewhere else. The lifecycle after that (approve,
 * issue, credit note) belongs to
 * {@see FeeRefundResource}.
 */
class FeePaymentResource extends Resource
{
    use AuthorizesWithFeesPermission;

    public static function viewPermission(): string
    {
        return FeesPermissions::VIEW;
    }

    public static function managePermission(): string
    {
        return FeesPermissions::COLLECT;
    }

    protected static ?string $model = FeePayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-fees::fees.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('Search Fees Payment');
    }

    public static function getModelLabel(): string
    {
        return __('Fee Payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Fee Payments');
    }

    protected static ?int $navigationSort = 47;

    protected static ?string $recordTitleAttribute = 'receipt_no';

    public static function table(Table $table): Table
    {
        return FeePaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeePayments::route('/'),
            'view' => ViewFeePayment::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // SessionScope is lifted here so the table's Academic Session filter
        // (defaulting to the current session — the unchanged default view) can
        // span sessions when the user picks another one or "All"; it also lets
        // the View page open receipts from any session.
        return parent::getEloquentQuery()
            ->with(['student', 'collector', 'details.feeType', 'session'])
            ->withoutGlobalScopes([SoftDeletingScope::class, SessionScope::class])
            // How much of this receipt has been refunded, as ONE correlated
            // subquery rather than a query per row. The alternative — asking each
            // record in a column closure — is the N+1 this table has already been
            // burned by once (see the full_name sorting comment in the table), and
            // it would run for every row on every page load whether or not any
            // refund exists.
            //
            // `live()` is spelled out rather than called on the model, because a
            // scope on a nested builder is easy to lose in a refactor and this
            // figure gates the Revert action: a refund still
            // sitting at `requested` must already count, or two clerks can each
            // refund the same receipt in full.
            //
            // addSelect() with a keyed subquery is safe on a column-less builder:
            // Laravel adds `fee_payments.*` itself before appending the subselect.
            ->addSelect(['refunded_total' => FeeRefund::query()
                ->selectRaw('COALESCE(SUM(fee_refunds.amount), 0)')
                ->whereColumn('fee_refunds.fee_payment_id', 'fee_payments.id')
                ->whereNull('fee_refunds.deleted_at')
                ->whereNotIn('fee_refunds.status', ['rejected', 'voided']),
            ]);
    }
}

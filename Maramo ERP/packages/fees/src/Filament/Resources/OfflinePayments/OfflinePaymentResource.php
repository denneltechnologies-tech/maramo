<?php

namespace Classivo\Fees\Filament\Resources\OfflinePayments;

use BackedEnum;
use Classivo\Fees\Filament\Concerns\AuthorizesWithFeesPermission;
use Classivo\Fees\Filament\Resources\OfflinePayments\Pages\CreateOfflinePayment;
use Classivo\Fees\Filament\Resources\OfflinePayments\Pages\EditOfflinePayment;
use Classivo\Fees\Filament\Resources\OfflinePayments\Pages\ListOfflinePayments;
use Classivo\Fees\Filament\Resources\OfflinePayments\Schemas\OfflinePaymentForm;
use Classivo\Fees\Filament\Resources\OfflinePayments\Tables\OfflinePaymentsTable;
use Classivo\Fees\Models\OfflinePayment;
use Classivo\Fees\Support\FeesPermissions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * OfflinePaymentResource. Student/parent
 * submitted payments awaiting review; Approve posts a real receipt. Nav group
 * "Fees Collection".
 */
class OfflinePaymentResource extends Resource
{
    use AuthorizesWithFeesPermission;

    public static function viewPermission(): string
    {
        return FeesPermissions::COLLECT;
    }

    public static function managePermission(): string
    {
        return FeesPermissions::COLLECT;
    }

    protected static ?string $model = OfflinePayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-fees::fees.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('Offline Payments');
    }

    public static function getModelLabel(): string
    {
        return __('Offline Payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Offline Payments');
    }

    protected static ?int $navigationSort = 49;

    public static function form(Schema $schema): Schema
    {
        return OfflinePaymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OfflinePaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOfflinePayments::route('/'),
            'create' => CreateOfflinePayment::route('/create'),
            'edit' => EditOfflinePayment::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['student', 'reviewer'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}

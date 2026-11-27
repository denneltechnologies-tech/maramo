<?php

namespace Classivo\Fees\Filament\Resources\FeeDiscounts;

use BackedEnum;
use Classivo\Fees\Filament\Concerns\AuthorizesWithFeesPermission;
use Classivo\Fees\Filament\Resources\FeeDiscounts\Pages\CreateFeeDiscount;
use Classivo\Fees\Filament\Resources\FeeDiscounts\Pages\EditFeeDiscount;
use Classivo\Fees\Filament\Resources\FeeDiscounts\Pages\ListFeeDiscounts;
use Classivo\Fees\Filament\Resources\FeeDiscounts\Schemas\FeeDiscountForm;
use Classivo\Fees\Filament\Resources\FeeDiscounts\Tables\FeeDiscountsTable;
use Classivo\Fees\Models\FeeDiscount;
use Classivo\Fees\Support\FeesPermissions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * FeeDiscountResource. Nav group "Fees Collection".
 * Applying a discount to a student's allocation is gated `fees.discount` (enforced on
 * the Collect page / profile action); this resource manages the discount masters.
 */
class FeeDiscountResource extends Resource
{
    use AuthorizesWithFeesPermission;

    public static function viewPermission(): string
    {
        return FeesPermissions::SETUP;
    }

    public static function managePermission(): string
    {
        return FeesPermissions::SETUP;
    }

    protected static ?string $model = FeeDiscount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-fees::fees.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('Discounts & Concessions');
    }

    public static function getModelLabel(): string
    {
        return __('Fees Discount');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Fees Discounts');
    }

    protected static ?int $navigationSort = 44;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return FeeDiscountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeeDiscountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeeDiscounts::route('/'),
            'create' => CreateFeeDiscount::route('/create'),
            'edit' => EditFeeDiscount::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}

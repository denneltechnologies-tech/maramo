<?php

namespace Classivo\Fees\Filament\Resources\FeeTypes;

use BackedEnum;
use Classivo\Fees\Filament\Concerns\AuthorizesWithFeesPermission;
use Classivo\Fees\Filament\Resources\FeeTypes\Pages\CreateFeeType;
use Classivo\Fees\Filament\Resources\FeeTypes\Pages\EditFeeType;
use Classivo\Fees\Filament\Resources\FeeTypes\Pages\ListFeeTypes;
use Classivo\Fees\Filament\Resources\FeeTypes\Schemas\FeeTypeForm;
use Classivo\Fees\Filament\Resources\FeeTypes\Tables\FeeTypesTable;
use Classivo\Fees\Models\FeeType;
use Classivo\Fees\Support\FeesPermissions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * FeeTypeResource. Nav group "Fees Collection". Gated on the
 * (core) fees module.
 */
class FeeTypeResource extends Resource
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

    protected static ?string $model = FeeType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-fees::fees.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('Fees Type');
    }

    public static function getModelLabel(): string
    {
        return __('Fees Type');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Fees Types');
    }

    protected static ?int $navigationSort = 41;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return FeeTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeeTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeeTypes::route('/'),
            'create' => CreateFeeType::route('/create'),
            'edit' => EditFeeType::route('/{record}/edit'),
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

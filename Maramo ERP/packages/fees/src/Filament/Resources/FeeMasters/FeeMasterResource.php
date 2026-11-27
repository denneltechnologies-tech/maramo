<?php

namespace Classivo\Fees\Filament\Resources\FeeMasters;

use BackedEnum;
use Classivo\Fees\Filament\Concerns\AuthorizesWithFeesPermission;
use Classivo\Fees\Filament\Resources\FeeMasters\Pages\CreateFeeMaster;
use Classivo\Fees\Filament\Resources\FeeMasters\Pages\EditFeeMaster;
use Classivo\Fees\Filament\Resources\FeeMasters\Pages\ListFeeMasters;
use Classivo\Fees\Filament\Resources\FeeMasters\Schemas\FeeMasterForm;
use Classivo\Fees\Filament\Resources\FeeMasters\Tables\FeeMastersTable;
use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Support\FeesPermissions;
use Classivo\Fees\Support\StudentCategories;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * FeeMasterResource. Links fee group + fee type + amount +
 * due date + fine rule, optionally scoped to a class. Nav group "Fees Collection".
 */
class FeeMasterResource extends Resource
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

    protected static ?string $model = FeeMaster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-fees::fees.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('Course Fees Setup');
    }

    public static function getModelLabel(): string
    {
        return __('Fees Master');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Fees Masters');
    }

    protected static ?int $navigationSort = 43;

    public static function form(Schema $schema): Schema
    {
        return FeeMasterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeeMastersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeeMasters::route('/'),
            'create' => CreateFeeMaster::route('/create'),
            'edit' => EditFeeMaster::route('/{record}/edit'),
        ];
    }

    /**
     * `section`, `studentCategory` and `feeFine` join the eager loads because the
     * list renders all three per row — and `feeFine` in particular is read through
     * {@see FeeMaster::fineRule()}, which would otherwise lazy-load one query per
     * row on a price list that names a shared fine rule.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(array_merge(['feeGroup', 'feeType', 'schoolClass', 'section', 'feeFine'], StudentCategories::available() ? ['studentCategory'] : []));
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}

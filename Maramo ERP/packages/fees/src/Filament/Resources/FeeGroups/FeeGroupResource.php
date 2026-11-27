<?php

namespace Classivo\Fees\Filament\Resources\FeeGroups;

use BackedEnum;
use Classivo\Fees\Filament\Concerns\AuthorizesWithFeesPermission;
use Classivo\Fees\Filament\Resources\FeeGroups\Pages\CreateFeeGroup;
use Classivo\Fees\Filament\Resources\FeeGroups\Pages\EditFeeGroup;
use Classivo\Fees\Filament\Resources\FeeGroups\Pages\ListFeeGroups;
use Classivo\Fees\Filament\Resources\FeeGroups\Schemas\FeeGroupForm;
use Classivo\Fees\Filament\Resources\FeeGroups\Tables\FeeGroupsTable;
use Classivo\Fees\Models\FeeGroup;
use Classivo\Fees\Support\FeesPermissions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * FeeGroupResource. Nav group "Fees Collection".
 */
class FeeGroupResource extends Resource
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

    protected static ?string $model = FeeGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-fees::fees.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('Fees Group');
    }

    public static function getModelLabel(): string
    {
        return __('Fees Group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Fees Groups');
    }

    protected static ?int $navigationSort = 42;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return FeeGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeeGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeeGroups::route('/'),
            'create' => CreateFeeGroup::route('/create'),
            'edit' => EditFeeGroup::route('/{record}/edit'),
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

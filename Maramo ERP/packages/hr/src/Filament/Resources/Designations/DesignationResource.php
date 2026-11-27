<?php

namespace Classivo\Hr\Filament\Resources\Designations;

use BackedEnum;
use Classivo\Hr\Filament\Concerns\HrNavigation;
use Classivo\Hr\Filament\Resources\BaseResource as Resource;
use Classivo\Hr\Filament\Resources\Designations\Pages\CreateDesignation;
use Classivo\Hr\Filament\Resources\Designations\Pages\EditDesignation;
use Classivo\Hr\Filament\Resources\Designations\Pages\ListDesignations;
use Classivo\Hr\Filament\Resources\Designations\Schemas\DesignationForm;
use Classivo\Hr\Filament\Resources\Designations\Tables\DesignationsTable;
use Classivo\Hr\Models\Designation;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * DesignationResource (spec §15 "Departments / Designations") — the staff job-title
 * master. Nav group "Human Resource"; gated on the HR module.
 */
class DesignationResource extends Resource
{
    use HrNavigation;

    protected static string $navKey = 'designations';

    protected static string $permissionPrefix = 'hr.designations';

    protected static ?string $model = Designation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 73;

    public static function form(Schema $schema): Schema
    {
        return DesignationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DesignationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDesignations::route('/'),
            'create' => CreateDesignation::route('/create'),
            'edit' => EditDesignation::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}

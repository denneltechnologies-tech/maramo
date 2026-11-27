<?php

namespace Classivo\Hr\Filament\Resources\Departments;

use BackedEnum;
use Classivo\Hr\Filament\Concerns\HrNavigation;
use Classivo\Hr\Filament\Resources\BaseResource as Resource;
use Classivo\Hr\Filament\Resources\Departments\Pages\CreateDepartment;
use Classivo\Hr\Filament\Resources\Departments\Pages\EditDepartment;
use Classivo\Hr\Filament\Resources\Departments\Pages\ListDepartments;
use Classivo\Hr\Filament\Resources\Departments\Schemas\DepartmentForm;
use Classivo\Hr\Filament\Resources\Departments\Tables\DepartmentsTable;
use Classivo\Hr\Models\Department;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * DepartmentResource (spec §15 "Departments / Designations") — the staff department
 * master. Nav group "Human Resource"; gated on the HR module.
 */
class DepartmentResource extends Resource
{
    use HrNavigation;

    protected static string $navKey = 'departments';

    protected static string $permissionPrefix = 'hr.departments';

    protected static ?string $model = Department::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 72;

    public static function form(Schema $schema): Schema
    {
        return DepartmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepartmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDepartments::route('/'),
            'create' => CreateDepartment::route('/create'),
            'edit' => EditDepartment::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}

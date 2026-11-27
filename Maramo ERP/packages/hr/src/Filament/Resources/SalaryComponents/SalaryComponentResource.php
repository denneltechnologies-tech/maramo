<?php

namespace Classivo\Hr\Filament\Resources\SalaryComponents;

use BackedEnum;
use Classivo\Hr\Filament\Concerns\HrNavigation;
use Classivo\Hr\Filament\Resources\BaseResource as Resource;
use Classivo\Hr\Filament\Resources\SalaryComponents\Pages\CreateSalaryComponent;
use Classivo\Hr\Filament\Resources\SalaryComponents\Pages\EditSalaryComponent;
use Classivo\Hr\Filament\Resources\SalaryComponents\Pages\ListSalaryComponents;
use Classivo\Hr\Filament\Resources\SalaryComponents\Schemas\SalaryComponentForm;
use Classivo\Hr\Filament\Resources\SalaryComponents\Tables\SalaryComponentsTable;
use Classivo\Hr\Models\SalaryComponent;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * SalaryComponentResource (spec §15 Payroll "Salary structure") — the salary HEAD
 * master. Nav group "Human Resource"; gated on the HR module and, because these
 * rows decide what everybody is paid, on the same `payroll.generate` ability that
 * gates running payroll itself.
 */
class SalaryComponentResource extends Resource
{
    use HrNavigation;

    protected static string $navKey = 'salary_components';

    protected static string $permissionPrefix = 'hr.salary_components';

    protected static ?string $model = SalaryComponent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return SalaryComponentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalaryComponentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalaryComponents::route('/'),
            'create' => CreateSalaryComponent::route('/create'),
            'edit' => EditSalaryComponent::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}

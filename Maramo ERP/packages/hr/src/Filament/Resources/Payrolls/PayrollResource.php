<?php

namespace Classivo\Hr\Filament\Resources\Payrolls;

use BackedEnum;
use Classivo\Hr\Filament\Concerns\HrNavigation;
use Classivo\Hr\Filament\Resources\BaseResource as Resource;
use Classivo\Hr\Filament\Resources\Payrolls\Pages\ListPayrolls;
use Classivo\Hr\Filament\Resources\Payrolls\Pages\ViewPayroll;
use Classivo\Hr\Filament\Resources\Payrolls\Tables\PayrollsTable;
use Classivo\Hr\Models\Payroll;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * PayrollResource (spec §15 "Payroll") — the monthly salary list with per-row
 * View payslip / Pay actions and a link to the Generate Payroll page. Generation is
 * gated `payroll.generate`; marking paid is gated `payroll.pay`. Nav group
 * "Human Resource"; gated on the HR module.
 */
class PayrollResource extends Resource
{
    use HrNavigation;

    protected static string $navKey = 'payrolls';

    protected static string $permissionPrefix = 'hr.payrolls';

    protected static ?string $model = Payroll::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 79;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return PayrollsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrolls::route('/'),
            'view' => ViewPayroll::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}

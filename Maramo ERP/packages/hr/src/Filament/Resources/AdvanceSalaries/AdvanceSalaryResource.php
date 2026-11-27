<?php

namespace Classivo\Hr\Filament\Resources\AdvanceSalaries;

use BackedEnum;
use Classivo\Core\Tenancy\Scopes\BranchScope;
use Classivo\Hr\Filament\Concerns\HrNavigation;
use Classivo\Hr\Filament\Resources\AdvanceSalaries\Pages\CreateAdvanceSalary;
use Classivo\Hr\Filament\Resources\AdvanceSalaries\Pages\EditAdvanceSalary;
use Classivo\Hr\Filament\Resources\AdvanceSalaries\Pages\ListAdvanceSalaries;
use Classivo\Hr\Filament\Resources\AdvanceSalaries\Schemas\AdvanceSalaryForm;
use Classivo\Hr\Filament\Resources\AdvanceSalaries\Tables\AdvanceSalariesTable;
use Classivo\Hr\Filament\Resources\BaseResource as Resource;
use Classivo\Hr\Models\AdvanceSalary;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * AdvanceSalaryResource (spec §15 Payroll "Advance Salary") — staff advance requests
 * with Approve / Reject actions; an approved advance is auto-deducted from that
 * month's payroll on generation. Nav group "Human Resource"; gated on the HR module.
 */
class AdvanceSalaryResource extends Resource
{
    use HrNavigation;

    protected static string $navKey = 'advance_salaries';

    protected static string $permissionPrefix = 'hr.advance_salaries';

    protected static ?string $model = AdvanceSalary::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return AdvanceSalaryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdvanceSalariesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdvanceSalaries::route('/'),
            'create' => CreateAdvanceSalary::route('/create'),
            'edit' => EditAdvanceSalary::route('/{record}/edit'),
        ];
    }

    /**
     * The approval queue is SCHOOL-wide, not branch-scoped.
     *
     * An advance-salary request is raised against a staff member and stamped with
     * THAT member's branch — including the ones the mobile app files, which carry
     * the requesting teacher's branch. The panel, however, opens pinned to the
     * admin's default/selected branch, so a request from any OTHER campus was
     * silently filtered out of this list and could never be approved ("requested
     * from the app never appears in the admin panel"). Advance salary is a payroll
     * function that a school's HR clears centrally, so the branch scope is dropped
     * here; the tenant (school) scope stays, so cross-SCHOOL isolation is intact.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(BranchScope::class);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        // Same reason as getEloquentQuery(): the Approve/Reject/Edit pages must
        // resolve a request raised from any branch, or the action 404s.
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class, BranchScope::class]);
    }
}

<?php

namespace Classivo\Hr\Filament\Resources\Staff;

use BackedEnum;
use Classivo\Hr\Filament\Concerns\HrNavigation;
use Classivo\Hr\Filament\Resources\BaseResource as Resource;
use Classivo\Hr\Filament\Resources\Staff\Pages\CreateStaff;
use Classivo\Hr\Filament\Resources\Staff\Pages\EditStaff;
use Classivo\Hr\Filament\Resources\Staff\Pages\ListStaff;
use Classivo\Hr\Filament\Resources\Staff\Pages\ViewStaff;
use Classivo\Hr\Filament\Resources\Staff\Schemas\StaffForm;
use Classivo\Hr\Filament\Resources\Staff\Tables\StaffTable;
use Classivo\Hr\Models\Staff;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * StaffResource (spec §15 "Staff Directory") — the staff/employee directory with a
 * tabbed create/edit form (Personal / Contact / Payroll / Bank / Documents / Login),
 * a 360 view and an enable-login flow. Nav group "Human Resource"; gated on the HR
 * module. CSV import is a stub (header action notifies) — see phase notes.
 */
class StaffResource extends Resource
{
    use HrNavigation;

    protected static string $navKey = 'staff';

    protected static string $permissionPrefix = 'hr.staff';

    protected static ?string $model = Staff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'first_name';

    public static function form(Schema $schema): Schema
    {
        return StaffForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaffTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
            'create' => CreateStaff::route('/create'),
            'view' => ViewStaff::route('/{record}'),
            'edit' => EditStaff::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}

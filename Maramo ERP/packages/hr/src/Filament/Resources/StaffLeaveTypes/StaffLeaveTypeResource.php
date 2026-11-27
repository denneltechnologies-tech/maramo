<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveTypes;

use BackedEnum;
use Classivo\Hr\Filament\Concerns\HrNavigation;
use Classivo\Hr\Filament\Resources\BaseResource as Resource;
use Classivo\Hr\Filament\Resources\StaffLeaveTypes\Pages\CreateStaffLeaveType;
use Classivo\Hr\Filament\Resources\StaffLeaveTypes\Pages\EditStaffLeaveType;
use Classivo\Hr\Filament\Resources\StaffLeaveTypes\Pages\ListStaffLeaveTypes;
use Classivo\Hr\Filament\Resources\StaffLeaveTypes\Schemas\StaffLeaveTypeForm;
use Classivo\Hr\Filament\Resources\StaffLeaveTypes\Tables\StaffLeaveTypesTable;
use Classivo\Hr\Models\StaffLeaveType;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * StaffLeaveTypeResource (spec §15 Staff Leave "Leave Types") — the STAFF-leave
 * category master. Nav group "Human Resource"; gated on the HR module.
 */
class StaffLeaveTypeResource extends Resource
{
    use HrNavigation;

    protected static string $navKey = 'leave_types';

    protected static string $permissionPrefix = 'hr.leave_types';

    protected static ?string $model = StaffLeaveType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 76;

    public static function form(Schema $schema): Schema
    {
        return StaffLeaveTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaffLeaveTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffLeaveTypes::route('/'),
            'create' => CreateStaffLeaveType::route('/create'),
            'edit' => EditStaffLeaveType::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}

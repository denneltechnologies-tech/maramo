<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveAllocations;

use BackedEnum;
use Classivo\Hr\Filament\Concerns\HrNavigation;
use Classivo\Hr\Filament\Resources\BaseResource as Resource;
use Classivo\Hr\Filament\Resources\StaffLeaveAllocations\Pages\CreateStaffLeaveAllocation;
use Classivo\Hr\Filament\Resources\StaffLeaveAllocations\Pages\EditStaffLeaveAllocation;
use Classivo\Hr\Filament\Resources\StaffLeaveAllocations\Pages\ListStaffLeaveAllocations;
use Classivo\Hr\Filament\Resources\StaffLeaveAllocations\Schemas\StaffLeaveAllocationForm;
use Classivo\Hr\Filament\Resources\StaffLeaveAllocations\Tables\StaffLeaveAllocationsTable;
use Classivo\Hr\Models\StaffLeaveAllocation;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * StaffLeaveAllocationResource (spec §15 Staff Leave "Leave Allocation") — grants a
 * number of leave days of a type to a staff member for a session. Nav group
 * "Human Resource"; gated on the HR module.
 */
class StaffLeaveAllocationResource extends Resource
{
    use HrNavigation;

    protected static string $navKey = 'leave_allocations';

    protected static string $permissionPrefix = 'hr.leave_allocations';

    protected static ?string $model = StaffLeaveAllocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?int $navigationSort = 77;

    public static function form(Schema $schema): Schema
    {
        return StaffLeaveAllocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaffLeaveAllocationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffLeaveAllocations::route('/'),
            'create' => CreateStaffLeaveAllocation::route('/create'),
            'edit' => EditStaffLeaveAllocation::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}

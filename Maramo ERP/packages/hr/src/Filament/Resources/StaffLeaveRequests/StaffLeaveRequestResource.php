<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveRequests;

use BackedEnum;
use Classivo\Hr\Filament\Concerns\HrNavigation;
use Classivo\Hr\Filament\Resources\BaseResource as Resource;
use Classivo\Hr\Filament\Resources\StaffLeaveRequests\Pages\CreateStaffLeaveRequest;
use Classivo\Hr\Filament\Resources\StaffLeaveRequests\Pages\EditStaffLeaveRequest;
use Classivo\Hr\Filament\Resources\StaffLeaveRequests\Pages\ListStaffLeaveRequests;
use Classivo\Hr\Filament\Resources\StaffLeaveRequests\Schemas\StaffLeaveRequestForm;
use Classivo\Hr\Filament\Resources\StaffLeaveRequests\Tables\StaffLeaveRequestsTable;
use Classivo\Hr\Models\StaffLeaveRequest;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * StaffLeaveRequestResource (spec §15 Staff Leave "Approve Leave Requests") — staff
 * leave applications with Approve / Reject row + bulk actions (approved days count
 * against the allocation balance). Nav group "Human Resource"; gated on the HR module.
 */
class StaffLeaveRequestResource extends Resource
{
    use HrNavigation;

    protected static string $navKey = 'leave_requests';

    protected static string $permissionPrefix = 'hr.leave_requests';

    protected static ?string $model = StaffLeaveRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 78;

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::query()->where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function form(Schema $schema): Schema
    {
        return StaffLeaveRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaffLeaveRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffLeaveRequests::route('/'),
            'create' => CreateStaffLeaveRequest::route('/create'),
            'edit' => EditStaffLeaveRequest::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}

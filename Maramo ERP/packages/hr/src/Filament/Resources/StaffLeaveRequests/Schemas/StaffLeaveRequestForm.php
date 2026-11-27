<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveRequests\Schemas;

use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffLeaveRequest;
use Classivo\Hr\Models\StaffLeaveType;
use Classivo\Hr\Support\HrLabels;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Staff leave-request create/edit form (spec §15). Status transitions happen through
 * the Approve / Reject actions, so the form only captures the application itself.
 */
class StaffLeaveRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('hr::ui.leave_application'))
                    ->columns(2)
                    ->schema([
                        Select::make('staff_id')
                            ->label(__('hr::ui.staff'))
                            ->options(fn (): array => Staff::query()
                                ->where('status', 'active')
                                ->orderBy('first_name')
                                ->get()
                                ->mapWithKeys(fn (Staff $s): array => [$s->id => $s->full_name.' ('.$s->employee_id.')'])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('staff_leave_type_id')
                            ->label(__('hr::ui.leave_type'))
                            ->options(fn (): array => StaffLeaveType::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload(),
                        DatePicker::make('from_date')
                            ->label(__('hr::ui.from'))
                            ->required()
                            ->default(now()),
                        DatePicker::make('to_date')
                            ->label(__('hr::ui.to'))
                            ->required()
                            ->default(now())
                            ->afterOrEqual('from_date'),
                        Textarea::make('reason')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('hr::ui.review'))
                    ->columns(2)
                    ->visibleOn('edit')
                    ->schema([
                        Select::make('status')
                            ->options(HrLabels::enum(StaffLeaveRequest::STATUSES, 'staff_leave_request.statuses'))
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('hr::ui.use_the_approve_reject_actions')),
                        Textarea::make('review_remark')
                            ->label(__('hr::ui.review_remark'))
                            ->disabled()
                            ->dehydrated(false)
                            ->rows(2),
                    ]),
            ]);
    }
}

<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveAllocations\Schemas;

use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffLeaveType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Staff leave-allocation form (spec §15: staff, leave type, days, session). The session
 * is auto-stamped from the active session (BelongsToSession).
 */
class StaffLeaveAllocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('hr::ui.leave_allocation'))
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
                            ->preload()
                            ->required(),
                        TextInput::make('days_allocated')
                            ->label(__('hr::ui.days_allocated'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->default(0),
                        TextInput::make('note')
                            ->maxLength(255),
                    ]),
            ]);
    }
}

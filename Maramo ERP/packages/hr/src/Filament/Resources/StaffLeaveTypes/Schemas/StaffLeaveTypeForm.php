<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Staff leave-type form (spec §15: name, days allowed per year, is paid, status).
 */
class StaffLeaveTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('hr::ui.staff_leave_type'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->placeholder(__('hr::ui.e_g_casual_leave'))
                            ->required()
                            ->maxLength(100),
                        TextInput::make('days_allowed')
                            ->label(__('hr::ui.days_allowed_year'))
                            ->numeric()
                            ->minValue(0)
                            ->helperText(__('hr::ui.optional_annual_allowance')),
                        Toggle::make('is_paid')
                            ->label(__('hr::ui.paid_leave'))
                            ->default(true),
                        Toggle::make('status')
                            ->label(__('hr::ui.active'))
                            ->default(true),
                    ]),
            ]);
    }
}

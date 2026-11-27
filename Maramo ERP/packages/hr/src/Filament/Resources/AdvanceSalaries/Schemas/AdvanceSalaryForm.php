<?php

namespace Classivo\Hr\Filament\Resources\AdvanceSalaries\Schemas;

use Classivo\Hr\Models\Staff;
use Classivo\Hr\Support\Currency;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Advance-salary request form (spec §15: amount, reason, month). The month/year set
 * which payroll the approved advance is auto-deducted from.
 */
class AdvanceSalaryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('hr::ui.advance_request'))
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
                        TextInput::make('amount')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->prefix(Currency::symbol()),
                        Select::make('month')
                            ->options(__('hr::enums.months'))
                            ->default((int) now()->format('n'))
                            ->required()
                            ->native(false),
                        TextInput::make('year')
                            ->numeric()
                            ->default((int) now()->format('Y'))
                            ->required(),
                        Textarea::make('reason')
                            ->label(__('hr::ui.reason_from_the_applicant'))
                            ->rows(2)
                            ->columnSpanFull(),
                        // The approver's note. Separate from `reason` on purpose:
                        // one is why it was asked for, the other is what the
                        // decision said. The mobile decide endpoint writes here too.
                        Textarea::make('remark')
                            ->label(__('hr::ui.decision_remark'))
                            ->rows(2)
                            ->maxLength(2000)
                            ->helperText(__('hr::ui.recorded_with_the_approval_or'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

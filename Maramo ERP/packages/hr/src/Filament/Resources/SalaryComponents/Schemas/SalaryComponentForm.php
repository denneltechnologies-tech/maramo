<?php

namespace Classivo\Hr\Filament\Resources\SalaryComponents\Schemas;

use Classivo\Hr\Models\SalaryComponent;
use Classivo\Hr\Support\HrLabels;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Salary component create/edit form.
 *
 * The fields are ordered the way the decision is actually made: what is this
 * head called, is it money in or money out, how is its value arrived at, and
 * then the three flags that decide how the rest of payroll treats it.
 *
 * System heads (BASIC, LOP, PF_EMPLOYEE …) are looked up by code by the payroll
 * engine, so their code and calculation are locked here. Everything a school may
 * legitimately want to change about one — its display name, its order, whether
 * it counts as PF wages — stays editable.
 */
class SalaryComponentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('hr::ui.head'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label(__('hr::ui.code'))
                            ->placeholder(__('hr::ui.e_g_hra'))
                            ->helperText(__('hr::ui.uppercased_automatically_the_payroll_engine'))
                            ->required()
                            ->maxLength(50)
                            ->disabled(fn (?SalaryComponent $record): bool => (bool) $record?->is_system)
                            ->dehydrated(),
                        TextInput::make('name')
                            ->label(__('hr::ui.name'))
                            ->placeholder(__('hr::ui.e_g_house_rent_allowance'))
                            ->helperText(__('hr::ui.this_is_what_prints_on'))
                            ->required()
                            ->maxLength(150),
                        Select::make('type')
                            ->label(__('hr::ui.type'))
                            ->options(HrLabels::enum(SalaryComponent::TYPES, 'salary_component.types'))
                            ->default(SalaryComponent::TYPE_ALLOWANCE)
                            ->native(false)
                            ->required()
                            ->helperText(__('hr::ui.an_employer_contribution_is_the'))
                            ->disabled(fn (?SalaryComponent $record): bool => (bool) $record?->is_system)
                            ->dehydrated(),
                        Select::make('calculation')
                            ->label(__('hr::ui.calculated_as'))
                            ->options(HrLabels::enum(SalaryComponent::CALCULATIONS, 'salary_component.calculations'))
                            ->default('fixed')
                            ->native(false)
                            ->required()
                            ->live()
                            ->disabled(fn (?SalaryComponent $record): bool => (bool) $record?->is_system)
                            ->dehydrated(),
                        TextInput::make('display_order')
                            ->label(__('hr::ui.display_order'))
                            ->numeric()
                            ->default(100)
                            ->helperText(__('hr::ui.lower_numbers_print_first_on')),
                        Toggle::make('status')
                            ->label(__('hr::ui.active'))
                            ->default(true)
                            ->helperText(__('hr::ui.an_inactive_head_is_skipped')),
                    ]),

                Section::make(__('hr::ui.percentage'))
                    ->description(__('hr::ui.only_used_when_the_head'))
                    ->columns(2)
                    ->visible(fn (Get $get): bool => $get('calculation') === 'percentage')
                    ->schema([
                        TextInput::make('percentage')
                            ->label(__('hr::ui.rate'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1000)
                            ->suffix('%')
                            ->helperText(__('hr::ui.an_employee_s_own_structure')),
                        TextInput::make('base_codes')
                            ->label(__('hr::ui.percentage_of'))
                            ->placeholder(__('hr::ui.basic_da'))
                            ->helperText(__('hr::ui.comma_separated_head_codes_whose'))
                            // Stored as a JSON array; the field edits it as the
                            // comma-separated list a human would write.
                            ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state)
                            ->dehydrateStateUsing(fn ($state): array => collect(explode(',', (string) $state))
                                ->map(fn (string $code): string => strtoupper(trim($code)))
                                ->filter()
                                ->values()
                                ->all()),
                    ]),

                Section::make(__('hr::ui.statutory_binding'))
                    ->description(__('hr::ui.which_statutory_calculation_supplies_this'))
                    ->visible(fn (Get $get): bool => $get('calculation') === 'statutory')
                    ->schema([
                        Select::make('statutory_kind')
                            ->label(__('hr::ui.statutory_calculation'))
                            ->options(HrLabels::enum(SalaryComponent::STATUTORY_KINDS, 'salary_component.statutory_kinds'))
                            ->native(false)
                            ->disabled(fn (?SalaryComponent $record): bool => (bool) $record?->is_system)
                            ->dehydrated(),
                    ]),

                // ── THE HAND-WORKED STATUTORY HEAD ───────────────────────────
                // Every school that came off a spreadsheet arrives with fixed
                // deduction heads called things like "Provident Fund" — the
                // number somebody used to work out by hand. Switching the
                // statutory engine on then computed the SAME obligation as a
                // second line and both came out of the employee's pay. This is
                // the school's own statement that the two are the same money, so
                // the engine can drop the manual one and say that it did.
                Section::make(__('hr::ui.replaced_by_a_statutory_calculation'))
                    ->description(__('hr::ui.set_this_only_when_the'))
                    ->visible(fn (Get $get, ?SalaryComponent $record): bool => $get('type') === SalaryComponent::TYPE_DEDUCTION
                        && $get('calculation') === 'fixed'
                        && ! (bool) $record?->is_system)
                    ->schema([
                        Select::make('supersedes_statutory_kind')
                            ->label(__('hr::ui.this_head_is_really'))
                            ->options(HrLabels::enum(SalaryComponent::STATUTORY_KINDS, 'salary_component.statutory_kinds'))
                            ->native(false)
                            ->placeholder(__('hr::ui.nothing_it_is_its_own'))
                            ->helperText(__('hr::ui.filled_in_automatically_for_heads')),
                    ]),

                Section::make(__('hr::ui.how_payroll_treats_this_head'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('pf_wage')
                            ->label(__('hr::ui.counts_as_pf_wages'))
                            ->helperText(__('hr::ui.provident_fund_is_a_percentage')),
                        Toggle::make('esi_wage')
                            ->label(__('hr::ui.counts_as_esi_wages'))
                            ->helperText(__('hr::ui.esi_is_charged_on_essentially')),
                        Toggle::make('is_taxable')
                            ->label(__('hr::ui.taxable'))
                            ->default(true)
                            ->helperText(__('hr::ui.included_in_the_income_tax')),
                        Toggle::make('prorates_on_lop')
                            ->label(__('hr::ui.reduces_on_loss_of_pay'))
                            ->helperText(__('hr::ui.on_for_salary_heads_off')),
                        Toggle::make('emit_as_line')
                            ->label(__('hr::ui.print_a_payslip_line'))
                            ->default(true)
                            ->helperText(__('hr::ui.off_for_heads_already_carried'))
                            ->disabled(fn (?SalaryComponent $record): bool => (bool) $record?->is_system)
                            ->dehydrated(),
                        Textarea::make('description')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

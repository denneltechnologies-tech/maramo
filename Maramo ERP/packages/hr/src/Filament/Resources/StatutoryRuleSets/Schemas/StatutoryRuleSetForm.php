<?php

namespace Classivo\Hr\Filament\Resources\StatutoryRuleSets\Schemas;

use Classivo\Core\Models\Branch;
use Classivo\Hr\Models\StatutoryRule;
use Classivo\Hr\Models\StatutoryRuleSet;
use Classivo\Hr\Support\Currency;
use Classivo\Hr\Support\HrLabels;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Statutory rule set create/edit form.
 *
 * Two repeaters over the same child table, split by `row_type` because the two
 * shapes have nothing in common on screen: a PARAMETER is a named scalar (a
 * rate, a ceiling, an on/off flag) and a SLAB is a rung of a ladder (a band and
 * what is charged on it).
 *
 * The warning text on this form is not decoration. A number typed here changes
 * what every employee takes home, and the two levers most likely to be pulled by
 * accident — an `enabled` flag, and a slab ladder that has never been checked
 * against the current year's rules — are the two with a legal consequence
 * attached.
 */
class StatutoryRuleSetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('hr::ui.rule_set'))
                    ->description(__('hr::ui.one_version_of_the_statutory'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')
                            ->placeholder(__('hr::ui.e_g_statutory_rules_2026'))
                            ->required()
                            ->maxLength(150),
                        TextInput::make('financial_year')
                            ->label(__('hr::ui.financial_year'))
                            ->placeholder('2026-2027')
                            ->helperText(__('hr::ui.april_to_march'))
                            ->required()
                            ->maxLength(9),
                        Select::make('branch_id')
                            ->label(__('hr::ui.branch'))
                            ->placeholder(__('hr::ui.all_branches'))
                            ->options(fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->helperText(__('hr::ui.leave_blank_unless_a_campus')),
                        DatePicker::make('effective_from')
                            ->label(__('hr::ui.effective_from'))
                            ->default(fn (): string => now()->startOfYear()->addMonths(3)->toDateString())
                            ->required(),
                        DatePicker::make('effective_to')
                            ->label(__('hr::ui.effective_to'))
                            ->helperText(__('hr::ui.leave_blank_while_this_set')),
                        TextInput::make('state_code')
                            ->label(__('hr::ui.state_code'))
                            ->placeholder(__('hr::ui.ka'))
                            ->helperText(__('hr::ui.professional_tax_is_a_state'))
                            ->maxLength(10),
                        Toggle::make('status')
                            ->label(__('hr::ui.active'))
                            ->default(true),
                        Textarea::make('notes')
                            ->rows(2)
                            ->columnSpan(2),
                    ]),

                Section::make(__('hr::ui.rates_parameters'))
                    ->description(__('hr::ui.every_kind_ships_with_its'))
                    ->schema([
                        Repeater::make('params')
                            ->relationship('params')
                            ->hiddenLabel()
                            ->columns(4)
                            ->itemLabel(fn (array $state): ?string => isset($state['kind'], $state['param_key'])
                                ? ($state['kind'].'.'.$state['param_key'].' = '.($state['param_value'] ?? ''))
                                : null)
                            ->collapsed()
                            ->addActionLabel(__('hr::messages.form.add_parameter'))
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                $data['row_type'] = StatutoryRule::ROW_TYPE_PARAM;

                                return $data;
                            })
                            ->schema([
                                Select::make('kind')
                                    ->options(HrLabels::enum(StatutoryRuleSet::KINDS, 'statutory_rule_set.kinds'))
                                    ->native(false)
                                    ->required(),
                                TextInput::make('param_key')
                                    ->label(__('hr::ui.key'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('param_value')
                                    ->label(__('hr::ui.value'))
                                    ->maxLength(255),
                                TextInput::make('note')
                                    ->label(__('hr::ui.note'))
                                    ->maxLength(255),
                            ]),
                    ]),

                Section::make(__('hr::ui.slabs'))
                    ->description(__('hr::messages.form.ladders_note'))
                    ->schema([
                        Repeater::make('slabs')
                            ->relationship('slabs')
                            ->hiddenLabel()
                            ->columns(4)
                            ->itemLabel(fn (array $state): ?string => isset($state['kind'])
                                ? trim(($state['kind'] ?? '').' '
                                    .number_format((float) ($state['from_amount'] ?? 0), 0).'–'
                                    .($state['to_amount'] !== null && $state['to_amount'] !== '' ? number_format((float) $state['to_amount'], 0) : 'above'))
                                : null)
                            ->collapsed()
                            ->orderColumn('sequence')
                            ->addActionLabel(__('hr::messages.form.add_slab'))
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                $data['row_type'] = StatutoryRule::ROW_TYPE_SLAB;

                                return $data;
                            })
                            ->schema([
                                Select::make('kind')
                                    ->options([
                                        'professional_tax' => HrLabels::enum(StatutoryRuleSet::KINDS, 'statutory_rule_set.kinds')['professional_tax'],
                                        'income_tax' => HrLabels::enum(StatutoryRuleSet::KINDS, 'statutory_rule_set.kinds')['income_tax'],
                                    ])
                                    ->native(false)
                                    ->live()
                                    ->required(),
                                TextInput::make('variant')
                                    ->label(__('hr::ui.variant'))
                                    ->placeholder(__('hr::ui.new_old'))
                                    ->helperText(fn (Get $get): string => $get('kind') === 'income_tax'
                                        ? __('hr::messages.form.regime_help')
                                        : __('hr::messages.form.variant_help'))
                                    ->maxLength(255),
                                TextInput::make('from_amount')
                                    ->label(__('hr::ui.over'))
                                    ->numeric()
                                    ->default(0)
                                    ->helperText(__('hr::ui.exclusive')),
                                TextInput::make('to_amount')
                                    ->label(__('hr::ui.up_to'))
                                    ->numeric()
                                    ->helperText(__('hr::ui.inclusive_blank_on_the_top')),
                                TextInput::make('rate_percent')
                                    ->label(__('hr::ui.rate'))
                                    ->numeric()
                                    ->suffix('%')
                                    ->helperText(fn (Get $get): string => $get('kind') === 'income_tax'
                                        ? __('hr::messages.form.band_help')
                                        : __('hr::messages.form.flat_help')),
                                TextInput::make('fixed_amount')
                                    ->label(__('hr::ui.flat_amount'))
                                    ->numeric()
                                    ->prefix(Currency::symbol())
                                    ->helperText(__('hr::ui.professional_tax_slabs_are_usually')),
                                Select::make('month')
                                    ->label(__('hr::ui.only_in_month'))
                                    ->options(__('hr::enums.months'))
                                    ->native(false)
                                    ->helperText(__('hr::ui.for_a_state_that_levies')),
                                TextInput::make('note')
                                    ->label(__('hr::ui.note'))
                                    ->maxLength(255),
                            ]),
                    ]),
            ]);
    }
}

<?php

namespace Classivo\Hr\Filament\Resources\StatutoryRuleSets\Tables;

use Classivo\Hr\Models\StatutoryRuleSet;
use Classivo\Hr\Support\HrLabels;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * Statutory rule sets list.
 *
 * The column that matters is "Switched on": it answers, at a glance, the only
 * dangerous question this screen holds — which statutory deductions are actually
 * coming out of people's pay under this set. Everything ships with that column
 * reading "none".
 */
class StatutoryRuleSetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['rules', 'branch']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('financial_year')
                    ->label(__('hr::ui.fy'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('branch.name')
                    ->label(__('hr::ui.branch'))
                    ->placeholder(__('hr::ui.all_branches')),
                TextColumn::make('state_code')
                    ->label(__('hr::ui.state'))
                    ->placeholder('—'),
                TextColumn::make('effective_from')
                    ->label(__('hr::ui.from'))
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('effective_to')
                    ->label(__('hr::ui.to'))
                    ->date('d M Y')
                    ->placeholder(__('hr::ui.current')),
                TextColumn::make('enabled_kinds')
                    ->label(__('hr::ui.switched_on'))
                    ->badge()
                    ->color('warning')
                    ->placeholder(__('hr::ui.none'))
                    ->state(function (StatutoryRuleSet $record): array {
                        $on = [];

                        foreach (HrLabels::enum(StatutoryRuleSet::KINDS, 'statutory_rule_set.kinds') as $kind => $label) {
                            if ($record->enabled($kind)) {
                                $on[] = $label;
                            }
                        }

                        return $on;
                    }),
                TextColumn::make('rules_count')
                    ->label(__('hr::ui.rules'))
                    ->badge()
                    ->color('gray')
                    ->counts('rules')
                    ->toggleable(),
                ToggleColumn::make('status')
                    ->label(__('hr::ui.active')),
            ])
            ->filters([
                TernaryFilter::make('status')->label(__('hr::ui.active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('effective_from', 'desc');
    }
}

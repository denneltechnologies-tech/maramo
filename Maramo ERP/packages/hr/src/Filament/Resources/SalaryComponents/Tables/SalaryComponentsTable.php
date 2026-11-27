<?php

namespace Classivo\Hr\Filament\Resources\SalaryComponents\Tables;

use Classivo\Hr\Models\SalaryComponent;
use Classivo\Hr\Support\HrLabels;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * Salary components list: Code, Name, Type, Calculation, wage-base flags, how
 * many employees draw it, Status.
 *
 * The "Staff" count is the column that pays for this screen: it is the first
 * time anybody could see that thirty people are on "HRA" and two are on
 * "H.R.A." — the duplication the free-text structure made invisible.
 */
class SalaryComponentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (SalaryComponent $record): ?string => $record->is_system ? __('hr::messages.payroll.system_head') : null),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => HrLabels::enum(SalaryComponent::TYPES, 'salary_component.types')[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        SalaryComponent::TYPE_ALLOWANCE => 'success',
                        SalaryComponent::TYPE_DEDUCTION => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('calculation')
                    ->label(__('hr::ui.calculated_as'))
                    ->formatStateUsing(fn (?string $state): string => HrLabels::enum(SalaryComponent::CALCULATIONS, 'salary_component.calculations')[$state] ?? (string) $state)
                    ->description(fn (SalaryComponent $record): ?string => $record->calculation === 'percentage'
                        ? rtrim(rtrim(number_format((float) $record->percentage, 2), '0'), '.').'% of '
                            .(implode(' + ', $record->baseCodes()) ?: SalaryComponent::CODE_BASIC)
                        : null),
                IconColumn::make('pf_wage')
                    ->label(__('hr::ui.pf_wages'))
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('esi_wage')
                    ->label(__('hr::ui.esi_wages'))
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('prorates_on_lop')
                    ->label(__('hr::ui.lop'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('structures_count')
                    ->label(__('hr::ui.staff'))
                    ->badge()
                    ->color('info')
                    ->counts('structures'),
                TextColumn::make('display_order')
                    ->label(__('hr::ui.order'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('status')
                    ->label(__('hr::ui.active')),
            ])
            ->filters([
                SelectFilter::make('type')->options(HrLabels::enum(SalaryComponent::TYPES, 'salary_component.types')),
                SelectFilter::make('calculation')->options(HrLabels::enum(SalaryComponent::CALCULATIONS, 'salary_component.calculations')),
                TernaryFilter::make('status')->label(__('hr::ui.active')),
                TernaryFilter::make('is_system')->label(__('hr::ui.system_head')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                // A system head is looked up by code by the payroll engine, so
                // deleting one stops a deduction being calculated at all rather
                // than producing a visible error. Deactivating it is the
                // supported way to stop using one.
                DeleteAction::make()
                    ->visible(fn (SalaryComponent $record): bool => ! $record->is_system),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('display_order');
    }
}

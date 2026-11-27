<?php

namespace Classivo\Hr\Filament\Resources\Staff\Tables;

use Classivo\Hr\Filament\Resources\Staff\Actions\GuardedStaffDeleteActions;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffDocument;
use Classivo\Hr\Support\HrAuth;
use Classivo\Hr\Support\HrLabels;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff directory list (spec §15): Photo, Staff ID, Name, Role, Department,
 * Designation, Phone, Email, Joining date, Status. Filters by Department / Designation
 * / Status / Gender.
 *
 * Plus the document-expiry chase column: how many of this member's documents lapse
 * inside {@see StaffDocument::ALERT_WINDOW_DAYS} days (or already have). Same reading
 * as the transport vehicle register and the mobile Documents tab — see
 * {@see StaffDocument::scopeExpiringWithin()}.
 */
class StaffTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(array_filter(['department', 'designation', HrAuth::roleModel() ? 'role' : null]))
                ->withCount(['documents as expiring_documents_count' => fn (Builder $q): Builder => $q->expiringWithin()]))
            ->columns([
                SpatieMediaLibraryImageColumn::make('photo')
                    ->collection('photo')
                    ->circular()
                    ->label(__('hr::ui.photo'))
                    ->toggleable(),
                TextColumn::make('employee_id')
                    ->label(__('hr::ui.staff_id'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('full_name')
                    ->label(__('hr::ui.name'))
                    ->searchable(['first_name', 'last_name'])
                    // full_name is an accessor — bare sortable() 500s on header
                    // click (Phase 2 CRUD-loop finding).
                    ->sortable(['first_name']),
                TextColumn::make('role.name')
                    ->label(__('hr::ui.role'))
                    ->visible(fn (): bool => HrAuth::roleModel() !== null)
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),
                TextColumn::make('department.name')->label(__('hr::ui.department'))->placeholder('—')->toggleable(),
                TextColumn::make('designation.name')->label(__('hr::ui.designation'))->placeholder('—')->toggleable(),
                TextColumn::make('phone')->placeholder('—')->toggleable(),
                TextColumn::make('email')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('joining_date')->label(__('hr::ui.joining'))->date()->sortable(),
                TextColumn::make('expiring_documents_count')
                    ->label(__('hr::ui.docs_expiring'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? (string) $state : '—')
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray')
                    ->tooltip(__('hr::messages.staff.expiring_docs', ['days' => StaffDocument::ALERT_WINDOW_DAYS]))
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => HrLabels::enum(Staff::STATUSES, 'staff.statuses')[$state] ?? ucfirst((string) $state))
                    ->color(fn (?string $state): string => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('department_id')
                    ->label(__('hr::ui.department'))
                    ->relationship('department', 'name'),
                SelectFilter::make('designation_id')
                    ->label(__('hr::ui.designation'))
                    ->relationship('designation', 'name'),
                SelectFilter::make('status')->options(HrLabels::enum(Staff::STATUSES, 'staff.statuses')),
                SelectFilter::make('gender')->options(HrLabels::enum(Staff::GENDERS, 'staff.genders')),
                Filter::make('expiring_documents')
                    ->label(__('hr::ui.documents_expiring_soon'))
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'documents',
                        fn (Builder $documents): Builder => $documents->expiringWithin(),
                    ))
                    ->toggle(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    // Guarded: payrolls and advance salaries cascade off
                    // staff_id, so a bare force delete destroys paid payslips
                    // with no events, no log and nothing to restore.
                    GuardedStaffDeleteActions::bulk(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Action::make('import')
                    ->label(__('hr::ui.import_csv'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->color('gray')
                    ->action(fn () => Notification::make()
                        ->info()
                        ->title(__('hr::ui.import_is_a_stub'))
                        ->body(__('hr::ui.staff_csv_import_is_not'))
                        ->send()),
            ])
            ->defaultSort('employee_id');
    }
}

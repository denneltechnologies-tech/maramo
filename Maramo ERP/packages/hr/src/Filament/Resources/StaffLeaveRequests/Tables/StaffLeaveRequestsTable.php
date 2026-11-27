<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveRequests\Tables;

use Classivo\Hr\Models\StaffLeaveRequest;
use Classivo\Hr\Support\HrLabels;
use Classivo\Hr\Support\HrPermissions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Staff leave-requests list (spec §15 "Approve Leave Requests"): Staff, Type, Applied,
 * From–To, Days, Status, Reviewed by. Approve / Reject row + bulk actions.
 */
class StaffLeaveRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['staff', 'leaveType', 'approvedBy']))
            ->columns([
                TextColumn::make('staff.full_name')
                    ->label(__('hr::ui.staff'))
                    ->searchable(['first_name', 'last_name'])
                    // full_name is an accessor — sort by a real column (bare
                    // sortable() 500s on header click once rows exist; Phase 2
                    // CRUD-loop finding, latent here because the table was empty).
                    ->sortable(['first_name'])
                    ->weight('medium'),
                TextColumn::make('leaveType.name')
                    ->label(__('hr::ui.type'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label(__('hr::ui.applied'))
                    ->date()
                    ->sortable(),
                TextColumn::make('from_date')->label(__('hr::ui.from'))->date()->sortable(),
                TextColumn::make('to_date')->label(__('hr::ui.to'))->date(),
                TextColumn::make('days')
                    ->label(__('hr::ui.days'))
                    ->state(fn (StaffLeaveRequest $record): int => $record->days)
                    ->badge()
                    ->color('info'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => HrLabels::enum(StaffLeaveRequest::STATUSES, 'staff_leave_request.statuses')[$state] ?? ucfirst((string) $state))
                    ->color(fn (?string $state): string => StaffLeaveRequest::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('approvedBy.name')
                    ->label(__('hr::ui.reviewed_by'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(HrLabels::enum(StaffLeaveRequest::STATUSES, 'staff_leave_request.statuses')),
                SelectFilter::make('staff_leave_type_id')
                    ->label(__('hr::ui.leave_type'))
                    ->relationship('leaveType', 'name'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->authorize(fn (): bool => HrPermissions::can(HrPermissions::LEAVE_REQUESTS_APPROVE))
                    ->label(__('hr::ui.approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (StaffLeaveRequest $record): bool => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (StaffLeaveRequest $record): void {
                        $record->approve(auth()->id());

                        Notification::make()->success()->title(__('hr::ui.leave_approved'))->send();
                    }),
                Action::make('reject')
                    ->authorize(fn (): bool => HrPermissions::can(HrPermissions::LEAVE_REQUESTS_APPROVE))
                    ->label(__('hr::ui.reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (StaffLeaveRequest $record): bool => $record->status === 'pending')
                    ->schema([
                        Textarea::make('review_remark')
                            ->label(__('hr::ui.reason_for_rejection'))
                            ->rows(2),
                    ])
                    ->action(function (StaffLeaveRequest $record, array $data): void {
                        $record->reject(auth()->id(), $data['review_remark'] ?? null);

                        Notification::make()->warning()->title(__('hr::ui.leave_rejected'))->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approveSelected')
                        ->authorize(fn (): bool => HrPermissions::can(HrPermissions::LEAVE_REQUESTS_APPROVE))
                        ->label(__('hr::ui.approve_selected'))
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $count = 0;

                            foreach ($records->where('status', 'pending') as $record) {
                                $record->approve(auth()->id());
                                $count++;
                            }

                            Notification::make()->success()->title(__('hr::messages.attendance.approved_leaves', ['count' => $count]))->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

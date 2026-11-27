<?php

namespace Classivo\Hr\Filament\Resources\StaffLeaveRequests\Pages;

use Classivo\Hr\Filament\Resources\StaffLeaveRequests\StaffLeaveRequestResource;
use Classivo\Hr\Models\StaffLeaveRequest;
use Classivo\Hr\Support\HrPermissions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditStaffLeaveRequest extends EditRecord
{
    protected static string $resource = StaffLeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
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

                    $this->refreshFormData(['status', 'review_remark']);
                }),
            Action::make('reject')
                ->authorize(fn (): bool => HrPermissions::can(HrPermissions::LEAVE_REQUESTS_APPROVE))
                ->label(__('hr::ui.reject'))
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (StaffLeaveRequest $record): bool => $record->status === 'pending')
                ->schema([
                    Textarea::make('review_remark')->label(__('hr::ui.reason_for_rejection'))->rows(2),
                ])
                ->action(function (StaffLeaveRequest $record, array $data): void {
                    $record->reject(auth()->id(), $data['review_remark'] ?? null);

                    Notification::make()->warning()->title(__('hr::ui.leave_rejected'))->send();

                    $this->refreshFormData(['status', 'review_remark']);
                }),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}

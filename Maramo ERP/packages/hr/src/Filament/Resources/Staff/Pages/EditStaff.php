<?php

namespace Classivo\Hr\Filament\Resources\Staff\Pages;

use Classivo\Hr\Filament\Resources\Staff\Actions\DocumentDownloadActions;
use Classivo\Hr\Filament\Resources\Staff\Actions\GuardedStaffDeleteActions;
use Classivo\Hr\Filament\Resources\Staff\Schemas\StaffForm;
use Classivo\Hr\Filament\Resources\Staff\StaffResource;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Support\HrPermissions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DocumentDownloadActions::make($this->record),
            static::retireLegacySalaryAction(),
            DeleteAction::make()
                ->modalDescription(__('hr::messages.staff.archive_modal')),
            // Never a bare ForceDeleteAction: payrolls and advance salaries
            // cascade off staff_id, so an unguarded purge destroys paid payslips.
            GuardedStaffDeleteActions::record(),
            RestoreAction::make(),
        ];
    }

    /**
     * "Retire the free-text salary" — empty the legacy JSON columns, but only
     * once every entry in them has genuinely been replaced.
     *
     * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
     * The two legacy repeaters on the Payroll tab go READ-ONLY the moment an
     * employee has any salary-structure rows, because from that point an edit
     * there is either ignored or silently retroactive. Read-only with no way out
     * would be its own trap: the stale rows would sit on the record forever,
     * still visible, still ready to come back to life the day somebody renames a
     * head — which is exactly the accident that paid House Rent Allowance twice.
     * This is the one safe transition, made deliberately and once.
     *
     * ── AND WHY IT REFUSES ───────────────────────────────────────────────────
     * It clears the columns only when NOTHING in them is still being paid. An
     * entry with no structure head behind it is real money the employee is owed;
     * deleting it would cut their salary without anybody deciding to. In that
     * case the action names the entries and stops, and the fix is to add the
     * missing dated head first.
     */
    protected static function retireLegacySalaryAction(): Action
    {
        return Action::make('retireLegacySalary')
            ->label(__('hr::ui.retire_the_free_text_salary'))
            ->icon(Heroicon::OutlinedArchiveBoxXMark)
            ->color('gray')
            ->visible(fn (Staff $record): bool => StaffForm::isMigrated($record)
                && ((array) ($record->allowances ?? []) !== [] || (array) ($record->deductions ?? []) !== []))
            ->requiresConfirmation()
            ->modalHeading(__('hr::ui.clear_the_old_free_text'))
            ->modalDescription(__('hr::messages.staff.retire_modal'))
            ->action(function (Staff $record): void {
                $live = StaffForm::liveLegacyNames($record);

                if ($live !== []) {
                    Notification::make()
                        ->danger()
                        ->persistent()
                        ->title(__('hr::ui.nothing_was_cleared_some_free'))
                        ->body(trans_choice('hr::messages.staff.retire_refused', count($live), ['names' => implode(', ', $live)]))
                        ->send();

                    return;
                }

                $cleared = count((array) ($record->allowances ?? [])) + count((array) ($record->deductions ?? []));

                $record->forceFill(['allowances' => [], 'deductions' => []])->save();

                Notification::make()
                    ->success()
                    ->title(__('hr::ui.free_text_salary_retired'))
                    ->body(trans_choice('hr::messages.staff.retired_body', $cleared, ['count' => $cleared]))
                    ->send();
            });
    }

    /**
     * Reflect the Login tab's current state so the toggle shows whether the staff
     * has an ACTIVE linked login (a deactivated account reads as "login disabled").
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['enable_login'] = $this->record->user_id !== null
            && (bool) ($this->record->user?->is_active);

        return $data;
    }

    /**
     * Honour the Login-tab "enable login" toggle on save (spec §15): enabling
     * provisions/reactivates the linked user + role; disabling deactivates the
     * linked login (kept linked so re-enabling is seamless) so it can no longer
     * authenticate — no orphaned live account.
     */
    protected function afterSave(): void
    {
        if (! HrPermissions::can(HrPermissions::STAFF_MANAGE_LOGIN)) {
            return;
        }

        if (! empty($this->data['enable_login'])) {
            try {
                $this->record->provisionLogin($this->data['login_password'] ?? null, $this->record->role_id);
            } catch (\DomainException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }

            return;
        }

        if ($this->record->user && $this->record->user->is_active) {
            $this->record->user->forceFill(['is_active' => false])->saveQuietly();
            // Disabling the login must also revoke live API tokens (incident #3).
            if (method_exists($this->record->user, 'revokeApiTokens')) {
                $this->record->user->revokeApiTokens();
            }
        }
    }
}

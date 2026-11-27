<?php

namespace Classivo\Hr\Filament\Resources\Staff\Pages;

use Classivo\Hr\Filament\Resources\Staff\StaffResource;
use Classivo\Hr\Support\HrPermissions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    /**
     * Honour the Login-tab "enable login" toggle (spec §15): provision a linked user
     * account with the staff's role after the staff record is created.
     */
    protected function afterCreate(): void
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
        }
    }
}

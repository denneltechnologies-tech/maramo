<?php

namespace Classivo\Hr\Filament\Resources\Staff\Pages;

use Classivo\Hr\Filament\Resources\Staff\Actions\DocumentDownloadActions;
use Classivo\Hr\Filament\Resources\Staff\StaffResource;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Support\Currency;
use Classivo\Hr\Support\HrAuth;
use Classivo\Hr\Support\HrLabels;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Staff 360 view (spec §15 "View Profile"). The staff details across identity,
 * contact, payroll and bank, plus login status and leave/payroll counts.
 */
class ViewStaff extends ViewRecord
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DocumentDownloadActions::make($this->record),
            EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('hr::ui.identity'))
                ->columns(3)
                ->schema([
                    SpatieMediaLibraryImageEntry::make('photo')
                        ->collection('photo')
                        ->circular()
                        ->label(__('hr::ui.photo')),
                    TextEntry::make('employee_id')->label(__('hr::ui.staff_id'))->weight('bold'),
                    TextEntry::make('full_name')->label(__('hr::ui.name')),
                    TextEntry::make('role.name')->label(__('hr::ui.role'))->visible(fn (): bool => HrAuth::roleModel() !== null)->badge()->color('info')->placeholder('—'),
                    TextEntry::make('department.name')->label(__('hr::ui.department'))->placeholder('—'),
                    TextEntry::make('designation.name')->label(__('hr::ui.designation'))->placeholder('—'),
                    TextEntry::make('gender')
                        ->formatStateUsing(fn (?string $state): string => HrLabels::enum(Staff::GENDERS, 'staff.genders')[$state] ?? '—'),
                    TextEntry::make('date_of_birth')->date()->placeholder('—'),
                    TextEntry::make('joining_date')->date(),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => HrLabels::enum(Staff::STATUSES, 'staff.statuses')[$state] ?? ucfirst((string) $state))
                        ->color(fn (?string $state): string => $state === 'active' ? 'success' : 'gray'),
                ]),

            Section::make(__('hr::ui.contact'))
                ->columns(3)
                ->schema([
                    TextEntry::make('phone')->placeholder('—'),
                    TextEntry::make('emergency_contact')->label(__('hr::ui.emergency_contact'))->placeholder('—'),
                    TextEntry::make('email')->placeholder('—'),
                    TextEntry::make('current_address')->placeholder('—')->columnSpan(3),
                    TextEntry::make('qualification')->placeholder('—')->columnSpan(3),
                ]),

            Section::make(__('hr::ui.payroll_bank'))
                ->columns(3)
                ->schema([
                    TextEntry::make('basic_salary')->label(__('hr::ui.basic_salary'))->money(Currency::code()),
                    TextEntry::make('contract_type')
                        ->label(__('hr::ui.contract'))
                        ->formatStateUsing(fn (?string $state): string => HrLabels::enum(Staff::CONTRACT_TYPES, 'staff.contract_types')[$state] ?? '—'),
                    TextEntry::make('epf_no')->label(__('hr::ui.epf_no'))->placeholder('—'),
                    TextEntry::make('bank_name')->placeholder('—'),
                    TextEntry::make('bank_account_no')->label(__('hr::ui.account_no'))->placeholder('—'),
                    TextEntry::make('bank_ifsc')->label(__('hr::ui.ifsc'))->placeholder('—'),
                ]),

            Section::make(__('hr::ui.account_activity'))
                ->columns(3)
                ->schema([
                    TextEntry::make('login_status')
                        ->label(__('hr::ui.login'))
                        ->badge()
                        ->state(fn (Staff $record): string => $record->user_id ? 'Enabled' : 'None')
                        ->color(fn (Staff $record): string => $record->user_id ? 'success' : 'gray'),
                    TextEntry::make('payrolls_count')
                        ->label(__('hr::ui.payrolls'))
                        ->state(fn (Staff $record): int => $record->payrolls()->count()),
                    TextEntry::make('leave_requests_count')
                        ->label(__('hr::ui.leave_requests'))
                        ->state(fn (Staff $record): int => $record->leaveRequests()->count()),
                ]),
        ]);
    }
}

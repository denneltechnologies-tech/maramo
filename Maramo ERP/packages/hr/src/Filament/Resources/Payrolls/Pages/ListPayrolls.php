<?php

namespace Classivo\Hr\Filament\Resources\Payrolls\Pages;

use Classivo\Hr\Filament\Pages\GeneratePayroll;
use Classivo\Hr\Filament\Pages\SalaryDisbursement;
use Classivo\Hr\Filament\Resources\Payrolls\PayrollResource;
use Classivo\Hr\Support\HrPermissions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPayrolls extends ListRecords
{
    protected static string $resource = PayrollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label(__('hr::ui.generate_payroll'))
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->visible(fn (): bool => HrPermissions::can(HrPermissions::PAYROLL_GENERATE))
                ->url(GeneratePayroll::getUrl()),
            // The disbursement page is reached from here rather than from the
            // sidebar for the same reason Generate Payroll is: it acts on a
            // period you have just been looking at, not on nothing.
            Action::make('disburse')
                ->label(__('hr::ui.bank_transfer_file'))
                ->icon(Heroicon::OutlinedBuildingLibrary)
                ->color('gray')
                ->visible(fn (): bool => SalaryDisbursement::canAccess())
                ->url(SalaryDisbursement::getUrl()),
        ];
    }
}

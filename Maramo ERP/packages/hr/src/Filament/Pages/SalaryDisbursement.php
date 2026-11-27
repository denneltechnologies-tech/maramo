<?php

namespace Classivo\Hr\Filament\Pages;

use BackedEnum;
use Classivo\Hr\Filament\Concerns\HrPageNavigation;
use Classivo\Hr\Filament\Pages\BasePage as Page;
use Classivo\Hr\Models\Department;
use Classivo\Hr\Services\Payroll\SalaryDisbursementService;
use Classivo\Hr\Support\HrPermissions;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Salary Disbursement (spec §15 Payroll) — the bank transfer file for a month,
 * and the pre-flight that has to pass before anybody uploads it.
 *
 * The staff bank columns have been captured since the module shipped and nothing
 * has ever read them — or checked them. A missing IFSC is not discovered when it
 * is typed; it is discovered when a bank rejects a batch on payday, or accepts
 * thirty-seven transfers and silently drops one. This page is where it is
 * discovered instead.
 *
 * Reached from the Payroll list's "Bank transfer file" header action; not a
 * standalone nav item. Gated on the HR module and on `payroll.pay` — building
 * the file that moves the money is the same decision as marking it paid.
 *
 * @property-read Schema $form
 */
class SalaryDisbursement extends Page implements HasForms
{
    use HrPageNavigation;

    protected static string $navKey = 'salary_disbursement';

    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected string $view = 'hr::pages.salary-disbursement';

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    /** Reached from the Payroll list; not a standalone nav item. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return HrPermissions::can(HrPermissions::PAYROLL_DISBURSE);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'department_id' => null,
            'month' => (int) now()->format('n'),
            'year' => (int) now()->format('Y'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make(__('hr::ui.disbursement_period'))
                    ->columns(3)
                    ->schema([
                        Select::make('department_id')
                            ->label(__('hr::ui.department_optional'))
                            ->options(fn (): array => Department::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->live(),
                        Select::make('month')
                            ->options(__('hr::enums.months'))
                            ->default((int) now()->format('n'))
                            ->required()
                            ->live(),
                        Select::make('year')
                            ->options(fn (): array => collect(range((int) now()->format('Y') - 2, (int) now()->format('Y') + 1))
                                ->mapWithKeys(fn (int $y): array => [$y => (string) $y])
                                ->all())
                            ->default((int) now()->format('Y'))
                            ->required()
                            ->live(),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * The pre-flight for the selected period.
     *
     * A plain array-returning public method, deliberately: the blade renders it,
     * a test can assert on it, and neither has to drive Livewire to do so.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->data ?? [];

        return app(SalaryDisbursementService::class)->preflight(
            (int) ($data['month'] ?? now()->format('n')),
            (int) ($data['year'] ?? now()->format('Y')),
            ($id = (int) ($data['department_id'] ?? 0)) > 0 ? $id : null,
        );
    }

    /**
     * Download the transfer file.
     *
     * Rows with a blocking problem are not in it. Refusing to produce a file at
     * all when one row is broken would be worse — it would hold up thirty-seven
     * correct salaries for one missing IFSC — so the file is produced, and the
     * notification says exactly how many people were left out of it.
     */
    public function download(): ?StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $report = $this->report();

        if ($report['payable'] === 0) {
            Notification::make()
                ->warning()
                ->title(__('hr::ui.nothing_to_disburse'))
                ->body(__('hr::ui.no_generated_payroll_for_this'))
                ->send();

            return null;
        }

        if ($report['blocked'] > 0) {
            Notification::make()
                ->warning()
                ->title(__('hr::messages.payroll.left_out', ['count' => $report['blocked']]))
                ->body(__('hr::ui.fix_the_blocking_problems_listed'))
                ->send();
        }

        $data = $this->data ?? [];

        return app(SalaryDisbursementService::class)->download(
            (int) $data['month'],
            (int) $data['year'],
            ($id = (int) ($data['department_id'] ?? 0)) > 0 ? $id : null,
        );
    }
}

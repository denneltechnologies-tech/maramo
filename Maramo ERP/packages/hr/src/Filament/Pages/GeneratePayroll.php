<?php

namespace Classivo\Hr\Filament\Pages;

use BackedEnum;
use Classivo\Hr\Filament\Concerns\HrPageNavigation;
use Classivo\Hr\Filament\Pages\BasePage as Page;
use Classivo\Hr\Models\Department;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Support\HrLabels;
use Classivo\Hr\Support\HrPermissions;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Generate Payroll (spec §15 Payroll "Generate Payroll for Selected") — a custom page.
 *
 * Pick a Month + Year (+ optional Department) and Generate: creates/updates one
 * payroll per active staff member for that period via {@see PayrollService} — the
 * effective-dated salary structure, loss of pay from attendance, computed statutory
 * deductions and advance recovery. Idempotent; never overwrites a PAID row and never
 * a LOCKED one. This page is NOT registered in navigation (reached from the Payroll
 * list's "Generate Payroll" header action) and is gated by the `payroll.generate`
 * ability.
 *
 * ── WHY THE RESULT NOTIFICATION IS LONGER THAN IT WAS ────────────────────────
 * It used to say "Generated payroll for 38 staff." whether it had written 38 rows
 * or 6 — the rows it refused to touch were invisible. An operator regenerating a
 * month cannot tell those two runs apart, and the difference is the whole question
 * they are asking. {@see PayrollService::generateBatch()} reports what it actually
 * did, and this page repeats it back.
 *
 * @property-read Schema $form
 */
class GeneratePayroll extends Page implements HasForms
{
    use HrPageNavigation;

    protected static string $navKey = 'generate_payroll';

    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected string $view = 'hr::pages.generate-payroll';

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    /** Reached from the Payroll list; not a standalone nav item. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return HrPermissions::can(HrPermissions::PAYROLL_GENERATE);
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
                FormSection::make(__('hr::ui.payroll_period'))
                    ->columns(3)
                    ->schema([
                        Select::make('department_id')
                            ->label(__('hr::ui.department_optional'))
                            ->options(fn (): array => Department::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        Select::make('month')
                            ->options(__('hr::enums.months'))
                            ->default((int) now()->format('n'))
                            ->required(),
                        Select::make('year')
                            ->options(fn (): array => collect(range((int) now()->format('Y') - 2, (int) now()->format('Y') + 1))
                                ->mapWithKeys(fn (int $y): array => [$y => (string) $y])
                                ->all())
                            ->default((int) now()->format('Y'))
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function generate(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $month = (int) $data['month'];
        $year = (int) $data['year'];
        $departmentId = ($id = (int) ($data['department_id'] ?? 0)) > 0 ? $id : null;

        // school_id is spelled out for the same reason PayrollService::generateBatch()
        // now spells it out: TenantScope is a no-op in an unresolved context, and
        // a payroll run must never be able to reach another school's staff.
        $staff = Staff::where('school_id', current_school_id())
            ->where('status', 'active')
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->get();

        $summary = app(PayrollService::class)->generateBatch($month, $year, auth()->id(), $staff);

        Notification::make()
            ->success()
            ->title(__('hr::messages.payroll.generated_title', ['count' => $summary['written']]))
            ->body(implode(' ', array_filter([
                HrLabels::monthYear($month, $year).'.',
                $summary['skipped_paid'] > 0
                    ? __('hr::messages.payroll.already_paid', ['count' => $summary['skipped_paid']])
                    : null,
                $summary['skipped_locked'] > 0
                    ? __('hr::messages.payroll.locked_untouched', ['count' => $summary['skipped_locked']])
                    : null,
                $summary['overrides_kept'] > 0
                    ? __('hr::messages.payroll.overrides_kept', ['count' => $summary['overrides_kept']])
                    : null,
            ])))
            ->send();

        // ── SAY WHO IS STILL EXPOSED ─────────────────────────────────────────
        // Effective dating is what stops an edit to a staff record restating a
        // month already priced. It only covers the heads that have actually been
        // moved onto the salary structure — and basic pay, 62% of this payroll,
        // is priced from `staff.basic_salary` unless somebody has given the
        // employee a dated BASIC row. Payroll has always known which employees
        // were in that state and has never said so; an operator cannot act on a
        // warning nobody prints.
        if ($summary['exposed_legacy'] > 0 || $summary['exposed_basic'] > 0) {
            Notification::make()
                ->warning()
                ->persistent()
                ->title(__('hr::ui.some_of_these_payslips_can'))
                ->body(implode(' ', array_filter([
                    $summary['exposed_basic'] > 0
                        ? __('hr::messages.payroll.exposed_basic', ['count' => $summary['exposed_basic']])
                        : null,
                    $summary['exposed_legacy'] > 0
                        ? __('hr::messages.payroll.exposed_legacy', ['count' => $summary['exposed_legacy']])
                        : null,
                    __('hr::messages.payroll.lock_freezes'),
                ])))
                ->send();
        }
    }
}

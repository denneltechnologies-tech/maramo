<?php

namespace Classivo\Hr\Filament\Pages;

use BackedEnum;
use Carbon\CarbonImmutable;
use Classivo\Hr\Filament\Concerns\HrPageNavigation;
use Classivo\Hr\Filament\Pages\BasePage as Page;
use Classivo\Hr\Models\Department;
use Classivo\Hr\Models\StaffAttendance;
use Classivo\Hr\Services\StaffAttendanceService;
use Classivo\Hr\Support\HrLabels;
use Classivo\Hr\Support\HrPermissions;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Staff Attendance Report (spec §15 "Reports: Monthly staff attendance"). A Month +
 * Year (+ optional Department) filter drives a per-staff summary of per-status counts
 * and attendance % over the month.
 *
 * Per-staff monthly attendance summary built on StaffAttendanceService.
 */
class StaffAttendanceReport extends Page implements HasForms
{
    use HrPageNavigation;

    protected static string $navKey = 'attendance_report';

    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 75;

    protected string $view = 'hr::pages.staff-attendance-report';

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        return HrPermissions::can(HrPermissions::STAFF_ATTENDANCE_REPORT);
    }

    public function mount(): void
    {
        HrPermissions::authorize(HrPermissions::STAFF_ATTENDANCE_REPORT);

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
                FormSection::make(__('hr::ui.filter'))
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
                            ->live(),
                        Select::make('year')
                            ->options(fn (): array => collect(range((int) now()->format('Y') - 4, (int) now()->format('Y') + 1))
                                ->mapWithKeys(fn (int $y): array => [$y => (string) $y])
                                ->all())
                            ->default((int) now()->format('Y'))
                            ->live(),
                    ]),
            ])
            ->statePath('data');
    }

    /** First/last day of the selected month as Y-m-d strings. */
    public function range(): array
    {
        $month = (int) ($this->data['month'] ?? now()->format('n'));
        $year = (int) ($this->data['year'] ?? now()->format('Y'));
        $start = CarbonImmutable::create($year, $month, 1);

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    /**
     * Per-staff summary for the selected month: per-status counts + attendance %.
     *
     * @return Collection<int, object>
     */
    public function rows(): Collection
    {
        HrPermissions::authorize(HrPermissions::STAFF_ATTENDANCE_REPORT);

        [$from, $to] = $this->range();
        $service = app(StaffAttendanceService::class);
        $departmentId = $this->data['department_id'] ?? null;
        $statuses = array_keys(HrLabels::enum(StaffAttendance::STATUSES, 'staff_attendance.statuses'));

        return $service->roster($departmentId ? (int) $departmentId : null)->map(function ($staff) use ($service, $from, $to, $statuses): object {
            $raw = $service->statusCounts($staff->id, $from, $to);
            $counts = [];

            foreach ($statuses as $status) {
                $counts[$status] = (int) ($raw[$status] ?? 0);
            }

            return (object) [
                'employee_id' => $staff->employee_id,
                'name' => $staff->full_name,
                'counts' => $counts,
                'percentage' => $service->percentage($staff->id, $from, $to),
            ];
        })->values();
    }

    /** @return array<string, string> */
    public function statusLabels(): array
    {
        return HrLabels::enum(StaffAttendance::STATUSES, 'staff_attendance.statuses');
    }
}

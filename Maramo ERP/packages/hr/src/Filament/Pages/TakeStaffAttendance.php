<?php

namespace Classivo\Hr\Filament\Pages;

use BackedEnum;
use Classivo\Hr\Filament\Concerns\HrPageNavigation;
use Classivo\Hr\Filament\Pages\BasePage as Page;
use Classivo\Hr\Models\Department;
use Classivo\Hr\Models\StaffAttendance;
use Classivo\Hr\Services\StaffAttendanceService;
use Classivo\Hr\Support\HrLabels;
use Classivo\Hr\Support\HrPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Take Staff Attendance (spec §15 "Staff Attendance") — a custom page.
 *
 * Pick a Date (+ optional Department) and [Load staff]: the active roster loads into a
 * per-staff row (status default Present, optional remark). Re-loading a date that was
 * already taken reloads the saved marks for editing. "Mark all Present / Absent /
 * Holiday" set every row at once. [Save] upserts one row per staff (unique staff+date)
 * via the shared {@see StaffAttendanceService}. Gated on the HR module.
 *
 * @property-read Schema $form
 */
class TakeStaffAttendance extends Page
{
    use HrPageNavigation;

    protected static string $navKey = 'take_attendance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 74;

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public const MARK_OPTIONS = [
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'half_day' => 'Half Day',
        'holiday' => 'Holiday',
    ];

    public static function canAccess(array $parameters = []): bool
    {
        return HrPermissions::can(HrPermissions::STAFF_ATTENDANCE_TAKE);
    }

    public function mount(): void
    {
        HrPermissions::authorize(HrPermissions::STAFF_ATTENDANCE_TAKE);

        $this->form->fill([
            'department_id' => null,
            'date' => now()->toDateString(),
            'staff' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make(__('hr::messages.attendance.step_select'))
                    ->columns(3)
                    ->schema([
                        DatePicker::make('date')
                            ->required()
                            ->default(now())
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('staff', [])),
                        Select::make('department_id')
                            ->label(__('hr::ui.department_optional'))
                            ->options(fn (): array => Department::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('staff', [])),
                        Actions::make([
                            Action::make('load')
                                ->label(__('hr::ui.load_staff'))
                                ->icon(Heroicon::OutlinedUsers)
                                ->action(fn () => $this->load()),
                        ]),
                    ]),

                FormSection::make(__('hr::messages.attendance.step_mark'))
                    ->visible(fn (Get $get): bool => filled($get('staff')))
                    ->schema([
                        Actions::make([
                            Action::make('allPresent')
                                ->label(__('hr::ui.mark_all_present'))
                                ->icon(Heroicon::OutlinedCheck)
                                ->color('success')
                                ->action(fn () => $this->markAll('present')),
                            Action::make('allAbsent')
                                ->label(__('hr::ui.mark_all_absent'))
                                ->icon(Heroicon::OutlinedXMark)
                                ->color('danger')
                                ->action(fn () => $this->markAll('absent')),
                            Action::make('allHoliday')
                                ->label(__('hr::ui.mark_all_holiday'))
                                ->icon(Heroicon::OutlinedSun)
                                ->color('gray')
                                ->action(fn () => $this->markAll('holiday')),
                        ]),
                        Repeater::make('staff')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(12)
                            ->schema([
                                TextInput::make('employee_id')
                                    ->label(__('hr::ui.staff_id'))
                                    ->disabled()
                                    ->columnSpan(2),
                                TextInput::make('label')
                                    ->label(__('hr::ui.staff'))
                                    ->disabled()
                                    ->columnSpan(4),
                                Select::make('status')
                                    ->label(__('hr::ui.status'))
                                    ->options(HrLabels::enum(self::MARK_OPTIONS, 'staff_attendance.statuses'))
                                    ->default('present')
                                    ->required()
                                    ->columnSpan(3),
                                TextInput::make('remark')
                                    ->label(__('hr::ui.remark'))
                                    ->columnSpan(3),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    /** Load the active staff roster for the chosen date, prefilling saved marks. */
    public function load(): void
    {
        HrPermissions::authorize(HrPermissions::STAFF_ATTENDANCE_TAKE);

        $data = $this->form->getState();
        $date = $data['date'] ?? null;
        $departmentId = ($id = (int) ($data['department_id'] ?? 0)) > 0 ? $id : null;

        if (! $date) {
            Notification::make()->warning()->title(__('hr::ui.choose_a_date_first'))->send();

            return;
        }

        $service = app(StaffAttendanceService::class);
        $roster = $service->roster($departmentId);
        $existing = $service->existingDaily($date);

        $rows = $roster->map(function ($staff) use ($existing): array {
            /** @var StaffAttendance|null $mark */
            $mark = $existing->get($staff->id);

            return [
                'staff_id' => $staff->id,
                'employee_id' => $staff->employee_id,
                'label' => $staff->full_name,
                'status' => $mark?->status ?? 'present',
                'remark' => $mark?->remark,
            ];
        })->values()->all();

        $this->data['staff'] = $rows;

        if ($rows === []) {
            Notification::make()->warning()->title(__('hr::ui.no_active_staff_found'))->send();

            return;
        }

        if ($existing->isNotEmpty()) {
            Notification::make()->info()->title(__('hr::ui.attendance_already_taken_for_this'))->send();
        } else {
            Notification::make()->success()->title(__('hr::messages.attendance.loaded', ['count' => count($rows)]))->send();
        }
    }

    /** Bulk control: set every loaded row to a single status. */
    public function markAll(string $status): void
    {
        HrPermissions::authorize(HrPermissions::STAFF_ATTENDANCE_TAKE);

        foreach ($this->data['staff'] ?? [] as $i => $row) {
            $this->data['staff'][$i]['status'] = $status;
        }
    }

    /** Persist the marked roster (upsert per staff+date). */
    public function save(): void
    {
        $data = $this->form->getState();
        $rows = $data['staff'] ?? [];

        if ($rows === []) {
            Notification::make()->warning()->title(__('hr::ui.load_staff_first'))->send();

            return;
        }

        $count = app(StaffAttendanceService::class)->saveDaily($data['date'], $rows, auth()->id());

        Notification::make()->success()->title(__('hr::messages.attendance.saved', ['count' => $count]))->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
        ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('hr::ui.save_attendance'))
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->submit('save'),
                ]),
            ]);
    }
}

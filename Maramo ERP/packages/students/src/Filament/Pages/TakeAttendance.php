<?php

namespace Classivo\Students\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\ClassSection;
use Classivo\Students\Models\Attendance;
use Classivo\Students\Services\AttendanceService;
use Classivo\Students\Support\StudentsPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Take daily student attendance: pick a class-section + date, load the
 * enrolled roster (saved marks reload for editing; weekends default to
 * Holiday), mark, save. All writes go through {@see AttendanceService}.
 *
 * @property-read Schema $form
 */
class TakeAttendance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'take-attendance';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Statuses offered for manual daily marking. */
    public const MARK_STATUSES = ['present', 'absent', 'late', 'half_day', 'holiday'];

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-students::students.attendance_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-students::students.pages.take_attendance');
    }

    public function getTitle(): string
    {
        return __('classivo-students::students.pages.take_attendance');
    }

    /** @return array<string, string> */
    public static function markOptions(): array
    {
        return array_intersect_key(Attendance::statusOptions(), array_flip(self::MARK_STATUSES));
    }

    public static function canAccess(): bool
    {
        return StudentsPermissions::can(StudentsPermissions::ATTENDANCE_TAKE);
    }

    public function mount(): void
    {
        $this->form->fill([
            'class_section_id' => null,
            'date' => now()->toDateString(),
            'students' => [],
        ]);
    }

    /** Extra selectors shown next to class-section + date (subject page overrides). */
    protected function extraSelectors(): array
    {
        return [];
    }

    public function form(Schema $schema): Schema
    {
        $f = fn (string $k): string => __('classivo-students::students.fields.'.$k);
        $mark = fn (string $status): string => __('classivo-students::students.actions.mark_all', [
            'status' => __('classivo-students::students.attendance_statuses.'.$status),
        ]);

        return $schema
            ->components([
                FormSection::make()
                    ->columns(4)
                    ->schema([
                        Select::make('class_section_id')
                            ->label($f('class_section'))
                            ->options(fn (): array => static::classSectionOptions())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('students', [])),
                        DatePicker::make('date')
                            ->label($f('date'))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('students', [])),
                        ...$this->extraSelectors(),
                        Actions::make([
                            Action::make('load')
                                ->label(__('classivo-students::students.actions.load'))
                                ->icon(Heroicon::OutlinedUsers)
                                ->action(fn () => $this->load()),
                        ]),
                    ]),

                FormSection::make()
                    ->visible(fn (Get $get): bool => filled($get('students')))
                    ->schema([
                        Actions::make([
                            Action::make('allPresent')->label($mark('present'))->color('success')
                                ->action(fn () => $this->markAll('present')),
                            Action::make('allAbsent')->label($mark('absent'))->color('danger')
                                ->action(fn () => $this->markAll('absent')),
                            Action::make('allHoliday')->label($mark('holiday'))->color('gray')
                                ->action(fn () => $this->markAll('holiday')),
                        ]),
                        Repeater::make('students')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(12)
                            ->schema([
                                TextInput::make('roll')->label(__('classivo-students::students.fields.roll_no'))->disabled()->columnSpan(1),
                                TextInput::make('label')->label($f('full_name'))->disabled()->columnSpan(5),
                                Select::make('status')->label($f('status'))->options(fn (): array => static::markOptions())
                                    ->default('present')->required()->columnSpan(3),
                                TextInput::make('remark')->label($f('remark'))->columnSpan(3),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function load(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $classSection = $this->classSectionOrNotify($data);

        if (! $classSection || empty($data['date'])) {
            return;
        }

        $service = app(AttendanceService::class);
        $roster = $service->roster($classSection);
        $existing = $this->existingMarks($classSection, $data);
        $isWeekend = $service->isWeekend($classSection->branch_id, $data['date']);

        $rows = $roster->map(function ($student) use ($existing, $isWeekend): array {
            $mark = $existing->get($student->id);

            return [
                'student_id' => $student->id,
                'roll' => $student->getAttribute('roster_roll_no') ?? $student->roll_no,
                'label' => $student->full_name.' ('.$student->admission_no.')',
                'status' => $mark?->status ?? ($isWeekend ? 'holiday' : 'present'),
                'remark' => $mark?->remark,
            ];
        })->values()->all();

        $this->data['students'] = $rows;

        $n = 'classivo-students::students.notices.';

        if ($rows === []) {
            Notification::make()->warning()->title(__($n.'no_students'))->send();
        } elseif ($existing->isNotEmpty()) {
            Notification::make()->info()->title(__($n.'loaded_existing'))->send();
        } elseif ($isWeekend) {
            Notification::make()->warning()->title(__($n.'weekend'))->send();
        } else {
            Notification::make()->success()->title(__($n.'loaded', ['count' => count($rows)]))->send();
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function existingMarks(ClassSection $classSection, array $data): Collection
    {
        return app(AttendanceService::class)->existingDaily($classSection->id, $data['date']);
    }

    public function markAll(string $status): void
    {
        foreach ($this->data['students'] ?? [] as $i => $row) {
            $this->data['students'][$i]['status'] = $status;
        }
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $rows = $data['students'] ?? [];
        $classSection = $this->classSectionOrNotify($data);

        if (! $classSection) {
            return;
        }

        if ($rows === []) {
            Notification::make()->warning()->title(__('classivo-students::students.notices.load_first'))->send();

            return;
        }

        $count = $this->persist($classSection, $data, $rows);

        Notification::make()->success()->title(__('classivo-students::students.notices.saved', ['count' => $count]))->send();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function persist(ClassSection $classSection, array $data, array $rows): int
    {
        return app(AttendanceService::class)->saveDaily($classSection, $data['date'], $rows, auth()->id());
    }

    /** @param  array<string, mixed>  $data */
    protected function classSectionOrNotify(array $data): ?ClassSection
    {
        $id = (int) ($data['class_section_id'] ?? 0);

        if (! $id) {
            Notification::make()->warning()->title(__('classivo-students::students.notices.choose_first'))->send();

            return null;
        }

        $classSection = ClassSection::find($id);

        if (! $classSection) {
            Notification::make()->danger()->title(__('classivo-students::students.notices.not_found'))->send();
        }

        return $classSection;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('classivo-students::students.actions.save_attendance'))
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->submit('save'),
                ]),
            ]);
    }

    /** @return array<int, string> */
    public static function classSectionOptions(): array
    {
        return ClassSection::query()
            ->with(['schoolClass', 'section'])
            ->get()
            ->sortBy(fn (ClassSection $cs): array => [$cs->schoolClass?->numeric_order ?? 0, $cs->name])
            ->mapWithKeys(fn (ClassSection $cs): array => [$cs->id => $cs->name])
            ->all();
    }
}

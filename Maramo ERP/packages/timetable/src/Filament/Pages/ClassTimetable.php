<?php

namespace Classivo\Timetable\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Models\Subject;
use Classivo\Timetable\Actions\GenerateTimetables;
use Classivo\Timetable\Exceptions\TimetableConflictException;
use Classivo\Timetable\Models\Timetable;
use Classivo\Timetable\Models\TimetablePeriod;
use Classivo\Timetable\Support\Authorize;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
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
use Illuminate\Support\Facades\DB;

/**
 * Class Timetable / Routine.
 *
 * Pick a Class + Section (→ the canonical class_sections row), then build the
 * week's periods in a repeater grid (day, period no, times, subject, teacher, room,
 * break). Save rebuilds the class-section's {@see Timetable} inside a transaction:
 * every row is (re)created through {@see TimetablePeriod}, whose model guard rejects
 * CONFLICTS — a teacher double-booked in an overlapping slot anywhere in the
 * branch/session, or an overlap within this class-section — surfaced here as a
 * danger notification (the whole save rolls back). [Clear] empties the timetable.
 * To fill every class automatically, see
 * {@see GenerateTimetables}.
 *
 * Viewless: renders via the base Page content() schema (no blade template).
 *
 * @property-read Schema $form
 */
class ClassTimetable extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    /** Open with `view`; saving and clearing need `edit`. */
    public static function canAccess(): bool
    {
        return Authorize::can('view');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-timetable::timetable.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-timetable::timetable.navigation_label');
    }

    public function getTitle(): string
    {
        return __('classivo-timetable::timetable.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'class_id' => null,
            'section_id' => null,
            'periods' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make(__('classivo-timetable::timetable.form.select_class_section'))
                    ->columns(2)
                    ->schema([
                        Select::make('class_id')
                            ->label(__('classivo-timetable::timetable.form.class'))
                            ->options(fn (): array => SchoolClass::query()->orderBy('numeric_order')->pluck('name', 'id')->all())
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('section_id', null);
                                $set('periods', []);
                            }),
                        Select::make('section_id')
                            ->label(__('classivo-timetable::timetable.form.section'))
                            ->options(fn (Get $get): array => $this->sectionOptions($get('class_id') ? (int) $get('class_id') : null))
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                $classId = $get('class_id') ? (int) $get('class_id') : null;
                                $set('periods', $this->existingPeriods($classId, $state ? (int) $state : null));
                            }),
                    ]),

                FormSection::make(__('classivo-timetable::timetable.form.weekly_periods'))
                    ->schema([
                        Repeater::make('periods')
                            ->hiddenLabel()
                            ->addActionLabel(__('classivo-timetable::timetable.form.add_period'))
                            ->columns(12)
                            ->defaultItems(0)
                            ->schema([
                                Select::make('day_of_week')
                                    ->label(__('classivo-timetable::timetable.form.day'))
                                    ->options(TimetablePeriod::dayOptions())
                                    ->required()
                                    ->columnSpan(2),
                                TextInput::make('period_no')
                                    ->label(__('classivo-timetable::timetable.form.period'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->columnSpan(1),
                                TimePicker::make('start_time')
                                    ->label(__('classivo-timetable::timetable.form.start'))
                                    ->seconds(false)
                                    ->required()
                                    ->columnSpan(2),
                                TimePicker::make('end_time')
                                    ->label(__('classivo-timetable::timetable.form.end'))
                                    ->seconds(false)
                                    ->required()
                                    ->columnSpan(2),
                                Select::make('subject_id')
                                    ->label(__('classivo-timetable::timetable.form.subject'))
                                    ->options(fn (): array => Subject::query()->orderBy('name')->pluck('name', 'id')->all())
                                    ->searchable()
                                    ->columnSpan(2),
                                Select::make('teacher_id')
                                    ->label(__('classivo-timetable::timetable.form.teacher'))
                                    ->options(fn (): array => $this->teacherOptions())
                                    ->searchable()
                                    ->columnSpan(2),
                                TextInput::make('room')
                                    ->label(__('classivo-timetable::timetable.form.room'))
                                    ->maxLength(50)
                                    ->columnSpan(2),
                                Toggle::make('is_break')
                                    ->label(__('classivo-timetable::timetable.form.break'))
                                    ->inline(false)
                                    ->columnSpan(1),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        if (! Authorize::can('edit')) {
            abort(403);
        }

        $data = $this->form->getState();

        $classSection = $this->resolveSelectedClassSection($data);

        if (! $classSection) {
            return;
        }

        $timetable = Timetable::firstOrCreate(
            ['class_section_id' => $classSection->id],
            ['name' => $classSection->name],
        );

        try {
            DB::transaction(function () use ($timetable, $data): void {
                // Full replace — hard-delete so re-adding the same slots never
                // collides with soft-deleted rows nor self-conflicts.
                $timetable->periods()->forceDelete();

                foreach ($data['periods'] ?? [] as $row) {
                    if (empty($row['start_time']) || empty($row['end_time']) || ! isset($row['day_of_week'])) {
                        continue;
                    }

                    TimetablePeriod::create([
                        'timetable_id' => $timetable->id,
                        'day_of_week' => (int) $row['day_of_week'],
                        'period_no' => (int) ($row['period_no'] ?? 1),
                        'start_time' => $row['start_time'],
                        'end_time' => $row['end_time'],
                        'subject_id' => $row['subject_id'] ?? null,
                        'teacher_id' => $row['teacher_id'] ?? null,
                        'room' => $row['room'] ?? null,
                        'is_break' => (bool) ($row['is_break'] ?? false),
                    ]);
                }
            });
        } catch (TimetableConflictException $e) {
            Notification::make()
                ->title(__('classivo-timetable::timetable.notifications.not_saved'))
                ->body(implode("\n", $e->messages()))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('classivo-timetable::timetable.notifications.saved'))
            ->success()
            ->send();
    }

    public function clearTimetable(): void
    {
        if (! Authorize::can('edit')) {
            abort(403);
        }

        $data = $this->form->getState();
        $classSection = $this->resolveSelectedClassSection($data);

        if (! $classSection) {
            return;
        }

        $timetable = Timetable::where('class_section_id', $classSection->id)->first();
        $timetable?->periods()->forceDelete();

        $this->form->fill([
            'class_id' => $data['class_id'] ?? null,
            'section_id' => $data['section_id'] ?? null,
            'periods' => [],
        ]);

        Notification::make()->title(__('classivo-timetable::timetable.notifications.cleared'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [

            Action::make('clear')
                ->label(__('classivo-timetable::timetable.form.clear'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => Authorize::can('edit'))
                ->action('clearTimetable'),
        ];
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
                        ->label(__('classivo-timetable::timetable.form.save'))
                        ->submit('save')
                        ->visible(fn (): bool => Authorize::can('edit'))
                        ->keyBindings(['mod+s']),
                ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveSelectedClassSection(array $data): ?ClassSection
    {
        $classId = $data['class_id'] ?? null;
        $sectionId = $data['section_id'] ?? null;

        if (! $classId || ! $sectionId) {
            Notification::make()->title(__('classivo-timetable::timetable.notifications.choose_class_section'))->warning()->send();

            return null;
        }

        $classSection = $this->resolveClassSection((int) $classId, (int) $sectionId);

        if (! $classSection) {
            Notification::make()->title(__('classivo-timetable::timetable.notifications.section_not_linked'))->danger()->send();
        }

        return $classSection;
    }

    /**
     * Section options for a class (only sections that form a class_section).
     *
     * @return array<int, string>
     */
    protected function sectionOptions(?int $classId): array
    {
        if (! $classId) {
            return [];
        }

        return Section::query()
            ->whereHas('classSections', fn ($query) => $query->where('class_id', $classId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Teacher picker options: users holding one of the configured teacher roles
     * when the user model has a `roles` relation, else every user.
     *
     * @return array<int, string>
     */
    protected function teacherOptions(): array
    {
        $model = config('classivo.user_model');
        $roles = (array) config('classivo-timetable.teacher_roles', []);

        return $model::query()
            ->when(
                $roles !== [] && method_exists($model, 'roles'),
                fn ($query) => $query->whereHas('roles', fn ($q) => $q->whereIn('name', $roles)),
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected function resolveClassSection(int $classId, int $sectionId): ?ClassSection
    {
        return ClassSection::query()
            ->where('class_id', $classId)
            ->where('section_id', $sectionId)
            ->first();
    }

    /**
     * Repeater rows from the saved periods for a (class, section).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function existingPeriods(?int $classId, ?int $sectionId): array
    {
        if (! $classId || ! $sectionId) {
            return [];
        }

        $classSection = $this->resolveClassSection($classId, $sectionId);

        if (! $classSection) {
            return [];
        }

        $timetable = Timetable::where('class_section_id', $classSection->id)->first();

        if (! $timetable) {
            return [];
        }

        return $timetable->periods()
            ->get()
            ->map(fn (TimetablePeriod $period): array => [
                'day_of_week' => $period->day_of_week,
                'period_no' => $period->period_no,
                'start_time' => TimetablePeriod::formatTime($period->start_time),
                'end_time' => TimetablePeriod::formatTime($period->end_time),
                'subject_id' => $period->subject_id,
                'teacher_id' => $period->teacher_id,
                'room' => $period->room,
                'is_break' => $period->is_break,
            ])
            ->all();
    }
}

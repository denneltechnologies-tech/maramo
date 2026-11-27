<?php

namespace Classivo\Exams\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Models\Subject;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamSchedule;
use Classivo\Exams\Support\ExamsPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
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

/**
 * Exam Schedule / Time Table — a custom page.
 *
 * Pick an Exam + Class (+ optional Section — blank = every section of the class),
 * then manage the subject sittings as a repeater: subject, date, start/end time,
 * room, full/pass marks. On load the repeater pre-fills from the existing
 * exam_schedules for the chosen scope; Save reconciles via
 * {@see ExamSchedule::buildSchedule()}. Viewless (base Page content() schema, like
 * AssignSubjectToClass). Deep-linkable with ?exam=<id>.
 *
 * @property-read Schema $form
 */
class BuildExamSchedule extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-exams::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-exams::ui.exam_schedule');
    }

    public function getTitle(): string
    {
        return __('classivo-exams::ui.exam_schedule');
    }

    protected static ?int $navigationSort = 12;

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return ExamsPermissions::can(ExamsPermissions::SCHEDULE_BUILD);
    }

    public function mount(): void
    {
        $examId = request()->integer('exam') ?: null;

        $this->form->fill([
            'exam_id' => $examId,
            'class_id' => null,
            'section_id' => null,
            'schedules' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make(__('classivo-exams::ui.select_exam_class_section'))
                    ->columns(3)
                    ->schema([
                        Select::make('exam_id')
                            ->label(__('classivo-exams::ui.exam'))
                            ->options(fn (): array => Exam::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                $set('schedules', $this->existingSchedules($get('exam_id'), $get('class_id'), $get('section_id')));
                            }),
                        Select::make('class_id')
                            ->label(__('classivo-exams::ui.class'))
                            ->options(fn (): array => SchoolClass::query()->orderBy('numeric_order')->pluck('name', 'id')->all())
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                $set('section_id', null);
                                $set('schedules', $this->existingSchedules($get('exam_id'), $get('class_id'), null));
                            }),
                        Select::make('section_id')
                            ->label(__('classivo-exams::ui.section'))
                            ->placeholder(__('classivo-exams::ui.all_sections_of_the_class'))
                            ->options(fn (Get $get): array => $this->sectionOptions($get('class_id') ? (int) $get('class_id') : null))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                $set('schedules', $this->existingSchedules($get('exam_id'), $get('class_id'), $get('section_id')));
                            }),
                    ]),

                FormSection::make(__('classivo-exams::ui.subjects'))
                    ->schema([
                        Repeater::make('schedules')
                            ->hiddenLabel()
                            ->addActionLabel(__('classivo-exams::ui.add_subject'))
                            ->columns(12)
                            ->schema([
                                Select::make('subject_id')
                                    ->label(__('classivo-exams::ui.subject'))
                                    ->options(fn (): array => Subject::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all())
                                    ->required()
                                    ->searchable()
                                    ->columnSpan(3),
                                DatePicker::make('date')->label(__('classivo-exams::ui.date'))->columnSpan(2),
                                TimePicker::make('start_time')->label(__('classivo-exams::ui.start'))->seconds(false)->columnSpan(2),
                                TimePicker::make('end_time')->label(__('classivo-exams::ui.end'))->seconds(false)->columnSpan(2),
                                TextInput::make('room')->label(__('classivo-exams::ui.room'))->columnSpan(1),
                                TextInput::make('full_marks')->label(__('classivo-exams::ui.full'))->numeric()->default(100)->minValue(0)->columnSpan(1),
                                TextInput::make('pass_marks')->label(__('classivo-exams::ui.pass'))->numeric()->default(35)->minValue(0)->columnSpan(1),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $exam = Exam::find($data['exam_id'] ?? null);

        if (! $exam || empty($data['class_id'])) {
            Notification::make()->warning()->title(__('classivo-exams::ui.choose_an_exam_and_class_first'))->send();

            return;
        }

        $sectionId = ! empty($data['section_id']) ? (int) $data['section_id'] : null;

        // Guard: pass marks must not exceed full marks.
        foreach ($data['schedules'] ?? [] as $row) {
            if (($row['pass_marks'] ?? 0) > ($row['full_marks'] ?? 0)) {
                Notification::make()->danger()->title(__('classivo-exams::ui.pass_marks_cannot_exceed_full_marks'))->send();

                return;
            }
        }

        ExamSchedule::buildSchedule($exam, (int) $data['class_id'], $sectionId, $data['schedules'] ?? []);

        Notification::make()->success()->title(__('classivo-exams::ui.exam_schedule_saved'))->send();
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
                        ->label(__('classivo-exams::ui.save_schedule'))
                        ->submit('save')
                        ->keyBindings(['mod+s']),
                ]),
            ]);
    }

    /**
     * Section options for a class (its class_sections).
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
     * Build repeater rows from the existing exam_schedules for a scope.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function existingSchedules(mixed $examId, mixed $classId, mixed $sectionId): array
    {
        if (! $examId || ! $classId) {
            return [];
        }

        return ExamSchedule::query()
            ->where('exam_id', (int) $examId)
            ->where('class_id', (int) $classId)
            ->where('section_id', $sectionId ? (int) $sectionId : null)
            ->orderBy('date')
            ->get()
            ->map(fn (ExamSchedule $s): array => [
                'subject_id' => $s->subject_id,
                'date' => optional($s->date)->toDateString(),
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
                'room' => $s->room,
                'full_marks' => $s->full_marks,
                'pass_marks' => $s->pass_marks,
            ])
            ->all();
    }
}

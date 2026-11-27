<?php

namespace Classivo\Exams\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\Subject;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamSchedule;
use Classivo\Exams\Models\Mark;
use Classivo\Exams\Services\ExamMarksService;
use Classivo\Exams\Services\ExamResultService;
use Classivo\Exams\Support\Authorize;
use Classivo\Exams\Support\ExamsPermissions;
use Classivo\Exams\Support\MarksScope;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Marks Register / Marks Entry — a custom page.
 *
 * Pick Exam + Class-Section + Subject and [Load Students]; enter each enrolled
 * student's obtained marks (or mark absent) + remark, then [Save Marks]. Saving is
 * gated by `exam.enter_marks` and routed through {@see ExamMarksService} so marks
 * that exceed the schedule's full marks are rejected, and entry is refused once the
 * exam's result is published (locked). Teachers see and may enter marks only for
 * the class-sections and subjects they teach ({@see MarksScope}); Admin bypasses
 * this. Deep-linkable with ?exam=<id>.
 *
 * @property-read Schema $form
 */
class MarksRegister extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-exams::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-exams::ui.marks_register');
    }

    public function getTitle(): string
    {
        return __('classivo-exams::ui.marks_register');
    }

    protected static ?int $navigationSort = 13;

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return ExamsPermissions::can(ExamsPermissions::MARKS_VIEW) || Authorize::can('enter_marks');
    }

    public function mount(): void
    {
        $this->form->fill([
            'exam_id' => request()->integer('exam') ?: null,
            'class_section_id' => null,
            'subject_id' => null,
            'students' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make(__('classivo-exams::ui.1_choose_exam_class_section_subject'))
                    ->columns(3)
                    ->schema([
                        Select::make('exam_id')
                            ->label(__('classivo-exams::ui.exam'))
                            ->options(fn (): array => Exam::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->required()
                            ->searchable()
                            ->live(),
                        Select::make('class_section_id')
                            ->label(__('classivo-exams::ui.class_section'))
                            ->options(fn (): array => $this->classSectionOptions())
                            ->required()
                            ->searchable()
                            ->live(),
                        Select::make('subject_id')
                            ->label(__('classivo-exams::ui.subject'))
                            ->options(fn (Get $get): array => $this->subjectOptions($get('exam_id'), $get('class_section_id')))
                            ->required()
                            ->searchable(),
                        Actions::make([
                            Action::make('load')
                                ->label(__('classivo-exams::ui.load_students'))
                                ->icon(Heroicon::OutlinedMagnifyingGlass)
                                ->action(fn () => $this->load()),
                        ]),
                    ]),

                FormSection::make(__('classivo-exams::ui.2_enter_marks'))
                    ->schema([
                        Placeholder::make('lock_notice')
                            ->hiddenLabel()
                            ->content(__('classivo-exams::ui.result_is_published_marks_are_locked_and_read_on'))
                            ->visible(fn (Get $get): bool => $this->examLocked($get('exam_id'))),
                        Repeater::make('students')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(12)
                            ->schema([
                                TextInput::make('label')->label(__('classivo-exams::ui.student'))->disabled()->columnSpan(4),
                                TextInput::make('marks_obtained')
                                    ->label(__('classivo-exams::ui.marks'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->disabled(fn (Get $get): bool => (bool) $get('is_absent'))
                                    ->columnSpan(2),
                                Toggle::make('is_absent')->label(__('classivo-exams::ui.absent'))->inline(false)->live()->columnSpan(2),
                                TextInput::make('remark')->label(__('classivo-exams::ui.remark'))->columnSpan(4),
                            ])
                            ->visible(fn (Get $get): bool => filled($get('students'))),
                    ]),
            ])
            ->statePath('data');
    }

    /** Load enrolled students (+ any existing marks) for the chosen scope. */
    public function load(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();

        $exam = Exam::find($data['exam_id'] ?? null);
        $classSection = ClassSection::find($data['class_section_id'] ?? null);
        $subject = Subject::find($data['subject_id'] ?? null);

        if (! $exam || ! $classSection || ! $subject) {
            Notification::make()->warning()->title(__('classivo-exams::ui.choose_exam_class_section_and_subject_first'))->send();

            return;
        }

        abort_unless($this->mayUseScope($classSection, $subject), 403);

        $students = app(ExamResultService::class)->studentsFor($classSection);

        if ($students->isEmpty()) {
            $this->data['students'] = [];
            Notification::make()->warning()->title(__('classivo-exams::ui.no_enrolled_students_found_for_that_class_sectio'))->send();

            return;
        }

        $existing = Mark::query()
            ->where('exam_id', $exam->id)
            ->where('subject_id', $subject->id)
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy('student_id');

        $this->data['students'] = $students->map(function ($student) use ($existing): array {
            $mark = $existing->get($student->id);

            return [
                'student_id' => $student->id,
                'label' => $student->full_name.' ('.$student->admission_no.')',
                'marks_obtained' => $mark?->marks_obtained,
                'is_absent' => (bool) $mark?->is_absent,
                'remark' => $mark?->remark,
            ];
        })->all();

        Notification::make()->success()->title(__('classivo-exams::ui.students_loaded', ['count' => $students->count()]))->send();
    }

    /** Persist the register via the marks service (gated by `exam.enter_marks`). */
    public function save(): void
    {
        if (! Authorize::can('enter_marks')) {
            Notification::make()->danger()->title(__('classivo-exams::ui.you_are_not_allowed_to_enter_marks'))->send();

            return;
        }

        $data = $this->form->getState();

        $exam = Exam::find($data['exam_id'] ?? null);
        $classSection = ClassSection::find($data['class_section_id'] ?? null);
        $subject = Subject::find($data['subject_id'] ?? null);

        if (! $exam || ! $classSection || ! $subject) {
            Notification::make()->warning()->title(__('classivo-exams::ui.choose_exam_class_section_and_subject_first'))->send();

            return;
        }

        if (! MarksScope::allows(auth()->user(), $classSection, $subject)) {
            Notification::make()->danger()->title(__('classivo-exams::ui.not_your_class_section'))->send();

            return;
        }

        try {
            $count = app(ExamMarksService::class)->save(
                $exam,
                $classSection,
                $subject,
                $data['students'] ?? [],
                auth()->id(),
            );
        } catch (AuthorizationException) {
            Notification::make()->danger()->title(__('classivo-exams::ui.not_your_class_section'))->send();

            return;
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(__('classivo-exams::ui.some_marks_were_rejected'))->body(collect($e->errors())->flatten()->first())->send();

            return;
        }

        Notification::make()->success()->title(__('classivo-exams::ui.saved_marks', ['count' => $count]))->send();
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
                        ->label(__('classivo-exams::ui.save_marks'))
                        ->submit('save')
                        ->keyBindings(['mod+s']),
                ]),
            ]);
    }

    // -----------------------------------------------------------------
    // Option helpers
    // -----------------------------------------------------------------

    /** @return array<int, string> */
    protected function classSectionOptions(): array
    {
        $allowed = $this->restrictedToOwnClasses() ? MarksScope::classSectionIds(auth()->user()) : null;

        return ClassSection::query()
            ->with(['schoolClass', 'section'])
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->get()
            ->sortBy('name')
            ->mapWithKeys(fn (ClassSection $cs): array => [$cs->id => $cs->name])
            ->all();
    }

    /**
     * Subjects that have an exam_schedule for the chosen exam + class-section's class.
     *
     * @return array<int, string>
     */
    protected function subjectOptions(mixed $examId, mixed $classSectionId): array
    {
        if (! $examId || ! $classSectionId) {
            return [];
        }

        $classSection = ClassSection::find($classSectionId);

        if (! $classSection) {
            return [];
        }

        $subjectIds = ExamSchedule::query()
            ->where('exam_id', (int) $examId)
            ->where('class_id', $classSection->class_id)
            ->where(fn ($q) => $q->whereNull('section_id')->orWhere('section_id', $classSection->section_id))
            ->pluck('subject_id')
            ->unique();

        $subjects = Subject::query()
            ->whereIn('id', $subjectIds)
            ->orderBy('name')
            ->get();

        if ($this->restrictedToOwnClasses()) {
            $subjects = $subjects->filter(fn (Subject $subject): bool => MarksScope::allows(auth()->user(), $classSection, $subject));
        }

        return $subjects->pluck('name', 'id')->all();
    }

    /**
     * Read-only register viewers (exams.marks.view) may browse every class-section;
     * anyone else (a teacher holding only exam.enter_marks) is limited to their own.
     */
    protected function restrictedToOwnClasses(): bool
    {
        return ! ExamsPermissions::can(ExamsPermissions::MARKS_VIEW) && ! MarksScope::bypasses(auth()->user());
    }

    protected function mayUseScope(ClassSection $classSection, Subject $subject): bool
    {
        return ! $this->restrictedToOwnClasses() || MarksScope::allows(auth()->user(), $classSection, $subject);
    }

    protected function examLocked(mixed $examId): bool
    {
        return (bool) Exam::find($examId)?->isLocked();
    }
}

<?php

namespace Classivo\Students\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Students\Models\Student;
use Classivo\Students\Services\AttendanceService;
use Classivo\Students\Services\StudentPromotionService;
use Classivo\Students\Support\StudentsPermissions;
use Filament\Actions\Action;
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
use Illuminate\Validation\ValidationException;

/**
 * Promote a class-section into the next session: choose the source
 * class-section (active session) and the target session + class-section, load
 * the roster, set result/action per student, promote. Every row goes through
 * {@see StudentPromotionService}.
 *
 * @property-read Schema $form
 */
class StudentPromotion extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?int $navigationSort = 24;

    protected static ?string $slug = 'student-promotion';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-students::students.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-students::students.pages.promotion');
    }

    public function getTitle(): string
    {
        return __('classivo-students::students.pages.promotion');
    }

    public static function canAccess(): bool
    {
        return StudentsPermissions::can(StudentsPermissions::PROMOTE);
    }

    public function mount(): void
    {
        $this->form->fill(['class_section_id' => null, 'next_session_id' => null, 'next_class_section_id' => null, 'carry_forward_fees' => false, 'students' => []]);
    }

    public function form(Schema $schema): Schema
    {
        $f = fn (string $k): string => __('classivo-students::students.fields.'.$k);
        $p = fn (string $k): string => __('classivo-students::students.promotion.'.$k);

        return $schema
            ->components([
                FormSection::make()
                    ->columns(3)
                    ->schema([
                        Select::make('class_section_id')->label($f('class_section'))
                            ->options(fn (): array => TakeAttendance::classSectionOptions())->required(),
                        Select::make('next_session_id')->label($f('to_session'))
                            ->options(fn (): array => AcademicSession::query()->whereKeyNot(current_session_id())->orderByDesc('id')->pluck('name', 'id')->all())
                            ->required()->live(),
                        Select::make('next_class_section_id')->label($f('next_class').' / '.$f('next_section'))
                            ->options(fn (Get $get): array => static::classSectionsIn($get('next_session_id'))),
                        Toggle::make('carry_forward_fees')->label($f('carry_forward_fees')),
                        Actions::make([
                            Action::make('load')->label(__('classivo-students::students.actions.load'))->action(fn () => $this->load()),
                        ]),
                    ]),
                FormSection::make()
                    ->visible(fn (Get $get): bool => filled($get('students')))
                    ->schema([
                        Repeater::make('students')
                            ->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)
                            ->columns(12)
                            ->schema([
                                TextInput::make('label')->label($f('full_name'))->disabled()->columnSpan(5),
                                Select::make('result')->label($f('result'))
                                    ->options(['pass' => $p('pass'), 'fail' => $p('fail')])->default('pass')->columnSpan(2),
                                Select::make('action')->label($f('action'))
                                    ->options(['continue' => $p('continue'), 'leave' => $p('leave')])->default('continue')->columnSpan(3),
                                TextInput::make('new_roll')->label($f('new_roll'))->columnSpan(2),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    /** @return array<int, string> class-sections of a given session */
    public static function classSectionsIn(mixed $sessionId): array
    {
        if (! $sessionId) {
            return [];
        }

        return ClassSection::withoutGlobalScope(SessionScope::class)
            ->where('session_id', $sessionId)
            ->with(['schoolClass' => fn ($q) => $q->withoutGlobalScope(SessionScope::class), 'section' => fn ($q) => $q->withoutGlobalScope(SessionScope::class)])
            ->get()
            ->mapWithKeys(fn (ClassSection $cs): array => [$cs->id => $cs->name])
            ->all();
    }

    public function load(): void
    {
        abort_unless(static::canAccess(), 403);

        $classSection = ClassSection::find((int) ($this->data['class_section_id'] ?? 0));

        if (! $classSection) {
            Notification::make()->warning()->title(__('classivo-students::students.notices.choose_first'))->send();

            return;
        }

        $this->data['students'] = app(AttendanceService::class)->roster($classSection)
            ->map(fn (Student $s): array => [
                'student_id' => $s->id,
                'label' => $s->full_name.' ('.$s->admission_no.')',
                'result' => 'pass',
                'action' => 'continue',
                'new_roll' => $s->getAttribute('roster_roll_no'),
            ])->values()->all();
    }

    public function promote(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $target = ClassSection::withoutGlobalScope(SessionScope::class)->find($data['next_class_section_id'] ?? 0);
        $service = app(StudentPromotionService::class);
        $count = 0;

        // Only students enrolled in the selected source class-section may be promoted,
        // whatever ids the browser sends back in the rows.
        $source = ClassSection::find((int) ($data['class_section_id'] ?? 0));
        $enrolled = $source ? app(AttendanceService::class)->enrolledIds($source) : [];

        try {
            foreach ($data['students'] ?? [] as $row) {
                $studentId = (int) ($row['student_id'] ?? 0);
                $student = isset($enrolled[$studentId]) ? Student::find($studentId) : null;

                if (! $student) {
                    continue;
                }

                $service->promoteStudent($student, [
                    'from_session_id' => (int) current_session_id(),
                    'result' => $row['result'] ?? null,
                    'action' => $row['action'] ?? 'continue',
                    'next_session_id' => (int) $data['next_session_id'],
                    'next_class_id' => $target?->class_id,
                    'next_section_id' => $target?->section_id,
                    'new_roll' => $row['new_roll'] ?? null,
                    'carry_forward_fees' => (bool) ($data['carry_forward_fees'] ?? false),
                ]);

                $count++;
            }
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

            return;
        }

        $this->data['students'] = [];

        Notification::make()->success()->title(__('classivo-students::students.promotion.done', ['count' => $count]))->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('promote')
            ->footer([
                Actions::make([
                    Action::make('promote')
                        ->label(__('classivo-students::students.actions.promote'))
                        ->icon(Heroicon::OutlinedArrowTrendingUp)
                        ->requiresConfirmation()
                        ->action(fn () => $this->promote()),
                ]),
            ]);
    }
}

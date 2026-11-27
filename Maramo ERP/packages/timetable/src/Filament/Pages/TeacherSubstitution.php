<?php

namespace Classivo\Timetable\Filament\Pages;

use BackedEnum;
use Classivo\Timetable\Models\Substitution;
use Classivo\Timetable\Models\TimetablePeriod;
use Classivo\Timetable\Services\SubstitutionService;
use Classivo\Timetable\Support\Authorize;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Same-day substitution: pick a date and the absent teacher, mark them absent
 * (one pending row per timetabled period that weekday), then cover each
 * period with a suggested free teacher. Assigning fires SubstitutionAssigned.
 * Gated by `substitute` (timetable.substitute).
 *
 * @property-read Schema $form
 */
class TeacherSubstitution extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 4;

    protected string $view = 'classivo-timetable::filament.pages.teacher-substitution';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<int|string, int|string|null> substitution id => chosen teacher id */
    public array $choices = [];

    public static function canAccess(): bool
    {
        return Authorize::can('substitute');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-timetable::timetable.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-timetable::timetable.substitution.title');
    }

    public function getTitle(): string
    {
        return __('classivo-timetable::timetable.substitution.title');
    }

    public function mount(): void
    {
        $this->form->fill(['date' => now()->toDateString(), 'teacher_id' => null, 'reason' => null]);
    }

    public function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __('classivo-timetable::timetable.substitution.'.$key);

        return $schema->components([
            FormSection::make($t('absent_teacher'))
                ->columns(3)
                ->schema([
                    DatePicker::make('date')->label($t('date'))->required()->live(),
                    Select::make('teacher_id')->label($t('teacher'))->options(fn (): array => $this->teacherOptions())->searchable()->required()->live(),
                    TextInput::make('reason')->label($t('reason'))->maxLength(255),
                ]),
        ])->statePath('data');
    }

    public function markAbsent(): void
    {
        $this->authorizeSubstitute();
        $data = $this->form->getState();

        $rows = app(SubstitutionService::class)->markAbsent((int) $data['teacher_id'], $data['date'], $data['reason'] ?? null);

        Notification::make()
            ->title($rows->isEmpty()
                ? __('classivo-timetable::timetable.substitution.no_periods')
                : __('classivo-timetable::timetable.substitution.marked', ['count' => $rows->count()]))
            ->{$rows->isEmpty() ? 'warning' : 'success'}()
            ->send();
    }

    public function assign(int $substitutionId): void
    {
        $this->authorizeSubstitute();
        $teacherId = (int) ($this->choices[$substitutionId] ?? 0);

        if ($teacherId === 0) {
            Notification::make()->title(__('classivo-timetable::timetable.substitution.choose_teacher'))->warning()->send();

            return;
        }

        try {
            app(SubstitutionService::class)->assign(Substitution::query()->findOrFail($substitutionId), $teacherId);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        unset($this->choices[$substitutionId]);
        Notification::make()->title(__('classivo-timetable::timetable.substitution.assigned'))->success()->send();
    }

    public function cancel(int $substitutionId): void
    {
        $this->authorizeSubstitute();
        app(SubstitutionService::class)->cancel(Substitution::query()->findOrFail($substitutionId));
        Notification::make()->title(__('classivo-timetable::timetable.substitution.cancelled'))->success()->send();
    }

    /**
     * Rows for the chosen date (all absent teachers), with suggestions for pending ones.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $date = $this->data['date'] ?? null;
        if (! $date) {
            return [];
        }

        $service = app(SubstitutionService::class);

        return Substitution::query()
            ->whereDate('date', Carbon::parse($date))
            ->where('status', '!=', Substitution::STATUS_CANCELLED)
            ->with(['period.timetable.classSection.schoolClass', 'period.timetable.classSection.section', 'period.subject', 'absentTeacher', 'substitute'])
            ->get()
            ->sortBy(fn (Substitution $s) => [$s->absentTeacher?->name, TimetablePeriod::toMinutes($s->period?->start_time)])
            ->map(fn (Substitution $s): array => [
                'id' => $s->id,
                'absent' => $s->absentTeacher?->name,
                'time' => TimetablePeriod::formatTime($s->period?->start_time).'–'.TimetablePeriod::formatTime($s->period?->end_time),
                'period_no' => $s->period?->period_no,
                'class' => $s->period?->timetable?->classSection?->name,
                'subject' => $s->period?->subject?->name,
                'status' => $s->status,
                'substitute' => $s->substitute?->name,
                'candidates' => $s->status === Substitution::STATUS_PENDING ? $service->candidates($s) : [],
            ])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    protected function teacherOptions(): array
    {
        $model = config('classivo.user_model');

        return $model::query()->whereKey(app(SubstitutionService::class)->teacherPool())->orderBy('name')->pluck('name', 'id')->all();
    }

    protected function authorizeSubstitute(): void
    {
        abort_unless(Authorize::can('substitute'), 403);
    }
}

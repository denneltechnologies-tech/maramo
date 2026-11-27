<?php

namespace Classivo\Timetable\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\Subject;
use Classivo\Timetable\Actions\GenerateTimetables;
use Classivo\Timetable\Actions\GenerateTimetablesResult;
use Classivo\Timetable\Generator\InvalidProblem;
use Classivo\Timetable\Generator\Result;
use Classivo\Timetable\Models\TimetablePeriod;
use Classivo\Timetable\Support\Authorize;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * Generate Timetable — runs the clash-free generator for the chosen
 * class-sections and shows a dry-run preview (one grid per class-section,
 * plus the reasons / unplaced periods when it fails). [Save] re-runs the same
 * deterministic generation (same seed and inputs) and writes it. Weekly periods
 * and room types come from Timetable Requirements. Gated by `generate`.
 *
 * @property-read Schema $form
 */
class GenerateTimetable extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 3;

    protected string $view = 'classivo-timetable::filament.pages.generate-timetable';

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    /** Last dry-run, as plain arrays (null = none yet). @var array<string, mixed>|null */
    public ?array $preview = null;

    public static function canAccess(): bool
    {
        return Authorize::can('generate');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-timetable::timetable.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-timetable::timetable.generate.title');
    }

    public function getTitle(): string
    {
        return __('classivo-timetable::timetable.generate.title');
    }

    public function mount(): void
    {
        $config = (array) config('classivo-timetable.generator', []);

        $this->form->fill([
            'class_section_ids' => array_keys($this->classSectionOptions()),
            'days' => array_map('strval', $config['days'] ?? app(GenerateTimetables::class)->defaultDays()),
            'max_consecutive' => $config['max_consecutive'] ?? null,
            'max_same_subject_per_day' => $config['max_same_subject_per_day'] ?? null,
            'seed' => $config['seed'] ?? 1,
            'time_limit_seconds' => $config['time_limit_seconds'] ?? 10,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __('classivo-timetable::timetable.'.$key);

        return $schema
            ->components([
                FormSection::make($t('generate.settings'))
                    ->columns(2)
                    ->schema([
                        CheckboxList::make('class_section_ids')
                            ->label($t('generate.class_sections'))
                            ->options(fn (): array => $this->classSectionOptions())
                            ->bulkToggleable()
                            ->columns(3)
                            ->required()
                            ->columnSpanFull(),
                        CheckboxList::make('days')
                            ->label($t('generate.days'))
                            ->options(TimetablePeriod::dayOptions())
                            ->columns(7)
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('max_consecutive')->label($t('generate.max_consecutive'))->numeric()->minValue(1)->maxValue(20),
                        TextInput::make('max_same_subject_per_day')->label($t('generate.max_same_subject_per_day'))->numeric()->minValue(1)->maxValue(20),
                        TextInput::make('seed')->label($t('generate.seed'))->numeric()->integer()->required(),
                        TextInput::make('time_limit_seconds')->label($t('generate.time_limit'))->numeric()->minValue(1)->maxValue(120)->required(),
                    ]),
            ])
            ->statePath('data');
    }

    /** Dry run: generate without saving and show the preview. */
    public function generatePreview(): void
    {
        $this->authorizeGenerate();
        $out = $this->run(save: false);
        $this->preview = $out ? $this->toPreview($out) : null;
    }

    /** Re-run the same deterministic generation and save it. */
    public function saveTimetables(): void
    {
        $this->authorizeGenerate();
        $out = $this->run(save: true);

        if (! $out) {
            return;
        }

        if ($out->saved()) {
            $this->preview = null;
            Notification::make()
                ->title(__('classivo-timetable::timetable.generate.saved', ['count' => count($out->timetableIds)]))
                ->success()
                ->send();

            return;
        }

        $this->preview = $this->toPreview($out);
        Notification::make()->title(__('classivo-timetable::timetable.generate.not_saved'))->danger()->send();
    }

    protected function authorizeGenerate(): void
    {
        if (! Authorize::can('generate')) {
            abort(403);
        }
    }

    protected function run(bool $save): ?GenerateTimetablesResult
    {
        $data = $this->form->getState();

        try {
            return app(GenerateTimetables::class)->handle(
                classSectionIds: array_map('intval', $data['class_section_ids'] ?? []),
                days: array_values(array_map('intval', $data['days'] ?? [])),
                maxConsecutive: filled($data['max_consecutive'] ?? null) ? (int) $data['max_consecutive'] : null,
                maxSameSubjectPerDay: filled($data['max_same_subject_per_day'] ?? null) ? (int) $data['max_same_subject_per_day'] : null,
                seed: (int) $data['seed'],
                timeLimitSeconds: (float) $data['time_limit_seconds'],
                save: $save,
            );
        } catch (InvalidProblem|InvalidArgumentException $e) {
            Notification::make()->title(__('classivo-timetable::timetable.generate.invalid'))->body($e->getMessage())->danger()->send();

            return null;
        }
    }

    /** @return array<string, mixed> */
    protected function toPreview(GenerateTimetablesResult $out): array
    {
        $result = $out->result;
        $periods = array_values((array) config('classivo-timetable.generator.periods', []));
        $days = array_values(array_map('intval', $this->data['days'] ?? []));
        sort($days);
        $sections = ClassSection::query()->with(['schoolClass', 'section'])->get()->keyBy('id');
        $subjects = Subject::query()->pluck('name', 'id');
        $teacherModel = config('classivo.user_model');
        $teachers = $teacherModel::query()->pluck('name', 'id');

        $grids = [];
        foreach ($result->placements as $p) {
            $grids[$p->lesson->classId][$p->period][$p->day] = trim(
                ($subjects[$p->lesson->subjectId] ?? '?')
                .($p->lesson->teacherId !== null ? ' · '.($teachers[$p->lesson->teacherId] ?? '') : '')
                .($p->roomId !== null ? ' · '.$p->roomId : '')
            );
        }

        $classes = [];
        foreach (array_map('intval', $this->data['class_section_ids'] ?? []) as $csId) {
            $rows = [];
            foreach ($periods as $i => $bell) {
                $no = $i + 1;
                $cells = [];
                foreach ($days as $day) {
                    $cells[$day] = ! empty($bell['break'])
                        ? __('classivo-timetable::timetable.form.break')
                        : ($grids[$csId][$no][$day] ?? '');
                }
                $rows[] = ['no' => $no, 'time' => $bell['start'].'–'.$bell['end'], 'break' => ! empty($bell['break']), 'cells' => $cells];
            }
            $classes[] = ['name' => $sections[$csId]?->name ?? (string) $csId, 'rows' => $rows];
        }

        $unplaced = [];
        foreach ($result->unplaced as $lessonId => $count) {
            [$csId, $subjectId, $teacherId] = array_pad(explode(':', (string) $lessonId, 3), 3, '-');
            $unplaced[] = __('classivo-timetable::timetable.generate.unplaced_row', [
                'class' => $sections[(int) $csId]?->name ?? $csId,
                'subject' => $subjects[(int) $subjectId] ?? $subjectId,
                'teacher' => $teachers[(int) $teacherId] ?? '—',
                'count' => $count,
            ]);
        }

        return [
            'status' => $result->status,
            'solved' => $result->isSolved(),
            'reasons' => $result->reasons,
            'unplaced' => $unplaced,
            'days' => array_map(fn (int $d) => __('classivo-timetable::timetable.days.'.$d), array_combine($days, $days) ?: []),
            'classes' => $classes,
            'stats' => $result->stats,
        ];
    }

    /** @return array<int, string> */
    protected function classSectionOptions(): array
    {
        return ClassSection::query()
            ->with(['schoolClass', 'section'])
            ->get()
            ->sortBy(fn (ClassSection $cs) => [$cs->schoolClass?->numeric_order ?? 0, $cs->section?->name ?? ''])
            ->mapWithKeys(fn (ClassSection $cs) => [$cs->id => $cs->name])
            ->all();
    }

    public function isSolvedPreview(): bool
    {
        return ($this->preview['status'] ?? null) === Result::SOLVED;
    }
}

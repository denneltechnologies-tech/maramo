<?php

namespace Classivo\Exams\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\ClassSection;
use Classivo\Exams\Contracts\ResultDocumentLinks;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamResult;
use Classivo\Exams\Services\ExamResultService;
use Classivo\Exams\Support\Authorize;
use Classivo\Exams\Support\ExamsPermissions;
use Filament\Actions\Action;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Exam Results — a custom page.
 *
 * Pick an Exam + Class-Section and [Generate Results]: the {@see ExamResultService}
 * computes totals / percentage / grade / division / rank and pass/fail per student.
 * [Publish] / [Unpublish] flip the published flag and are gated by
 * `exam.publish_result` (a user without it cannot publish). Document links (tabulation,
 * merit list, report cards) come from the optional {@see ResultDocumentLinks} binding. Deep-linkable with ?exam=<id>.
 *
 * @property-read Schema $form
 */
class ExamResults extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-exams::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-exams::ui.exam_result');
    }

    public function getTitle(): string
    {
        return __('classivo-exams::ui.exam_results');
    }

    protected static ?int $navigationSort = 16;

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return ExamsPermissions::can(ExamsPermissions::RESULTS_VIEW) || Authorize::can('publish_result');
    }

    public function mount(): void
    {
        $this->form->fill([
            'exam_id' => request()->integer('exam') ?: null,
            'class_section_id' => null,
            'results' => [],
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
                            ->live(),
                        Select::make('class_section_id')
                            ->label(__('classivo-exams::ui.class_section'))
                            ->options(fn (): array => $this->classSectionOptions())
                            ->required()
                            ->searchable()
                            ->live(),
                        Actions::make([
                            Action::make('generateBtn')
                                ->label(__('classivo-exams::ui.generate_results'))
                                ->icon(Heroicon::OutlinedCog6Tooth)
                                ->action(fn () => $this->generate()),
                            Action::make('publishBtn')
                                ->label(__('classivo-exams::ui.publish'))
                                ->icon(Heroicon::OutlinedCheckBadge)
                                ->color('success')
                                ->visible(fn (): bool => (bool) Authorize::can('publish_result'))
                                ->requiresConfirmation()
                                ->action(fn () => $this->publish()),
                            Action::make('unpublishBtn')
                                ->label(__('classivo-exams::ui.unpublish'))
                                ->icon(Heroicon::OutlinedLockClosed)
                                ->color('warning')
                                ->visible(fn (): bool => (bool) Authorize::can('publish_result'))
                                ->requiresConfirmation()
                                ->action(fn () => $this->unpublish()),
                        ]),
                    ]),

                FormSection::make(__('classivo-exams::ui.result_downloads'))
                    ->schema([
                        Actions::make(fn (): array => $this->documentActions())
                            ->visible(fn (): bool => $this->documentActions() !== []),
                        Repeater::make('results')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(12)
                            ->schema([
                                TextInput::make('rank')->label(__('classivo-exams::ui.rank'))->disabled()->columnSpan(1),
                                TextInput::make('student')->label(__('classivo-exams::ui.student'))->disabled()->columnSpan(3),
                                TextInput::make('total_display')->label(__('classivo-exams::ui.total'))->disabled()->columnSpan(2),
                                TextInput::make('percentage')->label(__('classivo-exams::ui.percent_short'))->disabled()->columnSpan(2),
                                TextInput::make('grade')->label(__('classivo-exams::ui.grade'))->disabled()->columnSpan(2),
                                TextInput::make('result')->label(__('classivo-exams::ui.result'))->disabled()->columnSpan(2),
                            ])
                            ->visible(fn (Get $get): bool => filled($get('results'))),
                    ]),
            ])
            ->statePath('data');
    }

    /** Generate results for the chosen exam + class-section, then reload the table. */
    public function generate(): void
    {
        abort_unless(ExamsPermissions::can(ExamsPermissions::RESULTS_GENERATE), 403);

        $exam = Exam::find($this->data['exam_id'] ?? null);
        $classSection = ClassSection::find($this->data['class_section_id'] ?? null);

        if (! $exam || ! $classSection) {
            Notification::make()->warning()->title(__('classivo-exams::ui.choose_an_exam_and_class_section_first'))->send();

            return;
        }

        $results = app(ExamResultService::class)->generate($exam, $classSection);

        $this->loadResults();

        Notification::make()->success()->title(__('classivo-exams::ui.generated_results', ['count' => $results->count()]))->send();
    }

    /** Publish results (gated by `exam.publish_result`). */
    public function publish(): void
    {
        if (! Authorize::can('publish_result')) {
            Notification::make()->danger()->title(__('classivo-exams::ui.you_are_not_allowed_to_publish_results'))->send();

            return;
        }

        $exam = Exam::find($this->data['exam_id'] ?? null);

        if (! $exam) {
            Notification::make()->warning()->title(__('classivo-exams::ui.choose_an_exam_first'))->send();

            return;
        }

        app(ExamResultService::class)->publish($exam);
        $this->loadResults();

        Notification::make()->success()->title(__('classivo-exams::ui.results_published'))->send();
    }

    /** Unpublish results (gated by `exam.publish_result`). */
    public function unpublish(): void
    {
        if (! Authorize::can('publish_result')) {
            Notification::make()->danger()->title(__('classivo-exams::ui.you_are_not_allowed_to_unpublish_results'))->send();

            return;
        }

        $exam = Exam::find($this->data['exam_id'] ?? null);

        if (! $exam) {
            return;
        }

        app(ExamResultService::class)->unpublish($exam);
        $this->loadResults();

        Notification::make()->success()->title(__('classivo-exams::ui.results_unpublished'))->send();
    }

    /** Load the persisted result rows for the chosen scope into the display repeater. */
    protected function loadResults(): void
    {
        $examId = $this->data['exam_id'] ?? null;
        $classSectionId = $this->data['class_section_id'] ?? null;

        if (! $examId || ! $classSectionId) {
            $this->data['results'] = [];

            return;
        }

        $this->data['results'] = ExamResult::query()
            ->where('exam_id', $examId)
            ->where('class_section_id', $classSectionId)
            ->with(['student', 'grade', 'division'])
            ->orderBy('rank')
            ->get()
            ->map(fn (ExamResult $r): array => [
                'rank' => $r->rank,
                'student' => $r->student?->full_name,
                'total_display' => rtrim(rtrim(number_format((float) $r->total_marks, 2), '0'), '.').' / '.rtrim(rtrim(number_format((float) $r->total_full_marks, 2), '0'), '.'),
                'percentage' => rtrim(rtrim(number_format((float) $r->percentage, 2), '0'), '.').'%',
                'grade' => $r->grade?->name ?? '—',
                'result' => strtoupper((string) $r->result),
            ])
            ->all();
    }

    /**
     * Document links (tabulation sheet, merit list, report cards ...) contributed by
     * whichever package binds {@see ResultDocumentLinks}. Empty when none is bound.
     *
     * @return array<int, Action>
     */
    protected function documentActions(): array
    {
        $exam = Exam::find($this->data['exam_id'] ?? null);
        $classSection = ClassSection::find($this->data['class_section_id'] ?? null);

        if (! $exam || ! $classSection || ! app()->bound(ResultDocumentLinks::class)) {
            return [];
        }

        $actions = [];

        foreach (app(ResultDocumentLinks::class)->classLinks($exam, $classSection) as $label => $url) {
            $actions[] = Action::make('doc_'.md5((string) $label))
                ->label((string) $label)
                ->color('gray')
                ->url($url, shouldOpenInNewTab: true);
        }

        return $actions;
    }

    /** @return array<int, string> */
    protected function classSectionOptions(): array
    {
        return ClassSection::query()
            ->with(['schoolClass', 'section'])
            ->get()
            ->sortBy('name')
            ->mapWithKeys(fn (ClassSection $cs): array => [$cs->id => $cs->name])
            ->all();
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
            ->id('form');
    }
}

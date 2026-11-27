<?php

namespace Classivo\Fees\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Fees\Models\FeeCarryForward as FeeCarryForwardModel;
use Classivo\Fees\Services\FeeCarryForwardService;
use Classivo\Fees\Support\FeesPermissions;
use Classivo\Fees\Support\Money;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Fees Carry Forward — the DESK tool for opening balances: pick the
 * source session + class, load each student's prior balance, adjust the odd figure
 * by hand, save. The whole-year bulk job lives on {@see FeesSessionRollover}; both
 * write through the same {@see FeeCarryForwardService}, so a balance carried from
 * either place behaves identically.
 *
 * Saving raises each carried balance as a real fee line in the current session
 * (linked to the carry-forward register row), so it is collectable on Collect
 * Fees and appears in Search Due Fees. The class filter matches the class the
 * student sat in during the SOURCE session (student_session_records).
 *
 * @property-read Schema $form
 */
class FeesCarryForward extends FeesPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightCircle;

    protected static ?int $navigationSort = 46;

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public static function permission(): string
    {
        return FeesPermissions::CARRY_FORWARD;
    }

    protected static function englishTitle(): string
    {
        return 'Carry Forward';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'from_session_id' => null,
            'class_id' => null,
            'due_date' => null,
            'carries' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make(__('Source'))
                    ->columns(3)
                    ->schema([
                        Select::make('from_session_id')
                            ->label(__('From session'))
                            ->options(fn (): array => AcademicSession::query()
                                ->when(current_session_id(), fn ($q, $id) => $q->where('id', '!=', $id))
                                ->orderByDesc('id')
                                ->pluck('name', 'id')
                                ->all())
                            ->required()
                            ->live(),
                        Select::make('class_id')
                            ->label(__('Class'))
                            ->helperText(__('A class of the SOURCE session.'))
                            // Classes are session-scoped rows, so the picker must be
                            // filled from the session being carried FROM — offering
                            // the current session's classes would list ids that can
                            // never match the old cohort.
                            ->options(fn (Get $get): array => $this->sourceClassOptions($get('from_session_id')))
                            ->searchable()
                            ->live(),
                        DatePicker::make('due_date')
                            ->label(__('Opening balance due on'))
                            ->helperText(__('Blank = this session\'s start date.'))
                            ->native(false),
                        Actions::make([
                            Action::make('load')
                                ->label(__('Load balances'))
                                ->icon(Heroicon::OutlinedMagnifyingGlass)
                                ->action(fn () => $this->loadBalances()),
                        ]),
                    ]),
                FormSection::make(__('Carry forward'))
                    ->visible(fn (Get $get): bool => filled($get('from_session_id')))
                    ->schema([
                        Repeater::make('carries')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(12)
                            ->schema([
                                TextInput::make('name')->label(__('Student'))->disabled()->columnSpan(3),
                                TextInput::make('prev_balance')->label(__('Previous balance'))->disabled()->columnSpan(2),
                                // What an earlier run already carried, so a re-run
                                // reads as the no-op it is instead of new money.
                                TextInput::make('already_carried')->label(__('Already carried'))->disabled()->columnSpan(2),
                                TextInput::make('amount')->label(__('Carry amount'))->numeric()->minValue(0)->columnSpan(2),
                                TextInput::make('note')->label(__('Note'))->columnSpan(3),
                            ]),
                        Actions::make([
                            Action::make('save')
                                ->label(__('Save carry forward'))
                                ->icon(Heroicon::OutlinedArrowRightCircle)
                                ->color('primary')
                                ->action(fn () => $this->save()),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Load each student's outstanding balance in the source session.
     *
     * The whole computation — including the class filter, which must resolve the
     * cohort through `student_session_records` rather than the student's current
     * class — lives in {@see FeeCarryForwardService::preview()}, the same method
     * the rollover page and the commit path use. One query, one answer, three
     * callers.
     */
    public function loadBalances(): void
    {
        $fromSessionId = $this->data['from_session_id'] ?? null;

        if (! $fromSessionId) {
            Notification::make()->warning()->title(__('Choose a source session first.'))->send();

            return;
        }

        $targetSessionId = current_session_id();

        if ($targetSessionId === null) {
            Notification::make()->warning()->title(__('No current academic session is set.'))->send();

            return;
        }

        if ((int) $fromSessionId === (int) $targetSessionId) {
            Notification::make()->warning()->title(__('The source session must differ from the current one.'))->send();

            return;
        }

        $this->data['carries'] = app(FeeCarryForwardService::class)
            ->preview((int) $fromSessionId, (int) $targetSessionId, $this->data['class_id'] ?? null)
            ->all();

        if (empty($this->data['carries'])) {
            Notification::make()->info()->title(__('No outstanding balances in that session/class.'))->send();
        }
    }

    /**
     * Raise the opening-due lines in the current session.
     *
     * Delegated to {@see FeeCarryForwardService::carryForwardMany()}, which writes
     * BOTH halves: the {@see FeeCarryForwardModel} register row (provenance) and the
     * allocation that makes the money collectable. Writing only the former is what
     * made this page a no-op for its whole life.
     *
     * Idempotent — both writes are keyed find-or-create, so a double-clicked save
     * restates the same two rows instead of billing the school twice.
     */
    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $fromSessionId = $this->data['from_session_id'] ?? null;
        $targetSessionId = current_session_id();

        if (! $fromSessionId || $targetSessionId === null || (int) $fromSessionId === (int) $targetSessionId) {
            Notification::make()->warning()->title(__('Choose a source session other than the current one.'))->send();

            return;
        }

        $result = app(FeeCarryForwardService::class)->carryForwardMany(
            $this->data['carries'] ?? [],
            (int) $fromSessionId,
            (int) $targetSessionId,
            array_filter([
                'due_date' => $this->data['due_date'] ?? null,
                'created_by' => auth()->id(),
            ]),
        );

        Notification::make()
            ->success()
            ->title(__('Carried :count opening balance(s) forward.', ['count' => $result['students']]))
            ->body(__(':amount is now payable on Collect Fees.', ['amount' => Money::format($result['amount'])]))
            ->send();
    }

    /**
     * Classes OF THE SOURCE SESSION (see the class docblock).
     *
     * @return array<int, string>
     */
    private function sourceClassOptions(mixed $sessionId): array
    {
        if (! $sessionId) {
            return [];
        }

        return SchoolClass::withoutGlobalScope(SessionScope::class)
            ->where('session_id', (int) $sessionId)
            ->orderBy('numeric_order')
            ->pluck('name', 'id')
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
        return Form::make([EmbeddedSchema::make('form')])->id('form');
    }
}

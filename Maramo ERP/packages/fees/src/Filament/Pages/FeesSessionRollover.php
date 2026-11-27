<?php

namespace Classivo\Fees\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Fees\Services\FeeCarryForwardService;
use Classivo\Fees\Services\FeeSessionRolloverService;
use Classivo\Fees\Support\FeesPermissions;
use Classivo\Fees\Support\Labels;
use Classivo\Fees\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fees Session Rollover — opening a new academic year's fees in one pass.
 *
 * Step 1: source session → target session, a percentage increment with a rounding
 * rule, and how far to move due dates. Step 2: [Preview] shows exactly what would be
 * written — every fee head with its old and new price, marked create / skip /
 * blocked, plus how many students would bring an opening balance with them and how
 * much. Step 3: [Commit] writes it.
 *
 * TWO HALVES OF ONE ANNUAL JOB. A school does not "clone a price list" and
 * separately "carry balances" — it opens next year. So this page runs both through
 * their services in ONE transaction ({@see FeeSessionRolloverService} for the
 * structure, {@see FeeCarryForwardService} for the balances) and records the run in
 * `fee_session_rollovers`. Either half can be switched off for the schools that
 * genuinely want one without the other.
 *
 * NOTHING IS WRITTEN BEFORE [Commit]. Preview runs the same code the commit runs —
 * see FeeSessionRolloverService::preview() — so it cannot promise more than the
 * commit delivers.
 *
 * PER-STUDENT EDITING STAYS ON {@see FeesCarryForward}. This page shows the balance
 * side as a total, not 450 repeater rows: the bulk flow should not become slower
 * than the desk tool it exists to replace. An operator who needs to adjust one
 * child's opening balance does it there, after (or instead of) the bulk run.
 *
 * @property-read Schema $form
 */
class FeesSessionRollover extends FeesPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?int $navigationSort = 45;

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    /** True once a preview has been built — gates the whole second section. */
    public bool $previewed = false;

    public static function permission(): string
    {
        return FeesPermissions::ROLLOVER;
    }

    protected static function englishTitle(): string
    {
        return 'Session Rollover';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'from_session_id' => null,
            'to_session_id' => current_session_id(),
            'percent' => 0,
            'rounding' => '10',
            'rounding_mode' => 'nearest',
            'shift_months' => 12,
            'clone_structure' => true,
            'carry_balances' => true,
            'class_id' => null,
            'carry_due_date' => null,
            'structure' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make(__('1. Sessions and pricing'))
                    ->columns(3)
                    ->schema([
                        Select::make('from_session_id')
                            ->label(__('Copy from session'))
                            ->options(fn (): array => $this->sessionOptions())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $this->resetPreview($set)),
                        Select::make('to_session_id')
                            ->label(__('Into session'))
                            ->options(fn (): array => $this->sessionOptions())
                            ->default(current_session_id())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $this->resetPreview($set);

                                if ($get('from_session_id') && $get('to_session_id')) {
                                    $set('shift_months', app(FeeSessionRolloverService::class)
                                        ->shiftMonths((int) $get('from_session_id'), (int) $get('to_session_id')));
                                }
                            }),
                        TextInput::make('percent')
                            ->label(__('Increase by (%)'))
                            ->helperText(__('0 keeps last year\'s prices.'))
                            ->numeric()
                            ->default(0)
                            ->minValue(-100)
                            ->maxValue(500)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $this->resetPreview($set)),
                        Select::make('rounding')
                            ->label(__('Round to'))
                            ->options(Labels::t(FeeSessionRolloverService::ROUNDING_STEPS))
                            ->default('10')
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $this->resetPreview($set)),
                        Select::make('rounding_mode')
                            ->label(__('Rounding direction'))
                            ->options(Labels::t(FeeSessionRolloverService::ROUNDING_MODES))
                            ->default('nearest')
                            ->visible(fn (Get $get): bool => (int) $get('rounding') > 0)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $this->resetPreview($set)),
                        TextInput::make('shift_months')
                            ->label(__('Shift due dates by (months)'))
                            ->helperText(__('Defaults to the gap between the two sessions.'))
                            ->numeric()
                            ->default(12)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $this->resetPreview($set)),
                    ]),

                FormSection::make(__('2. What to roll over'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('clone_structure')
                            ->label(__('Copy the fee structure (groups + heads)'))
                            ->helperText(__('Never touches who is allocated what — only the price list.'))
                            ->default(true)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $this->resetPreview($set)),
                        Toggle::make('carry_balances')
                            ->label(__('Carry outstanding balances forward'))
                            ->helperText(__('Raises each student\'s unpaid balance as a collectable opening-due line in the target session.'))
                            ->default(true)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $this->resetPreview($set)),
                        Select::make('class_id')
                            ->label(__('Only this class (balances)'))
                            ->placeholder(__('All classes'))
                            ->helperText(__('A class of the SOURCE session — matched on where the student sat that year.'))
                            ->options(fn (Get $get): array => $this->sourceClassOptions($get('from_session_id')))
                            ->searchable()
                            ->visible(fn (Get $get): bool => (bool) $get('carry_balances'))
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $this->resetPreview($set)),
                        DatePicker::make('carry_due_date')
                            ->label(__('Opening balance due on'))
                            ->helperText(__('Blank = the target session\'s start date.'))
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('carry_balances')),

                        Actions::make([
                            Action::make('runPreview')
                                ->label(__('Preview'))
                                ->icon(Heroicon::OutlinedEye)
                                ->action(fn () => $this->runPreview()),
                        ])->columnSpanFull(),

                        Placeholder::make('previous_runs')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (Get $get): string => $this->previousRunsNotice($get('from_session_id'), $get('to_session_id')))
                            ->visible(fn (Get $get): bool => $this->previousRunsNotice($get('from_session_id'), $get('to_session_id')) !== ''),
                    ]),

                FormSection::make(__('3. Preview'))
                    ->visible(fn (): bool => $this->previewed)
                    ->schema([
                        Placeholder::make('summary')
                            ->hiddenLabel()
                            ->content(fn (): string => $this->summaryLine()),

                        Repeater::make('structure')
                            ->label(__('Fee heads'))
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(12)
                            ->visible(fn (Get $get): bool => (bool) $get('clone_structure'))
                            ->schema([
                                Toggle::make('include')
                                    ->label(__('Copy'))
                                    ->inline(false)
                                    ->columnSpan(1),
                                TextInput::make('group')->label(__('Group'))->disabled()->columnSpan(2),
                                TextInput::make('head')->label(__('Head'))->disabled()->columnSpan(2),
                                TextInput::make('class')->label(__('Class'))->disabled()->columnSpan(2),
                                TextInput::make('old_amount')->label(__('Was'))->disabled()->columnSpan(1),
                                TextInput::make('new_amount')->label(__('Becomes'))->disabled()->columnSpan(1),
                                TextInput::make('new_due_date')->label(__('Due'))->disabled()->columnSpan(1),
                                TextInput::make('reason')->label(__('Note'))->disabled()->columnSpan(2),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    // -----------------------------------------------------------------
    // Preview
    // -----------------------------------------------------------------

    /**
     * Build the preview. Named runPreview() and not preview() on purpose — a public
     * method sharing a name with a synced public property is the documented
     * Livewire footgun in {@see SendCommunication}, and keeping the verb distinct
     * from the state keeps the whole page immune to it.
     */
    public function runPreview(): void
    {
        $data = $this->data;
        $from = (int) ($data['from_session_id'] ?? 0);
        $to = (int) ($data['to_session_id'] ?? 0);

        if (! $from || ! $to) {
            Notification::make()->warning()->title(__('Choose both sessions first.'))->send();

            return;
        }

        if ($from === $to) {
            Notification::make()->warning()->title(__('Source and target session must differ.'))->send();

            return;
        }

        if (! ($data['clone_structure'] ?? false) && ! ($data['carry_balances'] ?? false)) {
            Notification::make()->warning()->title(__('Nothing selected — pick the structure, the balances, or both.'))->send();

            return;
        }

        $this->data['structure'] = ($data['clone_structure'] ?? false)
            ? app(FeeSessionRolloverService::class)
                ->preview($from, $to, $this->rolloverOptions())
                // Blocked heads cannot be written, so they arrive unticked: the
                // operator sees the reason instead of a promise the commit breaks.
                ->map(fn (array $row): array => $row + ['include' => $row['action'] === 'create'])
                ->all()
            : [];

        $this->previewed = true;

        if (($data['clone_structure'] ?? false) && $this->data['structure'] === []) {
            Notification::make()->info()->title(__('That session has no fee heads to copy.'))->send();
        }
    }

    /** Counts + money for the preview header — one line an operator can check. */
    public function summaryLine(): string
    {
        $rows = collect($this->data['structure'] ?? []);
        $parts = [];

        if ($this->data['clone_structure'] ?? false) {
            $parts[] = __(':create head(s) to create, :skip already present, :blocked blocked', [
                'create' => $rows->where('action', 'create')->count(),
                'skip' => $rows->where('action', 'skip')->count(),
                'blocked' => $rows->where('action', 'blocked')->count(),
            ]);
        }

        if ($this->data['carry_balances'] ?? false) {
            $balances = $this->balancePreview();
            $parts[] = __(':count student(s) carrying :amount forward', [
                'count' => $balances->count(),
                'amount' => Money::format($balances->sum(fn (array $r): float => (float) $r['amount'])),
            ]);
        }

        return implode(' · ', $parts) ?: __('Nothing to do.');
    }

    /**
     * The balance side of the preview, from the shared carry-forward service.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function balancePreview(): Collection
    {
        $from = (int) ($this->data['from_session_id'] ?? 0);
        $to = (int) ($this->data['to_session_id'] ?? 0);

        if (! $from || ! $to || ! ($this->data['carry_balances'] ?? false)) {
            return collect();
        }

        return app(FeeCarryForwardService::class)
            ->preview($from, $to, $this->data['class_id'] ?? null);
    }

    /** Warn — never block — when this pair has been rolled over before. */
    public function previousRunsNotice(mixed $from, mixed $to): string
    {
        if (! $from || ! $to) {
            return '';
        }

        $runs = app(FeeSessionRolloverService::class)->previousRuns((int) $from, (int) $to);

        if ($runs->isEmpty()) {
            return '';
        }

        $last = $runs->first();

        return __('Already rolled over :count time(s), most recently on :date. Running again only fills gaps; nothing is priced or billed twice.', [
            'count' => $runs->count(),
            'date' => $last->created_at?->format('d M Y') ?? '—',
        ]);
    }

    // -----------------------------------------------------------------
    // Commit
    // -----------------------------------------------------------------

    /**
     * Write the rollover. Both halves share one transaction: a school that ends up
     * with next year's prices but no opening balances (or the reverse) is in a
     * worse state than one that has to press the button again.
     */
    public function commitRollover(): void
    {
        abort_unless(static::canAccess(), 403);

        if (! $this->previewed) {
            Notification::make()->warning()->title(__('Preview first — nothing is written until you have seen it.'))->send();

            return;
        }

        $data = $this->form->getState();
        $from = (int) ($data['from_session_id'] ?? 0);
        $to = (int) ($data['to_session_id'] ?? 0);

        if (! $from || ! $to || $from === $to) {
            Notification::make()->warning()->title(__('Choose two different sessions.'))->send();

            return;
        }

        $options = $this->rolloverOptions();
        $rollover = app(FeeSessionRolloverService::class);
        $carry = app(FeeCarryForwardService::class);

        $summary = DB::transaction(function () use ($data, $from, $to, $options, $rollover, $carry): array {
            $result = ['groups' => 0, 'created' => 0, 'skipped' => 0, 'blocked' => 0, 'students' => 0, 'amount' => 0.0];

            if ($data['clone_structure'] ?? false) {
                // Only the rows still ticked in the preview — the operator's edit of
                // the proposal is the proposal.
                $only = collect($data['structure'] ?? [])
                    ->filter(fn (array $row): bool => (bool) ($row['include'] ?? false))
                    ->pluck('fee_master_id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                $result = array_merge($result, $rollover->commit($from, $to, $options, $only));
            }

            if ($data['carry_balances'] ?? false) {
                $carried = $carry->carryForwardMany(
                    $carry->preview($from, $to, $data['class_id'] ?? null),
                    $from,
                    $to,
                    array_filter([
                        'due_date' => $data['carry_due_date'] ?? null,
                        'created_by' => auth()->id(),
                    ]),
                );

                $result['students'] = $carried['students'];
                $result['amount'] = $carried['amount'];
            }

            $rollover->record($from, $to, $options, $result);

            return $result;
        });

        $this->previewed = false;
        $this->data['structure'] = [];

        Notification::make()
            ->success()
            ->title(__('Session rollover committed.'))
            ->body(__(':created fee head(s) created, :skipped skipped, :blocked blocked. :students student(s) carried :amount forward.', [
                'created' => $summary['created'],
                'skipped' => $summary['skipped'],
                'blocked' => $summary['blocked'],
                'students' => $summary['students'],
                'amount' => Money::format((float) $summary['amount']),
            ]))
            ->persistent()
            ->send();
    }

    public function resetPage(): void
    {
        $this->previewed = false;
        $this->data['structure'] = [];
    }

    /** @return array<string, mixed> */
    protected function rolloverOptions(): array
    {
        return [
            'percent' => (float) ($this->data['percent'] ?? 0),
            'rounding' => (int) ($this->data['rounding'] ?? 0),
            'rounding_mode' => (string) ($this->data['rounding_mode'] ?? 'nearest'),
            'shift_months' => (int) ($this->data['shift_months'] ?? 12),
        ];
    }

    /** Any change to the terms invalidates the preview it produced. */
    protected function resetPreview(Set $set): void
    {
        $this->previewed = false;
        $set('structure', []);
    }

    // -----------------------------------------------------------------
    // Page shell
    // -----------------------------------------------------------------

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
            ->livewireSubmitHandler('commitRollover')
            ->footer([
                Actions::make([
                    Action::make('commitRollover')
                        ->label(__('Commit rollover'))
                        ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                        // A live school's price list for a whole year — confirm it,
                        // and say in the modal what is about to happen.
                        ->requiresConfirmation()
                        ->modalHeading(__('Commit this rollover?'))
                        ->modalDescription(fn (): string => $this->summaryLine())
                        ->disabled(fn (): bool => ! $this->previewed)
                        ->submit('commitRollover'),
                    Action::make('resetPage')
                        ->label(__('Reset'))
                        ->color('gray')
                        ->action(fn () => $this->resetPage()),
                ]),
            ]);
    }

    // -----------------------------------------------------------------
    // Option helpers
    // -----------------------------------------------------------------

    /** @return array<int, string> */
    private function sessionOptions(): array
    {
        return AcademicSession::query()
            ->orderByDesc('is_current')
            ->orderByDesc('id')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Classes OF THE SOURCE SESSION. `classes` is session-scoped, so the current
     * session's class ids name different rows entirely — offering them here would
     * filter the source cohort on ids that cannot match.
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
}

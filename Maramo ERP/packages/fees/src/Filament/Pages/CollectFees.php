<?php

namespace Classivo\Fees\Filament\Pages;

use BackedEnum;
use Classivo\Fees\Models\FeeDiscount;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Services\FeeAccrualService;
use Classivo\Fees\Services\FeeAllocationService;
use Classivo\Fees\Services\FeeCollectionService;
use Classivo\Fees\Support\DiscountApprovalPolicy;
use Classivo\Fees\Support\FeeDues;
use Classivo\Fees\Support\FeesPermissions;
use Classivo\Fees\Support\Money;
use Classivo\Students\Models\Student;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Collect Fees — the counter screen. Pick a student, see every line that still
 * owes money, enter what is being paid now (plus a concession or late fine where
 * allowed), and mint a numbered receipt in one transaction.
 *
 * Every line is re-priced against the LOCKED allocation inside the transaction
 * before anything is written, so a sheet left open while another cashier took a
 * payment cannot over-collect: the second attempt is refused and reloaded.
 * Online gateway payments are not part of this package.
 */
class CollectFees extends FeesPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 40;

    public ?array $data = [];

    public ?int $lastReceiptId = null;

    public static function permission(): string
    {
        return FeesPermissions::COLLECT;
    }

    protected static function englishTitle(): string
    {
        return 'Collect Fees';
    }

    public function mount(): void
    {
        $this->form->fill([
            'student_id' => request()->integer('student') ?: null,
            'date' => now()->toDateString(),
            'mode' => 'cash',
            'lines' => [],
            'freehand' => [],
        ]);

        if (! empty($this->data['student_id'])) {
            $this->loadStudent();
        }
    }

    public function form(Schema $schema): Schema
    {
        $symbol = Money::symbol();
        $mayDiscount = fn (): bool => FeesPermissions::can(FeesPermissions::DISCOUNT);

        return $schema
            ->components([
                FormSection::make(__('Student'))
                    ->columns(4)
                    ->schema([
                        Select::make('student_id')
                            ->label(__('Search student (name / admission no)'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->studentOptions($search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->studentLabel((int) $value))
                            ->live()
                            ->afterStateUpdated(fn () => $this->loadStudent())
                            ->columnSpan(2),
                        TextInput::make('summary_total')->label(__('Total fees'))->disabled()->prefix($symbol),
                        TextInput::make('summary_paid')->label(__('Paid'))->disabled()->prefix($symbol),
                        TextInput::make('summary_discount')->label(__('Discount'))->disabled()->prefix($symbol),
                        TextInput::make('summary_balance')->label(__('Balance'))->disabled()->prefix($symbol),
                    ]),
                FormSection::make(__('Due fees'))
                    ->visible(fn (Get $get): bool => filled($get('student_id')))
                    ->schema([
                        Actions::make([
                            Action::make('payFull')
                                ->label(__('Pay full (all lines)'))
                                ->icon(Heroicon::OutlinedCheckCircle)
                                ->color('success')
                                ->action(fn () => $this->payFull()),
                            Action::make('applyDiscount')
                                ->label(__('Apply discount to lines'))
                                ->icon(Heroicon::OutlinedReceiptPercent)
                                ->color('gray')
                                ->visible($mayDiscount)
                                ->schema([
                                    Select::make('discount_id')
                                        ->label(__('Discount'))
                                        ->options(fn (): array => FeeDiscount::query()->usableOn()->orderBy('name')->pluck('name', 'id')->all())
                                        ->required(),
                                ])
                                ->action(fn (array $data) => $this->applyDiscount((int) $data['discount_id'])),
                        ]),
                        Placeholder::make('no_due')
                            ->hiddenLabel()
                            ->content(__('No due fees — this student is fully paid.'))
                            ->visible(fn (Get $get): bool => blank($get('lines'))),
                        Repeater::make('lines')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(12)
                            ->visible(fn (Get $get): bool => filled($get('lines')))
                            ->schema([
                                TextInput::make('head')->label(__('Fee head'))->disabled()->columnSpan(3),
                                TextInput::make('due_date')->label(__('Due date'))->disabled()->columnSpan(2),
                                TextInput::make('balance')->label(__('Balance'))->disabled()->columnSpan(2),
                                TextInput::make('discount')->label(__('Discount'))->numeric()->minValue(0)->default(0)->columnSpan(1)
                                    ->visible($mayDiscount),
                                TextInput::make('fine')->label(__('Late fine'))->numeric()->minValue(0)->default(0)->columnSpan(2),
                                TextInput::make('amount')->label(__('Pay now'))->numeric()->minValue(0)->default(0)->columnSpan(2),
                            ]),
                        TextInput::make('discount_reason')
                            ->label(__('Reason for discount'))
                            ->helperText(__('Required when any line carries a discount.'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $mayDiscount() && filled($get('lines'))),
                        TextInput::make('fine_reason')
                            ->label(__('Reason for manual fine'))
                            ->helperText(__('Only needed to charge more fine than the fee rule allows.'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $mayDiscount() && filled($get('lines'))),
                    ]),
                FormSection::make(__('Other fees'))
                    ->visible(fn (Get $get): bool => filled($get('student_id')))
                    ->collapsed()
                    ->schema([
                        Repeater::make('freehand')
                            ->hiddenLabel()
                            ->columns(2)
                            ->addActionLabel(__('Add other fee'))
                            ->schema([
                                TextInput::make('label')
                                    ->label(__('Fee title'))
                                    ->required()
                                    ->datalist([FeeCollectionService::ADVANCE_LABEL]),
                                TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0)->required()->prefix($symbol),
                            ])
                            ->defaultItems(0),
                    ]),
                FormSection::make(__('Payment'))
                    ->visible(fn (Get $get): bool => filled($get('student_id')))
                    ->columns(3)
                    ->schema([
                        DatePicker::make('date')->label(__('Payment date'))->required()->native(false)->default(now()),
                        Select::make('mode')
                            ->label(__('Payment mode'))
                            ->options(fn (): array => array_map(fn (string $label): string => __($label), FeePayment::MANUAL_MODES))
                            ->default('cash')
                            ->required(),
                        TextInput::make('reference_no')->label(__('Cheque / transaction / reference no')),
                        Textarea::make('note')->label(__('Note'))->rows(2)->columnSpanFull(),
                        Actions::make([
                            Action::make('collect')
                                ->label(__('Collect and print receipt'))
                                ->icon(Heroicon::OutlinedBanknotes)
                                ->color('primary')
                                ->action(fn () => $this->collect()),
                            Action::make('printReceipt')
                                ->label(__('Print last receipt'))
                                ->icon(Heroicon::OutlinedPrinter)
                                ->color('gray')
                                ->visible(fn (): bool => $this->lastReceiptId !== null)
                                ->url(fn (): ?string => $this->lastReceiptId ? route('fees.receipt', ['payment' => $this->lastReceiptId]) : null, shouldOpenInNewTab: true),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function loadStudent(): void
    {
        $student = Student::find($this->data['student_id'] ?? null);

        if (! $student) {
            $this->data['lines'] = [];

            return;
        }

        $summary = FeeDues::summary((int) $student->getKey());
        $this->data['summary_total'] = number_format($summary['total'], 2, '.', '');
        $this->data['summary_paid'] = number_format($summary['paid'], 2, '.', '');
        $this->data['summary_discount'] = number_format($summary['discount'], 2, '.', '');
        $this->data['summary_balance'] = number_format($summary['balance'], 2, '.', '');

        $accrual = app(FeeAccrualService::class);

        $this->data['lines'] = app(FeeAllocationService::class)->allocationsFor($student)
            ->filter(fn (FeeStudentAllocation $a): bool => $a->balance() > 0)
            ->map(fn (FeeStudentAllocation $a): array => [
                'allocation_id' => $a->id,
                'head' => $a->feeMaster?->feeType?->name ?? __('Fee'),
                'due_date' => $a->due_date?->format('d M Y') ?? '—',
                'balance' => number_format($a->balance(), 2, '.', ''),
                'discount' => 0,
                'fine' => number_format($accrual->unchargedFine($a), 2, '.', ''),
                'amount' => 0,
            ])
            ->values()
            ->all();
    }

    public function payFull(): void
    {
        $lines = $this->data['lines'] ?? [];

        foreach ($lines as $i => $line) {
            $lines[$i]['amount'] = $line['balance'] ?? 0;
        }

        $this->data['lines'] = $lines;
    }

    public function applyDiscount(int $discountId): void
    {
        if (! FeesPermissions::can(FeesPermissions::DISCOUNT)) {
            Notification::make()->danger()->title(__('You are not allowed to apply discounts.'))->send();

            return;
        }

        $discount = FeeDiscount::find($discountId);

        if (! $discount) {
            return;
        }

        if (($unusable = $discount->unusableReason()) !== null) {
            Notification::make()->warning()->title($unusable)->send();

            return;
        }

        $lines = $this->data['lines'] ?? [];

        foreach ($lines as $i => $line) {
            $lines[$i]['discount'] = number_format($discount->computeFor((float) ($line['balance'] ?? 0)), 2, '.', '');
            $lines[$i]['discount_id'] = $discount->id;
        }

        $this->data['lines'] = $lines;

        if (blank($this->data['discount_reason'] ?? null)) {
            $this->data['discount_reason'] = __('Concession').': '.$discount->name;
        }

        Notification::make()->success()->title(__('Discount applied to due lines.'))->send();
    }

    public function collect(): void
    {
        if (! FeesPermissions::can(FeesPermissions::COLLECT)) {
            Notification::make()->danger()->title(__('You are not allowed to collect fees.'))->send();

            return;
        }

        $student = Student::find($this->data['student_id'] ?? null);

        if (! $student) {
            Notification::make()->warning()->title(__('Select a student first.'))->send();

            return;
        }

        $reason = $this->guardDiscounts();

        if ($reason === false) {
            return;
        }

        $lines = $this->buildAllocationLines($reason);

        foreach ($this->data['freehand'] ?? [] as $line) {
            $lines[] = [
                'label' => $line['label'] ?? __('Other fee'),
                'amount' => (float) ($line['amount'] ?? 0),
            ];
        }

        $collectable = array_filter($lines, fn (array $l): bool => (float) ($l['amount'] ?? 0) > 0
            || (float) ($l['discount'] ?? 0) > 0
            || (float) ($l['fine'] ?? 0) > 0);

        if ($collectable === []) {
            Notification::make()->warning()->title(__('Nothing to collect — enter an amount.'))->send();

            return;
        }

        $refusal = null;

        try {
            $payment = DB::transaction(function () use ($student, $lines, $reason, &$refusal): ?FeePayment {
                [$priced, $refusal] = $this->repriceAgainstLockedBalances($student, $lines);

                if ($refusal !== null) {
                    return null;
                }

                return app(FeeCollectionService::class)->collect($student, $priced, [
                    'date' => $this->data['date'] ?? now()->toDateString(),
                    'mode' => $this->data['mode'] ?? 'cash',
                    'reference_no' => $this->data['reference_no'] ?? null,
                    'note' => $this->data['note'] ?? null,
                    'collected_by' => auth()->id(),
                    'discount_reason' => $reason !== '' ? $reason : null,
                    'discount_granted_by' => auth()->id(),
                ]);
            });
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title(__('Nothing was collected'))->body($e->getMessage())->persistent()->send();

            return;
        }

        if ($payment === null) {
            $this->loadStudent();

            Notification::make()
                ->danger()
                ->title(__('Nothing was collected — the sheet is out of date'))
                ->body($refusal)
                ->persistent()
                ->send();

            return;
        }

        $this->lastReceiptId = $payment->id;
        $this->data['freehand'] = [];
        $this->data['discount_reason'] = null;
        $this->data['fine_reason'] = null;
        $this->loadStudent();

        Notification::make()->success()->title(__('Collected. Receipt :no generated.', ['no' => $payment->receipt_no]))->send();
    }

    /**
     * A discount needs the privilege and a reason, and must not exceed the
     * school's approval ceiling (above it, grant it from the Concession Register
     * so it goes through approval). Returns the reason, '' when no line carries
     * a discount, or false when the collection must stop.
     */
    protected function guardDiscounts(): string|false
    {
        $total = 0.0;

        foreach ($this->data['lines'] ?? [] as $line) {
            $total += (float) ($line['discount'] ?? 0);
        }

        if (round($total, 2) <= 0) {
            return '';
        }

        if (! FeesPermissions::can(FeesPermissions::DISCOUNT)) {
            Notification::make()->danger()->title(__('You are not allowed to apply discounts.'))->send();

            return false;
        }

        $reason = trim((string) ($this->data['discount_reason'] ?? ''));

        if ($reason === '') {
            Notification::make()->warning()->title(__('Enter a reason for the discount before collecting.'))->send();

            return false;
        }

        foreach ($this->data['lines'] ?? [] as $line) {
            $discount = round(max(0, (float) ($line['discount'] ?? 0)), 2);

            if ($discount <= 0) {
                continue;
            }

            $breach = DiscountApprovalPolicy::breach($discount, (float) ($line['balance'] ?? 0));

            if ($breach !== null) {
                Notification::make()
                    ->danger()
                    ->title(__('This concession needs approval first'))
                    ->body($breach.' '.__('Grant it from the Concession Register instead; the reduced amount can be collected once it is approved.'))
                    ->persistent()
                    ->send();

                return false;
            }
        }

        return $reason;
    }

    /** @return list<array<string, mixed>> */
    protected function buildAllocationLines(string $reason): array
    {
        $lines = [];

        foreach ($this->data['lines'] ?? [] as $line) {
            $balance = round((float) ($line['balance'] ?? 0), 2);
            $discount = min($balance, max(0, round((float) ($line['discount'] ?? 0), 2)));

            $lines[] = [
                'allocation_id' => $line['allocation_id'] ?? null,
                'head' => $line['head'] ?? null,
                'amount' => (float) ($line['amount'] ?? 0),
                'discount' => $discount,
                'fine' => (float) ($line['fine'] ?? 0),
                'fee_discount_id' => $discount > 0 ? ($line['discount_id'] ?? null) : null,
                'discount_reason' => $discount > 0 ? $reason : null,
                'discount_granted_by' => $discount > 0 ? auth()->id() : null,
            ];
        }

        return $lines;
    }

    /**
     * Re-check every allocation line against the row as it stands NOW, under a
     * lock. Refuses (with a message) a line that would collect more than is
     * owed, or a fine above the fee's own rule without the privilege and a
     * reason; quietly lowers a rule fine that has meanwhile been charged.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    protected function repriceAgainstLockedBalances(Student $student, array $lines): array
    {
        $ids = array_values(array_filter(array_map(fn (array $l) => $l['allocation_id'] ?? null, $lines)));

        if ($ids === []) {
            return [$lines, null];
        }

        $locked = FeeStudentAllocation::query()
            ->whereIn('id', $ids)
            ->where('student_id', $student->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $accrual = app(FeeAccrualService::class);
        $mayOverrideFine = FeesPermissions::can(FeesPermissions::DISCOUNT);
        $fineReason = trim((string) ($this->data['fine_reason'] ?? ''));
        $stale = __('Reload the sheet and try again.');

        foreach ($lines as $i => $line) {
            if (empty($line['allocation_id'])) {
                continue;
            }

            $allocation = $locked->get($line['allocation_id']);

            if ($allocation === null) {
                return [$lines, __('One or more fee lines no longer exist for this student.').' '.$stale];
            }

            $balance = round($allocation->balance(), 2);
            $amount = max(0, round((float) ($line['amount'] ?? 0), 2));
            $discount = max(0, round((float) ($line['discount'] ?? 0), 2));

            if ($amount + $discount > $balance + 0.001) {
                return [$lines, __(':head has :balance outstanding but the sheet claims :claimed.', [
                    'head' => $line['head'] ?? ($allocation->feeMaster?->feeType?->name ?? __('Fee')),
                    'balance' => Money::format($balance),
                    'claimed' => Money::format($amount + $discount),
                ]).' '.$stale];
            }

            $fine = max(0, round((float) ($line['fine'] ?? 0), 2));
            $ceiling = round($accrual->unchargedFine($allocation), 2);
            $override = false;

            if ($fine > $ceiling + 0.001) {
                if (! $mayOverrideFine || $fineReason === '') {
                    return [$lines, __('The fee rule supports a late fine of :ceiling. A higher fine needs the discount permission and a reason.', [
                        'ceiling' => Money::format($ceiling),
                    ])];
                }

                $override = true;
            }

            unset($lines[$i]['head']);
            $lines[$i]['amount'] = $amount;
            $lines[$i]['discount'] = $discount;
            $lines[$i]['fine'] = $override ? $fine : min($fine, $ceiling);
            $lines[$i]['fine_override'] = $override;
            $lines[$i]['fine_reason'] = $override ? $fineReason : null;
            $lines[$i]['fine_charged_by'] = $fine > 0 ? auth()->id() : null;
        }

        return [array_values($lines), null];
    }

    /** @return array<int, string> */
    protected function studentOptions(string $search): array
    {
        return Student::query()
            ->where(fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('admission_no', 'like', "%{$search}%"))
            ->orderBy('first_name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Student $s): array => [$s->id => $s->full_name.' ('.$s->admission_no.')'])
            ->all();
    }

    protected function studentLabel(int $id): ?string
    {
        $student = Student::find($id);

        return $student ? $student->full_name.' ('.$student->admission_no.')' : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])->id('form');
    }
}

<?php

namespace Classivo\Fees\Filament\Resources\FeePayments\Tables;

use Classivo\Core\Models\AcademicSession;
use Classivo\Fees\Filament\Resources\FeePayments\FeePaymentResource;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeePaymentDetail;
use Classivo\Fees\Services\FeeCollectionService;
use Classivo\Fees\Services\FeeRefundService;
use Classivo\Fees\Support\FeeRefunds;
use Classivo\Fees\Support\Labels;
use Classivo\Fees\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Search Fees Payment table: Payment ID, Date, Student, Admission no,
 * Mode, Amount, Discount, Fine, Total paid, Collected by; footer totals. Row actions:
 * View, Print Receipt, Refund, and Revert (gated `fees.revert`).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * REFUND vs REVERT — WHY BOTH, AND WHY THEY EXCLUDE EACH OTHER
 * ─────────────────────────────────────────────────────────────────────────────
 * Revert is the ORIGINAL, whole-receipt operation and it stays exactly as it was:
 * the receipt never happened, every allocation goes back, the ledger gets a
 * contra entry. It is the right answer to "this receipt was entered by mistake".
 *
 * Refund is the new, PARTIAL-capable operation: the receipt stands, a stated
 * amount goes back to the family against a reason code, a numbered credit note is
 * produced, and only the refunded lines re-open. It is the right answer to "the
 * child left in October and the hostel fee for the rest of the year is owed back".
 *
 * The two must never both run on one receipt, and the guard is arithmetic rather
 * than a flag: Revert (and the gateway "Refund (gateway)" action beside it, which
 * calls a gateway refund) are hidden the
 * moment any live refund exists against the receipt, because reverting would
 * restore dues the refund has already restored and reverse a ledger entry the
 * refund has already reversed in part. `refunded_total` comes from the correlated
 * subquery in {@see FeePaymentResource::getEloquentQuery()},
 * so the check costs no extra query per row.
 */
class FeePaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('receipt_no')->label(__('Receipt'))->searchable()->sortable(),
                TextColumn::make('session.name')
                    ->label(__('Session'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('payment_date')->label(__('Date'))->date()->sortable(),
                // full_name is an accessor — sort by a real column (bare sortable()
                // 500s the page on header click; Phase 2 CRUD-loop finding).
                TextColumn::make('student.full_name')->label(__('Student'))->searchable(['first_name', 'last_name'])->sortable(['first_name']),
                TextColumn::make('student.admission_no')->label(__('Admission no'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mode')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => FeePayment::MODES[$state] ?? ucfirst((string) $state)),
                TextColumn::make('discount_amount')->label(__('Discount'))->money('GHS')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->summarize(Sum::make()->money('GHS')),
                TextColumn::make('fine_amount')->label(__('Fine'))->money('GHS')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->summarize(Sum::make()->money('GHS')),
                TextColumn::make('paid_amount')->label(__('Total paid'))->money('GHS')->weight('bold')
                    ->summarize(Sum::make()->money('GHS')),
                // From the correlated subquery on the resource query — no per-row
                // lookup. Hidden by default so the unchanged default view stays
                // unchanged for a school that never refunds anything.
                TextColumn::make('refunded_total')
                    ->label(__('Refunded'))
                    ->money('GHS')
                    ->color('danger')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('collector.name')->label(__('Collected by'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'reverted' ? 'danger' : 'success'),
            ])
            ->filters([
                // Cross-session receipts (base query lifts the SessionScope): the
                // filter defaults to the CURRENT session so the default view is
                // unchanged; pick another session — or "All" — to span sessions.
                SelectFilter::make('session_id')
                    ->label(__('Academic session'))
                    ->options(fn (): array => AcademicSession::query()
                        ->orderByDesc('start_date')
                        ->orderByDesc('id')
                        ->pluck('name', 'id')
                        ->all())
                    ->default(fn (): ?int => current_session_id()),
                SelectFilter::make('mode')->options(Labels::t(FeePayment::MODES)),
                SelectFilter::make('status')->options([
                    'completed' => __('Completed'),
                    'reverted' => __('Reverted'),
                ]),
                Filter::make('payment_date')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('payment_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('payment_date', '<=', $d))),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('print')
                    ->label(__('Print Receipt'))
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (FeePayment $record): string => route('fees.receipt', ['payment' => $record->id]), shouldOpenInNewTab: true),
                // The app-style dompdf receipt (same document an API client
                // serves) — works for receipts of ANY academic session.
                Action::make('downloadPdf')
                    ->label(__('Download PDF'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->url(fn (FeePayment $record): string => route('fees.receipt.pdf', ['payment' => $record->id, 'download' => 1]), shouldOpenInNewTab: true),
                // ─────────────────────────────────────────────────────────────
                // REFUND — full or partial, with a reason code and a credit note.
                // Everything it does happens in FeeRefundService: the row lock on
                // the receipt, the refundable-balance check that stops two clerks
                // refunding the same money, the line apportionment, the approval
                // requirement. This action collects input and reports the answer.
                // ─────────────────────────────────────────────────────────────
                Action::make('refund')
                    ->label(__('Refund'))
                    ->icon(Heroicon::OutlinedReceiptRefund)
                    ->color('warning')
                    ->visible(fn (FeePayment $record): bool => $record->status === 'completed'
                        && FeeRefunds::allows(auth()->user(), 'fees.refund'))
                    ->modalHeading(fn (FeePayment $record): string => __('Refund receipt ').$record->receipt_no)
                    ->modalSubmitActionLabel(__('Raise refund'))
                    // fillForm() rather than per-field ->default(): the per-head
                    // boxes have to be seeded from THIS receipt's lines, and their
                    // ceilings are what previous partial refunds have left, which
                    // only the service knows.
                    ->fillForm(fn (FeePayment $record): array => self::refundFormState($record))
                    ->schema(fn (FeePayment $record): array => [
                        Placeholder::make('refundable_note')
                            ->hiddenLabel()
                            ->content(fn (): string => __('Refundable on this receipt: ')
                                .number_format(self::refundable($record), 2)
                                .' of '.number_format((float) $record->paid_amount, 2).' paid.'
                                .' Refunds over '.number_format(FeeRefunds::threshold(), 2).' need approval before they can be issued.'),
                        Select::make('reason_code')
                            ->label(__('Reason'))
                            ->options(Labels::t(FeeRefunds::REASON_CODES))
                            ->required()
                            ->live()
                            // The reason decides whether the fee becomes payable
                            // again — it is a property of the case, not of the
                            // clerk's mood — so picking one moves the toggle. The
                            // toggle stays editable for the mixed receipt.
                            ->afterStateUpdated(fn (?string $state, callable $set) => $set('restore_dues', FeeRefunds::restoresDues($state))),
                        Textarea::make('reason_detail')
                            ->label(__('Detail'))
                            ->rows(2)
                            ->helperText(__('Recorded beside the reason code, never instead of it.')),
                        Select::make('mode')
                            ->label(__('How is it going back?'))
                            ->options(Labels::t(FeeRefunds::MODES))
                            ->default('original')
                            ->required(),
                        TextInput::make('reference_no')
                            ->label(__('Cheque no / UTR (if known)'))
                            ->maxLength(191),
                        TextInput::make('amount')
                            ->label(__('Total to refund'))
                            ->numeric()
                            ->minValue(0)
                            ->prefix(fn (): string => Money::symbol())
                            ->helperText(__('Leave the per-head boxes at zero and this is apportioned across the receipt, largest head first.')),
                        Toggle::make('restore_dues')
                            ->label(__('Make the refunded fee payable again'))
                            ->helperText(__('On for a TC, a cancelled admission or a wrong head. Off when returning an over-payment that was never owed.')),
                        Repeater::make('lines')
                            ->label(__('Refund specific heads (optional)'))
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(3)
                            ->schema([
                                // A Repeater only returns the keys its schema
                                // declares, and a disabled TextInput is not
                                // dehydrated at all — so the receipt line's id
                                // needs a Hidden or the submitted rows come back
                                // with no idea which line they belong to.
                                Hidden::make('detail_id'),
                                TextInput::make('head')->label(__('Fee head'))->disabled(),
                                TextInput::make('refundable')->label(__('Still refundable'))->disabled(),
                                TextInput::make('amount')->label(__('Refund'))->numeric()->minValue(0)->default(0),
                            ]),
                    ])
                    ->action(function (FeePayment $record, array $data): void {
                        try {
                            $refund = app(FeeRefundService::class)->request($record, [
                                'amount' => (float) ($data['amount'] ?? 0),
                                'lines' => self::explicitLines($data['lines'] ?? []),
                                'reason_code' => $data['reason_code'] ?? null,
                                'reason_detail' => $data['reason_detail'] ?? null,
                                'mode' => $data['mode'] ?? 'original',
                                'reference_no' => $data['reference_no'] ?? null,
                                'restore_dues' => (bool) ($data['restore_dues'] ?? true),
                            ], auth()->user());

                            Notification::make()->success()
                                ->title($refund->isApproved()
                                    ? __('Refund approved automatically — issue it from Fee Refunds.')
                                    : __('Refund raised — awaiting ').$refund->required_approvals.' approval(s).')
                                ->body(__('No money has moved yet. Issuing on the Fee Refunds screen mints the credit note.'))
                                ->send();
                        } catch (RuntimeException $e) {
                            // The service refuses under a row lock; this is where
                            // that refusal becomes a sentence rather than a 500.
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Action::make('revert')
                    ->label(__('Revert'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    // Hidden once ANY live refund exists against the receipt:
                    // reverting would restore dues the refund already restored and
                    // reverse a ledger entry the refund already reversed in part.
                    // See the class docblock.
                    ->visible(fn (FeePayment $record): bool => $record->status === 'completed'
                        && ! self::hasRefunds($record)
                        && (bool) auth()->user()?->can('fees.revert'))
                    ->requiresConfirmation()
                    ->modalDescription(__('Reverting restores the student\'s dues and reverses any accounting entry.'))
                    ->schema([
                        Textarea::make('reason')->label(__('Reason'))->required()->rows(2),
                    ])
                    ->action(function (FeePayment $record, array $data): void {
                        app(FeeCollectionService::class)->revert($record, $data['reason'], auth()->id());

                        Notification::make()->success()->title(__('Payment reverted; dues restored.'))->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * How much of a receipt has already been refunded.
     *
     * Prefers `refunded_total`, the correlated subquery the resource query adds,
     * so a table page costs one query rather than one per row. It falls back to
     * asking the service only when the attribute is absent — which happens when a
     * FeePayment reaches this class from somewhere that did not build it through
     * FeePaymentResource::getEloquentQuery(), and where being right matters more
     * than being cheap.
     */
    protected static function refundedTotal(FeePayment $record): float
    {
        if ($record->getAttribute('refunded_total') !== null) {
            return round((float) $record->getAttribute('refunded_total'), 2);
        }

        return round((float) $record->paid_amount - app(FeeRefundService::class)->refundableFor($record), 2);
    }

    /** What is still refundable on this receipt. */
    protected static function refundable(FeePayment $record): float
    {
        return round(max(0, (float) $record->paid_amount - self::refundedTotal($record)), 2);
    }

    /** Is any refund live against this receipt? Gates Revert. */
    protected static function hasRefunds(FeePayment $record): bool
    {
        return self::refundedTotal($record) > 0;
    }

    /**
     * Seed the refund modal from the receipt: the reason's dues default, the full
     * refundable figure, and one per-head row per receipt line carrying what that
     * line has left.
     *
     * @return array<string, mixed>
     */
    protected static function refundFormState(FeePayment $record): array
    {
        $service = app(FeeRefundService::class);

        $lines = FeePaymentDetail::query()
            ->with('feeType')
            ->where('fee_payment_id', $record->id)
            ->orderBy('id')
            ->get()
            ->map(fn (FeePaymentDetail $detail): array => [
                'detail_id' => $detail->id,
                'head' => $detail->feeType?->name ?? $detail->label ?? __('Fee'),
                'refundable' => number_format($service->refundableForDetail($detail), 2, '.', ''),
                'amount' => 0,
            ])
            ->all();

        return [
            'amount' => number_format(self::refundable($record), 2, '.', ''),
            'mode' => 'original',
            'restore_dues' => true,
            'lines' => $lines,
        ];
    }

    /**
     * Turn the repeater rows into the [detail_id => amount] map the service
     * expects, dropping the zeros.
     *
     * An empty map is what tells {@see FeeRefundService::resolveLines()} to
     * apportion the total instead — the two shapes are exclusive by design, so a
     * clerk who fills in one head and also types a total gets the head they
     * chose, not both.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, float>
     */
    protected static function explicitLines(array $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $detailId = (int) ($row['detail_id'] ?? 0);

            if ($amount > 0 && $detailId > 0) {
                $lines[$detailId] = $amount;
            }
        }

        return $lines;
    }
}

<?php

namespace Classivo\Fees\Filament\Resources\FeeRefunds\Tables;

use Classivo\Fees\Models\FeeRefund;
use Classivo\Fees\Services\FeeRefundService;
use Classivo\Fees\Support\FeeRefunds;
use Classivo\Fees\Support\Labels;
use Classivo\Fees\Support\PdfFilename;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
 * Fee Refunds table — the lifecycle screen for a refund raised on Search Fees
 * Payment.
 *
 * EVERY ACTION HERE DELEGATES TO {@see FeeRefundService}. Not one of them writes a
 * column: the service owns the row locks, the exactly-once guards, the dues
 * restoration, the credit-note series and the ledger event, and a panel action
 * that re-derived any of that is how one refund becomes two payouts.
 *
 * THE ABILITIES, and why they are checked through {@see FeeRefunds::allows()}:
 *   • fees.refund          — raise one (enforced on the Search Fees Payment side)
 *   • fees.refund.approve  — approve or reject
 *   • fees.refund.issue    — actually pay it out and mint the credit note
 * Deny by default: grant them with FeesPermissionSeeder or your own gates.
 */
class FeeRefundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('credit_note_no')
                    ->label(__('Credit note'))
                    ->placeholder(__('— not issued —'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('payment.receipt_no')->label(__('Receipt'))->searchable(),
                TextColumn::make('requested_at')->label(__('Requested'))->dateTime('d M Y H:i')->sortable(),
                // full_name is an accessor — sort by a real column, the same
                // finding FeePaymentsTable carries.
                TextColumn::make('student.full_name')
                    ->label(__('Student'))
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name']),
                TextColumn::make('reason_code')
                    ->label(__('Reason'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => FeeRefunds::reasonLabel($state)),
                TextColumn::make('mode')
                    ->label(__('Returned by'))
                    ->formatStateUsing(fn (?string $state): string => FeeRefunds::modeLabel($state)),
                TextColumn::make('amount')
                    ->label(__('Refunded'))
                    ->money('INR')
                    ->weight('bold')
                    ->summarize(Sum::make()->money('INR')),
                // Two counters in one column: "1 / 2" is the only honest way to
                // show a refund that one person has signed and a second has not.
                TextColumn::make('approvals_received')
                    ->label(__('Approvals'))
                    ->badge()
                    ->color(fn (FeeRefund $record): string => $record->isPendingApproval() ? 'warning' : 'success')
                    ->formatStateUsing(fn (FeeRefund $record): string => (int) $record->required_approvals === 0
                        ? __('Auto')
                        : $record->approvals_received.' / '.$record->required_approvals),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => FeeRefund::STATUSES[$state] ?? ucfirst((string) $state))
                    ->color(fn (?string $state): string => FeeRefund::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('payout_status')
                    ->label(__('Payout'))
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'paid' ? 'success' : 'warning')
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                    ->toggleable(),
                TextColumn::make('issuedBy.name')->label(__('Issued by'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(Labels::t(FeeRefund::STATUSES)),
                SelectFilter::make('reason_code')->label(__('Reason'))->options(Labels::t(FeeRefunds::REASON_CODES)),
                SelectFilter::make('mode')->label(__('Returned by'))->options(Labels::t(FeeRefunds::MODES)),
                SelectFilter::make('payout_status')->label(__('Payout'))->options([
                    'pending' => __('Pending'),
                    'paid' => __('Paid'),
                ]),
                Filter::make('awaiting_approval')
                    ->label(__('Awaiting approval'))
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', 'requested')
                        ->whereColumn('approvals_received', '<', 'required_approvals')),
                Filter::make('requested_at')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('requested_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('requested_at', '<=', $d))),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (FeeRefund $record): bool => $record->isPendingApproval()
                        && FeeRefunds::allows(auth()->user(), 'fees.refund.approve'))
                    ->modalDescription(fn (FeeRefund $record): string => __('Approval ')
                        .((int) $record->approvals_received + 1).' of '.$record->required_approvals
                        .'. Approving does not pay anything out — the refund still has to be issued.')
                    ->schema([
                        Textarea::make('remark')->label(__('Remark'))->rows(2)
                            ->helperText(__('Recorded in the approval log against your name.')),
                    ])
                    ->action(function (FeeRefund $record, array $data): void {
                        self::run(function () use ($record, $data): void {
                            app(FeeRefundService::class)->approve($record, auth()->user(), $data['remark'] ?? null);

                            Notification::make()->success()->title(__('Refund approved.'))->send();
                        });
                    }),

                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (FeeRefund $record): bool => in_array($record->status, ['requested', 'approved'], true)
                        && FeeRefunds::allows(auth()->user(), 'fees.refund.approve'))
                    ->schema([
                        Textarea::make('remark')->label(__('Reason for rejection'))->required()->rows(2),
                    ])
                    ->action(function (FeeRefund $record, array $data): void {
                        self::run(function () use ($record, $data): void {
                            app(FeeRefundService::class)->reject($record, auth()->user(), (string) $data['remark']);

                            Notification::make()->success()->title(__('Refund rejected.'))->send();
                        });
                    }),

                Action::make('issue')
                    ->label(__('Issue refund'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('primary')
                    ->visible(fn (FeeRefund $record): bool => $record->isApproved()
                        && FeeRefunds::allows(auth()->user(), 'fees.refund.issue'))
                    ->requiresConfirmation()
                    ->modalHeading(__('Issue this refund'))
                    ->modalDescription(__('Mints the credit note and restores the student\'s dues for the refunded lines. Record the physical payout afterwards with "Mark paid".'))
                    ->action(function (FeeRefund $record): void {
                        self::run(function () use ($record): void {
                            $issued = app(FeeRefundService::class)->issue($record, auth()->user());

                            Notification::make()->success()
                                ->title(__('Refund issued — credit note ').$issued->credit_note_no.'.')
                                ->send();
                        });
                    }),

                Action::make('markPaid')
                    ->label(__('Mark paid'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('gray')
                    ->visible(fn (FeeRefund $record): bool => $record->isIssued()
                        && $record->payout_status !== 'paid'
                        && FeeRefunds::allows(auth()->user(), 'fees.refund.issue'))
                    ->schema([
                        TextInput::make('reference')->label(__('Cheque no / UTR / voucher'))->maxLength(191),
                    ])
                    ->action(function (FeeRefund $record, array $data): void {
                        self::run(function () use ($record, $data): void {
                            app(FeeRefundService::class)->markPaid($record, auth()->user(), $data['reference'] ?? null);

                            Notification::make()->success()->title(__('Payout recorded.'))->send();
                        });
                    }),

                Action::make('creditNote')
                    ->label(__('Credit note'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->color('gray')
                    // Returned straight to Livewire, which turns a dompdf Response
                    // into a browser download — the same trick the receipt route
                    // performs over HTTP, without needing a route of its own.
                    ->action(fn (FeeRefund $record) => app(FeeRefundService::class)
                        ->creditNotePdf($record)
                        ->download('CreditNote-'.PdfFilename::safe($record->credit_note_no ?: 'draft-'.$record->id).'.pdf')),

                Action::make('void')
                    ->label(__('Withdraw'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('gray')
                    ->visible(fn (FeeRefund $record): bool => in_array($record->status, ['requested', 'approved'], true)
                        && FeeRefunds::allows(auth()->user(), 'fees.refund.approve'))
                    ->requiresConfirmation()
                    ->modalDescription(__('Withdraws a refund raised in error. Nothing has been paid out, so nothing is reversed.'))
                    ->schema([
                        Textarea::make('reason')->label(__('Why is it being withdrawn?'))->required()->rows(2),
                    ])
                    ->action(function (FeeRefund $record, array $data): void {
                        self::run(function () use ($record, $data): void {
                            app(FeeRefundService::class)->void($record, auth()->user(), (string) $data['reason']);

                            Notification::make()->success()->title(__('Refund withdrawn.'))->send();
                        });
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * Run a service call and turn its refusal into a notification.
     *
     * The service throws rather than returning a flag, because a refusal there is
     * an invariant being defended under a row lock ("someone else has already
     * decided this", "only 1,200.00 is still refundable") and a caller that could
     * ignore a return value would be a caller that could pay twice. The panel is
     * where that becomes a sentence a human reads; the alternative is a 500 page
     * in the middle of an accountant's afternoon.
     *
     * ONLY RuntimeException is caught — the one class the service raises for a
     * refusal, and one whose messages are all written to be read by the office. A
     * broader catch would turn a genuine bug into a friendly toast and hide it.
     */
    protected static function run(callable $work): void
    {
        try {
            $work();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}

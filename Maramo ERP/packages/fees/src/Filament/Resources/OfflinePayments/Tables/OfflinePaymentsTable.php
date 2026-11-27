<?php

namespace Classivo\Fees\Filament\Resources\OfflinePayments\Tables;

use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\OfflinePayment;
use Classivo\Fees\Services\FeeCollectionService;
use Classivo\Fees\Support\Labels;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Offline payments list: Student, Amount, Payment date, Reference,
 * Submitted at, Status, Reviewed by. Approve (gated `fees.collect`) posts a real
 * receipt via {@see FeeCollectionService::collectAgainstDues()} — the amount is
 * applied to the student's outstanding allocations oldest-due-first (partial
 * application allowed; any excess stays on the receipt as an advance line), so
 * approval actually reduces the dues. Reject records a reason.
 */
class OfflinePaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('student.full_name')->label(__('Student'))->searchable(['first_name', 'last_name']),
                TextColumn::make('amount')->money('INR'),
                TextColumn::make('payment_date')->date()->placeholder('—'),
                TextColumn::make('reference_no')->label(__('Reference'))->placeholder('—'),
                TextColumn::make('created_at')->label(__('Submitted'))->since(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => OfflinePayment::STATUSES[$state] ?? ucfirst((string) $state))
                    ->color(fn (?string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('reviewer.name')->label(__('Reviewed by'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(Labels::t(OfflinePayment::STATUSES)),
            ])
            ->recordActions([
                Action::make('proof')
                    ->label(__('Proof'))
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->visible(fn (OfflinePayment $record): bool => filled($record->attachment_path))
                    ->url(fn (OfflinePayment $record): string => route('fees.offline-payment.proof', $record->id))
                    ->openUrlInNewTab(),
                EditAction::make()
                    ->visible(fn (OfflinePayment $record): bool => $record->status === 'pending'),
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (OfflinePayment $record): bool => $record->status === 'pending' && (bool) auth()->user()?->can('fees.collect'))
                    ->requiresConfirmation()
                    ->modalDescription(__('Approving posts a fee receipt and applies the amount to the student\'s outstanding dues (oldest first).'))
                    ->action(function (OfflinePayment $record): void {
                        // Mirror the strict API path (ApprovalAdminController → OfflinePaymentApproval):
                        // re-read the row under lockForUpdate, re-check it is still pending, and mint the
                        // receipt AND stamp the request inside ONE transaction. The ->visible() guard above
                        // is a render-time hint, not a concurrency guard; without this a crash between the
                        // mint and the stamp committed a receipt against a still-"pending" row (a stranded
                        // receipt), and a retry then minted a second one.
                        $payment = DB::transaction(function () use ($record): ?FeePayment {
                            /** @var OfflinePayment|null $locked */
                            $locked = OfflinePayment::whereKey($record->getKey())->lockForUpdate()->first();

                            if ($locked === null || $locked->status !== 'pending') {
                                return null;
                            }

                            $receipt = app(FeeCollectionService::class)->collectAgainstDues(
                                $locked->student,
                                (float) $locked->amount,
                                [
                                    'date' => $locked->payment_date?->toDateString(),
                                    'mode' => $locked->mode,
                                    'reference_no' => $locked->reference_no,
                                    'note' => __('Approved offline payment #').$locked->id,
                                    'collected_by' => auth()->id(),
                                ],
                                __('Offline payment').($locked->reference_no ? ' — '.$locked->reference_no : '').' (advance)',
                            );

                            $locked->update([
                                'status' => 'approved',
                                'reviewed_by' => auth()->id(),
                                'reviewed_at' => now(),
                                'fee_payment_id' => $receipt->id,
                            ]);

                            return $receipt;
                        });

                        if ($payment === null) {
                            Notification::make()->warning()->title(__('Already decided — no receipt was created.'))->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Approved. Receipt ').$payment->receipt_no.' created.')->send();
                    }),
                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (OfflinePayment $record): bool => $record->status === 'pending' && (bool) auth()->user()?->can('fees.collect'))
                    ->schema([
                        Textarea::make('review_note')->label(__('Reason'))->required()->rows(2),
                    ])
                    ->action(function (OfflinePayment $record, array $data): void {
                        $rejected = DB::transaction(function () use ($record, $data): bool {
                            /** @var OfflinePayment|null $locked */
                            $locked = OfflinePayment::whereKey($record->getKey())->lockForUpdate()->first();

                            if ($locked === null || $locked->status !== 'pending') {
                                return false;
                            }

                            $locked->update([
                                'status' => 'rejected',
                                'reviewed_by' => auth()->id(),
                                'reviewed_at' => now(),
                                'review_note' => $data['review_note'],
                            ]);

                            return true;
                        });

                        Notification::make()
                            ->warning()
                            ->title($rejected ? __('Offline payment rejected.') : __('Already decided — no change made.'))
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }
}

<?php

namespace Classivo\Fees\Filament\Resources\FeeRefunds\Pages;

use Classivo\Fees\Filament\Resources\FeeRefunds\FeeRefundResource;
use Classivo\Fees\Models\FeeRefund;
use Classivo\Fees\Services\FeeRefundService;
use Classivo\Fees\Support\FeeRefunds;
use Classivo\Fees\Support\PdfFilename;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * View one fee refund: the money, the reason, the lines it came out of, and —
 * the point of the screen — the complete audit trail.
 *
 * The trail is read from `fee_refund_decisions`, written inside the same
 * transaction as each transition.
 */
class ViewFeeRefund extends ViewRecord
{
    protected static string $resource = FeeRefundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('creditNote')
                ->label(__('Credit note (PDF)'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->action(fn (FeeRefund $record) => app(FeeRefundService::class)
                    ->creditNotePdf($record)
                    ->download('CreditNote-'.PdfFilename::safe($record->credit_note_no ?: 'draft-'.$record->id).'.pdf')),
            Action::make('receipt')
                ->label(__('Original receipt'))
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (FeeRefund $record): string => route('fees.receipt', ['payment' => $record->fee_payment_id]), shouldOpenInNewTab: true),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Refund'))->columns(4)->schema([
                TextEntry::make('credit_note_no')->label(__('Credit note'))->placeholder(__('— not issued —'))->weight('bold'),
                TextEntry::make('payment.receipt_no')->label(__('Against receipt')),
                TextEntry::make('student.full_name')->label(__('Student')),
                TextEntry::make('student.admission_no')->label(__('Admission no')),
                TextEntry::make('amount')->label(__('Refunded'))->money('INR')->weight('bold'),
                TextEntry::make('payment.paid_amount')->label(__('Receipt total'))->money('INR'),
                TextEntry::make('reason_code')->label(__('Reason'))->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state): string => FeeRefunds::reasonLabel($state)),
                TextEntry::make('mode')->label(__('Returned by'))
                    ->formatStateUsing(fn (?string $state): string => FeeRefunds::modeLabel($state)),
                TextEntry::make('status')->badge()
                    ->formatStateUsing(fn (?string $state): string => FeeRefund::STATUSES[$state] ?? ucfirst((string) $state))
                    ->color(fn (?string $state): string => FeeRefund::STATUS_COLORS[$state] ?? 'gray'),
                TextEntry::make('payout_status')->label(__('Payout'))->badge()
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                    ->color(fn (?string $state): string => $state === 'paid' ? 'success' : 'warning'),
                TextEntry::make('reference_no')->label(__('Cheque / UTR'))->placeholder('—'),
                // Spelled out because it is the question a parent asks: has the
                // fee become payable again, or was this simply money back?
                TextEntry::make('restore_dues')->label(__('Dues restored'))
                    ->formatStateUsing(fn ($state): string => $state ? __('Yes — the refunded fee is payable again') : __('No — over-payment returned')),
                TextEntry::make('reason_detail')->label(__('Detail'))->placeholder('—')->columnSpanFull(),
            ]),

            Section::make(__('Approval'))->columns(4)->schema([
                TextEntry::make('required_approvals')->label(__('Approvals required'))
                    ->formatStateUsing(fn ($state): string => (int) $state === 0 ? __('None (below threshold)') : (string) $state),
                TextEntry::make('approvals_received')->label(__('Approvals received')),
                TextEntry::make('threshold_at_request')->label(__('Threshold at request'))->money('INR')
                    ->helperText(__('The figure in force when the refund was raised — frozen, so changing the setting later cannot rewrite this decision.')),
                TextEntry::make('requestedBy.name')->label(__('Requested by'))->placeholder('—'),
                TextEntry::make('requested_at')->label(__('Requested at'))->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('approvedBy.name')->label(__('Final approver'))->placeholder('—'),
                TextEntry::make('issuedBy.name')->label(__('Issued by'))->placeholder('—'),
                TextEntry::make('issued_at')->label(__('Issued at'))->dateTime('d M Y H:i')->placeholder('—'),
            ]),

            Section::make(__('Refunded lines'))->schema([
                RepeatableEntry::make('lines')
                    ->hiddenLabel()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('label')->label(__('Fee head'))->placeholder('—'),
                        TextEntry::make('amount')->label(__('Refunded'))->money('INR'),
                        TextEntry::make('restore_dues')->label(__('Dues restored'))
                            ->formatStateUsing(fn ($state): string => $state ? __('Yes') : 'No'),
                    ]),
            ]),

            Section::make(__('Audit trail'))
                ->description(__('Every step of this refund, from the decision log.'))
                ->schema([
                    RepeatableEntry::make('decisions')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('created_at')->label(__('When'))->dateTime('d M Y H:i'),
                            TextEntry::make('decision')->label(__('What'))->badge()
                                ->formatStateUsing(fn (?string $state): string => str($state ?? '')->headline()->toString()),
                            TextEntry::make('decider.name')->label(__('Who'))->placeholder(__('System')),
                            TextEntry::make('remark')->label(__('Why'))->placeholder('—'),
                        ]),
                ]),
        ]);
    }
}

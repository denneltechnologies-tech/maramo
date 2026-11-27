<?php

namespace Classivo\Fees\Filament\Resources\FeePayments\Pages;

use Classivo\Core\Tenancy\Scopes\BranchScope;
use Classivo\Core\Tenancy\Scopes\TenantScope;
use Classivo\Fees\Filament\Resources\FeePayments\FeePaymentResource;
use Classivo\Fees\Filament\Resources\FeeRefunds\FeeRefundResource;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeRefund;
use Classivo\Fees\Services\FeeRefundService;
use Classivo\Fees\Support\FeeRefunds;
use Classivo\Fees\Support\Money;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * View a collection receipt. Shows the receipt header, its fee lines,
 * a Print Receipt (PDF) action — and, when there are any, the refunds raised
 * against it.
 *
 * The refund section is deliberately conditional: a receipt that has never been
 * refunded looks exactly as it did before, and a receipt that HAS shows the
 * credit notes beside the money they came out of, so "we paid 30,000 and got
 * 4,000 back in October" is one screen rather than two.
 */
class ViewFeePayment extends ViewRecord
{
    protected static string $resource = FeePaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label(__('Print Receipt'))
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (FeePayment $record): string => route('fees.receipt', ['payment' => $record->id]), shouldOpenInNewTab: true),
            Action::make('refunds')
                ->label(__('Refunds on this receipt'))
                ->icon(Heroicon::OutlinedReceiptRefund)
                ->color('gray')
                ->visible(fn (FeePayment $record): bool => self::refunds($record)->isNotEmpty())
                ->url(fn (FeePayment $record): string => FeeRefundResource::getUrl('index', [
                    'tableSearch' => $record->receipt_no,
                ])),
        ];
    }

    /**
     * The refunds raised against this receipt, memoised for the request.
     *
     * Tenant scopes are lifted for the same reason the receipt lookup itself
     * lifts SessionScope: this page opens receipts from any session, and a refund
     * whose branch context does not match the operator's current one must still be
     * visible — a refund that silently fails to load reads as "no refund", which
     * is precisely the wrong thing to tell someone about money that left.
     */
    protected static function refunds(FeePayment $record): Collection
    {
        static $cache = [];

        return $cache[$record->id] ??= FeeRefund::query()
            ->withoutGlobalScopes([TenantScope::class, BranchScope::class])
            ->where('school_id', $record->school_id)
            ->where('fee_payment_id', $record->id)
            ->orderBy('id')
            ->get();
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Receipt'))->columns(4)->schema([
                TextEntry::make('receipt_no')->label(__('Receipt no'))->weight('bold'),
                TextEntry::make('payment_date')->label(__('Date'))->date(),
                TextEntry::make('student.full_name')->label(__('Student')),
                TextEntry::make('student.admission_no')->label(__('Admission no')),
                TextEntry::make('mode')->badge()
                    ->formatStateUsing(fn (?string $state): string => FeePayment::MODES[$state] ?? ucfirst((string) $state)),
                TextEntry::make('paid_amount')->label(__('Total paid'))->money('INR')->weight('bold'),
                TextEntry::make('discount_amount')->label(__('Discount'))->money('INR'),
                TextEntry::make('fine_amount')->label(__('Fine'))->money('INR'),
                TextEntry::make('collector.name')->label(__('Collected by'))->placeholder('—'),
                TextEntry::make('reference_no')->label(__('Reference'))->placeholder('—'),
                TextEntry::make('status')->badge()
                    ->color(fn (?string $state): string => $state === 'reverted' ? 'danger' : 'success'),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make(__('Fee lines'))->schema([
                RepeatableEntry::make('details')
                    ->hiddenLabel()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('feeType.name')->label(__('Fee head'))->placeholder(fn ($record) => $record->label ?? '—'),
                        TextEntry::make('amount')->money('INR'),
                        TextEntry::make('discount_amount')->label(__('Discount'))->money('INR'),
                        TextEntry::make('fine_amount')->label(__('Fine'))->money('INR'),
                    ]),
            ]),

            // Only rendered when something has actually been refunded, so the
            // ordinary receipt view is untouched.
            Section::make(__('Refunds'))
                ->visible(fn (FeePayment $record): bool => self::refunds($record)->isNotEmpty())
                ->columns(3)
                ->schema([
                    TextEntry::make('refunded_summary')
                        ->label(__('Refunded to date'))
                        ->weight('bold')
                        ->state(fn (FeePayment $record): string => Money::symbol().number_format(
                            self::refunds($record)
                                ->filter(fn (FeeRefund $r): bool => $r->countsAgainstReceipt())
                                ->sum(fn (FeeRefund $r): float => (float) $r->amount),
                            2,
                        )),
                    TextEntry::make('still_refundable')
                        ->label(__('Still refundable'))
                        ->state(fn (FeePayment $record): string => Money::symbol().number_format(
                            app(FeeRefundService::class)->refundableFor($record),
                            2,
                        )),
                    TextEntry::make('refund_count')
                        ->label(__('Credit notes'))
                        ->state(fn (FeePayment $record): string => self::refunds($record)
                            ->pluck('credit_note_no')
                            ->filter()
                            ->implode(', ') ?: '— none issued yet —'),
                    TextEntry::make('refund_list')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->listWithLineBreaks()
                        ->state(fn (FeePayment $record): array => self::refunds($record)
                            ->map(fn (FeeRefund $r): string => ($r->credit_note_no ?: __('Draft #').$r->id)
                                .' — '.Money::symbol().number_format((float) $r->amount, 2)
                                .' — '.FeeRefunds::reasonLabel($r->reason_code)
                                .' — '.FeeRefunds::modeLabel($r->mode)
                                .' — '.(FeeRefund::STATUSES[$r->status] ?? $r->status))
                            ->all()),
                ]),
        ]);
    }
}

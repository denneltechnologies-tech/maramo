<?php

namespace Classivo\Fees\Filament\Resources\OfflinePayments\Schemas;

use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Support\Labels;
use Classivo\Fees\Support\Money;
use Classivo\Students\Models\Student;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Offline payment request form: student, amount, payment date, mode,
 * reference no, proof attachment, note.
 */
class OfflinePaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Offline payment request'))
                    ->columns(2)
                    ->schema([
                        Select::make('student_id')
                            ->label(__('Student'))
                            ->options(fn (): array => Student::query()
                                ->orderBy('first_name')
                                ->get()
                                ->mapWithKeys(fn (Student $s): array => [$s->id => $s->full_name.' ('.$s->admission_no.')'])
                                ->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('amount')->numeric()->minValue(0)->required()->prefix(fn (): string => Money::symbol()),
                        DatePicker::make('payment_date')->native(false)->default(now()),
                        Select::make('mode')->options(Labels::t(FeePayment::MANUAL_MODES))->default('bank_transfer'),
                        TextInput::make('reference_no')->label(__('Reference / Transaction no'))->maxLength(120),
                        FileUpload::make('attachment_path')
                            ->label(__('Proof'))
                            ->disk(fn (): string => (string) config('fees.attachments.disk', 'local'))
                            ->visibility('private')
                            ->directory('offline-payments')
                            ->acceptedFileTypes((array) config('fees.attachments.mime_types', ['application/pdf', 'image/jpeg', 'image/png']))
                            ->rules(['extensions:pdf,jpg,jpeg,png'])
                            ->maxSize((int) config('fees.attachments.max_kb', 5120))
                            ->downloadable(false)
                            ->openable(false),
                        Textarea::make('note')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }
}

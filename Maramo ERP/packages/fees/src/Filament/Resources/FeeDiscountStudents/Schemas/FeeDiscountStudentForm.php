<?php

namespace Classivo\Fees\Filament\Resources\FeeDiscountStudents\Schemas;

use Classivo\Fees\Models\FeeDiscount;
use Classivo\Fees\Models\FeeDiscountStudent;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Support\DiscountApprovalPolicy;
use Classivo\Fees\Support\Labels;
use Classivo\Students\Models\Student;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Grant a concession. Student, concession, what it applies to, why,
 * and whether it recurs.
 *
 * TWO THINGS THIS FORM DOES THAT THE OLD ONE-LINE PIVOT COULD NOT:
 *
 *  1. It offers RECURRENCE. A recurring grant names no fee line — it is a standing
 *     instruction that attaches itself to every line raised from now on — so the
 *     allocation picker disappears when it is chosen, rather than being ignored.
 *  2. It says what will HAPPEN. The live threshold is shown before the form is
 *     submitted, so nobody is surprised by a concession that lands pending.
 *
 * Only concessions that are usable TODAY are offered: `FeeDiscount::scopeUsableOn()`
 * applies `status` AND the validity window, which is why an expired concession can
 * no longer be picked here at all.
 */
class FeeDiscountStudentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Who'))
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
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                    ]),

                Section::make(__('What'))
                    ->columns(2)
                    ->schema([
                        Select::make('fee_discount_id')
                            ->label(__('Concession'))
                            // Nullable ON PURPOSE: a hardship case agreed by the
                            // principal is a real concession with no catalogue row,
                            // and refusing to record it is why the register was
                            // half-empty. Leaving this blank requires an amount.
                            ->helperText(__('Leave blank for a one-off concession that is not in the catalogue — then enter the amount yourself.'))
                            ->options(fn (): array => FeeDiscount::query()
                                ->usableOn()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->live(),
                        Radio::make('recurrence')
                            ->label(__('Applies'))
                            ->options(Labels::t(FeeDiscountStudent::RECURRENCES))
                            ->default(FeeDiscountStudent::RECURRENCE_ONE_TIME)
                            ->inline()
                            ->live()
                            ->required(),
                        Select::make('fee_student_allocation_id')
                            ->label(__('Fee line'))
                            ->helperText(__('Which of this student\'s fee lines the concession comes off.'))
                            // A standing instruction deliberately names no line.
                            ->visible(fn (Get $get): bool => $get('recurrence') !== FeeDiscountStudent::RECURRENCE_RECURRING)
                            ->options(fn (Get $get): array => self::allocationOptions((int) $get('student_id')))
                            ->required(fn (Get $get): bool => $get('recurrence') !== FeeDiscountStudent::RECURRENCE_RECURRING)
                            ->columnSpanFull(),
                        TextInput::make('amount')
                            ->label(__('Amount'))
                            ->numeric()
                            ->minValue(0)
                            ->helperText(__('Leave blank to use the concession\'s own value. A percentage concession set to recur is worked out fresh against every fee line.'))
                            ->required(fn (Get $get): bool => blank($get('fee_discount_id'))),
                        Select::make('source')
                            ->label(__('Basis'))
                            ->options([
                                FeeDiscountStudent::SOURCE_MANUAL => FeeDiscountStudent::SOURCES[FeeDiscountStudent::SOURCE_MANUAL],
                                FeeDiscountStudent::SOURCE_SIBLING => FeeDiscountStudent::SOURCES[FeeDiscountStudent::SOURCE_SIBLING],
                                FeeDiscountStudent::SOURCE_STAFF_WARD => FeeDiscountStudent::SOURCES[FeeDiscountStudent::SOURCE_STAFF_WARD],
                            ])
                            ->default(FeeDiscountStudent::SOURCE_MANUAL)
                            ->required(),
                    ]),

                Section::make(__('Why, and for how long'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('reason')
                            ->label(__('Reason'))
                            // The counter path already refuses a discount with no
                            // reason. The register holds itself to the same rule:
                            // "who, when and why" is the question this table exists
                            // to answer and two thirds of an answer is not one.
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        DatePicker::make('valid_from')
                            ->label(__('From'))
                            ->native(false)
                            ->helperText(__('Optional. Narrows this grant inside the concession\'s own dates.')),
                        DatePicker::make('valid_to')
                            ->label(__('Until'))
                            ->native(false)
                            ->helperText(__('Optional. A sibling concession that ends when the elder child leaves.')),
                        Placeholder::make('threshold')
                            ->label(__('Approval'))
                            ->content(fn (): string => DiscountApprovalPolicy::summary())
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * This student's fee lines that still owe something, labelled by head and
     * balance.
     *
     * Settled lines are left out: a concession cannot be granted backwards against
     * money the school has already taken — that is what a refund is for — and
     * {@see FeeDiscountStudent::apply()} would cap it to zero anyway.
     *
     * @return array<int, string>
     */
    private static function allocationOptions(int $studentId): array
    {
        if ($studentId <= 0) {
            return [];
        }

        return FeeStudentAllocation::query()
            ->with('feeMaster.feeType')
            ->where('student_id', $studentId)
            ->get()
            ->filter(fn (FeeStudentAllocation $a): bool => $a->balance() > 0)
            ->mapWithKeys(fn (FeeStudentAllocation $a): array => [
                $a->id => ($a->feeMaster?->feeType?->name ?? __('Fee'))
                    .' — outstanding '.number_format($a->balance(), 2)
                    .($a->due_date ? ' (due '.$a->due_date->format('d M Y').')' : ''),
            ])
            ->all();
    }
}

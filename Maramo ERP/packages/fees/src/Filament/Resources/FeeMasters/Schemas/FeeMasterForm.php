<?php

namespace Classivo\Fees\Filament\Resources\FeeMasters\Schemas;

use Carbon\Carbon;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Fees\Models\FeeFine;
use Classivo\Fees\Models\FeeGroup;
use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Models\FeeType;
use Classivo\Fees\Support\Labels;
use Classivo\Fees\Support\Money;
use Classivo\Fees\Support\StudentCategories;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Fees Master create/edit form.
 *
 * FOUR PANELS, IN THE ORDER A BURSAR THINKS:
 *   1. Fee line       — what is charged and how much.
 *   2. Who pays it    — class, section, student category. The three dimensions
 *                       {@see Classivo\Fees\Services\FeeAllocationService::resolveMastersFor()}
 *                       ranks; the panel says out loud that the most specific rule
 *                       wins, because that is the one thing an operator must know
 *                       before authoring a second price for the same head.
 *   3. Billing        — one-time, or a cycle that expands into instalments. The
 *                       due-date field swaps for a first-due-date + count, and a
 *                       live preview lists the dates that will be written.
 *   4. Fine rule      — a NAMED rule from `fee_fines`, or the inline one. Choosing
 *                       a named rule hides the inline fields, because a master
 *                       uses one or the other and never both (see FeeMaster::fineRule()).
 * Plus effective dates, which is how a price change is scheduled rather than
 * typed over the top of the old one.
 */
class FeeMasterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make(__('Fee line'))
                    ->columns(2)
                    ->schema([
                        Select::make('fee_group_id')
                            ->label(__('Fees group'))
                            ->options(fn (): array => FeeGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        Select::make('fee_type_id')
                            ->label(__('Fees type'))
                            ->options(fn (): array => FeeType::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('amount')
                            ->label(fn (Get $get): string => self::isSchedule($get) ? __('Amount per instalment') : __('Amount'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->prefix(fn (): string => Money::symbol()),
                        Toggle::make('is_optional')
                            ->label(__('Optional fee (student can opt out)'))
                            ->helperText(__('Optional heads are never raised by a whole-class assignment — assign them per student.'))
                            ->default(false),
                    ]),

                FormSection::make(__('Who pays it'))
                    ->description(__('Leave a field empty to mean "any". When two fee lines for the same head both apply to a student, the MORE SPECIFIC one wins and only it is billed — student category beats section, section beats class.'))
                    ->columns(3)
                    ->schema([
                        Select::make('class_id')
                            ->label(__('Applicable class'))
                            ->options(fn (): array => SchoolClass::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->placeholder(__('All classes')),
                        Select::make('section_id')
                            ->label(__('Applicable section'))
                            ->options(fn (): array => Section::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->placeholder(__('All sections'))
                            ->helperText(__('E.g. price 10-A differently from 10-B.')),
                        Select::make('student_category_id')
                            ->label(__('Student category'))
                            ->visible(fn (): bool => StudentCategories::available())
                            ->options(fn (): array => StudentCategories::model()::query()
                                ->where('status', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->placeholder(__('All categories'))
                            ->helperText(__('E.g. an RTE, staff-ward or scholarship price. Beats a class or section price.')),
                    ]),

                FormSection::make(__('Billing'))
                    ->columns(3)
                    ->schema([
                        Select::make('billing_frequency')
                            ->label(__('Billing frequency'))
                            ->options(Labels::t(FeeMaster::BILLING_FREQUENCIES))
                            ->default('one_time')
                            ->required()
                            ->live()
                            ->helperText(__('How often the FEE is charged. Not the same as the fine frequency below, which is how fast an overdue penalty grows.'))
                            ->disabled(fn (?FeeMaster $record): bool => $record?->parent_fee_master_id !== null)
                            ->columnSpan(1),

                        // ONE-TIME: a single dated charge, exactly as before.
                        DatePicker::make('due_date')
                            ->label(__('Due date'))
                            ->native(false)
                            ->visible(fn (Get $get): bool => ! self::isSchedule($get))
                            ->columnSpan(2),

                        // RECURRING: a definition. `due_date` is written from the
                        // first due date on save so the definition row still sorts
                        // and reads sensibly in listings.
                        DatePicker::make('first_due_date')
                            ->label(__('First instalment due'))
                            ->native(false)
                            ->required(fn (Get $get): bool => self::isSchedule($get))
                            ->visible(fn (Get $get): bool => self::isSchedule($get))
                            ->live(onBlur: true),
                        TextInput::make('installment_count')
                            ->label(__('Number of instalments'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(60)
                            ->required(fn (Get $get): bool => self::isSchedule($get))
                            ->visible(fn (Get $get): bool => self::isSchedule($get))
                            ->live(onBlur: true),

                        Placeholder::make('installment_preview')
                            ->label(__('Instalments that will be generated'))
                            ->content(fn (Get $get): string => self::previewDates($get))
                            ->visible(fn (Get $get): bool => self::isSchedule($get))
                            ->columnSpanFull(),

                        Placeholder::make('installment_note')
                            ->hiddenLabel()
                            ->content(__('Saving writes one child fee line per instalment. Re-saving re-generates them — instalments already assigned to a student are left exactly as they were issued.'))
                            ->visible(fn (Get $get): bool => self::isSchedule($get))
                            ->columnSpanFull(),
                    ]),

                FormSection::make(__('Effective dates'))
                    ->description(__('When this price is in force. Leave both empty for "always". To change a price mid-year, do NOT edit the amount here — use Revise on the list screen, which schedules the new price from a date and keeps the old one on the record.'))
                    ->columns(2)
                    ->schema([
                        DatePicker::make('effective_from')
                            ->label(__('In force from'))
                            ->native(false)
                            ->placeholder(__('Always')),
                        DatePicker::make('effective_to')
                            ->label(__('In force until'))
                            ->native(false)
                            ->placeholder(__('Open-ended'))
                            ->afterOrEqual('effective_from'),
                    ]),

                FormSection::make(__('Fine rule'))
                    ->description(__('What is charged when this fee is paid late. The rule in force when a student is assigned this fee is FROZEN onto their due — editing it here changes future dues only.'))
                    ->columns(2)
                    ->schema([
                        Select::make('fee_fine_id')
                            ->label(__('Use a named fine rule'))
                            ->options(fn (): array => FeeFine::query()
                                ->where('status', true)
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (FeeFine $fine): array => [
                                    $fine->id => $fine->name.' ('.self::describeRule($fine).')',
                                ])
                                ->all())
                            ->placeholder(__('No — set the rule on this line'))
                            ->helperText(__('Named rules can be reused across fee lines.'))
                            ->live()
                            ->columnSpanFull(),

                        Radio::make('fine_type')
                            ->label(__('Fine type'))
                            ->options(Labels::t(FeeMaster::FINE_TYPES))
                            ->default('none')
                            ->inline()
                            ->live()
                            ->visible(fn (Get $get): bool => blank($get('fee_fine_id')))
                            ->columnSpanFull(),
                        TextInput::make('fine_amount')
                            ->label(fn (Get $get): string => $get('fine_type') === 'percent' ? __('Fine percentage') : __('Fine amount'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText(fn (Get $get): ?string => $get('fine_type') === 'percent'
                                ? __('Charged on the amount still outstanding, not on the original fee.')
                                : null)
                            ->visible(fn (Get $get): bool => blank($get('fee_fine_id')) && $get('fine_type') !== 'none'),
                        TextInput::make('fine_after_days')
                            ->label(__('Fine applies after (days)'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get): bool => blank($get('fee_fine_id')) && $get('fine_type') !== 'none'),
                        Select::make('fine_frequency')
                            ->label(__('Fine frequency'))
                            ->options(Labels::t(FeeMaster::FINE_FREQUENCIES))
                            ->default('one_time')
                            ->helperText(__('How fast the penalty grows once overdue. A daily fine skips school holidays.'))
                            ->visible(fn (Get $get): bool => blank($get('fee_fine_id')) && $get('fine_type') !== 'none'),
                    ]),

                FormSection::make()
                    ->schema([
                        Toggle::make('status')->label(__('Active'))->default(true),
                    ]),
            ]);
    }

    /** True while the form is describing a recurring cycle rather than one charge. */
    private static function isSchedule(Get $get): bool
    {
        $frequency = $get('billing_frequency');

        return $frequency !== null && $frequency !== 'one_time';
    }

    /**
     * The dates the generator will write, previewed live so an operator sees a
     * February date land on the 28th before they save rather than afterwards.
     */
    private static function previewDates(Get $get): string
    {
        $start = $get('first_due_date');
        $interval = FeeMaster::BILLING_INTERVAL_MONTHS[$get('billing_frequency')] ?? null;
        $count = (int) ($get('installment_count') ?? 0);

        if (blank($start) || $interval === null || $count < 1) {
            return __('Choose a first due date and a number of instalments.');
        }

        $first = Carbon::parse($start);
        $count = min($count, 60);

        $dates = collect(range(1, $count))
            ->map(fn (int $number): string => $first->copy()
                ->addMonthsNoOverflow($interval * ($number - 1))
                ->format('d M Y'));

        // Long schedules are summarised rather than listed: twelve dates is a
        // useful check, sixty is a wall.
        if ($dates->count() > 8) {
            return $dates->take(4)->implode(', ')
                .' … '.$dates->last()
                .' ('.$dates->count().' instalments)';
        }

        return $dates->implode(', ').' ('.$dates->count().' instalments)';
    }

    /** A named rule in one phrase, for the picker. */
    private static function describeRule(FeeFine $fine): string
    {
        $value = rtrim(rtrim(number_format((float) $fine->value, 2), '0'), '.');

        $body = match ($fine->type) {
            'percent' => $value.'% of the outstanding amount',
            'daily' => Money::symbol().$value.' per day',
            default => Money::symbol().$value,
        };

        return $fine->grace_days > 0
            ? $body.', after '.$fine->grace_days.' days'
            : $body;
    }
}

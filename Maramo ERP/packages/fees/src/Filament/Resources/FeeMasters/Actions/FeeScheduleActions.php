<?php

namespace Classivo\Fees\Filament\Resources\FeeMasters\Actions;

use Carbon\Carbon;
use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Services\FeeScheduleService;
use Classivo\Fees\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The two schedule-aware actions on a Fees Master: REVISE a price from a date,
 * and RE-GENERATE a definition's instalments.
 *
 * Built here rather than inline for the same reason as
 * {@see GuardedFeeMasterDeleteActions}: the list screen and the edit header must
 * offer the identical action, and a second hand-written copy of either is how two
 * screens end up disagreeing about money.
 */
final class FeeScheduleActions
{
    /**
     * Schedule a price revision.
     *
     * *** WHY THIS EXISTS RATHER THAN "JUST EDIT THE AMOUNT" ***
     * Editing `amount` in place is the obvious move and it is quietly wrong in two
     * ways. It applies the instant it is saved, so "the fee goes up in July" has to
     * be remembered and done by hand in July; and it leaves no trace that the fee
     * was ever anything else, so every screen that joins a due back to its master
     * — statements, the head-wise reports, any API listing of fee
     * masters — starts describing last term's bills at this term's price.
     *
     * A revision instead writes a SECOND master covering the same charge from a
     * chosen date, and closes the old one the day before.
     * {@see Classivo\Fees\Services\FeeAllocationService::resolveMastersFor()} picks whichever
     * is effective on the day an allocation is made, so the changeover happens by
     * itself and both prices stay on the record.
     *
     * NOT OFFERED ON A SCHEDULE DEFINITION. A recurring definition already has a
     * cleaner answer: change its amount and save. The generator re-prices every
     * instalment that has NOT been billed yet and leaves the billed ones exactly as
     * issued — which is the same guarantee, expressed in the schedule instead of in
     * a second row.
     */
    public static function revise(): Action
    {
        return Action::make('revise')
            ->label(__('Revise price'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (FeeMaster $record): bool => ! $record->isScheduleParent() && ! $record->trashed())
            ->modalHeading(__('Schedule a price revision'))
            ->modalDescription(__('The new price takes over on the date you choose. This fee line is closed off the day before and stays on the record, so dues already issued keep describing what they charged.'))
            ->modalSubmitActionLabel(__('Schedule revision'))
            ->schema([
                Placeholder::make('current')
                    ->label(__('Current price'))
                    ->content(fn (FeeMaster $record): string => Money::symbol().number_format((float) $record->amount, 2)
                        .' ('.self::windowLabel($record).')'),

                // A superseded row looks identical to the live one on the list —
                // same group, same type, same class — and the "In force" column is
                // the only thing that tells them apart. Say so here too, where the
                // operator is about to act.
                Placeholder::make('superseded_warning')
                    ->label('')
                    ->visible(fn (FeeMaster $record): bool => app(FeeScheduleService::class)->isSuperseded($record))
                    ->content(fn (FeeMaster $record): string => __('This price is no longer in force — it was closed on ')
                        .$record->effective_to->format('d M Y').'. '
                        .self::successorSentence($record)
                        .' Revising a closed price cannot change what anybody is billed.'),
                TextInput::make('amount')
                    ->label(__('New amount'))
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->prefix(fn (): string => Money::symbol())
                    ->default(fn (FeeMaster $record): string => (string) $record->amount),
                DatePicker::make('effective_from')
                    ->label(__('New price in force from'))
                    ->native(false)
                    ->required()
                    ->default(fn (): string => now()->addDay()->toDateString())
                    ->helperText(__('Allocations made on or after this date use the new price. Earlier ones are untouched.')),
            ])
            ->action(function (FeeMaster $record, array $data): void {
                $from = Carbon::parse($data['effective_from']);
                $schedule = app(FeeScheduleService::class);

                if ($record->effective_from !== null && $from->lte($record->effective_from)) {
                    Notification::make()
                        ->danger()
                        ->title(__('That date is not after the current price started.'))
                        ->body(__('A revision has to take over from a later date than the price it replaces, otherwise the two overlap and neither is clearly in force.'))
                        ->send();

                    return;
                }

                // REFUSED, not warned. Revising a row that has already been closed
                // off writes a replacement that takes over from a price nobody is
                // being charged any more, so the price the students ARE on does not
                // move — and the operator gets a green "scheduled" toast saying it
                // did. The list showed the closed row and the live row as two
                // near-identical lines, so picking the wrong one is a mis-click,
                // not a decision; the honest answer is to name the right row.
                if ($schedule->isSuperseded($record)) {
                    Notification::make()
                        ->danger()
                        ->title(__('This price is no longer in force — nothing was changed.'))
                        ->body(__('It was closed on ').$record->effective_to->format('d M Y')
                            .'. '.self::successorSentence($record)
                            .' Revising a closed price would schedule a change that never takes effect.')
                        ->persistent()
                        ->send();

                    return;
                }

                $replacement = $schedule->revise($record, (float) $data['amount'], $from);

                Notification::make()
                    ->success()
                    ->title(__('Revision scheduled.'))
                    ->body(Money::symbol().number_format((float) $replacement->amount, 2).' takes over on '
                        .$from->format('d M Y').' and runs '
                        .($replacement->effective_to !== null
                            ? 'until '.$replacement->effective_to->format('d M Y')
                            : 'until it is revised again')
                        .'. The current price runs until '
                        .$from->copy()->subDay()->format('d M Y').'.')
                    ->send();
            });
    }

    /** "in force from X until Y" / "in force until Y" / "always in force". */
    private static function windowLabel(FeeMaster $record): string
    {
        $from = $record->effective_from?->format('d M Y');
        $to = $record->effective_to?->format('d M Y');

        return match (true) {
            $from !== null && $to !== null => "in force {$from} – {$to}",
            $from !== null => "in force from {$from}",
            $to !== null => "in force until {$to}",
            default => 'in force since the price list began',
        };
    }

    /** Names the row that IS in force for this charge, when there is one. */
    private static function successorSentence(FeeMaster $record): string
    {
        $live = app(FeeScheduleService::class)->inForceSibling($record);

        return $live === null
            ? __('No price for this fee head is in force today — create one instead of revising this row.')
            : __('The price in force today is').' '.Money::format($live->amount)
                .' ('.self::windowLabel($live).') — revise that row instead.';
    }

    /**
     * Re-expand a schedule definition into its instalments without opening the
     * edit form — for a definition created before its class existed, or one whose
     * generation was interrupted. Idempotent: a definition already in step reports
     * "up to date" and writes nothing.
     */
    public static function generateInstallments(): Action
    {
        return Action::make('generateInstallments')
            ->label(__('Generate instalments'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->visible(fn (FeeMaster $record): bool => $record->isScheduleParent() && ! $record->trashed())
            ->requiresConfirmation()
            ->modalHeading(__('Generate this schedule\'s instalments'))
            ->modalDescription(__('Writes one fee line per instalment. Instalments already assigned to students are left exactly as they were issued.'))
            ->action(function (FeeMaster $record): void {
                $tally = app(FeeScheduleService::class)->generate($record);

                $notification = Notification::make()->success()->title(sprintf(
                    '%d created, %d updated, %d retired.',
                    $tally['created'],
                    $tally['updated'],
                    $tally['retired'],
                ));

                if ($tally['skipped'] > 0) {
                    $notification->body($tally['skipped'].' instalment(s) are already assigned to students and were left unchanged.');
                }

                $notification->send();
            });
    }
}

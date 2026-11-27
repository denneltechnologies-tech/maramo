<?php

namespace Classivo\Fees\Filament\Resources\FeeMasters\Actions;

use Classivo\Fees\Models\FeeMaster;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Number;

/**
 * The delete actions for Fees Masters, with the "someone has already paid this"
 * guard attached — built here rather than inline so the list screen, the edit
 * screen and the bulk toolbar cannot drift apart. Every delete path the panel
 * offers must come from this class.
 *
 * WHAT THIS STOPS. Deleting a fee master someone has paid against was a
 * one-click, unconfirmed action with no consequences shown, which can leave
 * students billed for fees that no longer exist. The guard cancels the action outright and names how many
 * allocations and how much money are involved, so the operator is told what
 * they were about to strand instead of finding out from a parent.
 *
 * WHY A UI GUARD *AND* {@see FeeMaster::booted()}. They are not duplicates:
 * the model hooks are the correctness backstop (they also cover console
 * commands, seeders and future code) but they can only return false, which
 * Filament reports as a generic "failed to delete". This layer is what turns
 * that refusal into a sentence a human can act on. Neither is redundant — the
 * model without this is silent, this without the model is bypassable.
 */
final class GuardedFeeMasterDeleteActions
{
    /** Single-record delete, for the table row and the Edit page header. */
    public static function record(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(
                __('Fees still assigned to students from this master are archived along with it, ')
                .'and come back if it is restored. A master that has already been paid against cannot be deleted.'
            )
            ->before(function (FeeMaster $record, DeleteAction $action): void {
                $summary = $record->paidAllocationSummary();

                if ($summary['count'] < 1) {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->persistent()
                    ->title(__('Not deleted — this fees master has been paid against.'))
                    ->body(static::paidBody($record, $summary['count'], $summary['total']))
                    ->send();

                $action->cancel();
            });
    }

    /**
     * Bulk delete. Refuses the WHOLE selection when any one master in it has
     * been paid against: a partial bulk delete would leave the operator with a
     * count they cannot reconcile against what they selected, and the masters
     * that did go are the ones they would not have checked.
     */
    public static function bulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            // PIN THIS. With fetchSelectedRecords() off, Filament deletes the
            // selection with one mass UPDATE on the query, which fires no model
            // events at all — FeeMaster's cascade would never run and the bulk
            // path alone would keep orphaning allocations.
            ->fetchSelectedRecords()
            ->modalDescription(
                __('Fees still assigned to students from these masters are archived along with them, ')
                .'and come back if they are restored. Masters that have already been paid against cannot be deleted.'
            )
            ->before(function (EloquentCollection $records, DeleteBulkAction $action): void {
                $blocked = [];
                $allocations = 0;
                $money = 0.0;

                foreach ($records as $record) {
                    if (! $record instanceof FeeMaster) {
                        continue;
                    }

                    $summary = $record->paidAllocationSummary();

                    if ($summary['count'] < 1) {
                        continue;
                    }

                    $blocked[] = static::label($record);
                    $allocations += $summary['count'];
                    $money += $summary['total'];
                }

                if ($blocked === []) {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->persistent()
                    ->title(__('Nothing was deleted — ').count($blocked).' of the selected fees masters have been paid against.')
                    ->body(
                        static::names($blocked).' '
                        .static::plural($allocations, 'allocation', 'allocations').' already collected '
                        .static::money($records->first(), $money).'. '
                        .'Deleting them would leave those bills in students\' accounts with no fee line behind them. '
                        .__('Take those masters out of the selection, or reverse the payments first.')
                    )
                    ->send();

                $action->cancel();
            });
    }

    /** The sentence shown when a single master is refused. */
    private static function paidBody(FeeMaster $record, int $count, float $total): string
    {
        return self::plural($count, 'allocation', 'allocations').' assigned from this master '
            .($count === 1 ? 'has' : 'have').' already collected '.self::money($record, $total).'. '
            .'Deleting it would leave those bills in students\' accounts with no fee line behind them. '
            .__('Reverse the payments on the Collect screen first, or set this master to Inactive ')
            .'so it stops being assigned to anyone new.';
    }

    /** "3 allocations" / "1 allocation". */
    private static function plural(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    /** Money in the branch's own currency, falling back to INR. */
    private static function money(?FeeMaster $record, float $amount): string
    {
        return (string) Number::currency($amount, $record?->branch?->currency ?: 'INR');
    }

    /** "Tuition Fee (Grade 1 Fees)" — enough for an operator to find the row again. */
    private static function label(FeeMaster $record): string
    {
        $type = $record->feeType?->name ?? __('Fees master #').$record->getKey();
        $group = $record->feeGroup?->name;

        return $group === null ? $type : $type.' ('.$group.')';
    }

    /** At most three names, so one careless "select all" cannot fill the screen. */
    private static function names(array $labels): string
    {
        $shown = array_slice($labels, 0, 3);
        $rest = count($labels) - count($shown);

        return implode(', ', $shown).($rest > 0 ? ' and '.$rest.' more.' : '.');
    }
}

<?php

namespace Classivo\Fees\Filament\Resources\FeeMasters\Concerns;

use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Services\FeeScheduleService;
use Filament\Notifications\Notification;

/**
 * The half of the Fees Master form that is not a column: turning "monthly, ten
 * times, from 10 April" into a schedule DEFINITION and then into ten dated fee
 * lines.
 *
 * Shared by the create and edit pages so the two cannot drift — the same mistake
 * {@see Classivo\Fees\Filament\Resources\FeeMasters\Actions\GuardedFeeMasterDeleteActions}
 * exists to prevent for deletes.
 *
 * NOTHING HERE WRITES AN INSTALMENT ITSELF. It normalises the form's answer into
 * the three columns that describe a schedule and then hands the saved row to
 * {@see FeeScheduleService}, which owns every rule about what may be rewritten
 * and what is already somebody's debt.
 */
trait SavesFeeSchedule
{
    /**
     * Reconcile the schedule columns with the frequency the operator chose.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyScheduleRules(array $data, ?FeeMaster $record = null): array
    {
        // Editing a generated instalment. It is one dated charge that happens to
        // remember which cycle produced it; it is never itself a definition, and
        // the form disables the frequency field to say so.
        if ($record?->parent_fee_master_id !== null) {
            $data['is_schedule_parent'] = false;
            $data['installment_count'] = null;
            $data['first_due_date'] = null;

            return $data;
        }

        $frequency = $data['billing_frequency'] ?? 'one_time';

        if ($frequency === 'one_time') {
            $data['is_schedule_parent'] = false;
            $data['installment_count'] = null;
            $data['first_due_date'] = null;

            return $data;
        }

        $data['is_schedule_parent'] = true;

        // A definition carries `due_date` too, mirroring its first instalment, so
        // every listing, sort and export that reads a master's due date still
        // reads something true rather than a blank.
        if (! blank($data['first_due_date'] ?? null)) {
            $data['due_date'] = $data['first_due_date'];
        }

        $count = (int) ($data['installment_count'] ?? 0);

        if ($count < 1) {
            $interval = FeeMaster::BILLING_INTERVAL_MONTHS[$frequency] ?? 1;
            $data['installment_count'] = (int) (12 / $interval);
        }

        return $data;
    }

    /**
     * Expand the saved definition into its instalments and say what happened.
     * A one-time master is not a schedule, so this is a no-op for it.
     */
    protected function regenerateInstallments(FeeMaster $record): void
    {
        if (! $record->isScheduleParent()) {
            return;
        }

        $tally = app(FeeScheduleService::class)->generate($record);

        $parts = [];

        foreach (['created' => 'created', 'updated' => 'updated', 'retired' => 'retired'] as $key => $verb) {
            if ($tally[$key] > 0) {
                $parts[] = $tally[$key].' '.$verb;
            }
        }

        $notification = Notification::make()->success()->title(
            $parts === []
                ? __('Instalments are already up to date.')
                : __('Instalments ').implode(', ', $parts).'.',
        );

        // The skipped ones are the important half of the message: they are
        // instalments a student has already been billed, which a revision
        // deliberately does not rewrite. Saying so stops an operator concluding
        // the save silently failed.
        if ($tally['skipped'] > 0) {
            $notification->body($tally['skipped'].' instalment(s) were left unchanged because they are already assigned to students. Use Revise to change a price that has been billed.');
        }

        $notification->send();
    }
}

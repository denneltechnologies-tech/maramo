<?php

namespace Classivo\Fees\Console;

use Classivo\Fees\Services\Fees\FeeDefaulterQuery;
use Classivo\Fees\Services\Fees\FeeReminderLadder;
use Classivo\Fees\Services\Fees\FeeReminderSender;
use Classivo\Fees\Support\SchoolTimezone;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * fees:send-reminders — walk every overdue defaulter, pick the ladder rung
 * (3/7/15/30 days), and e-mail the guardians. Idempotent: a rung is sent once
 * and at most one reminder per school day; --dry-run renders and sends nothing.
 */
class SendFeeReminders extends Command
{
    protected $signature = 'fees:send-reminders
        {--dry-run : Render every message and send nothing}
        {--school= : Pin the sweep to one school id}
        {--stage= : Only fire this rung (3|7|15|30)}
        {--chunk=200 : Defaulters loaded per pass}
        {--limit= : Stop after this many reminders}';

    protected $description = 'E-mail fee reminders on the 3/7/15/30-day overdue ladder (--dry-run renders without sending).';

    public function handle(FeeDefaulterQuery $defaulters, FeeReminderLadder $ladder, FeeReminderSender $sender): int
    {
        if (! config('fees.reminders.enabled', true)) {
            $this->info('Fee reminders are disabled (fees.reminders.enabled).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $schoolId = $this->option('school') === null ? null : (int) $this->option('school');
        $onlyStage = $this->option('stage') === null ? null : (int) $this->option('stage');
        $limit = $this->option('limit') === null ? null : max(0, (int) $this->option('limit'));

        if ($onlyStage !== null && ! in_array($onlyStage, FeeReminderLadder::RUNGS, true)) {
            $this->error('--stage must be one of '.implode(', ', FeeReminderLadder::RUNGS).'.');

            return self::FAILURE;
        }

        FeeReminderLadder::resetRunState();

        $asOf = SchoolTimezone::today();
        $sent = 0;
        $emails = 0;
        $skipped = ['no_rung' => 0, 'already_told' => 0, 'cooldown' => 0, 'stale' => 0, 'ceiling' => 0];
        $digests = [];

        $visited = $defaulters->chunk(function (Collection $rows) use (
            $ladder, $sender, $dryRun, $onlyStage, $limit, &$sent, &$emails, &$skipped, &$digests
        ): void {
            $lastSent = $sender->lastRemindedForStudents($rows->pluck('student_id')->map(fn ($id): int => (int) $id)->all());

            foreach ($rows as $row) {
                if (($limit !== null && $sent >= $limit) || $row->student === null) {
                    continue;
                }

                if ($ladder->isStale($row->days_overdue)) {
                    $skipped['stale']++;

                    continue;
                }

                $rung = $ladder->rungFor($row->days_overdue);

                if ($rung === null || ($onlyStage !== null && $rung !== $onlyStage)) {
                    $skipped['no_rung']++;

                    continue;
                }

                if (! $ladder->isClear($row->reminder_stage, $row->last_reminded_at, $rung)) {
                    $skipped['already_told']++;

                    continue;
                }

                $last = $lastSent->get((int) $row->student_id);

                if ($ladder->withinCooldown($last) || $ladder->announcedToday($last)) {
                    $skipped['cooldown']++;

                    continue;
                }

                if ($ladder->ceilingReached()) {
                    $skipped['ceiling']++;

                    continue;
                }

                if ($dryRun) {
                    $preview = $sender->preview($row->student, $row->due, $rung, $row->days_overdue, $row->oldest_due_date);
                    $this->line(sprintf('[%d days] %s -> %s', $rung, $preview['subject'], implode(', ', $preview['recipients']) ?: '(no address)'));
                } else {
                    $emails += $sender->send($row->student, $row->due, $rung, $row->days_overdue, $row->oldest_due_date, force: true);
                }

                $ladder->countDispatch();
                $sent++;

                if ($ladder->copiesBackOffice($rung)) {
                    $digests[(int) $row->school_id][$rung][] = ['name' => $row->name, 'admission_no' => $row->admission_no, 'due' => $row->due];
                }
            }
        }, max(1, (int) $this->option('chunk')), $schoolId, $asOf);

        if (! $dryRun) {
            foreach ($digests as $school => $byStage) {
                foreach ($byStage as $stage => $list) {
                    $emails += $sender->sendBackOfficeDigest((int) $school, (int) $stage, $list);
                }
            }
        }

        $this->info(sprintf(
            '%sDefaulters seen: %d; reminders: %d; e-mails: %d; skipped: %s',
            $dryRun ? '[dry run] ' : '',
            $visited,
            $sent,
            $emails,
            collect($skipped)->map(fn ($n, $k): string => "{$k} {$n}")->implode(', '),
        ));

        return self::SUCCESS;
    }
}

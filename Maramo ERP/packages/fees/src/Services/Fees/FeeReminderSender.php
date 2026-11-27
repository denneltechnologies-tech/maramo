<?php

namespace Classivo\Fees\Services\Fees;

use Carbon\CarbonImmutable;
use Classivo\Core\Models\School;
use Classivo\Fees\Contracts\ReminderRecipients;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Notifications\FeeReminderNotification;
use Classivo\Fees\Support\Money;
use Classivo\Fees\Support\SchoolTimezone;
use Classivo\Students\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * FeeReminderSender — renders and delivers fee reminders by e-mail.
 *
 * Delivery uses Laravel notifications on the MAIL channel only (on-demand
 * routes, so recipients do not need to be User models). SMS, WhatsApp and push
 * are not part of the open-source fees package; a host app that wants them can
 * listen for the same data or bind its own {@see ReminderRecipients}.
 *
 * The ladder policy (which rung, cooldown, once a day, run ceiling) lives in
 * {@see FeeReminderLadder}; this class only answers "who" and "what text", and
 * stamps the rung after a successful send.
 */
class FeeReminderSender
{
    public function __construct(
        private readonly FeeReminderLadder $ladder,
        private readonly ReminderRecipients $recipients,
    ) {}

    /**
     * Send one reminder. Returns the number of e-mails dispatched (0 when the
     * student has no reachable address, or the cooldown blocks it).
     */
    public function send(
        Student $student,
        float $due,
        ?int $stage = null,
        int $daysOverdue = 0,
        ?CarbonImmutable $dueDate = null,
        bool $force = false,
    ): int {
        if (! $force && $this->withinCooldown($student)) {
            return 0;
        }

        $message = $this->render($student, $due, $stage, $daysOverdue, $dueDate);
        $sent = 0;

        foreach ($this->recipients->for($student) as $recipient) {
            try {
                Notification::route('mail', [$recipient['email'] => $recipient['name']])
                    ->notify(new FeeReminderNotification(
                        $message['subject'],
                        [$this->interpolate($message['body'], ['guardian_name' => $recipient['name'] ?? 'Parent/Guardian'])],
                    ));
                $sent++;
            } catch (Throwable $e) {
                Log::warning('Fee reminder could not be sent.', [
                    'student_id' => $student->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($sent > 0) {
            $this->ladder->stampCycle((int) $student->getKey(), $stage ?? FeeReminderLadder::RUNGS[0]);
        }

        return $sent;
    }

    /**
     * Render without sending (used by --dry-run and previews).
     *
     * @return array{subject: string, body: string, recipients: list<string>, blocked: ?string}
     */
    public function preview(Student $student, float $due, ?int $stage = null, int $daysOverdue = 0, ?CarbonImmutable $dueDate = null): array
    {
        $message = $this->render($student, $due, $stage, $daysOverdue, $dueDate);
        $recipients = array_map(fn (array $r): string => $r['email'], $this->recipients->for($student));

        $blocked = match (true) {
            $recipients === [] => 'No guardian or student e-mail address on file.',
            $this->withinCooldown($student) => 'Reminded within the last '.$this->ladder->cooldownMinutes().' minutes.',
            default => null,
        };

        return [
            'subject' => $message['subject'],
            'body' => $this->interpolate($message['body'], ['guardian_name' => 'Parent/Guardian']),
            'recipients' => $recipients,
            'blocked' => $blocked,
        ];
    }

    /**
     * One e-mail per configured office address listing the students who reached
     * an escalation rung in this run. Returns e-mails sent.
     *
     * @param  list<array{name: string, admission_no: string, due: float}>  $rows
     */
    public function sendBackOfficeDigest(int $schoolId, int $stage, array $rows): int
    {
        $emails = array_values(array_filter((array) config('fees.reminders.escalate.office_emails', []), fn ($e): bool => is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
        $max = max(0, (int) config('fees.reminders.escalate.max_back_office', 3));
        $emails = array_slice($emails, 0, $max);

        if ($emails === [] || $rows === []) {
            return 0;
        }

        $lines = ['The following students reached the "'.$this->ladder->label($stage).'" stage today:'];

        foreach ($rows as $row) {
            $lines[] = sprintf('%s (%s) — %s overdue', $row['name'], $row['admission_no'], Money::format($row['due']));
        }

        $subject = 'Fee escalation — '.$this->schoolName($schoolId).' — '.count($rows).' student(s)';
        $sent = 0;

        foreach ($emails as $email) {
            try {
                Notification::route('mail', $email)->notify(new FeeReminderNotification($subject, $lines));
                $sent++;
            } catch (Throwable $e) {
                Log::warning('Fee escalation digest could not be sent.', ['error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /** Most recent reminder stamp across the student's allocations. */
    public function lastRemindedAt(Student $student): ?CarbonImmutable
    {
        return $this->lastRemindedForStudents([(int) $student->getKey()])->get((int) $student->getKey());
    }

    public function withinCooldown(Student $student): bool
    {
        return $this->ladder->withinCooldown($this->lastRemindedAt($student));
    }

    /**
     * @param  list<int>  $studentIds
     * @return Collection<int, CarbonImmutable>
     */
    public function lastRemindedForStudents(array $studentIds): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return FeeStudentAllocation::query()
            ->withoutGlobalScopes()
            ->whereIn('student_id', $studentIds)
            ->whereNotNull('last_reminded_at')
            ->groupBy('student_id')
            ->selectRaw('student_id, MAX(last_reminded_at) AS last_at')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->student_id => CarbonImmutable::parse($row->last_at)]);
    }

    /** @return array{subject: string, body: string} */
    private function render(Student $student, float $due, ?int $stage, int $daysOverdue, ?CarbonImmutable $dueDate): array
    {
        $rung = $stage ?? ($this->ladder->rungFor($daysOverdue) ?? FeeReminderLadder::RUNGS[0]);
        $vars = $this->baseVars($student, $due) + $this->ladder->contextFor($rung, $due, $daysOverdue, $dueDate);

        return [
            'subject' => $this->interpolate($this->ladder->subject($rung), $vars),
            'body' => $this->interpolate($this->ladder->body($rung), $vars, keepGuardian: true),
        ];
    }

    /** @return array<string, string|int> */
    private function baseVars(Student $student, float $due): array
    {
        $name = trim((string) ($student->full_name ?? trim(($student->first_name ?? '').' '.($student->last_name ?? ''))));

        return [
            'student_name' => $name !== '' ? $name : 'your ward',
            'admission_no' => (string) ($student->admission_no ?? ''),
            'class' => (string) ($student->class_name ?? ''),
            'school_name' => $this->schoolName((int) $student->school_id),
            'due_amount' => Money::format($due),
            'amount' => Money::format($due),
            'date' => SchoolTimezone::now()->isoFormat('D MMM YYYY'),
        ];
    }

    /** @param  array<string, mixed>  $vars */
    private function interpolate(string $text, array $vars, bool $keepGuardian = false): string
    {
        foreach ($vars as $key => $value) {
            if ($keepGuardian && $key === 'guardian_name') {
                continue;
            }

            $text = str_replace('{'.$key.'}', (string) $value, $text);
        }

        return $text;
    }

    private function schoolName(int $schoolId): string
    {
        static $names = [];

        return $names[$schoolId] ??= (string) (School::query()->whereKey($schoolId)->value('name') ?? config('app.name', 'School'));
    }
}

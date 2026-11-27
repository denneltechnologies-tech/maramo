<?php

namespace Classivo\Fees\Services\Fees;

use Carbon\CarbonImmutable;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Support\SchoolTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FeeReminderLadder — the escalation policy for overdue-fee reminders.
 *
 * A family is reminded at fixed rungs of lateness (3, 7, 15 and 30 days past
 * the oldest unpaid due date), at most once per rung and at most once per
 * school day, never beyond `fees.reminders.max_days_overdue`, and never more
 * than `fees.reminders.max_per_run` per process. The rung reached is stamped
 * onto the student's overdue allocations (`reminder_stage`,
 * `last_reminded_at`), so the ladder survives restarts and is shared by every
 * path that can send (panel button, bulk action, scheduled command).
 *
 * The copy (subject/body/tone) escalates by rung. Delivery is done by
 * {@see FeeReminderSender} through Laravel notifications (mail channel only);
 * SMS/WhatsApp/push vendors are not part of this package.
 */
class FeeReminderLadder
{
    /**
     * The rungs, in days past the due date, ascending.
     *
     * Four is a deliberate number: it is the same count as the renewal ladder, it
     * covers a month of arrears, and every extra rung is another SMS a real parent
     * receives about a bill they already know about.
     */
    public const RUNGS = [3, 7, 15, 30];

    /**
     * Debts older than this are not announced at all.
     *
     * 120 days is four months — long past the last rung, and comfortably past any
     * plausible "the scheduler was off for a while". Beyond it the office is having
     * a conversation, not sending an SMS.
     */
    private const MAX_DAYS_OVERDUE = 120;

    /** Reminders dispatched by THIS PROCESS before the circuit breaker trips. */
    private const MAX_PER_RUN = 500;

    /**
     * How long after a reminder the same student is refused another one, on ANY
     * path — the panel button, the bulk action, the admin API, the sweep.
     *
     * This is the double-click guard, and it is deliberately shorter than the
     * once-a-day ladder rule: the ladder rule protects the parent from the
     * scheduler, and this protects them from a human with a slow connection who
     * clicks "Send reminder to all" twice.
     */
    private const COOLDOWN_MINUTES = 180;

    /** The rung at which the class teacher starts getting a copy. */
    private const ESCALATE_TO_CLASS_TEACHER_AT = 15;

    /** The rung at which the back office / head of school starts getting one. */
    private const ESCALATE_TO_BACK_OFFICE_AT = 30;

    /**
     * Allocation statuses that belong to a student's live arrears cycle.
     *
     * The same superset {@see FeeDefaulterQuery} pre-filters on;
     * the authoritative test is the balance expression, applied alongside it.
     */
    private const CYCLE_STATUSES = ['unpaid', 'partial'];

    /**
     * Reminders dispatched by this process.
     *
     * Static for the same reason the renewal ladder's is: "a run" is a process, and
     * the ceiling has to hold across every instance the container hands out during
     * it, including one resolved fresh inside a chunk callback.
     */
    private static int $dispatched = 0;

    /** Whether the ceiling has already been logged this process. */
    private static bool $ceilingLogged = false;

    /** Test seam: forget the per-process ceiling. */
    public static function resetRunState(): void
    {
        self::$dispatched = 0;
        self::$ceilingLogged = false;
    }

    // ==================================================================
    // WHICH RUNG
    // ==================================================================

    /**
     * The rung a student who is `$daysOverdue` days in arrears is on, or NULL when
     * they are on none — not yet at the first rung, or past the staleness ceiling.
     *
     * The HIGHEST qualifying rung, never a list of the ones passed. See point 1 of
     * the dormant-scheduler note in the class docblock: returning every passed rung
     * is precisely how a first run becomes four SMS per parent.
     */
    public function rungFor(int $daysOverdue): ?int
    {
        if ($daysOverdue < self::RUNGS[0] || $daysOverdue > $this->maxDaysOverdue()) {
            return null;
        }

        $rung = null;

        foreach (self::RUNGS as $candidate) {
            if ($daysOverdue >= $candidate) {
                $rung = $candidate;
            }
        }

        return $rung;
    }

    /** True when a debt is too old to be worth an automatic message. */
    public function isStale(int $daysOverdue): bool
    {
        return $daysOverdue > $this->maxDaysOverdue();
    }

    // ==================================================================
    // WHETHER IT MAY BE ANNOUNCED
    // ==================================================================

    /**
     * Whether `$rung` may still be announced for a cycle whose markers are
     * `$stage` / `$lastRemindedAt`.
     *
     * Two independent tests, and both are needed:
     *   • the same rung — or a gentler one — is never announced twice for a cycle,
     *     which is what makes a stage idempotent across days; and
     *   • a student hears about their fees AT MOST ONCE A DAY, which is what makes a
     *     same-day re-run a no-op whatever rung it lands on.
     *
     * `$lastRemindedAt` NULL means nobody has ever been told, which is true of every
     * allocation on the platform today. A stage recorded WITHOUT a timestamp is read
     * as "not yet told" for the same reason the renewal ladder does: it is the
     * signature of something that stamped a marker without speaking to anyone.
     */
    public function isClear(?int $stage, ?CarbonImmutable $lastRemindedAt, int $rung): bool
    {
        if ($lastRemindedAt === null) {
            return true;
        }

        // THE LADDER ONLY ASCENDS. Without this, a student already sent the
        // 30-day final notice would be sent the 3-day "gentle reminder" the moment
        // a newer allocation shifted the arithmetic — a gentler message describing
        // a worse situation than the one they are actually in.
        if ($stage !== null && $stage >= $rung) {
            return false;
        }

        return ! $this->isSchoolToday($lastRemindedAt);
    }

    /**
     * Whether this student has already heard from us on the school's calendar day.
     *
     * *** THE HOLE THIS CLOSES ***
     * {@see isClear()} is scoped to a CYCLE, because the markers live on the
     * allocation the cycle is about. That is what makes the ladder re-arm for free
     * when a debt is settled — and it is also a hole: a student who clears their
     * oldest arrear hands the ladder to the NEXT allocation, which carries NULL
     * markers and is therefore "never spoken to". If that one is already past a
     * rung, isClear() says yes and the guardian gets an "URGENT, 15 days overdue"
     * message on the afternoon they paid.
     *
     * This is the student-scoped half of the same rule, read from
     * `communication_logs` (which is per RECIPIENT, not per allocation), and it is
     * what makes "at most one fee reminder per student per day" true across cycles
     * rather than only within one.
     */
    public function announcedToday(?CarbonImmutable $lastSentAt): bool
    {
        return $lastSentAt !== null && $this->isSchoolToday($lastSentAt);
    }

    /**
     * Whether this student was messaged recently enough that another send is a
     * duplicate rather than a decision.
     *
     * Separate from {@see isClear()} on purpose: that one governs the LADDER, this
     * one governs every HUMAN path, and a human sending a one-off reminder from the
     * page must not be blocked for a whole day — only for long enough that a double
     * click, a double-submitted form or an app retrying a request cannot reach a
     * parent twice.
     */
    public function withinCooldown(?CarbonImmutable $lastSentAt): bool
    {
        return $lastSentAt !== null
            && $lastSentAt->diffInMinutes(CarbonImmutable::now(), true) < $this->cooldownMinutes();
    }

    public function cooldownMinutes(): int
    {
        return max(0, (int) config('fees.reminders.cooldown_minutes', self::COOLDOWN_MINUTES));
    }

    public function maxDaysOverdue(): int
    {
        return max(0, (int) config('fees.reminders.max_days_overdue', self::MAX_DAYS_OVERDUE));
    }

    public function maxPerRun(): int
    {
        return max(0, (int) config('fees.reminders.max_per_run', self::MAX_PER_RUN));
    }

    /** Whether this process may dispatch another reminder. */
    public function ceilingReached(): bool
    {
        if (self::$dispatched < $this->maxPerRun()) {
            return false;
        }

        if (! self::$ceilingLogged) {
            self::$ceilingLogged = true;

            Log::error('Fee-reminder ceiling reached; the rest of this run is suppressed.', [
                'max_per_run' => $this->maxPerRun(),
                'hint' => 'A sweep this large usually means the scheduler has been dormant or a whole class was allocated with a past due date. Investigate before raising the ceiling.',
            ]);
        }

        return true;
    }

    /** Count one dispatch against the per-process ceiling. */
    public function countDispatch(): void
    {
        self::$dispatched++;
    }

    public function dispatchedThisRun(): int
    {
        return self::$dispatched;
    }

    /**
     * Record that `$rung` was announced for this student's CURRENT ARREARS CYCLE.
     *
     * *** THE HOLE THIS CLOSES, AND WHY THE MARKER MOVED ***
     * ---------------------------------------------------------------------------
     * This used to stamp ONE allocation — the oldest unpaid overdue one, the row
     * the ladder reads its marker from. The class docblock argued that made
     * re-arming free: settle the marked debt and the next allocation becomes the
     * oldest, carrying NULL markers, so the ladder starts again.
     *
     * That is true when the settled debt was the whole cycle. It is a HOLE when it
     * was one line of several. A student with five allocations dated the same day
     * is ONE arrears cycle by any sane reading, but it is five rows: announce
     * "Urgent (15 days)", let the guardian pay the marked line, and the ladder
     * hands itself to a sibling row with NULL markers that is still 15 days
     * overdue. `isClear()` says yes, and the guardian is sent the identical
     * "URGENT, 15 days overdue" message — on the afternoon of their payment. With
     * five same-dated lines that repeats five times, one per payment.
     * `announcedToday()` does not catch it: it only suppresses a re-announcement
     * on the SAME DAY, and the payment lands the next morning.
     *
     * So the rung is now stamped on EVERY allocation in the cycle — every unpaid
     * overdue row the student currently has — which makes "already announced at
     * this rung" a property of the STUDENT AND THE RUNG rather than of one row
     * that a single payment can retire. Settling any subset now leaves the
     * survivors carrying the stamp, and the ladder refuses to repeat itself.
     *
     * Re-arming still works, and still for free: when the LAST overdue line is
     * settled every stamped row leaves the cycle set at once, and the next debt to
     * fall overdue is a fresh row with NULL markers. A part-payment still re-arms
     * nothing, because the row keeps its balance and its stamp.
     *
     * A max-of-old-and-new CASE rather than a plain assignment for the same reason
     * {@see isClear()} refuses a lower rung: the ladder only ascends, and a row
     * that already carries a 30 must not be walked back to a 15 by a later cycle
     * arithmetic change.
     *
     * Written ONLY after a dispatch that raised no exception, so a provider that
     * blows up leaves the rung un-burned and tomorrow's sweep tries again rather
     * than skipping the student for the rest of the cycle.
     *
     * One UPDATE, not one save per row, and deliberately scope-free: the sweep
     * runs in the console where the tenant scope is inert, and one statement keeps
     * hundreds of bookkeeping stamps from firing model events.
     *
     * @param  int|null  $studentId  the student whose cycle was announced
     * @return int allocations stamped
     */
    public function stampCycle(?int $studentId, int $rung, ?CarbonImmutable $asOf = null): int
    {
        if ($studentId === null) {
            return 0;
        }

        $asOf ??= SchoolTimezone::today();

        try {
            return FeeStudentAllocation::query()
                ->withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('student_id', $studentId)
                ->whereIn('status', self::CYCLE_STATUSES)
                ->whereNotNull('due_date')
                ->where('due_date', '<', $asOf->toDateString())
                ->whereRaw('(amount - discount_amount + fine_amount - paid_amount) > 0')
                ->update([
                    'reminder_stage' => DB::raw('CASE WHEN COALESCE(reminder_stage, 0) > '.(int) $rung.' THEN reminder_stage ELSE '.(int) $rung.' END'),
                    'last_reminded_at' => now(),
                ]);
        } catch (Throwable $e) {
            // A ladder that cannot record itself is a ladder that repeats itself,
            // which is bad — but failing the send that already went out is worse.
            Log::warning('Fee-reminder stage could not be recorded; the rung may fire again tomorrow.', [
                'student_id' => $studentId,
                'rung' => $rung,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    // ==================================================================
    // WHAT IT SAYS
    // ==================================================================

    /** Human name for a rung, used in modals, logs and the staff copy. */
    public function label(int $rung): string
    {
        return match ($rung) {
            3 => 'Gentle reminder (3 days)',
            7 => 'Reminder (7 days)',
            15 => 'Urgent (15 days)',
            30 => 'Final notice (30 days)',
            default => $rung.' days overdue',
        };
    }

    /** The one-word register of a rung — the shortcode a school can drop into its own template. */
    public function urgency(int $rung): string
    {
        return match ($rung) {
            3 => 'Reminder',
            7 => 'Reminder',
            15 => 'Urgent',
            30 => 'Final notice',
            default => 'Reminder',
        };
    }

    /**
     * The sentence that carries the escalation.
     *
     * Kept as its own shortcode so a school with an approved DLT template — which
     * it may not legally alter — can still escalate by inserting one variable,
     * rather than needing four newly-approved templates.
     */
    public function toneLine(int $rung): string
    {
        return match ($rung) {
            3 => 'Please pay at the fee counter at your convenience.',
            7 => 'Please clear this at the earliest to avoid a late fee.',
            15 => 'This is now seriously overdue. Please clear it as soon as possible.',
            30 => 'This is a final notice. Please contact the school office immediately to settle or agree a plan.',
            default => 'Please clear this at the earliest.',
        };
    }

    /** Email subject for a rung. */
    public function subject(int $rung): string
    {
        return match ($rung) {
            3 => 'Fee reminder — {student_name}',
            7 => 'Fee overdue — {student_name}',
            15 => 'Urgent: fee overdue — {student_name}',
            30 => 'Final notice: fee overdue — {student_name}',
            default => 'Fee reminder — {student_name}',
        };
    }

    /**
     * The guardian-facing body for a rung, as a shortcode template.
     *
     * Plain text, short, no rich formatting. Unknown tokens are left untouched by
     * the renderer, so a missing shortcode degrades to visible text rather than to
     * a blank message.
     */
    public function body(int $rung): string
    {
        return match ($rung) {
            3 => 'Dear {guardian_name}, fee of {due_amount} for {student_name} ({admission_no}) was due on {due_date} and is now {days_overdue} days overdue. {tone_line} - {school_name}',
            7 => 'Dear {guardian_name}, fee of {due_amount} for {student_name} ({admission_no}) is {days_overdue} days overdue (due {due_date}). {tone_line} - {school_name}',
            15 => 'URGENT: {due_amount} is {days_overdue} days overdue for {student_name} ({admission_no}), class {class}. {tone_line} - {school_name}',
            30 => 'FINAL NOTICE: {due_amount} has been outstanding for {days_overdue} days for {student_name} ({admission_no}), class {class}. {tone_line} - {school_name}',
            default => 'Dear {guardian_name}, {due_amount} is due for {student_name} ({admission_no}). {tone_line} - {school_name}',
        };
    }

    /**
     * The STAFF copy. Deliberately a different message from the parent's.
     *
     * A class teacher forwarded "Dear {guardian_name}, please pay" learns nothing
     * and cannot act on it; what they need is which child, which class, how much and
     * how long. It also states plainly that the guardian has already been told, so
     * nobody makes a second call about the same debt.
     */
    public function staffCopyBody(): string
    {
        return '{urgency}: {student_name} ({admission_no}), class {class} — {due_amount} overdue {days_overdue} days (due {due_date}). The guardian has been sent the {stage_label}.';
    }

    public function staffCopySubject(): string
    {
        return 'Fee escalation — {student_name} ({admission_no})';
    }

    /**
     * Every shortcode a fee reminder may use, on top of the student-level ones
     * (student_name, admission_no, guardian_name, class, school_name).
     *
     * `amount` and `due_amount` are both populated because the seeded templates use
     * one and the compose UI advertises the other, and a template that renders
     * "{amount}" as literal text in an SMS to a parent is the kind of defect nobody
     * notices until a guardian rings up about it.
     *
     * @return array<string, string|int>
     */
    public function contextFor(int $rung, float $due, int $daysOverdue, ?CarbonImmutable $dueDate): array
    {
        $amount = number_format($due, 2);

        return [
            'amount' => $amount,
            'due_amount' => $amount,
            'due_date' => $dueDate?->isoFormat('D MMM YYYY') ?? '',
            'date' => SchoolTimezone::now()->isoFormat('D MMM YYYY'),
            'days_overdue' => $daysOverdue,
            'stage' => $rung,
            'stage_label' => $this->label($rung),
            'urgency' => $this->urgency($rung),
            'tone_line' => $this->toneLine($rung),
        ];
    }

    // ==================================================================
    // WHO ELSE HEARS ABOUT IT
    // ==================================================================

    public function copiesClassTeacher(int $rung): bool
    {
        return $rung >= (int) config('fees.reminders.escalate.class_teacher_at', self::ESCALATE_TO_CLASS_TEACHER_AT);
    }

    public function copiesBackOffice(int $rung): bool
    {
        return $rung >= (int) config('fees.reminders.escalate.back_office_at', self::ESCALATE_TO_BACK_OFFICE_AT);
    }

    // ==================================================================
    // INTERNALS
    // ==================================================================

    /**
     * Whether a timestamp falls on the school's calendar day.
     *
     * `isToday()` would compare against `config('app.timezone')`, which is UTC and
     * must stay UTC. For a school in IST every reminder sent before 05:30 local is
     * stamped on the PREVIOUS UTC day, so a UTC "today" lets the once-a-day rule be
     * bypassed every single morning. {@see SchoolTimezone} is the seam that exists
     * for exactly this.
     */
    private function isSchoolToday(CarbonImmutable $moment): bool
    {
        $timezone = SchoolTimezone::resolve();

        return $moment->setTimezone($timezone)->toDateString() === SchoolTimezone::now()->toDateString();
    }
}

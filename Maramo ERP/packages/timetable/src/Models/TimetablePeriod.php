<?php

namespace Classivo\Timetable\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\Subject;
use Classivo\Timetable\Exceptions\TimetableConflictException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * TimetablePeriod — a single cell of a class-section's weekly routine.
 *
 * CONFLICT DETECTION is enforced at the MODEL layer: a `creating`/`updating`
 * guard rejects a save that would (a) give a teacher two overlapping periods on
 * the same weekday anywhere in the branch/session, (b) give a class-section two
 * overlapping periods, or (c) set an end time that is not after the start time
 * — throwing {@see TimetableConflictException} with the human-readable clashes.
 * The Class Timetable page catches it and surfaces a notification; the guard
 * also protects programmatic writes (tests, imports, the generator action).
 *
 * Tenant + branch + session scoped, soft-deleting.
 */
class TimetablePeriod extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    /** 0=Sunday .. 6=Saturday → English label (see {@see dayOptions()} for translated labels). */
    public const DAYS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'timetable_id',
        'day_of_week',
        'period_no',
        'start_time',
        'end_time',
        'subject_id',
        'teacher_id',
        'room',
        'is_break',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'period_no' => 'integer',
            'is_break' => 'boolean',
        ];
    }

    /**
     * Enforce the scheduling invariants on every write (see class docblock).
     */
    protected static function booted(): void
    {
        $guard = function (self $period): void {
            $start = static::toMinutes($period->start_time);
            $end = static::toMinutes($period->end_time);

            if ($start !== null && $end !== null && $end <= $start) {
                throw TimetableConflictException::make([
                    __('classivo-timetable::timetable.conflicts.end_before_start'),
                ]);
            }

            $conflicts = static::findConflicts($period);

            if ($conflicts !== []) {
                throw TimetableConflictException::make($conflicts);
            }
        };

        static::creating($guard);
        static::updating($guard);
    }

    /** @return array<int,string> translated day labels */
    public static function dayOptions(): array
    {
        $out = [];
        foreach (static::DAYS as $day => $label) {
            $out[$day] = __('classivo-timetable::timetable.days.'.$day);
        }

        return $out;
    }

    /**
     * Human-readable scheduling conflicts this period would introduce, checked
     * against the persisted periods (excluding itself). Empty = safe to save.
     *
     * @return list<string>
     */
    public static function findConflicts(self $period): array
    {
        $start = static::toMinutes($period->start_time);
        $end = static::toMinutes($period->end_time);

        if ($start === null || $end === null) {
            return [];
        }

        $branchId = $period->branch_id ?? current_branch_id();
        $sessionId = $period->session_id ?? current_session_id();
        $day = (int) $period->day_of_week;
        $dayLabel = __('classivo-timetable::timetable.days.'.$day);

        $overlaps = static function (self $other) use ($start, $end): bool {
            $otherStart = static::toMinutes($other->start_time);
            $otherEnd = static::toMinutes($other->end_time);

            if ($otherStart === null || $otherEnd === null) {
                return false;
            }

            // [start,end) overlaps [otherStart,otherEnd) ⇔ start < otherEnd ∧ otherStart < end.
            return $start < $otherEnd && $otherStart < $end;
        };

        $conflicts = [];

        // (a) Teacher double-booked across ANY class-section in the branch/session.
        if (! empty($period->teacher_id) && ! $period->is_break) {
            $teacherRows = static::query()
                ->where('branch_id', $branchId)
                ->where('session_id', $sessionId)
                ->where('day_of_week', $day)
                ->where('teacher_id', $period->teacher_id)
                ->where('is_break', false)
                ->when($period->exists, fn ($query) => $query->whereKeyNot($period->getKey()))
                ->with(['timetable.classSection.schoolClass', 'timetable.classSection.section', 'teacher'])
                ->get();

            foreach ($teacherRows as $other) {
                if ($overlaps($other)) {
                    $conflicts[] = __('classivo-timetable::timetable.conflicts.teacher_busy', [
                        'teacher' => $other->teacher?->name ?? __('classivo-timetable::timetable.conflicts.selected_teacher'),
                        'start' => static::formatTime($other->start_time),
                        'end' => static::formatTime($other->end_time),
                        'day' => $dayLabel,
                        'class' => $other->timetable?->classSection?->name ?? __('classivo-timetable::timetable.conflicts.another_class'),
                    ]);
                }
            }
        }

        // (b) Same class-section (timetable) with overlapping periods.
        if (! empty($period->timetable_id)) {
            $sectionRows = static::query()
                ->where('timetable_id', $period->timetable_id)
                ->where('day_of_week', $day)
                ->when($period->exists, fn ($query) => $query->whereKeyNot($period->getKey()))
                ->get();

            foreach ($sectionRows as $other) {
                if ($overlaps($other)) {
                    $conflicts[] = __('classivo-timetable::timetable.conflicts.class_overlap', [
                        'start' => static::formatTime($other->start_time),
                        'end' => static::formatTime($other->end_time),
                        'day' => $dayLabel,
                    ]);
                }
            }
        }

        return $conflicts;
    }

    /**
     * Whether saving $period would be rejected — a convenience to pre-validate
     * a whole grid before persisting.
     */
    public static function hasConflict(self $period): bool
    {
        return static::findConflicts($period) !== [];
    }

    /** Minutes since midnight for a time value, or null when blank/unparseable. */
    public static function toMinutes(mixed $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }

        try {
            $carbon = $time instanceof Carbon ? $time : Carbon::parse((string) $time);
        } catch (\Throwable) {
            return null;
        }

        return $carbon->hour * 60 + $carbon->minute;
    }

    /** Display a stored time as H:i (e.g. "09:05"), passing blanks through. */
    public static function formatTime(mixed $time): string
    {
        if ($time === null || $time === '') {
            return '—';
        }

        try {
            return ($time instanceof Carbon ? $time : Carbon::parse((string) $time))->format('H:i');
        } catch (\Throwable) {
            return (string) $time;
        }
    }

    public function dayLabel(): string
    {
        return array_key_exists((int) $this->day_of_week, static::DAYS)
            ? __('classivo-timetable::timetable.days.'.(int) $this->day_of_week)
            : (string) $this->day_of_week;
    }

    public function timetable(): BelongsTo
    {
        return $this->belongsTo(Timetable::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(config('classivo.user_model'), 'teacher_id');
    }
}

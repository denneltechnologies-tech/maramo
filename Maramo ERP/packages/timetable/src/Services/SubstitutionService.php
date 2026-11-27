<?php

namespace Classivo\Timetable\Services;

use Carbon\CarbonInterface;
use Classivo\Core\Models\ClassSubject;
use Classivo\Core\Models\ClassTeacher;
use Classivo\Timetable\Events\SubstitutionAssigned;
use Classivo\Timetable\Events\SubstitutionCancelled;
use Classivo\Timetable\Models\Substitution;
use Classivo\Timetable\Models\TimetablePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Same-day teacher substitution.
 *
 *  1. markAbsent(): a teacher is away on a date → one pending substitution per
 *     period that teacher has in the weekly timetable on that weekday.
 *  2. candidates(): teachers FREE in that slot (no overlapping period of their
 *     own that weekday, not absent themselves, not already covering an
 *     overlapping period that date), best first: teaches this subject, knows
 *     this class, lightest load that day.
 *  3. assign(): re-checks the substitute is still free, records it and fires
 *     {@see SubstitutionAssigned} so a communication module can notify them.
 *
 * Everything is read inside the current branch + session (model scopes).
 */
class SubstitutionService
{
    /**
     * @param  list<int>|null  $periodIds  only these periods (null = all that day)
     * @return Collection<int, Substitution>
     */
    public function markAbsent(int $teacherId, CarbonInterface|string $date, ?string $reason = null, ?array $periodIds = null): Collection
    {
        $date = Carbon::parse($date)->startOfDay();

        return DB::transaction(function () use ($teacherId, $date, $reason, $periodIds): Collection {
            return $this->teacherPeriods($teacherId, $date)
                ->when($periodIds !== null, fn ($c) => $c->whereIn('id', $periodIds))
                ->map(function (TimetablePeriod $period) use ($teacherId, $date, $reason): Substitution {
                    $row = Substitution::query()
                        ->where('timetable_period_id', $period->id)
                        ->whereDate('date', $date)
                        ->first();

                    if ($row === null) {
                        return Substitution::create([
                            'date' => $date->toDateString(),
                            'timetable_period_id' => $period->id,
                            'absent_teacher_id' => $teacherId,
                            'status' => Substitution::STATUS_PENDING,
                            'reason' => $reason,
                        ]);
                    }

                    if ($row->status === Substitution::STATUS_CANCELLED) {
                        $row->forceFill(['status' => Substitution::STATUS_PENDING, 'substitute_teacher_id' => null, 'reason' => $reason ?? $row->reason])->save();
                    }

                    return $row;
                })
                ->values();
        });
    }

    /** A teacher's regular (non-break) periods on the weekday of $date. @return Collection<int, TimetablePeriod> */
    public function teacherPeriods(int $teacherId, CarbonInterface|string $date): Collection
    {
        return TimetablePeriod::query()
            ->where('teacher_id', $teacherId)
            ->where('day_of_week', Carbon::parse($date)->dayOfWeek)
            ->where('is_break', false)
            ->with(['timetable.classSection.schoolClass', 'timetable.classSection.section', 'subject'])
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Free teachers for a substitution, best first.
     *
     * @return list<array{id: int, name: string, subject_match: bool, knows_class: bool, load: int}>
     */
    public function candidates(Substitution $substitution): array
    {
        $period = $substitution->period()->with('timetable')->firstOrFail();
        $date = Carbon::parse($substitution->date);
        $classSectionId = (int) $period->timetable?->class_section_id;
        $userModel = config('classivo.user_model');

        $pool = collect($this->teacherPool())
            ->reject(fn (int $id): bool => $id === (int) $substitution->absent_teacher_id)
            ->filter(fn (int $id): bool => $this->isFree($id, $date, $period, $substitution->id))
            ->values();

        if ($pool->isEmpty()) {
            return [];
        }

        $names = $userModel::query()->whereKey($pool->all())->pluck('name', 'id');
        $subjectTeachers = $period->subject_id
            ? ClassSubject::query()->where('subject_id', $period->subject_id)->whereIn('teacher_id', $pool)->pluck('teacher_id')->map('intval')->all()
            : [];
        $classTeachers = array_merge(
            ClassTeacher::query()->where('class_section_id', $classSectionId)->whereIn('teacher_id', $pool)->pluck('teacher_id')->map('intval')->all(),
            TimetablePeriod::query()->where('timetable_id', $period->timetable_id)->whereIn('teacher_id', $pool)->pluck('teacher_id')->map('intval')->all(),
        );

        return $pool
            ->filter(fn (int $id): bool => isset($names[$id]))
            ->map(fn (int $id): array => [
                'id' => $id,
                'name' => (string) $names[$id],
                'subject_match' => in_array($id, $subjectTeachers, true),
                'knows_class' => in_array($id, $classTeachers, true),
                'load' => $this->loadOn($id, $date),
            ])
            ->sort(fn (array $a, array $b): int => [! $a['subject_match'], ! $a['knows_class'], $a['load'], $a['name']]
                <=> [! $b['subject_match'], ! $b['knows_class'], $b['load'], $b['name']])
            ->values()
            ->all();
    }

    /** @throws RuntimeException when the substitute is not free or the row is cancelled */
    public function assign(Substitution $substitution, int $substituteId, ?int $assignedBy = null, ?string $note = null): Substitution
    {
        return DB::transaction(function () use ($substitution, $substituteId, $assignedBy, $note): Substitution {
            $row = Substitution::query()->lockForUpdate()->findOrFail($substitution->id);
            $period = $row->period()->with('timetable')->firstOrFail();

            if ($row->status === Substitution::STATUS_CANCELLED) {
                throw new RuntimeException(__('classivo-timetable::timetable.substitution.errors.cancelled'));
            }

            if ($substituteId === (int) $row->absent_teacher_id) {
                throw new RuntimeException(__('classivo-timetable::timetable.substitution.errors.same_teacher'));
            }

            if (! $this->isFree($substituteId, Carbon::parse($row->date), $period, $row->id)) {
                throw new RuntimeException(__('classivo-timetable::timetable.substitution.errors.not_free'));
            }

            $row->forceFill([
                'substitute_teacher_id' => $substituteId,
                'status' => Substitution::STATUS_ASSIGNED,
                'assigned_by' => $assignedBy ?? auth()->id(),
                'assigned_at' => now(),
                'note' => $note ?? $row->note,
            ])->save();

            SubstitutionAssigned::dispatch(
                (int) $row->id,
                (int) $row->school_id,
                Carbon::parse($row->date)->toDateString(),
                $substituteId,
                (int) $row->absent_teacher_id,
                (int) $period->timetable?->class_section_id,
                (int) $period->period_no,
                TimetablePeriod::formatTime($period->start_time),
                TimetablePeriod::formatTime($period->end_time),
                $period->subject_id !== null ? (int) $period->subject_id : null,
                $period->room,
            );

            return $row;
        });
    }

    public function cancel(Substitution $substitution): Substitution
    {
        if ($substitution->status === Substitution::STATUS_CANCELLED) {
            return $substitution;
        }

        $previous = $substitution->substitute_teacher_id !== null ? (int) $substitution->substitute_teacher_id : null;
        $substitution->forceFill(['status' => Substitution::STATUS_CANCELLED])->save();

        SubstitutionCancelled::dispatch(
            (int) $substitution->id,
            (int) $substitution->school_id,
            Carbon::parse($substitution->date)->toDateString(),
            $previous,
            (int) $substitution->absent_teacher_id,
        );

        return $substitution;
    }

    /**
     * Free in that slot on that date: no own regular period overlapping it that
     * weekday, not marked absent that date, not covering an overlapping period.
     */
    public function isFree(int $teacherId, CarbonInterface $date, TimetablePeriod $slot, ?int $ignoreSubstitutionId = null): bool
    {
        $start = TimetablePeriod::toMinutes($slot->start_time);
        $end = TimetablePeriod::toMinutes($slot->end_time);
        $overlaps = fn (TimetablePeriod $p): bool => TimetablePeriod::toMinutes($p->start_time) < $end && $start < TimetablePeriod::toMinutes($p->end_time);

        $absent = Substitution::query()
            ->where('absent_teacher_id', $teacherId)
            ->whereDate('date', $date)
            ->where('status', '!=', Substitution::STATUS_CANCELLED)
            ->exists();

        if ($absent) {
            return false;
        }

        if ($this->teacherPeriods($teacherId, $date)->contains($overlaps)) {
            return false;
        }

        return ! Substitution::query()
            ->where('substitute_teacher_id', $teacherId)
            ->whereDate('date', $date)
            ->where('status', Substitution::STATUS_ASSIGNED)
            ->when($ignoreSubstitutionId, fn ($q) => $q->whereKeyNot($ignoreSubstitutionId))
            ->with('period')
            ->get()
            ->contains(fn (Substitution $s): bool => $s->period !== null && $overlaps($s->period));
    }

    /** Regular periods that weekday + covers assigned that date. */
    public function loadOn(int $teacherId, CarbonInterface $date): int
    {
        return $this->teacherPeriods($teacherId, $date)->count()
            + Substitution::query()->where('substitute_teacher_id', $teacherId)->whereDate('date', $date)->where('status', Substitution::STATUS_ASSIGNED)->count();
    }

    /**
     * Everyone who can teach: users holding a configured teacher role (when the
     * user model has roles), plus anyone timetabled, allotted a subject or made
     * a class teacher in this branch/session.
     *
     * @return list<int>
     */
    public function teacherPool(): array
    {
        $ids = array_merge(
            TimetablePeriod::query()->whereNotNull('teacher_id')->distinct()->pluck('teacher_id')->all(),
            ClassSubject::query()->whereNotNull('teacher_id')->distinct()->pluck('teacher_id')->all(),
            ClassTeacher::query()->distinct()->pluck('teacher_id')->all(),
        );

        $model = config('classivo.user_model');
        $roles = (array) config('classivo-timetable.teacher_roles', []);
        if ($roles !== [] && method_exists($model, 'roles')) {
            $ids = array_merge($ids, $model::query()->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))->pluck('id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}

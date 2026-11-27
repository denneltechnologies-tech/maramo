<?php

namespace Classivo\Timetable\Actions;

use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\ClassSubject;
use Classivo\Core\Support\Preset;
use Classivo\Timetable\Generator\Lesson;
use Classivo\Timetable\Generator\Options;
use Classivo\Timetable\Generator\Problem;
use Classivo\Timetable\Generator\Result;
use Classivo\Timetable\Generator\Room;
use Classivo\Timetable\Generator\Slot;
use Classivo\Timetable\Generator\TimetableGenerator;
use Classivo\Timetable\Models\Room as RoomModel;
use Classivo\Timetable\Models\SubjectRequirement;
use Classivo\Timetable\Models\Timetable;
use Classivo\Timetable\Models\TimetablePeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Generate clash-free timetables for class-sections of the CURRENT branch and
 * session and save them as {@see TimetablePeriod} rows.
 *
 * What it reads:
 *  - class-sections (all, or the ids you pass);
 *  - subject allotments ({@see ClassSubject}: class-wide rows, overridden by a
 *    section-specific row for the same subject) and their teachers;
 *  - the saved periods of class-sections NOT being regenerated — their
 *    teachers are treated as busy at those times, so the result never clashes
 *    with timetables you keep.
 *
 *  - weekly periods and required room type per subject from
 *    {@see SubjectRequirement} (section row over class row), and the active
 *    {@see RoomModel} rooms of the branch.
 *
 * What you pass (all optional; arguments override the database, which
 * overrides config `classivo-timetable.generator`):
 *  - weeklyPeriods: subject_id => periods a week, for every class;
 *  - classWeeklyPeriods: class_section_id => [subject_id => periods] overrides
 *    (0 = do not schedule that subject for that class);
 *  - teacherUnavailable: teacher_id => list of [day_of_week, period_no];
 *  - subjectRoomTypes + rooms: subject_id => room type, and the rooms
 *    ({@see Room}); the room id is saved in the period's `room` column;
 *  - days, periods (bell schedule), maxConsecutive, maxSameSubjectPerDay,
 *    seed, timeLimitSeconds.
 *
 * Nothing is written unless the generator SOLVES the whole problem; then every
 * chosen class-section's timetable is replaced in one transaction (the model
 * conflict guard still runs on every row).
 */
class GenerateTimetables
{
    public function __construct(protected TimetableGenerator $generator) {}

    /**
     * @param  list<int>|null  $classSectionIds
     * @param  array<int,int>  $weeklyPeriods
     * @param  array<int,array<int,int>>  $classWeeklyPeriods
     * @param  array<int,list<array{0:int,1:int}>>  $teacherUnavailable
     * @param  array<int,string>  $subjectRoomTypes
     * @param  list<Room>  $rooms
     * @param  list<int>|null  $days
     * @param  list<array{start:string,end:string,break?:bool}>|null  $periods
     */
    public function handle(
        ?array $classSectionIds = null,
        array $weeklyPeriods = [],
        array $classWeeklyPeriods = [],
        array $teacherUnavailable = [],
        array $subjectRoomTypes = [],
        array $rooms = [],
        ?array $days = null,
        ?array $periods = null,
        ?int $maxConsecutive = null,
        ?int $maxSameSubjectPerDay = null,
        ?int $seed = null,
        ?float $timeLimitSeconds = null,
        bool $save = true,
    ): GenerateTimetablesResult {
        $config = (array) config('classivo-timetable.generator', []);
        $days = array_values($days ?? $config['days'] ?? $this->defaultDays());
        $periods = array_values($periods ?? $config['periods'] ?? []);
        if ($periods === []) {
            throw new InvalidArgumentException('No bell schedule: pass $periods or set classivo-timetable.generator.periods.');
        }
        $maxConsecutive ??= $config['max_consecutive'] ?? null;
        $maxSameSubjectPerDay ??= $config['max_same_subject_per_day'] ?? null;
        $defaultWeekly = (int) ($config['default_weekly_periods'] ?? 5);

        $sections = ClassSection::query()
            ->with(['schoolClass', 'section'])
            ->when($classSectionIds !== null, fn ($q) => $q->whereKey($classSectionIds))
            ->get()
            ->keyBy('id');

        $breakSlots = [];
        foreach ($periods as $i => $row) {
            if (! empty($row['break'])) {
                foreach ($days as $day) {
                    $breakSlots[] = new Slot($day, $i + 1);
                }
            }
        }

        if ($rooms === []) {
            $rooms = RoomModel::query()->where('status', true)->orderBy('name')->get()
                ->map(fn (RoomModel $room) => $room->toGeneratorRoom())->all();
        }

        $lessons = $this->lessons($sections, $weeklyPeriods, $classWeeklyPeriods, $subjectRoomTypes, $defaultWeekly);

        $unavailable = $this->busyElsewhere($sections->keys()->all(), $days, $periods);
        foreach ($teacherUnavailable as $teacher => $slots) {
            foreach ($slots as $slot) {
                $unavailable[$teacher][] = Slot::from($slot);
            }
        }
        $unavailable = array_map(fn (array $slots) => $this->uniqueSlots($slots), $unavailable);

        $problem = new Problem(
            days: $days,
            periodsPerDay: count($periods),
            classes: $sections->keys()->all(),
            lessons: $lessons,
            teacherUnavailable: $unavailable,
            rooms: $rooms,
            maxConsecutive: $maxConsecutive,
            blockedSlots: $breakSlots,
        );

        $result = $this->generator->solve($problem, new Options(
            seed: $seed ?? (int) ($config['seed'] ?? 1),
            timeLimitSeconds: $timeLimitSeconds ?? (float) ($config['time_limit_seconds'] ?? 10),
            maxSameSubjectPerDay: $maxSameSubjectPerDay,
        ));

        if (! $result->isSolved() || ! $save) {
            return new GenerateTimetablesResult($result, []);
        }

        return new GenerateTimetablesResult($result, $this->persist($sections, $result, $days, $periods));
    }

    /**
     * @param  Collection<int,ClassSection>  $sections
     * @return list<Lesson>
     */
    protected function lessons(Collection $sections, array $weeklyPeriods, array $classWeeklyPeriods, array $subjectRoomTypes, int $defaultWeekly): array
    {
        $allotments = ClassSubject::query()
            ->whereIn('class_id', $sections->pluck('class_id')->unique()->all())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $requirements = SubjectRequirement::query()
            ->whereIn('class_id', $sections->pluck('class_id')->unique()->all())
            ->get();

        $lessons = [];
        foreach ($sections as $cs) {
            // Section-specific allotment wins over the class-wide one for a subject.
            $bySubject = [];
            foreach ($allotments as $row) {
                if ($row->class_id !== $cs->class_id) {
                    continue;
                }
                if ($row->section_id === null) {
                    $bySubject[$row->subject_id] ??= $row;
                } elseif ($row->section_id === $cs->section_id) {
                    $bySubject[$row->subject_id] = $row;
                }
            }

            foreach ($bySubject as $subjectId => $row) {
                $stored = $requirements->first(fn ($r) => $r->class_id === $cs->class_id && $r->section_id === $cs->section_id && $r->subject_id === $subjectId)
                    ?? $requirements->first(fn ($r) => $r->class_id === $cs->class_id && $r->section_id === null && $r->subject_id === $subjectId);
                $count = $classWeeklyPeriods[$cs->id][$subjectId] ?? $weeklyPeriods[$subjectId] ?? $stored?->weekly_periods ?? $defaultWeekly;
                if ($count <= 0) {
                    continue;
                }
                $lessons[] = new Lesson(
                    classId: $cs->id,
                    subjectId: $subjectId,
                    teacherId: $row->teacher_id,
                    periodsPerWeek: (int) $count,
                    roomType: $subjectRoomTypes[$subjectId] ?? ($stored?->room_type ?: null),
                );
            }
        }

        return $lessons;
    }

    /**
     * Teachers' saved periods in class-sections that are NOT being regenerated,
     * mapped onto the bell schedule.
     *
     * @param  list<int>  $regenerating
     * @return array<int,list<Slot>>
     */
    protected function busyElsewhere(array $regenerating, array $days, array $periods): array
    {
        $grid = [];
        foreach ($periods as $i => $row) {
            if (empty($row['break'])) {
                $grid[$i + 1] = [TimetablePeriod::toMinutes($row['start']), TimetablePeriod::toMinutes($row['end'])];
            }
        }

        $busy = [];
        TimetablePeriod::query()
            ->where('is_break', false)
            ->whereNotNull('teacher_id')
            ->whereIn('day_of_week', $days)
            ->whereHas('timetable', fn ($q) => $q->whereNotIn('class_section_id', $regenerating ?: [0]))
            ->get()
            ->each(function (TimetablePeriod $p) use ($grid, &$busy): void {
                $start = TimetablePeriod::toMinutes($p->start_time);
                $end = TimetablePeriod::toMinutes($p->end_time);
                foreach ($grid as $no => [$gStart, $gEnd]) {
                    if ($start < $gEnd && $gStart < $end) {
                        $busy[$p->teacher_id][] = new Slot($p->day_of_week, $no);
                    }
                }
            });

        return $busy;
    }

    /**
     * @param  Collection<int,ClassSection>  $sections
     * @return list<int> saved timetable ids
     */
    protected function persist(Collection $sections, Result $result, array $days, array $periods): array
    {
        return DB::transaction(function () use ($sections, $result, $days, $periods): array {
            $byClass = [];
            foreach ($result->placements as $placement) {
                $byClass[$placement->lesson->classId][] = $placement;
            }

            $ids = [];
            foreach ($sections as $cs) {
                $timetable = Timetable::firstOrCreate(
                    ['class_section_id' => $cs->id],
                    ['name' => $cs->name],
                );
                $timetable->periods()->forceDelete();
                $ids[] = $timetable->id;

                foreach ($days as $day) {
                    foreach ($periods as $i => $row) {
                        if (! empty($row['break'])) {
                            TimetablePeriod::create([
                                'timetable_id' => $timetable->id,
                                'day_of_week' => $day,
                                'period_no' => $i + 1,
                                'start_time' => $row['start'],
                                'end_time' => $row['end'],
                                'is_break' => true,
                            ]);
                        }
                    }
                }

                foreach ($byClass[$cs->id] ?? [] as $placement) {
                    $row = $periods[$placement->period - 1];
                    TimetablePeriod::create([
                        'timetable_id' => $timetable->id,
                        'day_of_week' => $placement->day,
                        'period_no' => $placement->period,
                        'start_time' => $row['start'],
                        'end_time' => $row['end'],
                        'subject_id' => $placement->lesson->subjectId,
                        'teacher_id' => $placement->lesson->teacherId,
                        'room' => $placement->roomId,
                        'is_break' => false,
                    ]);
                }
            }

            return $ids;
        });
    }

    /** Every weekday except the active preset's weekend days. @return list<int> */
    public function defaultDays(): array
    {
        $weekend = (array) Preset::get('weekend_days', [0]);

        return array_values(array_diff([1, 2, 3, 4, 5, 6, 0], $weekend));
    }

    /**
     * @param  list<Slot>  $slots
     * @return list<Slot>
     */
    protected function uniqueSlots(array $slots): array
    {
        $seen = [];
        foreach ($slots as $slot) {
            $seen[$slot->day.'|'.$slot->period] = $slot;
        }

        return array_values($seen);
    }
}

<?php

namespace Classivo\Timetable\Tests\Unit\Generator;

use Classivo\Timetable\Generator\Lesson;
use Classivo\Timetable\Generator\Options;
use Classivo\Timetable\Generator\Problem;
use Classivo\Timetable\Generator\Result;
use Classivo\Timetable\Generator\Room;
use Classivo\Timetable\Generator\Slot;
use Classivo\Timetable\Generator\TimetableGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Plain PHPUnit (no Laravel): the generator namespace must stay framework-free.
 * assertValid() re-checks every hard constraint independently of the solver.
 */
abstract class GeneratorTestCase extends TestCase
{
    protected function solve(Problem $problem, ?Options $options = null): Result
    {
        return (new TimetableGenerator)->solve($problem, $options ?? new Options(timeLimitSeconds: 20));
    }

    /** Hard constraints hold for whatever was placed (complete or partial). */
    protected function assertValid(Problem $problem, Result $result, ?Options $options = null): void
    {
        $class = $teacher = $room = $subjectDay = [];
        $blocked = [];
        foreach ($problem->blockedSlots as $s) {
            $blocked[$s->day.'|'.$s->period] = true;
        }
        $unavailable = [];
        foreach ($problem->teacherUnavailable as $t => $slots) {
            foreach ($slots as $s) {
                $unavailable[$t.'|'.$s->day.'|'.$s->period] = true;
            }
        }
        $roomTypes = [];
        $roomUnavailable = [];
        foreach ($problem->rooms as $r) {
            $roomTypes[(string) $r->id] = $r->type;
            foreach ($r->unavailable as $s) {
                $s = Slot::from($s);
                $roomUnavailable[$r->id.'|'.$s->day.'|'.$s->period] = true;
            }
        }

        foreach ($result->placements as $p) {
            $key = $p->day.'|'.$p->period;
            $l = $p->lesson;
            $this->assertArrayHasKey($p->day, $problem->periodsPerDay, 'placed on a day that does not exist');
            $this->assertLessThanOrEqual($problem->periodsPerDay[$p->day], $p->period, 'placed beyond the last period');
            $this->assertArrayNotHasKey($key, $blocked, "lesson {$l->id} placed in blocked slot {$key}");

            $this->assertArrayNotHasKey($l->classId.'|'.$key, $class, "class {$l->classId} double-booked at {$key}");
            $class[$l->classId.'|'.$key] = true;

            if ($l->teacherId !== null) {
                $this->assertArrayNotHasKey($l->teacherId.'|'.$key, $teacher, "teacher {$l->teacherId} double-booked at {$key}");
                $this->assertArrayNotHasKey($l->teacherId.'|'.$key, $unavailable, "teacher {$l->teacherId} placed while unavailable at {$key}");
                $teacher[$l->teacherId.'|'.$key] = [$p->day, $p->period];
            }

            if ($l->needsRoom()) {
                $this->assertNotNull($p->roomId, "lesson {$l->id} got no room");
                if ($l->roomId !== null) {
                    $this->assertSame($l->roomId, $p->roomId);
                } else {
                    $this->assertSame($l->roomType, $roomTypes[(string) $p->roomId]);
                }
                $this->assertArrayNotHasKey($p->roomId.'|'.$key, $room, "room {$p->roomId} double-booked at {$key}");
                $this->assertArrayNotHasKey($p->roomId.'|'.$key, $roomUnavailable, "room {$p->roomId} used while unavailable");
                $room[$p->roomId.'|'.$key] = true;
            } else {
                $this->assertNull($p->roomId);
            }

            $sd = $l->classId.'|'.$l->subjectId.'|'.$p->day;
            $subjectDay[$sd] = ($subjectDay[$sd] ?? 0) + 1;
        }

        if ($problem->maxConsecutive !== null) {
            foreach ($teacher as $k => [$day, $period]) {
                $t = explode('|', $k)[0];
                $run = 1;
                for ($x = $period + 1; isset($teacher["{$t}|{$day}|{$x}"]); $x++) {
                    $run++;
                }
                $this->assertLessThanOrEqual($problem->maxConsecutive, $run, "teacher {$t} teaches {$run} in a row on day {$day}");
            }
        }

        if ($options?->maxSameSubjectPerDay !== null) {
            foreach ($subjectDay as $sd => $n) {
                $this->assertLessThanOrEqual($options->maxSameSubjectPerDay, $n, "subject cap broken at {$sd}");
            }
        }
    }

    /** Solved, valid, and every lesson got exactly its weekly periods. */
    protected function assertComplete(Problem $problem, Result $result, ?Options $options = null): void
    {
        $this->assertSame(Result::SOLVED, $result->status, $result->report());
        $this->assertValid($problem, $result, $options);

        $count = [];
        foreach ($result->placements as $p) {
            $count[$p->lesson->id] = ($count[$p->lesson->id] ?? 0) + 1;
        }
        foreach ($problem->lessons as $l) {
            $this->assertSame($l->periodsPerWeek, $count[$l->id] ?? 0, "lesson {$l->id} periods");
        }
        $this->assertSame([], $result->unplaced);
    }

    /**
     * A realistic 10-class school (grades 6–10, sections A/B), 6 days × 8
     * periods, 39 periods a week per class (45 when $full), shared subject teachers, a science
     * lab and a computer lab, part-time teachers, teachers capped at 4 in a row.
     */
    protected function realisticSchool(bool $full = false): Problem
    {
        $classes = [];
        foreach ([6, 7, 8, 9, 10] as $grade) {
            foreach (['A', 'B'] as $section) {
                $classes[] = "{$grade}{$section}";
            }
        }

        $plan = $full
            ? ['ENG' => 8, 'HIN' => 6, 'MAT' => 8, 'SCI' => 4, 'LAB' => 2, 'SST' => 6, 'CS' => 2, 'PE' => 2, 'ART' => 2, 'LIB' => 2, 'SKT' => 3]
            : ['ENG' => 6, 'HIN' => 5, 'MAT' => 7, 'SCI' => 4, 'LAB' => 2, 'SST' => 5, 'CS' => 2, 'PE' => 2, 'ART' => 2, 'LIB' => 1, 'SKT' => 3];

        $lessons = [];
        foreach ($classes as $i => $class) {
            $team = $i < 5 ? 1 : 2; // juniors / seniors share a subject teacher
            foreach ($plan as $subject => $n) {
                $teacher = match ($subject) {
                    'CS', 'PE', 'ART', 'LIB', 'SKT' => "T-{$subject}",
                    'LAB' => "T-SCI{$team}",
                    default => "T-{$subject}{$team}",
                };
                $lessons[] = new Lesson($class, $subject, $teacher, $n,
                    roomType: match ($subject) {
                        'LAB' => 'lab',
                        'CS' => 'computer',
                        default => null,
                    });
            }
        }

        return new Problem(
            days: [1, 2, 3, 4, 5, 6],
            periodsPerDay: 8,
            classes: $classes,
            lessons: $lessons,
            teacherUnavailable: [
                'T-ART' => [[6, 5], [6, 6], [6, 7], [6, 8]],      // part-time: Saturday afternoon off
                'T-SKT' => [[1, 1], [1, 2], [3, 1], [3, 2]],      // arrives late Mon/Wed
                'T-LIB' => [[2, 8], [4, 8]],
            ],
            rooms: [new Room('LAB-1', 'lab'), new Room('COMP-1', 'computer')],
            maxConsecutive: 4,
        );
    }
}

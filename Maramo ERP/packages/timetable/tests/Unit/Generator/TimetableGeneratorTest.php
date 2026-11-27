<?php

namespace Classivo\Timetable\Tests\Unit\Generator;

use Classivo\Timetable\Generator\InvalidProblem;
use Classivo\Timetable\Generator\Lesson;
use Classivo\Timetable\Generator\Options;
use Classivo\Timetable\Generator\Problem;
use Classivo\Timetable\Generator\Result;
use Classivo\Timetable\Generator\Room;
use Classivo\Timetable\Generator\TimetableGenerator;

class TimetableGeneratorTest extends GeneratorTestCase
{
    public function test_solves_a_small_school_with_every_period_placed(): void
    {
        $problem = new Problem([1, 2, 3, 4, 5], 6, ['A', 'B'], [
            new Lesson('A', 'MAT', 'T1', 6),
            new Lesson('A', 'ENG', 'T2', 6),
            new Lesson('A', 'SCI', 'T3', 5),
            new Lesson('B', 'MAT', 'T1', 6),
            new Lesson('B', 'ENG', 'T2', 6),
            new Lesson('B', 'SCI', 'T3', 5),
        ]);

        $result = $this->solve($problem);

        $this->assertComplete($problem, $result);
        $this->assertCount(34, $result->placements);
        $this->assertCount(17, $result->forClass('A'));
        $this->assertCount(12, $result->forTeacher('T1'));
    }

    public function test_a_teacher_shared_by_every_class_is_never_double_booked(): void
    {
        // One teacher, five classes, 5 periods each = 25 of the 30 slots: the
        // teacher constraint is the binding one.
        $classes = ['A', 'B', 'C', 'D', 'E'];
        $lessons = array_map(fn ($c) => new Lesson($c, 'PE', 'COACH', 5), $classes);
        $problem = new Problem([1, 2, 3, 4, 5], 6, $classes, $lessons);

        $result = $this->solve($problem);

        $this->assertComplete($problem, $result);
        $this->assertCount(25, $result->forTeacher('COACH'));
    }

    public function test_teacher_unavailability_is_respected(): void
    {
        $off = [];
        foreach ([1, 2, 3] as $day) {
            foreach ([1, 2, 3, 4] as $period) {
                $off[] = [$day, $period];
            }
        }
        $problem = new Problem([1, 2, 3], 6, ['A'], [new Lesson('A', 'MUS', 'T1', 6)], ['T1' => $off]);

        $result = $this->solve($problem);

        $this->assertComplete($problem, $result);
        foreach ($result->placements as $p) {
            $this->assertGreaterThanOrEqual(5, $p->period);
        }
    }

    public function test_blocked_slots_stay_empty(): void
    {
        $blocked = [[1, 1], [2, 1], [3, 1], [1, 4], [2, 4], [3, 4]];
        $problem = new Problem([1, 2, 3], 4, ['A'], [new Lesson('A', 'MAT', 'T1', 6)], blockedSlots: $blocked);

        $result = $this->solve($problem);

        $this->assertComplete($problem, $result);
        foreach ($result->placements as $p) {
            $this->assertContains($p->period, [2, 3]);
        }
    }

    public function test_max_consecutive_periods_for_a_teacher(): void
    {
        // 1 day, 7 periods, teacher teaches 6 of them with at most 3 in a row:
        // the only shape is X X X _ X X X.
        $problem = new Problem([1], 7, ['A', 'B'], [
            new Lesson('A', 'MAT', 'T1', 3),
            new Lesson('B', 'MAT', 'T1', 3),
        ], maxConsecutive: 3);

        $result = $this->solve($problem);

        $this->assertComplete($problem, $result);
        $periods = array_map(fn ($p) => $p->period, $result->forTeacher('T1'));
        sort($periods);
        $this->assertSame([1, 2, 3, 5, 6, 7], $periods);
    }

    public function test_typed_rooms_are_never_double_booked(): void
    {
        // Three classes need the single lab for 4 periods each (12 of 12 slots).
        $problem = new Problem([1, 2], 6, ['A', 'B', 'C'], [
            new Lesson('A', 'LAB', 'T1', 4, roomType: 'lab'),
            new Lesson('B', 'LAB', 'T2', 4, roomType: 'lab'),
            new Lesson('C', 'LAB', 'T3', 4, roomType: 'lab'),
            new Lesson('A', 'ENG', 'T4', 4),
            new Lesson('B', 'ENG', 'T4', 4),
        ], rooms: [new Room('LAB-1', 'lab'), new Room('R-1', 'classroom')]);

        $result = $this->solve($problem);

        $this->assertComplete($problem, $result);
        foreach ($result->placements as $p) {
            $this->assertSame($p->lesson->subjectId === 'LAB' ? 'LAB-1' : null, $p->roomId);
        }
    }

    public function test_fixed_room_and_room_unavailability(): void
    {
        $problem = new Problem([1], 4, ['A', 'B'], [
            new Lesson('A', 'MUS', 'T1', 2, roomId: 'HALL'),
            new Lesson('B', 'DRA', 'T2', 2, roomType: 'stage'),
        ], rooms: [
            new Room('HALL', 'stage', unavailable: [[1, 1]]),
            new Room('STAGE-2', 'stage'),
        ]);

        $result = $this->solve($problem);

        $this->assertComplete($problem, $result);
        foreach ($result->forClass('A') as $p) {
            $this->assertSame('HALL', $p->roomId);
            $this->assertNotSame(1, $p->period);
        }
    }

    public function test_pinned_room_is_left_free_for_its_fixed_lesson(): void
    {
        // HALL is the only room for A's 3 fixed periods; B's typed lesson must
        // use STAGE-2 whenever both are needed, never steal HALL.
        $problem = new Problem([1], 3, ['A', 'B'], [
            new Lesson('A', 'MUS', 'T1', 3, roomId: 'HALL'),
            new Lesson('B', 'DRA', 'T2', 3, roomType: 'stage'),
        ], rooms: [new Room('HALL', 'stage'), new Room('STAGE-2', 'stage')]);

        $result = $this->solve($problem);

        $this->assertComplete($problem, $result);
        foreach ($result->forClass('B') as $p) {
            $this->assertSame('STAGE-2', $p->roomId);
        }
    }

    public function test_subject_is_spread_across_days_when_possible(): void
    {
        $problem = new Problem([1, 2, 3, 4, 5], 6, ['A'], [
            new Lesson('A', 'MAT', 'T1', 5),
            new Lesson('A', 'ENG', 'T2', 5),
            new Lesson('A', 'SCI', 'T3', 4),
            new Lesson('A', 'ART', 'T4', 2),
        ]);

        $result = $this->solve($problem);

        $this->assertComplete($problem, $result);
        $this->assertSame(0, $result->stats['same_subject_repeats']);
        $days = array_map(fn ($p) => $p->day, array_filter($result->placements, fn ($p) => $p->lesson->subjectId === 'MAT'));
        sort($days);
        $this->assertSame([1, 2, 3, 4, 5], $days);
        // ART twice a week is not put on neighbouring days.
        $art = array_values(array_map(fn ($p) => $p->day, array_filter($result->placements, fn ($p) => $p->lesson->subjectId === 'ART')));
        $this->assertGreaterThan(1, abs($art[0] - $art[1]));
    }

    public function test_same_subject_twice_a_day_only_when_unavoidable(): void
    {
        // 7 maths periods over 5 days: exactly two days must have a double.
        $problem = new Problem([1, 2, 3, 4, 5], 6, ['A'], [new Lesson('A', 'MAT', 'T1', 7)]);

        $result = $this->solve($problem);

        $this->assertComplete($problem, $result);
        $this->assertSame(2, $result->stats['same_subject_repeats']);
    }

    public function test_hard_cap_on_same_subject_per_day(): void
    {
        $options = new Options(maxSameSubjectPerDay: 2);
        $problem = new Problem([1, 2, 3], 6, ['A'], [new Lesson('A', 'MAT', 'T1', 6), new Lesson('A', 'ENG', 'T2', 6)]);

        $result = $this->solve($problem, $options);

        $this->assertComplete($problem, $result, $options);
    }

    public function test_days_can_have_different_lengths(): void
    {
        $problem = new Problem([1, 2, 6], [1 => 6, 2 => 6, 6 => 3], ['A'], [new Lesson('A', 'MAT', 'T1', 15)]);

        $result = $this->solve($problem);

        $this->assertComplete($problem, $result);
        foreach ($result->placements as $p) {
            if ($p->day === 6) {
                $this->assertLessThanOrEqual(3, $p->period);
            }
        }
    }

    public function test_lessons_without_teacher_and_with_zero_periods(): void
    {
        $problem = new Problem([1, 2], 3, ['A'], [
            new Lesson('A', 'STUDY', null, 3),
            new Lesson('A', 'MAT', 'T1', 3),
            new Lesson('A', 'OPT', 'T2', 0),
        ]);

        $result = $this->solve($problem);

        $this->assertSame(Result::SOLVED, $result->status);
        $this->assertCount(6, $result->placements);
        $this->assertSame([], $result->forTeacher('T2'));
    }

    public function test_same_seed_gives_the_same_timetable_and_seeds_vary_it(): void
    {
        $problem = $this->realisticSchool();
        $run = fn (int $seed) => array_map(fn ($p) => $p->toArray(), $this->solve($problem, new Options(seed: $seed))->placements);

        $this->assertSame($run(7), $run(7));
        $this->assertNotSame($run(7), $run(8));
    }

    // ------------------------------------------------------------------
    // Unsatisfiable
    // ------------------------------------------------------------------

    public function test_unsatisfiable_when_a_class_needs_more_periods_than_slots(): void
    {
        $result = $this->solve(new Problem([1, 2], 4, ['A'], [
            new Lesson('A', 'MAT', 'T1', 5),
            new Lesson('A', 'ENG', 'T2', 4),
        ]));

        $this->assertSame(Result::UNSATISFIABLE, $result->status);
        $this->assertSame([], $result->placements);
        $this->assertStringContainsString('Class A needs 9 periods a week but only 8 slots exist', $result->report());
    }

    public function test_unsatisfiable_when_a_teacher_is_overloaded(): void
    {
        $result = $this->solve(new Problem([1], 4, ['A', 'B'], [
            new Lesson('A', 'MAT', 'T1', 3),
            new Lesson('B', 'MAT', 'T1', 2),
        ]));

        $this->assertSame(Result::UNSATISFIABLE, $result->status);
        $this->assertStringContainsString('Teacher T1 must teach 5 periods a week but can teach at most 4', $result->reasons[0]);
    }

    public function test_unsatisfiable_from_consecutive_cap_and_unavailability(): void
    {
        // 7 periods, max 2 in a row → at most 5; off in period 1 too → periods
        // 2..7 (a run of 6) allow only 4. The teacher needs 5.
        $result = $this->solve(new Problem([1], 7, ['A', 'B'], [
            new Lesson('A', 'MAT', 'T1', 3),
            new Lesson('B', 'MAT', 'T1', 2),
        ], ['T1' => [[1, 1]]], maxConsecutive: 2));

        $this->assertSame(Result::UNSATISFIABLE, $result->status);
        $this->assertStringContainsString('must teach 5 periods a week but can teach at most 4 (available slots, max 2 in a row)', $result->reasons[0]);
    }

    public function test_unsatisfiable_room_reasons(): void
    {
        $result = $this->solve(new Problem([1], 4, ['A', 'B', 'C'], [
            new Lesson('A', 'LAB', 'T1', 3, roomType: 'lab'),
            new Lesson('B', 'LAB', 'T2', 2, roomType: 'lab'),
            new Lesson('C', 'POOL', 'T3', 1, roomType: 'pool'),
        ], rooms: [new Room('LAB-1', 'lab')]));

        $this->assertSame(Result::UNSATISFIABLE, $result->status);
        $report = $result->report();
        $this->assertStringContainsString('needs a "pool" room but there is none', $report);
        $this->assertStringContainsString('"lab" rooms are needed for 5 periods a week but offer only 4', $report);
    }

    public function test_unsatisfiable_subject_cap(): void
    {
        $result = $this->solve(
            new Problem([1, 2], 6, ['A'], [new Lesson('A', 'MAT', 'T1', 5)]),
            new Options(maxSameSubjectPerDay: 2),
        );

        $this->assertSame(Result::UNSATISFIABLE, $result->status);
        $this->assertStringContainsString('more than 2 per day allows', $result->reasons[0]);
    }

    public function test_unsatisfiable_proven_by_exhaustive_search(): void
    {
        // Hall's condition fails: class A has three single-period lessons whose
        // three teachers are all available only in periods 1–2 of a 4-period
        // day. Every per-class, per-teacher and per-lesson capacity check
        // passes, so only the search can prove it. Teachers also cover other
        // classes so the search has real work to do.
        $off = [[1, 3], [1, 4]];
        $lessons = [
            new Lesson('A', 'MAT', 'T1', 1), new Lesson('A', 'ENG', 'T2', 1), new Lesson('A', 'SCI', 'T3', 1),
            new Lesson('A', 'ART', 'T4', 1),
        ];
        $classes = ['A'];
        foreach (range(1, 6) as $i) {
            $classes[] = "P{$i}";
            $lessons[] = new Lesson("P{$i}", 'PE', "PT{$i}", 4);
        }

        $result = $this->solve(new Problem([1], 4, $classes, $lessons, ['T1' => $off, 'T2' => $off, 'T3' => $off]));

        $this->assertSame(Result::UNSATISFIABLE, $result->status);
        $this->assertStringContainsString('Every arrangement was searched', $result->reasons[0]);
        $this->assertStringContainsString('Hardest to place', $result->report());
        $this->assertLessThan(2000, $result->stats['elapsed_ms']);
    }

    public function test_timeout_returns_a_valid_partial_timetable(): void
    {
        $problem = $this->realisticSchool();

        $result = $this->solve($problem, new Options(timeLimitSeconds: 0.000001));

        $this->assertSame(Result::TIMEOUT, $result->status);
        $this->assertValid($problem, $result);
        $this->assertNotSame([], $result->unplaced);
        $this->assertStringContainsString('Time limit', $result->reasons[0]);
    }

    // ------------------------------------------------------------------
    // Realistic
    // ------------------------------------------------------------------

    public function test_realistic_ten_class_school_solves_in_a_few_seconds(): void
    {
        $problem = $this->realisticSchool();
        $started = microtime(true);

        $result = $this->solve($problem, new Options(seed: 2026, timeLimitSeconds: 20));

        $elapsed = microtime(true) - $started;
        $this->assertComplete($problem, $result);
        $this->assertCount(390, $result->placements);
        $this->assertLessThan(3.0, $elapsed, "took {$elapsed}s");
        // MAT 7/week over 6 days forces one double per class (10); allow a little slack.
        $this->assertLessThanOrEqual(15, $result->stats['same_subject_repeats']);
    }

    public function test_tightly_packed_ten_class_school_solves(): void
    {
        // 45 of 48 slots per class; the two maths teachers carry 40 periods
        // with at most 4 in a row (capacity 42) — a hard instance.
        $problem = $this->realisticSchool(full: true);
        $started = microtime(true);

        $result = $this->solve($problem, new Options(seed: 1, timeLimitSeconds: 30));

        $this->assertComplete($problem, $result);
        $this->assertCount(450, $result->placements);
        $this->assertLessThan(10.0, microtime(true) - $started);
    }

    // ------------------------------------------------------------------
    // Input validation
    // ------------------------------------------------------------------

    public function test_rejects_malformed_input(): void
    {
        $cases = [
            fn () => new Problem([], 5, ['A'], []),
            fn () => new Problem([1, 1], 5, ['A'], []),
            fn () => new Problem([1], 5, ['A'], [new Lesson('Z', 'MAT', 'T1', 1)]),
            fn () => new Problem([1], 5, ['A'], [new Lesson('A', 'MAT', 'T1', 1), new Lesson('A', 'MAT', 'T1', 2)]),
            fn () => new Problem([1], 5, ['A'], [], ['T1' => [[2, 1]]]),
            fn () => new Problem([1], 5, ['A'], [], ['T1' => [[1, 6]]]),
            fn () => new Problem([1], 5, ['A'], [], maxConsecutive: 0),
            fn () => new Lesson('A', 'MAT', 'T1', -1),
            fn () => new Lesson('A', 'MAT', 'T1', 1, roomType: 'lab', roomId: 'R1'),
            fn () => new Options(timeLimitSeconds: 0),
            fn () => (new TimetableGenerator)->solve(
                new Problem([1], 5, ['A'], [new Lesson('A', 'MAT', 'T1', 1, roomId: 'NOPE')]),
            ),
        ];

        foreach ($cases as $i => $case) {
            try {
                $case();
                $this->fail("case {$i} should have been rejected");
            } catch (InvalidProblem) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_generator_namespace_has_no_framework_dependencies(): void
    {
        foreach (glob(dirname(__DIR__, 3).'/src/Generator/*.php') as $file) {
            $code = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/\b(Illuminate|Laravel|Filament|Classivo\\\\Core)\b/', $code, basename($file));
            $this->assertDoesNotMatchRegularExpression('/\b(config|app|now|collect)\(/', $code, basename($file));
        }
    }
}

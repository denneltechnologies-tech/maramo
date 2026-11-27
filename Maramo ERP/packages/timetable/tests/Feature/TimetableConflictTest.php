<?php

namespace Classivo\Timetable\Tests\Feature;

use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\Subject;
use Classivo\Timetable\Exceptions\TimetableConflictException;
use Classivo\Timetable\Models\Timetable;
use Classivo\Timetable\Models\TimetablePeriod;
use Classivo\Timetable\Tests\Support\User;
use Classivo\Timetable\Tests\TestCase;

/**
 * Model-layer conflict detection (ported from the source app's academics tests)
 * and tenant/branch/session auto-stamping.
 */
class TimetableConflictTest extends TestCase
{
    /** @return array{cs: ClassSection, cs2: ClassSection, subject: Subject, teacher: User} */
    private function fixtures(): array
    {
        [$cs, $cs2] = $this->classWithSections('Grade 1', 1, ['A', 'B']);

        return [
            'cs' => $cs,
            'cs2' => $cs2,
            'subject' => $this->subject('Mathematics'),
            'teacher' => $this->teacher('Teacher One'),
        ];
    }

    public function test_timetable_periods_auto_stamp_tenant_branch_session(): void
    {
        $this->foundation();
        $fx = $this->fixtures();

        $timetable = Timetable::create(['class_section_id' => $fx['cs']->id]);
        $period = TimetablePeriod::create([
            'timetable_id' => $timetable->id,
            'day_of_week' => 1,
            'period_no' => 1,
            'start_time' => '09:00',
            'end_time' => '09:45',
            'subject_id' => $fx['subject']->id,
            'teacher_id' => $fx['teacher']->id,
        ]);

        $this->assertSame($this->school->id, $timetable->school_id);
        $this->assertSame($this->branch->id, $timetable->branch_id);
        $this->assertSame($this->session->id, $timetable->session_id);
        $this->assertSame($this->school->id, $period->school_id);
        $this->assertSame($this->branch->id, $period->branch_id);
        $this->assertSame($this->session->id, $period->session_id);
        $this->assertSame(1, $timetable->periods()->count());
        $this->assertSame('Monday', $period->dayLabel());
    }

    public function test_teacher_double_booking_across_class_sections_is_rejected(): void
    {
        $this->foundation();
        $fx = $this->fixtures();
        $ttA = Timetable::create(['class_section_id' => $fx['cs']->id]);
        $ttB = Timetable::create(['class_section_id' => $fx['cs2']->id]);

        TimetablePeriod::create([
            'timetable_id' => $ttA->id,
            'day_of_week' => 1,
            'period_no' => 1,
            'start_time' => '09:00',
            'end_time' => '09:45',
            'subject_id' => $fx['subject']->id,
            'teacher_id' => $fx['teacher']->id,
        ]);

        try {
            TimetablePeriod::create([
                'timetable_id' => $ttB->id,
                'day_of_week' => 1,
                'period_no' => 1,
                'start_time' => '09:30',
                'end_time' => '10:15',
                'teacher_id' => $fx['teacher']->id,
            ]);
            $this->fail('Expected a TimetableConflictException for the double-booked teacher.');
        } catch (TimetableConflictException $e) {
            $this->assertStringContainsString('Teacher One is already booked 09:00–09:45 on Monday (Grade 1 - A)', $e->messages()[0]);
        }

        $this->assertSame(1, TimetablePeriod::count());

        // Back-to-back (touching, not overlapping) is fine.
        TimetablePeriod::create([
            'timetable_id' => $ttB->id,
            'day_of_week' => 1,
            'period_no' => 2,
            'start_time' => '09:45',
            'end_time' => '10:30',
            'teacher_id' => $fx['teacher']->id,
        ]);

        $this->assertSame(2, TimetablePeriod::count());
    }

    public function test_same_class_section_overlap_is_rejected_and_end_after_start_enforced(): void
    {
        $this->foundation();
        $fx = $this->fixtures();
        $tt = Timetable::create(['class_section_id' => $fx['cs']->id]);

        TimetablePeriod::create([
            'timetable_id' => $tt->id,
            'day_of_week' => 2,
            'period_no' => 1,
            'start_time' => '09:00',
            'end_time' => '09:45',
            'subject_id' => $fx['subject']->id,
        ]);

        $this->assertTrue(TimetablePeriod::hasConflict(new TimetablePeriod([
            'timetable_id' => $tt->id,
            'branch_id' => $this->branch->id,
            'session_id' => $this->session->id,
            'day_of_week' => 2,
            'start_time' => '09:30',
            'end_time' => '10:00',
        ])));

        $this->expectException(TimetableConflictException::class);
        TimetablePeriod::create([
            'timetable_id' => $tt->id,
            'day_of_week' => 3,
            'period_no' => 1,
            'start_time' => '10:00',
            'end_time' => '09:30',
        ]);
    }

    public function test_conflict_messages_are_translated(): void
    {
        $this->foundation();
        app()->setLocale('hi');

        try {
            TimetablePeriod::create([
                'timetable_id' => Timetable::create(['class_section_id' => $this->fixtures()['cs']->id])->id,
                'day_of_week' => 1,
                'start_time' => '10:00',
                'end_time' => '09:00',
            ]);
            $this->fail('expected conflict');
        } catch (TimetableConflictException $e) {
            $hindi = require __DIR__.'/../../lang/hi/timetable.php';
            $this->assertSame($hindi['conflicts']['end_before_start'], $e->getMessage());
            $this->assertNotSame("A period's end time must be after its start time.", $e->getMessage());
        }
    }
}

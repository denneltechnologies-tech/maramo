<?php

namespace Classivo\Students\Tests\Feature;

use Classivo\Core\Models\Subject;
use Classivo\Students\Events\StudentsMarkedAbsent;
use Classivo\Students\Models\Attendance;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\SubjectAttendance;
use Classivo\Students\Services\AttendanceService;
use Classivo\Students\Services\StudentAdmissionService;
use Classivo\Students\Tests\TestCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/**
 * Attendance via the shared AttendanceService (ported from AttendanceTest;
 * leave requests and the comms trigger are out of scope — absentees now fire
 * an event instead).
 */
class AttendanceTest extends TestCase
{
    private function roster(int $n = 3): Collection
    {
        foreach (range(1, $n) as $i) {
            $this->admit(['first_name' => 'Kid'.$i]);
        }

        return app(AttendanceService::class)->roster($this->classSection);
    }

    public function test_roster_follows_session_records_and_roll_order(): void
    {
        $this->bootFoundation();
        $this->admit(['first_name' => 'Ten', 'roll_no' => '10']);
        $this->admit(['first_name' => 'Two', 'roll_no' => '2']);
        $inactive = $this->admit(['first_name' => 'Gone', 'roll_no' => '3']);
        $inactive->disableStudent();

        $roster = app(AttendanceService::class)->roster($this->classSection);

        $this->assertSame(['Two', 'Ten'], $roster->pluck('first_name')->all(), 'Numeric roll order, active only.');
    }

    public function test_take_daily_attendance_creates_one_row_per_student_and_upserts_on_resave(): void
    {
        $this->bootFoundation();
        $service = app(AttendanceService::class);
        $roster = $this->roster();
        $date = '2030-03-04';

        $rows = $roster->map(fn (Student $s): array => ['student_id' => $s->id, 'status' => 'present'])->all();
        $service->saveDaily($this->classSection, $date, $rows);

        $this->assertSame($roster->count(), Attendance::whereDate('date', $date)->where('status', 'present')->count());

        $rows[0]['status'] = 'absent';
        $service->saveDaily($this->classSection, $date, $rows);

        $this->assertSame($roster->count(), Attendance::whereDate('date', $date)->count(), 'Re-save updates in place.');
        $this->assertSame('absent', Attendance::where('student_id', $roster->first()->id)->whereDate('date', $date)->value('status'));
        $this->assertCount($roster->count(), $service->existingDaily($this->classSection->id, $date));
    }

    public function test_rows_for_students_outside_the_class_section_are_ignored(): void
    {
        $this->bootFoundation();
        $mine = $this->admit(['first_name' => 'Mine']);
        $otherSection = $this->classSectionFor($this->session->id, 'Grade 2', 2, 'B');
        $other = app(StudentAdmissionService::class)->admit([
            'class_id' => $otherSection->class_id, 'section_id' => $otherSection->section_id,
            'first_name' => 'Other', 'last_name' => 'Kid', 'gender' => 'female', 'date_of_birth' => '2018-02-02',
        ]);
        $service = app(AttendanceService::class);

        $saved = $service->saveDaily($this->classSection, '2030-03-06', [
            ['student_id' => $mine->id, 'status' => 'present'],
            ['student_id' => $other->id, 'status' => 'absent'],
            ['student_id' => 999999, 'status' => 'absent'],
        ]);

        $this->assertSame(1, $saved);
        $this->assertFalse(Attendance::withoutGlobalScopes()->where('student_id', $other->id)->exists());
        $this->assertSame(0, $service->saveSubject($this->classSection, 1, '2030-03-06', null, [['student_id' => $other->id, 'status' => 'absent']]));
    }

    public function test_absentees_fire_an_event(): void
    {
        $this->bootFoundation();
        Event::fake([StudentsMarkedAbsent::class]);
        $roster = $this->roster(2);

        app(AttendanceService::class)->saveDaily($this->classSection, '2030-03-05', [
            ['student_id' => $roster[0]->id, 'status' => 'absent'],
            ['student_id' => $roster[1]->id, 'status' => 'present'],
        ]);

        Event::assertDispatched(StudentsMarkedAbsent::class, fn (StudentsMarkedAbsent $e): bool => $e->studentIds === [$roster[0]->id]);
    }

    public function test_percentage_uses_the_documented_weighted_model(): void
    {
        $this->bootFoundation();
        $service = app(AttendanceService::class);
        [$student, $other] = $this->roster(2);

        foreach (range(1, 8) as $d) {
            Attendance::create(['student_id' => $student->id, 'date' => sprintf('2030-01-%02d', $d), 'status' => 'present']);
        }
        foreach ([9, 10] as $d) {
            Attendance::create(['student_id' => $student->id, 'date' => sprintf('2030-01-%02d', $d), 'status' => 'absent']);
        }

        $this->assertSame(80.0, $service->percentage($student->id, '2030-01-01', '2030-01-31'));

        foreach (['present', 'late', 'half_day', 'absent', 'holiday', 'leave'] as $i => $status) {
            Attendance::create(['student_id' => $other->id, 'date' => sprintf('2030-02-%02d', $i + 1), 'status' => $status]);
        }

        $this->assertSame(62.5, $service->percentage($other->id, '2030-02-01', '2030-02-28'));
        $this->assertSame(0.0, $service->percentage($other->id, '2031-01-01', '2031-01-31'));
    }

    public function test_subject_attendance_persists_per_subject_and_date(): void
    {
        $this->bootFoundation();
        $service = app(AttendanceService::class);
        $roster = $this->roster();
        $subject = Subject::create(['name' => 'Mathematics', 'type' => 'theory']);
        $date = '2030-06-10';

        $rows = $roster->map(fn (Student $s): array => ['student_id' => $s->id, 'status' => 'present'])->all();
        $service->saveSubject($this->classSection, $subject->id, $date, null, $rows);
        $this->assertSame($roster->count(), SubjectAttendance::where('subject_id', $subject->id)->count());

        $rows[0]['status'] = 'absent';
        $service->saveSubject($this->classSection, $subject->id, $date, null, $rows);
        $this->assertSame($roster->count(), SubjectAttendance::where('subject_id', $subject->id)->count());
        $this->assertSame('absent', SubjectAttendance::where('student_id', $roster->first()->id)->value('status'));

        // A different period is a separate mark.
        $service->saveSubject($this->classSection, $subject->id, $date, 2, $rows);
        $this->assertSame($roster->count() * 2, SubjectAttendance::where('subject_id', $subject->id)->count());
    }

    public function test_attendance_is_tenant_branch_and_session_auto_stamped(): void
    {
        $this->bootFoundation();
        $student = $this->admit();

        $attendance = Attendance::create(['student_id' => $student->id, 'date' => '2031-09-09', 'status' => 'present']);

        $this->assertSame(current_school_id(), $attendance->school_id);
        $this->assertSame(current_branch_id(), $attendance->branch_id);
        $this->assertSame(current_session_id(), $attendance->session_id);
    }

    public function test_weekend_detection_uses_branch_weekend_days(): void
    {
        $this->bootFoundation();
        $service = app(AttendanceService::class);

        $this->assertTrue($service->isWeekend($this->branch->id, '2030-03-03'), '3 Mar 2030 is a Sunday.');
        $this->assertFalse($service->isWeekend($this->branch->id, '2030-03-04'));
    }

    public function test_attendance_history_blocks_force_delete(): void
    {
        $this->bootFoundation();
        $student = $this->admit();
        Attendance::create(['student_id' => $student->id, 'date' => '2030-01-01', 'status' => 'present']);

        $student->forceDelete();

        $this->assertNotNull(Student::withTrashed()->find($student->id));
    }
}

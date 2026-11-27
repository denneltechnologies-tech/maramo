<?php

namespace Classivo\Exams\Tests\Feature;

use Classivo\Core\Models\Subject;
use Classivo\Exams\Events\ExamResultsGenerated;
use Classivo\Exams\Events\ExamResultsPublished;
use Classivo\Exams\Events\ExamResultsUnpublished;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamGroup;
use Classivo\Exams\Models\ExamResult;
use Classivo\Exams\Models\ExamSchedule;
use Classivo\Exams\Models\Grade;
use Classivo\Exams\Models\Mark;
use Classivo\Exams\Services\ExamMarksService;
use Classivo\Exams\Services\ExamResultService;
use Classivo\Exams\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class ExamsTest extends TestCase
{
    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchool();

        $group = ExamGroup::create(['name' => 'Term 1', 'type' => 'marks']);
        $this->exam = Exam::create(['exam_group_id' => $group->id, 'name' => 'Mid Term']);

        $rows = array_map(
            fn (string $name): array => ['subject_id' => $this->makeSubject($name)->id, 'full_marks' => 100, 'pass_marks' => 35],
            ['English', 'Science'],
        );
        ExamSchedule::buildSchedule($this->exam, $this->classSection->class_id, null, $rows);
    }

    private function subject(string $name)
    {
        return Subject::where('name', $name)->firstOrFail();
    }

    public function test_tenancy_is_auto_stamped_on_create(): void
    {
        $group = ExamGroup::create(['name' => 'Auto', 'type' => 'marks']);

        $this->assertSame(current_school_id(), $group->school_id);
        $this->assertNotNull($group->branch_id);
        $this->assertNotNull($group->session_id);
    }

    public function test_build_schedule_reconciles_rows(): void
    {
        $this->assertSame(2, ExamSchedule::where('exam_id', $this->exam->id)->count());

        ExamSchedule::buildSchedule($this->exam, $this->classSection->class_id, null, [
            ['subject_id' => $this->subject('English')->id, 'full_marks' => 80, 'pass_marks' => 30],
        ]);

        $this->assertSame(1, ExamSchedule::where('exam_id', $this->exam->id)->count());
        $this->assertEquals(80.0, (float) ExamSchedule::where('exam_id', $this->exam->id)->value('full_marks'));
    }

    public function test_marks_over_full_marks_are_rejected(): void
    {
        $student = $this->makeStudent('Aarav', 1);
        $service = app(ExamMarksService::class);
        $english = $this->subject('English');

        $service->save($this->exam, $this->classSection, $english, [
            ['student_id' => $student->id, 'marks_obtained' => 50, 'is_absent' => false],
        ]);

        $threw = false;
        try {
            $service->save($this->exam, $this->classSection, $english, [
                ['student_id' => $student->id, 'marks_obtained' => 150, 'is_absent' => false],
            ]);
        } catch (ValidationException) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertEquals(50.0, (float) Mark::where('student_id', $student->id)->value('marks_obtained'));
    }

    public function test_results_compute_grade_division_rank_and_pass_fail(): void
    {
        $top = $this->makeStudent('Aarav', 1);
        $weak = $this->makeStudent('Vivaan', 2);
        $marks = app(ExamMarksService::class);

        $marks->save($this->exam, $this->classSection, $this->subject('English'), [
            ['student_id' => $top->id, 'marks_obtained' => 90],
            ['student_id' => $weak->id, 'marks_obtained' => 60],
        ]);
        $marks->save($this->exam, $this->classSection, $this->subject('Science'), [
            ['student_id' => $top->id, 'marks_obtained' => 90],
            ['student_id' => $weak->id, 'marks_obtained' => 10],
        ]);

        Event::fake([ExamResultsGenerated::class]);
        app(ExamResultService::class)->generate($this->exam, $this->classSection);
        Event::assertDispatched(ExamResultsGenerated::class);

        $a = ExamResult::where('student_id', $top->id)->firstOrFail();
        $this->assertEqualsWithDelta(90.0, (float) $a->percentage, 0.01);
        $this->assertSame('A+', $a->grade?->name);
        $this->assertSame('Distinction', $a->division?->name);
        $this->assertSame(1, $a->rank);
        $this->assertSame('pass', $a->result);

        $b = ExamResult::where('student_id', $weak->id)->firstOrFail();
        $this->assertSame(2, $b->rank);
        $this->assertSame('fail', $b->result);
    }

    public function test_absent_student_fails_and_ties_share_rank(): void
    {
        $one = $this->makeStudent('Ira', 1);
        $two = $this->makeStudent('Zoya', 2);
        $marks = app(ExamMarksService::class);

        foreach (['English', 'Science'] as $name) {
            $marks->save($this->exam, $this->classSection, $this->subject($name), [
                ['student_id' => $one->id, 'marks_obtained' => 70],
                ['student_id' => $two->id, 'marks_obtained' => 70],
            ]);
        }
        $marks->save($this->exam, $this->classSection, $this->subject('Science'), [
            ['student_id' => $two->id, 'is_absent' => true],
        ]);

        app(ExamResultService::class)->generate($this->exam, $this->classSection);

        $this->assertSame('fail', ExamResult::where('student_id', $two->id)->value('result'));
        $this->assertSame(1, ExamResult::where('student_id', $one->id)->value('rank'));
    }

    public function test_publish_locks_marks_and_unpublish_unlocks(): void
    {
        $student = $this->makeStudent('Aarav', 1);
        $service = app(ExamResultService::class);
        $service->generate($this->exam, $this->classSection);

        Event::fake([ExamResultsPublished::class, ExamResultsUnpublished::class]);

        $service->publish($this->exam);
        Event::assertDispatched(ExamResultsPublished::class, fn ($e) => $e->exam->id === $this->exam->id);
        $this->assertTrue($this->exam->fresh()->is_published);
        $this->assertTrue((bool) ExamResult::where('student_id', $student->id)->value('published'));

        $threw = false;
        try {
            app(ExamMarksService::class)->save($this->exam->fresh(), $this->classSection, $this->subject('English'), [
                ['student_id' => $student->id, 'marks_obtained' => 10],
            ]);
        } catch (ValidationException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Published exams are locked.');

        $service->unpublish($this->exam);
        Event::assertDispatched(ExamResultsUnpublished::class);
        $this->assertFalse($this->exam->fresh()->is_published);
        $this->assertFalse((bool) ExamResult::where('student_id', $student->id)->value('published'));
    }

    public function test_grade_lookup_by_percentage(): void
    {
        $this->assertSame('A+', Grade::forPercentage(95)?->name);
        $this->assertSame('E', Grade::forPercentage(10)?->name);
        $this->assertTrue((bool) Grade::forPercentage(10)?->is_fail);
    }
}

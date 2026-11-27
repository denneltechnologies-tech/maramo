<?php

namespace Classivo\Exams\Tests\Feature;

use Classivo\Core\Models\ClassTeacher;
use Classivo\Core\Models\Subject;
use Classivo\Exams\Filament\Pages\BuildExamSchedule;
use Classivo\Exams\Filament\Pages\ExamResults;
use Classivo\Exams\Filament\Pages\MarksRegister;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamGroup;
use Classivo\Exams\Models\ExamResult;
use Classivo\Exams\Models\ExamSchedule;
use Classivo\Exams\Models\Mark;
use Classivo\Exams\Services\ExamMarksService;
use Classivo\Exams\Support\ExamsPermissions;
use Classivo\Exams\Tests\Support\User;
use Classivo\Exams\Tests\TestCase;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

class PagesTest extends TestCase
{
    private Exam $exam;

    private Subject $english;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchool();
        Filament::setCurrentPanel('admin');

        $group = ExamGroup::create(['name' => 'Term 1', 'type' => 'marks']);
        $this->exam = Exam::create(['exam_group_id' => $group->id, 'name' => 'Mid Term']);
        $this->english = $this->makeSubject('English');
        ExamSchedule::buildSchedule($this->exam, $this->classSection->class_id, null, [
            ['subject_id' => $this->english->id, 'full_marks' => 100, 'pass_marks' => 35],
        ]);
    }

    private function actingWith(string ...$permissions): User
    {
        $user = User::create(['name' => 'Staff']);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }

        $this->actingAs($user);

        return $user;
    }

    public function test_schedule_page_saves_subject_rows(): void
    {
        $this->actingWith(ExamsPermissions::SCHEDULE_BUILD);
        $science = $this->makeSubject('Science');

        Livewire::test(BuildExamSchedule::class)
            ->set('data.exam_id', $this->exam->id)
            ->set('data.class_id', $this->classSection->class_id)
            ->set('data.schedules', [
                ['subject_id' => $this->english->id, 'date' => '2026-09-10', 'full_marks' => 100, 'pass_marks' => 35],
                ['subject_id' => $science->id, 'date' => '2026-09-12', 'full_marks' => 50, 'pass_marks' => 20],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, ExamSchedule::where('exam_id', $this->exam->id)->count());
        $this->assertEquals(50.0, (float) ExamSchedule::where('subject_id', $science->id)->value('full_marks'));
    }

    public function test_schedule_page_rejects_pass_marks_above_full_marks(): void
    {
        $this->actingWith(ExamsPermissions::SCHEDULE_BUILD);

        Livewire::test(BuildExamSchedule::class)
            ->set('data.exam_id', $this->exam->id)
            ->set('data.class_id', $this->classSection->class_id)
            ->set('data.schedules', [
                ['subject_id' => $this->english->id, 'full_marks' => 40, 'pass_marks' => 90],
            ])
            ->call('save');

        $this->assertEquals(100.0, (float) ExamSchedule::where('exam_id', $this->exam->id)->value('full_marks'), 'Schedule is unchanged.');
    }

    public function test_marks_register_loads_students_and_saves_marks_when_permitted(): void
    {
        $student = $this->makeStudent('Aarav', 1);
        $teacher = $this->actingWith('exam.enter_marks');
        ClassTeacher::create(['class_section_id' => $this->classSection->id, 'teacher_id' => $teacher->id, 'is_primary' => true]);

        $page = Livewire::test(MarksRegister::class)
            ->set('data.exam_id', $this->exam->id)
            ->set('data.class_section_id', $this->classSection->id)
            ->set('data.subject_id', $this->english->id)
            ->call('load');

        $rows = $page->get('data.students');
        $this->assertCount(1, $rows);
        $this->assertSame($student->id, array_values($rows)[0]['student_id']);

        $key = array_key_first($rows);
        $page->set("data.students.{$key}.marks_obtained", 77)->call('save');

        $this->assertEquals(77.0, (float) Mark::where('student_id', $student->id)->value('marks_obtained'));
    }

    public function test_marks_register_save_is_denied_without_permission(): void
    {
        $student = $this->makeStudent('Aarav', 1);

        // No permission at all: the page itself is refused.
        $this->actingWith();
        Livewire::test(MarksRegister::class)->assertForbidden();

        // Read-only viewer: may load the register but saving writes nothing.
        $this->actingWith(ExamsPermissions::MARKS_VIEW);
        $page = Livewire::test(MarksRegister::class)
            ->set('data.exam_id', $this->exam->id)
            ->set('data.class_section_id', $this->classSection->id)
            ->set('data.subject_id', $this->english->id)
            ->call('load');

        $key = array_key_first($page->get('data.students'));
        $page->set("data.students.{$key}.marks_obtained", 77)->call('save');

        $this->assertSame(0, Mark::where('student_id', $student->id)->count());
    }

    public function test_marks_register_rejects_marks_over_full_marks(): void
    {
        $student = $this->makeStudent('Aarav', 1);
        $teacher = $this->actingWith('exam.enter_marks');
        ClassTeacher::create(['class_section_id' => $this->classSection->id, 'teacher_id' => $teacher->id, 'is_primary' => true]);

        $page = Livewire::test(MarksRegister::class)
            ->set('data.exam_id', $this->exam->id)
            ->set('data.class_section_id', $this->classSection->id)
            ->set('data.subject_id', $this->english->id)
            ->call('load');

        $key = array_key_first($page->get('data.students'));
        $page->set("data.students.{$key}.marks_obtained", 150)->call('save');

        $this->assertSame(0, Mark::where('student_id', $student->id)->count());
    }

    public function test_results_page_generates_and_publish_is_permission_gated(): void
    {
        $student = $this->makeStudent('Aarav', 1);
        app(ExamMarksService::class)->save(
            $this->exam, $this->classSection, $this->english, [['student_id' => $student->id, 'marks_obtained' => 88]],
        );

        // Results permissions without publish: generate works, publish is refused.
        $this->actingWith(ExamsPermissions::RESULTS_VIEW, ExamsPermissions::RESULTS_GENERATE);
        Livewire::test(ExamResults::class)
            ->set('data.exam_id', $this->exam->id)
            ->set('data.class_section_id', $this->classSection->id)
            ->call('generate')
            ->call('publish');

        $this->assertSame(1, ExamResult::where('exam_id', $this->exam->id)->count());
        $this->assertFalse($this->exam->fresh()->is_published);

        // With permission: publish flips exam and result rows.
        $this->actingWith('exam.publish_result');
        Livewire::test(ExamResults::class)
            ->set('data.exam_id', $this->exam->id)
            ->set('data.class_section_id', $this->classSection->id)
            ->call('publish');

        $this->assertTrue($this->exam->fresh()->is_published);
        $this->assertTrue((bool) ExamResult::where('student_id', $student->id)->value('published'));

        Livewire::test(ExamResults::class)
            ->set('data.exam_id', $this->exam->id)
            ->set('data.class_section_id', $this->classSection->id)
            ->call('unpublish');

        $this->assertFalse($this->exam->fresh()->is_published);
    }
}

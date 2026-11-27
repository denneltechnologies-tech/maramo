<?php

namespace Tests\Feature\Scenarios;

use App\Models\User;
use Classivo\Exams\Filament\Pages\ExamResults;
use Classivo\Exams\Filament\Pages\MarksRegister;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamGroup;
use Classivo\Exams\Models\ExamSchedule;
use Classivo\Exams\Models\Mark;
use Classivo\Exams\Services\ExamMarksService;
use Classivo\Exams\Services\ExamResultService;
use Classivo\Exams\Support\Authorize;
use Classivo\OnlineExams\OnlineExamsPlugin;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentSessionRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Role seeding as the demo ships it: Teacher holds exam.enter_marks but not
 * exam.publish_result nor marksheets.download, and may enter marks only for the
 * class-sections they teach (the demo teacher is the subject teacher of every class).
 */
class PermissionBoundariesTest extends ScenarioTestCase
{
    private function exam(): array
    {
        $cs = $this->grade1A();
        $eng = $this->subject('ENG');
        $exam = Exam::create(['exam_group_id' => ExamGroup::create(['name' => 'T', 'type' => 'marks'])->id, 'class_id' => $cs->class_id, 'name' => 'Unit']);
        ExamSchedule::buildSchedule($exam, $cs->class_id, null, [['subject_id' => $eng->id, 'full_marks' => 50, 'pass_marks' => 17]]);

        return [$exam, $cs, $eng];
    }

    public function test_teacher_can_enter_marks_but_not_publish_or_download_marksheets(): void
    {
        $this->bootDemo();
        [$exam, $cs, $eng] = $this->exam();
        $teacher = $this->login('teacher@example.com');

        $this->assertTrue(Authorize::can('enter_marks'));
        $this->assertFalse(Authorize::can('publish_result'));

        $page = Livewire::test(MarksRegister::class)
            ->fillForm(['exam_id' => $exam->id, 'class_section_id' => $cs->id, 'subject_id' => $eng->id])
            ->call('load');
        $page->set('data.students.0.marks_obtained', 40)->call('save');
        $this->assertEquals(40.0, (float) Mark::where('exam_id', $exam->id)->value('marks_obtained'));
        $this->assertSame($teacher->id, (int) Mark::where('exam_id', $exam->id)->value('entered_by'));

        app(ExamResultService::class)->generate($exam, $cs);
        Livewire::test(ExamResults::class)->assertForbidden(); // results screen is not a teacher screen
        $this->assertFalse($exam->fresh()->is_published, 'a teacher cannot publish results');

        $student = Student::firstOrFail();
        $this->get(route('report-cards.marksheet', ['exam' => $exam->id, 'student' => $student->id]))->assertForbidden();
    }

    public function test_student_and_parent_cannot_enter_marks_and_marks_over_full_are_refused(): void
    {
        $this->bootDemo();
        [$exam, $cs, $eng] = $this->exam();

        foreach (['student@example.com', 'parent@example.com'] as $email) {
            $this->login($email);
            $this->assertFalse(Authorize::can('enter_marks'), $email);
            $this->get(route('report-cards.marksheet', ['exam' => $exam->id, 'student' => 1]))->assertForbidden();
        }

        $this->login('admin@example.com');
        $this->expectException(ValidationException::class);
        app(ExamMarksService::class)->save($exam, $cs, $eng, [['student_id' => 1, 'marks_obtained' => 51]]);
    }

    public function test_teacher_who_does_not_teach_the_class_cannot_enter_its_marks(): void
    {
        $this->bootDemo();
        [$exam, $cs, $eng] = $this->exam();

        $other = User::create(['name' => 'Other Teacher', 'email' => 'other.teacher@example.com', 'password' => 'password']);
        $other->assignRole('Teacher');
        $this->login('other.teacher@example.com');
        $this->assertTrue(Authorize::can('enter_marks'));

        $student = StudentSessionRecord::where('class_section_id', $cs->id)->value('student_id');

        try {
            app(ExamMarksService::class)->save($exam, $cs, $eng, [['student_id' => $student, 'marks_obtained' => 30]], $other->id);
            $this->fail('A teacher outside the class must be refused.');
        } catch (AuthorizationException) {
        }

        Livewire::test(MarksRegister::class)
            ->set('data.exam_id', $exam->id)
            ->set('data.class_section_id', $cs->id)
            ->set('data.subject_id', $eng->id)
            ->set('data.students', [['student_id' => $student, 'label' => 'x', 'marks_obtained' => 30, 'is_absent' => false, 'remark' => null]])
            ->call('save');

        $this->assertSame(0, Mark::where('exam_id', $exam->id)->count());

        // The admin is not restricted.
        $this->login('admin@example.com');
        app(ExamMarksService::class)->save($exam, $cs, $eng, [['student_id' => $student, 'marks_obtained' => 30]]);
        $this->assertSame(1, Mark::where('exam_id', $exam->id)->count());
    }

    /**
     * BUG (app/bootstrap/app.php:13): the demo app never calls redirectGuestsTo(), so a guest
     * hitting a package route behind plain `auth` (marksheets, payslips, online-exam papers)
     * gets a 500 "Route [login] not defined" instead of a redirect to /admin/login.
     */
    public function test_guests_are_sent_to_login(): void
    {
        $this->bootDemo();
        $this->get('/admin/students')->assertRedirect('/admin/login');
        $this->get(route('report-cards.tabulation', ['exam' => 1, 'classSection' => 1]))->assertRedirect();
        if (class_exists(OnlineExamsPlugin::class)) {
            $this->get('/student/online-exams')->assertRedirect();
        }
    }
}

<?php

namespace Classivo\Exams\Tests\Feature;

use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\ClassSubject;
use Classivo\Core\Models\ClassTeacher;
use Classivo\Core\Models\Section;
use Classivo\Core\Models\Subject;
use Classivo\Exams\Filament\Pages\MarksRegister;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamGroup;
use Classivo\Exams\Models\ExamSchedule;
use Classivo\Exams\Models\Mark;
use Classivo\Exams\Services\ExamMarksService;
use Classivo\Exams\Support\MarksScope;
use Classivo\Exams\Tests\Support\User;
use Classivo\Exams\Tests\TestCase;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentSessionRecord;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Teachers may enter marks only for the class-sections (and subjects) they teach. */
class TeacherMarksScopeTest extends TestCase
{
    private Exam $exam;

    private Subject $english;

    private Subject $maths;

    private ClassSection $otherSection;

    private Student $ownStudent;

    private Student $otherStudent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchool();
        Filament::setCurrentPanel('admin');

        $group = ExamGroup::create(['name' => 'Term 1', 'type' => 'marks']);
        $this->exam = Exam::create(['exam_group_id' => $group->id, 'name' => 'Mid Term']);
        $this->english = $this->makeSubject('English');
        $this->maths = $this->makeSubject('Maths');

        ExamSchedule::buildSchedule($this->exam, $this->classSection->class_id, null, [
            ['subject_id' => $this->english->id, 'full_marks' => 100, 'pass_marks' => 35],
            ['subject_id' => $this->maths->id, 'full_marks' => 100, 'pass_marks' => 35],
        ]);

        $sectionB = Section::create(['name' => 'B']);
        $this->otherSection = ClassSection::create(['class_id' => $this->classSection->class_id, 'section_id' => $sectionB->id]);

        $this->ownStudent = $this->makeStudent('Asha', 1);
        $this->otherStudent = Student::create(['first_name' => 'Bina', 'last_name' => 'Test', 'admission_no' => 'ADM99', 'status' => 'active']);
        StudentSessionRecord::create(['student_id' => $this->otherStudent->id, 'class_section_id' => $this->otherSection->id, 'roll_no' => '1']);
    }

    private function teacher(string ...$permissions): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => 'Teacher '.$n]);
        foreach (array_merge(['exam.enter_marks'], $permissions) as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }
        $this->actingAs($user);

        return $user;
    }

    private function saveFor(ClassSection $cs, Subject $subject, Student $student, int $marks): int
    {
        return app(ExamMarksService::class)->save($this->exam, $cs, $subject, [
            ['student_id' => $student->id, 'marks_obtained' => $marks],
        ], auth()->id());
    }

    public function test_class_teacher_may_enter_any_subject_of_own_section_only(): void
    {
        $t = $this->teacher();
        ClassTeacher::create(['class_section_id' => $this->classSection->id, 'teacher_id' => $t->id, 'is_primary' => true]);

        $this->assertSame(1, $this->saveFor($this->classSection, $this->maths, $this->ownStudent, 60));
        $this->assertTrue(MarksScope::allows($t, $this->classSection, $this->english));
        $this->assertFalse(MarksScope::allows($t, $this->otherSection, $this->english));

        $this->expectException(AuthorizationException::class);
        $this->saveFor($this->otherSection, $this->english, $this->otherStudent, 50);
    }

    public function test_subject_teacher_from_class_subjects_is_limited_to_that_subject(): void
    {
        $t = $this->teacher();
        ClassSubject::create([
            'class_id' => $this->classSection->class_id, 'section_id' => $this->classSection->section_id,
            'subject_id' => $this->english->id, 'teacher_id' => $t->id,
        ]);

        $this->assertSame(1, $this->saveFor($this->classSection, $this->english, $this->ownStudent, 70));
        $this->assertFalse(MarksScope::allows($t, $this->classSection, $this->maths));
        $this->assertFalse(MarksScope::allows($t, $this->otherSection, $this->english));
        $this->assertSame([$this->classSection->id], MarksScope::classSectionIds($t));

        $this->expectException(AuthorizationException::class);
        $this->saveFor($this->classSection, $this->maths, $this->ownStudent, 70);
    }

    public function test_timetable_teacher_may_enter_the_subject_they_teach_there(): void
    {
        Schema::create('timetables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('class_section_id');
            $table->softDeletes();
        });
        Schema::create('timetable_periods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('timetable_id');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->softDeletes();
        });

        $t = $this->teacher();
        $timetableId = DB::table('timetables')->insertGetId(['school_id' => current_school_id(), 'class_section_id' => $this->classSection->id]);
        DB::table('timetable_periods')->insert(['timetable_id' => $timetableId, 'subject_id' => $this->maths->id, 'teacher_id' => $t->id]);

        $this->assertTrue(MarksScope::allows($t, $this->classSection, $this->maths));
        $this->assertFalse(MarksScope::allows($t, $this->classSection, $this->english));
        $this->assertFalse(MarksScope::allows($t, $this->otherSection, $this->maths));
        $this->assertSame(1, $this->saveFor($this->classSection, $this->maths, $this->ownStudent, 55));
    }

    public function test_teacher_cannot_smuggle_another_sections_student_into_own_register(): void
    {
        $t = $this->teacher();
        ClassTeacher::create(['class_section_id' => $this->classSection->id, 'teacher_id' => $t->id]);

        try {
            $this->saveFor($this->classSection, $this->english, $this->otherStudent, 99);
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
        }

        $this->assertSame(0, Mark::where('student_id', $this->otherStudent->id)->count());
    }

    public function test_admin_role_and_any_class_permission_bypass_the_rule(): void
    {
        Role::findOrCreate('Admin', 'web');
        $admin = $this->teacher();
        $admin->assignRole('Admin');
        $this->assertSame(1, $this->saveFor($this->otherSection, $this->english, $this->otherStudent, 40));
        $this->assertNull(MarksScope::classSectionIds($admin));

        $coordinator = $this->teacher(MarksScope::ANY_CLASS);
        $this->assertTrue(MarksScope::allows($coordinator->fresh(), $this->otherSection, $this->maths));
    }

    public function test_marks_register_page_limits_options_and_refuses_other_sections(): void
    {
        $t = $this->teacher();
        ClassTeacher::create(['class_section_id' => $this->classSection->id, 'teacher_id' => $t->id]);

        $page = Livewire::test(MarksRegister::class);
        $options = (fn () => $this->classSectionOptions())->call($page->instance());
        $this->assertSame([$this->classSection->id], array_keys($options));

        // Forged ids for a section the teacher does not teach: load returns no students, save writes nothing.
        $page->set('data.exam_id', $this->exam->id)
            ->set('data.class_section_id', $this->otherSection->id)
            ->set('data.subject_id', $this->english->id)
            ->call('load');
        $this->assertEmpty($page->get('data.students'));

        Livewire::test(MarksRegister::class)
            ->set('data.exam_id', $this->exam->id)
            ->set('data.class_section_id', $this->otherSection->id)
            ->set('data.subject_id', $this->english->id)
            ->set('data.students', [['student_id' => $this->otherStudent->id, 'label' => 'x', 'marks_obtained' => 90, 'is_absent' => false, 'remark' => null]])
            ->call('save');

        $this->assertSame(0, Mark::where('student_id', $this->otherStudent->id)->count());

        // Own section works end to end.
        $own = Livewire::test(MarksRegister::class)
            ->set('data.exam_id', $this->exam->id)
            ->set('data.class_section_id', $this->classSection->id)
            ->set('data.subject_id', $this->english->id)
            ->call('load');
        $key = array_key_first($own->get('data.students'));
        $own->set("data.students.{$key}.marks_obtained", 81)->call('save');

        $this->assertEquals(81.0, (float) Mark::where('student_id', $this->ownStudent->id)->value('marks_obtained'));
    }
}

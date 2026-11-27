<?php

namespace Classivo\Core\Tests\Feature;

use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\ClassSubject;
use Classivo\Core\Models\ClassTeacher;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Models\Subject;
use Classivo\Core\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;

class AcademicStructureTest extends TestCase
{
    private function teacher(string $name): int
    {
        return DB::table('users')->insertGetId([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('classivo.user_model', User::class);
    }

    public function test_sync_sections_creates_restores_and_soft_deletes_pivot_rows(): void
    {
        $this->foundation();
        $a = Section::create(['name' => 'A']);
        $b = Section::create(['name' => 'B']);
        $class = SchoolClass::create(['name' => 'Grade 1', 'numeric_order' => 1]);

        $class->syncSections([$a->id, $b->id]);
        $this->assertSame(2, $class->classSections()->count());

        $class->syncSections([$a->id]);
        $this->assertSame(1, $class->classSections()->count());
        $this->assertSame(1, ClassSection::onlyTrashed()->count());

        $class->syncSections([$a->id, $b->id]);
        $this->assertSame(2, $class->classSections()->count());
        $this->assertSame(0, ClassSection::onlyTrashed()->count(), 'B is restored, not duplicated.');
        $this->assertSame(['A', 'B'], $class->sections()->orderBy('name')->pluck('name')->all());
    }

    public function test_class_section_name_combines_class_and_section(): void
    {
        $this->foundation();
        $a = Section::create(['name' => 'A']);
        $class = SchoolClass::create(['name' => 'Grade 5', 'numeric_order' => 5]);
        $class->syncSections([$a->id]);

        $this->assertSame('Grade 5 - A', $class->classSections()->first()->name);
    }

    public function test_allot_subjects_upserts_and_removes(): void
    {
        $this->foundation();
        $class = SchoolClass::create(['name' => 'Grade 1', 'numeric_order' => 1]);
        $maths = Subject::create(['name' => 'Mathematics', 'type' => 'theory']);
        $english = Subject::create(['name' => 'English', 'type' => 'theory']);
        $teacher = $this->teacher('Demo Teacher');

        ClassSubject::allot($class, null, [
            ['subject_id' => $maths->id, 'teacher_id' => $teacher, 'full_marks' => 100, 'pass_marks' => 33],
            ['subject_id' => $english->id],
        ]);
        $this->assertSame(2, ClassSubject::count());
        $this->assertSame(100, ClassSubject::where('subject_id', $maths->id)->value('full_marks'));

        ClassSubject::allot($class, null, [['subject_id' => $maths->id, 'full_marks' => 80]]);
        $this->assertSame(1, ClassSubject::count());
        $this->assertSame(80, ClassSubject::where('subject_id', $maths->id)->value('full_marks'));

        ClassSubject::allot($class, null, [['subject_id' => $maths->id], ['subject_id' => $english->id]]);
        $this->assertSame(2, ClassSubject::count());
        $this->assertSame(2, ClassSubject::withTrashed()->count(), 'Re-allotting restores rather than duplicates.');
    }

    public function test_assign_class_teachers(): void
    {
        $this->foundation();
        $a = Section::create(['name' => 'A']);
        $class = SchoolClass::create(['name' => 'Grade 1', 'numeric_order' => 1]);
        $class->syncSections([$a->id]);
        $cs = $class->classSections()->first();

        $t1 = $this->teacher('First Teacher');
        $t2 = $this->teacher('Second Teacher');

        ClassTeacher::assign($cs, [['teacher_id' => $t1, 'is_primary' => true], ['teacher_id' => $t2]]);
        $this->assertSame(2, $cs->classTeachers()->count());
        $this->assertTrue((bool) ClassTeacher::where('teacher_id', $t1)->value('is_primary'));
        $this->assertSame(2, $class->classTeachers()->count());

        ClassTeacher::assign($cs, [['teacher_id' => $t2, 'is_primary' => true]]);
        $this->assertSame(1, $cs->classTeachers()->count());
        $this->assertSame('Second Teacher', $cs->classTeachers()->first()->teacher->name);
    }

    public function test_subject_type_labels_are_translated(): void
    {
        $this->foundation();
        app()->setLocale('hi');

        $this->assertSame('सैद्धांतिक', Subject::typeOptions()['theory']);
    }
}

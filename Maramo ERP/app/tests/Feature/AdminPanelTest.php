<?php

namespace Tests\Feature;

use App\Models\User;
use Classivo\Core\Filament\Resources\Pages\ManageAcademicSessions;
use Classivo\Core\Filament\Resources\Pages\ManageBranches;
use Classivo\Core\Filament\Resources\Pages\ManageSchoolClasses;
use Classivo\Core\Filament\Resources\Pages\ManageSections;
use Classivo\Core\Filament\Resources\Pages\ManageSubjects;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\School;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Students\Filament\Pages\StudentPromotion;
use Classivo\Students\Filament\Pages\TakeAttendance;
use Classivo\Students\Filament\Pages\TakeSubjectAttendance;
use Classivo\Students\Filament\Resources\Pages\ManageGuardians;
use Classivo\Students\Filament\Resources\Pages\ManageStudentCategories;
use Classivo\Students\Filament\Resources\StudentResource\Pages\CreateStudent;
use Classivo\Students\Filament\Resources\StudentResource\Pages\EditStudent;
use Classivo\Students\Filament\Resources\StudentResource\Pages\ListStudents;
use Classivo\Students\Filament\Resources\StudentResource\Pages\ViewStudent;
use Classivo\Students\Models\Attendance;
use Classivo\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The demo admin panel end to end: seeded data, every screen renders, and the
 * main write paths (create class, admit student, take attendance) work
 * through the Filament UI.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function bootDemo(): User
    {
        $this->seed();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($admin);

        $school = School::where('code', 'DEMO')->firstOrFail();
        app(CurrentContext::class)
            ->setSchoolId($school->id)
            ->setBranchId(Branch::where('code', 'MAIN')->value('id'))
            ->setSessionId(AcademicSession::where('is_current', true)->value('id'))
            ->markResolved();

        return $admin;
    }

    public function test_demo_seeder_builds_a_working_school(): void
    {
        $this->bootDemo();

        $this->assertSame(1, School::count());
        $this->assertSame(5, SchoolClass::count());
        $this->assertSame(10, ClassSection::count());
        $this->assertSame(30, Student::count());
        $this->assertGreaterThan(0, Attendance::count());
        $this->assertSame('INR', Branch::first()->currency);
    }

    public function test_login_page_and_guest_redirect(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Classivo ERP');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_dashboard_renders_for_the_admin(): void
    {
        $this->bootDemo();

        $this->get('/admin')->assertOk();
        $this->get('/admin/students')->assertOk()->assertSee('ADM0001');
    }

    public function test_every_resource_and_page_renders(): void
    {
        $this->bootDemo();

        foreach ([
            ManageBranches::class, ManageAcademicSessions::class, ManageSchoolClasses::class,
            ManageSections::class, ManageSubjects::class, ManageGuardians::class,
            ManageStudentCategories::class, ListStudents::class, CreateStudent::class,
            TakeAttendance::class, TakeSubjectAttendance::class, StudentPromotion::class,
        ] as $page) {
            Livewire::test($page)->assertOk();
        }

        $student = Student::firstOrFail();
        Livewire::test(ViewStudent::class, ['record' => $student->getRouteKey()])
            ->assertOk()
            ->assertSee($student->admission_no)
            ->assertSee('Attendance %');
        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])->assertOk();
    }

    public function test_hindi_locale_translates_navigation(): void
    {
        $this->bootDemo();

        $this->get('/admin/students?lang=hi')->assertOk()->assertSee('विद्यार्थी');
    }

    public function test_create_class_with_sections_through_the_ui(): void
    {
        $this->bootDemo();
        $sectionIds = Section::pluck('id')->all();

        Livewire::test(ManageSchoolClasses::class)
            ->callAction('create', data: ['name' => 'Grade 6', 'numeric_order' => 6, 'section_ids' => $sectionIds, 'status' => true])
            ->assertHasNoActionErrors();

        $class = SchoolClass::where('name', 'Grade 6')->firstOrFail();
        $this->assertSame(count($sectionIds), $class->classSections()->count());
    }

    public function test_admit_a_student_through_the_ui(): void
    {
        $this->bootDemo();
        $cs = ClassSection::firstOrFail();

        Livewire::test(CreateStudent::class)
            ->fillForm([
                'class_id' => $cs->class_id,
                'section_id' => $cs->section_id,
                'first_name' => 'Newly',
                'last_name' => 'Admitted',
                'gender' => 'female',
                'guardian' => ['name' => 'Demo Guardian', 'relation' => 'mother', 'phone' => '9000099999'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('first_name', 'Newly')->firstOrFail();
        $this->assertSame('ADM0031', $student->admission_no);
        $this->assertSame($cs->id, $student->class_section_id);
        $this->assertSame('Demo Guardian', $student->primaryGuardian()?->name);
    }

    public function test_take_attendance_through_the_ui(): void
    {
        $this->bootDemo();
        $cs = ClassSection::firstOrFail();
        $date = '2030-03-04'; // a Monday

        Livewire::test(TakeAttendance::class)
            ->fillForm(['class_section_id' => $cs->id, 'date' => $date])
            ->call('load')
            ->call('markAll', 'absent')
            ->call('save');

        $this->assertSame(3, Attendance::whereDate('date', $date)->where('status', 'absent')->count());
    }
}

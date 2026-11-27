<?php

namespace Classivo\Exams\Tests\Feature;

use Classivo\Core\Database\Seeders\CorePermissionSeeder;
use Classivo\Core\Support\Permissions;
use Classivo\Exams\Database\Seeders\ExamsPermissionSeeder;
use Classivo\Exams\Filament\Pages\BuildExamSchedule;
use Classivo\Exams\Filament\Pages\ExamResults;
use Classivo\Exams\Filament\Pages\MarksRegister;
use Classivo\Exams\Filament\Resources\ExamAttendances\ExamAttendanceResource;
use Classivo\Exams\Filament\Resources\ExamGroups\ExamGroupResource;
use Classivo\Exams\Filament\Resources\Exams\ExamResource;
use Classivo\Exams\Filament\Resources\Grades\GradeResource;
use Classivo\Exams\Filament\Resources\MarksDistributions\MarksDistributionResource;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamGroup;
use Classivo\Exams\Models\ExamResult;
use Classivo\Exams\Support\ExamsPermissions;
use Classivo\Exams\Tests\Support\User;
use Classivo\Exams\Tests\TestCase;
use Classivo\Students\Database\Seeders\StudentsPermissionSeeder;
use Classivo\Students\Support\StudentsPermissions;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AuthorizationTest extends TestCase
{
    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchool();
        Filament::setCurrentPanel('admin');
        $group = ExamGroup::create(['name' => 'Term 1', 'type' => 'marks']);
        $this->exam = Exam::create(['exam_group_id' => $group->id, 'name' => 'Mid Term']);
    }

    private function signIn(string ...$permissions): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => 'Staff '.$n]);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }
        $this->actingAs($user);

        return $user;
    }

    public function test_user_without_permission_gets_403_on_every_resource_and_page_url(): void
    {
        $this->signIn();

        foreach ([ExamResource::class, ExamGroupResource::class, GradeResource::class, MarksDistributionResource::class, ExamAttendanceResource::class] as $resource) {
            $this->get($resource::getUrl('index'))->assertForbidden();
            $this->get($resource::getUrl('create'))->assertForbidden();
        }

        $this->get(ExamResource::getUrl('edit', ['record' => $this->exam]))->assertForbidden();
        $this->get(BuildExamSchedule::getUrl())->assertForbidden();
        $this->get(MarksRegister::getUrl())->assertForbidden();
        $this->get(ExamResults::getUrl())->assertForbidden();
        Livewire::test(ExamResults::class)->assertForbidden();
    }

    public function test_view_only_user_cannot_open_create_or_edit(): void
    {
        $this->signIn('exams.exams.view');

        $this->get(ExamResource::getUrl('index'))->assertOk();
        $this->get(ExamResource::getUrl('create'))->assertForbidden();
        $this->get(ExamResource::getUrl('edit', ['record' => $this->exam]))->assertForbidden();
        $this->assertFalse(ExamResource::canDelete($this->exam));
        $this->assertFalse(GradeResource::canViewAny(), 'no leakage across resources');
    }

    public function test_results_view_does_not_allow_generating(): void
    {
        $this->signIn(ExamsPermissions::RESULTS_VIEW);

        Livewire::test(ExamResults::class)
            ->assertOk()
            ->set('data.exam_id', $this->exam->id)
            ->set('data.class_section_id', $this->classSection->id)
            ->call('generate');

        $this->assertSame(0, ExamResult::query()->count());
    }

    public function test_marks_entry_holder_keeps_the_marks_register(): void
    {
        $this->signIn('exam.enter_marks');

        $this->assertTrue(MarksRegister::canAccess());
        $this->assertFalse(BuildExamSchedule::canAccess());
    }

    public function test_seeders_create_core_students_and_exams_permissions_and_grant_roles(): void
    {
        Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        config([
            'classivo.permissions.grant_to_roles' => ['Admin'],
            'classivo-students.grant_to_roles' => ['Admin'],
            'classivo-exams.grant_to_roles' => ['Admin'],
        ]);

        $this->seed([CorePermissionSeeder::class, StudentsPermissionSeeder::class, ExamsPermissionSeeder::class]);

        $admin = User::create(['name' => 'Admin']);
        $admin->assignRole('Admin');
        $clerk = User::create(['name' => 'Clerk']);

        foreach (array_merge(Permissions::all(), StudentsPermissions::all(), ExamsPermissions::all()) as $permission) {
            $this->assertTrue($admin->can($permission), $permission);
            $this->assertFalse($clerk->can($permission), $permission);
        }
    }
}

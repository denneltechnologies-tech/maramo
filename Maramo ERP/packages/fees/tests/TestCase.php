<?php

namespace Classivo\Fees\Tests;

use Barryvdh\DomPDF\ServiceProvider as DomPdfServiceProvider;
use Classivo\Core\CoreServiceProvider;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Fees\FeesServiceProvider;
use Classivo\Fees\Models\FeeGroup;
use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Models\FeeType;
use Classivo\Fees\Tests\Support\StubUser;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentSessionRecord;
use Classivo\Students\StudentsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

/** Runs against the real classivo/students package (../students). */
#[WithMigration]
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected School $school;

    protected Branch $branch;

    protected AcademicSession $session;

    protected SchoolClass $class;

    protected function getPackageProviders($app): array
    {
        return [PermissionServiceProvider::class, CoreServiceProvider::class, StudentsServiceProvider::class, FeesServiceProvider::class, DomPdfServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('classivo.user_model', StubUser::class);
        $app['config']->set('auth.providers.users.model', StubUser::class);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('permissions')) {
            (include __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->school = School::create(['name' => 'Demo Public School', 'code' => 'DEMO', 'status' => true]);
        $this->branch = Branch::create(['school_id' => $this->school->id, 'name' => 'Main Campus', 'code' => 'MAIN', 'is_default' => true, 'status' => true]);
        $this->session = AcademicSession::create([
            'school_id' => $this->school->id,
            'name' => '2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'is_current' => true,
            'status' => true,
        ]);

        config(['classivo.school_id' => $this->school->id]);
        app(CurrentContext::class)
            ->setSchoolId($this->school->id)
            ->setBranchId($this->branch->id)
            ->setSessionId($this->session->id);

        $this->class = SchoolClass::create([
            'school_id' => $this->school->id,
            'branch_id' => $this->branch->id,
            'session_id' => $this->session->id,
            'name' => 'Grade 5',
            'numeric_order' => 5,
            'status' => true,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function student(string $admissionNo = 'A001', array $attributes = []): Student
    {
        $student = Student::create($attributes + [
            'admission_no' => $admissionNo,
            'first_name' => 'Sam',
            'last_name' => 'Doe',
            'email' => strtolower($admissionNo).'@example.test',
            'class_id' => $this->class->id,
            'status' => 'active',
        ]);

        StudentSessionRecord::create([
            'school_id' => $this->school->id,
            'branch_id' => $this->branch->id,
            'session_id' => $this->session->id,
            'student_id' => $student->id,
            'class_id' => $student->class_id,
            'status' => 'active',
        ]);

        return $student;
    }

    /** A signed-in user holding exactly the given permissions (none = deny everything). */
    protected function userWith(array $permissions = [], ?string $email = null): StubUser
    {
        static $n = 0;
        $n++;

        $user = StubUser::create(['name' => 'Staff '.$n, 'email' => $email ?? "staff{$n}@example.test", 'password' => 'secret']);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    protected function master(float $amount = 1000, array $attributes = []): FeeMaster
    {
        $type = FeeType::firstOrCreate(['branch_id' => $this->branch->id, 'code' => 'TUI'], ['name' => 'Tuition Fee', 'status' => true]);
        $group = FeeGroup::firstOrCreate(['branch_id' => $this->branch->id, 'name' => 'Annual'], ['status' => true]);

        return FeeMaster::create($attributes + [
            'fee_group_id' => $group->id,
            'fee_type_id' => $type->id,
            'class_id' => $this->class->id,
            'amount' => $amount,
            'due_date' => now()->addMonth()->toDateString(),
            'status' => true,
        ]);
    }
}

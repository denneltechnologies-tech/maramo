<?php

namespace Classivo\Students\Tests;

use Classivo\Core\CoreServiceProvider;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\School;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Students\Models\Student;
use Classivo\Students\Services\StudentAdmissionService;
use Classivo\Students\StudentsServiceProvider;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;

#[WithMigration]
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected School $school;

    protected Branch $branch;

    protected AcademicSession $session;

    protected ClassSection $classSection;

    protected function getPackageProviders($app): array
    {
        return [CoreServiceProvider::class, StudentsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('classivo.user_model', User::class);
    }

    /** School + default branch (ADM series, Sunday weekend) + current session + Grade 1-A. */
    protected function bootFoundation(): void
    {
        $this->school = School::create(['name' => 'Demo Public School', 'code' => 'DEMO', 'status' => true]);
        $this->branch = Branch::create([
            'school_id' => $this->school->id,
            'name' => 'Main Campus',
            'code' => 'MAIN',
            'is_default' => true,
            'status' => true,
            'reg_no_prefix' => 'ADM',
            'reg_no_start' => 1,
            'reg_no_digits' => 4,
            'weekend_days' => [0],
        ]);
        $this->session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026-2027', 'is_current' => true, 'status' => true]);

        config(['classivo.school_id' => $this->school->id]);
        app(CurrentContext::class)
            ->setSchoolId($this->school->id)
            ->setBranchId($this->branch->id)
            ->setSessionId($this->session->id);

        $this->classSection = $this->classSectionFor($this->session->id, 'Grade 1', 1, 'A');
    }

    protected function classSectionFor(int $sessionId, string $className, int $order, string $section): ClassSection
    {
        $previous = current_session_id();
        app(CurrentContext::class)->setSessionId($sessionId);

        $sec = Section::create(['name' => $section, 'status' => true]);
        $cls = SchoolClass::create(['name' => $className, 'numeric_order' => $order, 'status' => true]);
        $cls->syncSections([$sec->id]);

        app(CurrentContext::class)->setSessionId($previous);

        return ClassSection::withoutGlobalScope(SessionScope::class)
            ->where('class_id', $cls->id)
            ->where('section_id', $sec->id)
            ->firstOrFail();
    }

    /** @param  array<string, mixed>  $overrides */
    protected function admit(array $overrides = []): Student
    {
        return app(StudentAdmissionService::class)->admit(array_merge([
            'class_id' => $this->classSection->class_id,
            'section_id' => $this->classSection->section_id,
            'first_name' => 'Test',
            'last_name' => 'Student',
            'gender' => 'male',
            'date_of_birth' => '2018-01-01',
        ], $overrides));
    }
}

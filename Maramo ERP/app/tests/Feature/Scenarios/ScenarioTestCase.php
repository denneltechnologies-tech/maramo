<?php

namespace Tests\Feature\Scenarios;

use App\Models\User;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\School;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Subject;
use Classivo\Core\Tenancy\CurrentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared set-up for the cross-package scenarios: the seeded demo school
 * (admin / teacher / student / parent logins), tenant context resolved.
 */
abstract class ScenarioTestCase extends TestCase
{
    use RefreshDatabase;

    protected function bootDemo(): void
    {
        $this->seed();

        $school = School::where('code', 'DEMO')->firstOrFail();
        app(CurrentContext::class)
            ->setSchoolId($school->id)
            ->setBranchId(Branch::where('code', 'MAIN')->value('id'))
            ->setSessionId(AcademicSession::where('is_current', true)->value('id'))
            ->markResolved();
    }

    protected function login(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        $this->flushSession(); // AuthenticateSession compares the session password hash; start clean per login
        $this->actingAs($user);

        return $user;
    }

    protected function grade1A(): ClassSection
    {
        $class = SchoolClass::where('name', 'Grade 1')->firstOrFail();

        return ClassSection::where('class_id', $class->id)
            ->whereHas('section', fn ($q) => $q->where('name', 'A'))
            ->firstOrFail();
    }

    protected function subject(string $code = 'ENG'): Subject
    {
        return Subject::where('code', $code)->firstOrFail();
    }
}

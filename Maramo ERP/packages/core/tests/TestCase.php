<?php

namespace Classivo\Core\Tests;

use Classivo\Core\CoreServiceProvider;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Tenancy\CurrentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;

#[WithMigration]
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [CoreServiceProvider::class];
    }

    /**
     * A school with one default branch and a current session, pinned on the
     * context the way the web middleware would.
     *
     * @return array{0: School, 1: Branch, 2: AcademicSession}
     */
    protected function foundation(string $code = 'DEMO'): array
    {
        $school = School::create(['name' => 'Demo Public School', 'code' => $code, 'status' => true]);
        $branch = Branch::create(['school_id' => $school->id, 'name' => 'Main Campus', 'code' => 'MAIN', 'is_default' => true, 'status' => true]);
        $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026-2027', 'is_current' => true, 'status' => true]);

        config(['classivo.school_id' => $school->id]);
        app(CurrentContext::class)
            ->setSchoolId($school->id)
            ->setBranchId($branch->id)
            ->setSessionId($session->id);

        return [$school, $branch, $session];
    }
}

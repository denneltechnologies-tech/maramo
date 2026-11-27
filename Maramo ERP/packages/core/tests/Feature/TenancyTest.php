<?php

namespace Classivo\Core\Tests\Feature;

use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Core\Tenancy\SingleSchoolResolver;
use Classivo\Core\Tenancy\TenantResolver;
use Classivo\Core\Tests\TestCase;

/**
 * Data isolation: the tenancy contract, the single-school default and the
 * global scopes (ported from CrossTenantIsolationTest, platform concept removed).
 */
class TenancyTest extends TestCase
{
    public function test_default_resolver_is_single_school_from_config(): void
    {
        config(['classivo.school_id' => 7]);

        $this->assertInstanceOf(SingleSchoolResolver::class, app(TenantResolver::class));
        $this->assertSame(7, app(TenantResolver::class)->schoolId());
        $this->assertSame(7, current_school_id());
    }

    public function test_a_custom_resolver_can_be_bound(): void
    {
        $this->app->bind(TenantResolver::class, fn () => new class implements TenantResolver
        {
            public function schoolId(): ?int
            {
                return 42;
            }
        });
        $this->app->forgetInstance(CurrentContext::class);

        $this->assertSame(42, current_school_id());
    }

    public function test_tenant_scope_isolates_branches_between_schools(): void
    {
        $schoolA = School::create(['name' => 'School A', 'code' => 'SCH-A', 'status' => true]);
        $schoolB = School::create(['name' => 'School B', 'code' => 'SCH-B', 'status' => true]);

        $branchA = Branch::create(['school_id' => $schoolA->id, 'name' => 'A Campus', 'code' => 'A-CMP']);
        $branchB = Branch::create(['school_id' => $schoolB->id, 'name' => 'B Campus', 'code' => 'B-CMP']);

        $context = app(CurrentContext::class);
        $context->setSchoolId($schoolA->id)->markResolved();

        $this->assertContains($branchA->id, Branch::pluck('id')->all());
        $this->assertNull(Branch::find($branchB->id));

        $context->setSchoolId($schoolB->id);

        $this->assertContains($branchB->id, Branch::pluck('id')->all());
        $this->assertNull(Branch::find($branchA->id));
    }

    public function test_unresolvable_school_matches_nothing(): void
    {
        $school = School::create(['name' => 'School A', 'code' => 'SCH-A', 'status' => true]);
        Branch::create(['school_id' => $school->id, 'name' => 'A Campus', 'code' => 'A-CMP']);

        $this->app->bind(TenantResolver::class, fn () => new class implements TenantResolver
        {
            public function schoolId(): ?int
            {
                return null;
            }
        });
        $this->app->forgetInstance(CurrentContext::class);
        app(CurrentContext::class)->markResolved();

        $this->assertSame(0, Branch::count());
    }

    public function test_writes_auto_fill_school_branch_and_session(): void
    {
        [$school, $branch, $session] = $this->foundation();

        $class = SchoolClass::create(['name' => 'Grade 1', 'numeric_order' => 1]);

        $this->assertSame($school->id, $class->school_id);
        $this->assertSame($branch->id, $class->branch_id);
        $this->assertSame($session->id, $class->session_id);
    }

    public function test_branch_takes_regional_defaults_from_the_india_preset(): void
    {
        [, $branch] = $this->foundation();

        $this->assertSame('INR', $branch->currency);
        $this->assertSame('Asia/Kolkata', $branch->timezone);
        $this->assertSame(4, $branch->session_start_month);
        $this->assertSame('en', $branch->language);
    }

    public function test_generic_preset_is_country_neutral(): void
    {
        config(['classivo.preset' => 'generic']);
        [, $branch] = $this->foundation();

        $this->assertSame('USD', $branch->currency);
        $this->assertSame('UTC', $branch->timezone);
    }
}

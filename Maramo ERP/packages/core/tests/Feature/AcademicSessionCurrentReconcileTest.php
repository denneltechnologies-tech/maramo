<?php

namespace Classivo\Core\Tests\Feature;

use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Core\Tests\TestCase;

/**
 * "At most one current session per school" must hold on every save path.
 */
class AcademicSessionCurrentReconcileTest extends TestCase
{
    private School $school;

    private function bootFoundation(): void
    {
        $this->school = School::create(['name' => 'Demo School', 'code' => 'DEMO', 'status' => true]);
        Branch::create(['school_id' => $this->school->id, 'name' => 'Main Campus', 'code' => 'MAIN', 'is_default' => true, 'status' => true]);

        config(['classivo.school_id' => $this->school->id]);
        app(CurrentContext::class)->setSchoolId($this->school->id);
    }

    private function currentCount(): int
    {
        return AcademicSession::where('school_id', $this->school->id)->where('is_current', true)->count();
    }

    public function test_creating_a_second_current_session_unsets_the_first(): void
    {
        $this->bootFoundation();

        $first = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2025-2026', 'is_current' => true, 'status' => true]);
        $second = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026-2027', 'is_current' => true, 'status' => true]);

        $this->assertSame(1, $this->currentCount());
        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->fresh()->is_current);
    }

    public function test_editing_a_session_to_current_unsets_the_sibling(): void
    {
        $this->bootFoundation();

        $first = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2025-2026', 'is_current' => true, 'status' => true]);
        $second = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026-2027', 'is_current' => false, 'status' => true]);

        $second->update(['is_current' => true]);

        $this->assertSame(1, $this->currentCount());
        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->fresh()->is_current);
    }

    public function test_current_context_resolves_a_single_current_session(): void
    {
        $this->bootFoundation();

        AcademicSession::create(['school_id' => $this->school->id, 'name' => '2025-2026', 'is_current' => true, 'status' => true]);
        $second = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026-2027', 'is_current' => true, 'status' => true]);

        $context = app(CurrentContext::class);
        $context->setSchoolId($this->school->id)->setSessionId(null);

        $this->assertSame($second->id, $context->session()?->id);
        $this->assertFalse($context->isViewingNonCurrentSession());
    }

    public function test_viewing_an_older_session_is_flagged(): void
    {
        $this->bootFoundation();

        $old = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2025-2026', 'is_current' => false, 'status' => true]);
        AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026-2027', 'is_current' => true, 'status' => true]);

        $context = app(CurrentContext::class)->setSessionId($old->id);

        $this->assertTrue($context->isViewingNonCurrentSession());
    }
}

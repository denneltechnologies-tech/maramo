<?php

namespace Classivo\Students\Tests\Feature;

use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Students\Events\StudentPromoted;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentSessionRecord;
use Classivo\Students\Services\StudentPromotionService;
use Classivo\Students\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Promotion via StudentPromotionService (ported from StudentPromotionTest;
 * fee carry-forward is now an event flag for the fees module).
 */
class StudentPromotionTest extends TestCase
{
    private AcademicSession $session2;

    private ClassSection $grade2A;

    private function bootSessions(): void
    {
        $this->bootFoundation();
        $this->session2 = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2027-2028', 'is_current' => false, 'status' => true]);
        $this->grade2A = $this->classSectionFor($this->session2->id, 'Grade 2', 2, 'A');
        app(CurrentContext::class)->setSessionId($this->session->id);
    }

    /** @param  array<string, mixed>  $extra */
    private function promote(Student $student, array $extra = []): ?StudentSessionRecord
    {
        return app(StudentPromotionService::class)->promoteStudent($student, array_merge([
            'from_session_id' => $this->session->id,
            'result' => 'pass',
            'action' => 'continue',
            'next_session_id' => $this->session2->id,
            'next_class_id' => $this->grade2A->class_id,
            'next_section_id' => $this->grade2A->section_id,
            'new_roll' => '7',
        ], $extra));
    }

    public function test_continue_promotion_creates_next_session_record_and_keeps_history(): void
    {
        $this->bootSessions();
        $student = $this->admit();

        $this->promote($student);

        $old = StudentSessionRecord::withoutGlobalScope(SessionScope::class)
            ->where('student_id', $student->id)->where('session_id', $this->session->id)->firstOrFail();
        $this->assertSame('promoted', $old->status);
        $this->assertSame('pass', $old->result);

        $new = StudentSessionRecord::withoutGlobalScope(SessionScope::class)
            ->where('student_id', $student->id)->where('session_id', $this->session2->id)->firstOrFail();
        $this->assertSame('studying', $new->status);
        $this->assertSame($this->grade2A->id, $new->class_section_id);
        $this->assertSame('7', $new->roll_no);

        $this->assertSame(2, $student->allSessionRecords()->count());

        $student->refresh();
        $this->assertSame($this->grade2A->id, $student->class_section_id);
        $this->assertSame($this->session2->id, $student->session_id);
        $this->assertSame('active', $student->status);

        $this->assertTrue(Student::enrolledInSession($this->session->id)->whereKey($student->id)->exists());
        $this->assertTrue(Student::enrolledInSession($this->session2->id)->whereKey($student->id)->exists());

        app(CurrentContext::class)->setSessionId($this->session2->id);
        $this->assertSame($this->grade2A->id, $student->fresh()->current_class_section?->id);
    }

    public function test_leave_marks_record_left_and_student_inactive(): void
    {
        $this->bootSessions();
        $student = $this->admit();

        $this->assertNull($this->promote($student, ['action' => 'leave']));

        $old = StudentSessionRecord::withoutGlobalScope(SessionScope::class)
            ->where('student_id', $student->id)->where('session_id', $this->session->id)->firstOrFail();
        $this->assertSame('left', $old->status);
        $this->assertFalse(StudentSessionRecord::withoutGlobalScope(SessionScope::class)
            ->where('student_id', $student->id)->where('session_id', $this->session2->id)->exists());
        $this->assertSame('inactive', $student->fresh()->status);
    }

    public function test_carry_forward_is_recorded_and_announced_without_fabricating_fees(): void
    {
        $this->bootSessions();
        Event::fake([StudentPromoted::class]);
        $student = $this->admit();

        $record = $this->promote($student, ['carry_forward_fees' => true]);

        $this->assertStringContainsString('Carry-forward', (string) $record->remark);
        Event::assertDispatched(StudentPromoted::class, fn (StudentPromoted $e): bool => $e->carryForwardFees && $e->toSessionId === $this->session2->id);
    }

    public function test_missing_target_class_section_aborts_without_corrupting_enrolment(): void
    {
        $this->bootSessions();
        $student = $this->admit();

        try {
            $this->promote($student, ['next_class_id' => 999, 'next_section_id' => 999]);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException) {
            // expected
        }

        $student->refresh();
        $this->assertSame($this->classSection->id, $student->class_section_id);
        $this->assertSame('studying', StudentSessionRecord::where('student_id', $student->id)->value('status'), 'Rolled back.');
    }
}

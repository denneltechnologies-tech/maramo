<?php

namespace Classivo\Fees\Tests\Feature;

use Classivo\Core\Models\AcademicSession;
use Classivo\Fees\Models\FeeCarryForward;
use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Models\FeeSessionRollover;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Services\FeeAllocationService;
use Classivo\Fees\Services\FeeCarryForwardService;
use Classivo\Fees\Services\FeeSessionRolloverService;
use Classivo\Fees\Tests\TestCase;

class SessionTransitionTest extends TestCase
{
    private function nextSession(): AcademicSession
    {
        return AcademicSession::create([
            'school_id' => $this->school->id,
            'name' => '2027-28',
            'start_date' => '2027-04-01',
            'end_date' => '2028-03-31',
            'is_current' => false,
            'status' => true,
        ]);
    }

    public function test_rollover_clones_the_price_list_with_an_increase(): void
    {
        $this->master(1000, ['class_id' => null, 'due_date' => '2026-04-10']);
        $next = $this->nextSession();

        $summary = app(FeeSessionRolloverService::class)->commit($this->session->id, $next->id, ['percent' => 10, 'rounding' => 0]);

        $this->assertSame(1, $summary['created']);
        $clone = FeeMaster::query()->withoutGlobalScopes()->where('session_id', $next->id)->first();
        $this->assertNotNull($clone);
        $this->assertSame(1100.0, (float) $clone->amount);
        $this->assertSame('2027-04-10', $clone->due_date->toDateString());

        // Running it again creates nothing new.
        $again = app(FeeSessionRolloverService::class)->commit($this->session->id, $next->id, ['percent' => 10, 'rounding' => 0]);
        $this->assertSame(0, $again['created']);
        $this->assertSame(1, FeeMaster::query()->withoutGlobalScopes()->where('session_id', $next->id)->count());

        app(FeeSessionRolloverService::class)->record($this->session->id, $next->id, ['percent' => 10], $summary + ['students' => 0, 'amount' => 0]);
        $this->assertSame(1, FeeSessionRollover::count());
    }

    public function test_carry_forward_raises_a_payable_due_in_the_next_session(): void
    {
        $student = $this->student();
        app(FeeAllocationService::class)->allocate($this->master(900), $student);
        $next = $this->nextSession();

        $service = app(FeeCarryForwardService::class);
        $this->assertSame(900.0, $service->outstandingBalance($student->id, $this->session->id));

        $carry = $service->carryForward($student, 900, $this->session->id, $next->id);

        $this->assertInstanceOf(FeeCarryForward::class, $carry);
        $allocation = FeeStudentAllocation::query()->withoutGlobalScopes()->find($carry->fee_student_allocation_id);
        $this->assertSame($next->id, (int) $allocation->session_id);
        $this->assertSame(900.0, $allocation->balance());

        // Idempotent.
        $service->carryForward($student, 900, $this->session->id, $next->id);
        $this->assertSame(1, FeeCarryForward::query()->withoutGlobalScopes()->count());
        $this->assertSame(900.0, $service->carriedOutOf($this->session->id, $student->id));
    }
}

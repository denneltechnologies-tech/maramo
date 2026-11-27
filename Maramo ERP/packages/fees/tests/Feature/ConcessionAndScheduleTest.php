<?php

namespace Classivo\Fees\Tests\Feature;

use Classivo\Fees\Models\FeeDiscount;
use Classivo\Fees\Models\FeeDiscountStudent;
use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Services\ConcessionService;
use Classivo\Fees\Services\FeeAllocationService;
use Classivo\Fees\Services\FeeCollectionService;
use Classivo\Fees\Services\FeeScheduleService;
use Classivo\Fees\Tests\TestCase;

class ConcessionAndScheduleTest extends TestCase
{
    public function test_one_time_concession_reduces_the_balance(): void
    {
        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master(2000), $student);
        $discount = FeeDiscount::create(['name' => 'Sibling', 'code' => 'SIB', 'type' => 'percent', 'value' => 10, 'status' => true]);

        $grant = app(ConcessionService::class)->grant([
            'student' => $student,
            'fee_discount_id' => $discount->id,
            'fee_student_allocation_id' => $allocation->id,
            'reason' => 'Second child',
        ]);

        $this->assertSame(FeeDiscountStudent::STATUS_APPROVED, $grant->approval_status);
        $this->assertSame(1800.0, $allocation->fresh()->balance());
    }

    public function test_concession_over_threshold_waits_for_approval(): void
    {
        config(['fees.concessions.requires_approval' => true, 'fees.concessions.threshold_amount' => 100]);

        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master(2000), $student);

        $grant = app(ConcessionService::class)->grant([
            'student' => $student,
            'fee_student_allocation_id' => $allocation->id,
            'amount' => 500,
            'reason' => 'Hardship',
        ]);

        $this->assertSame(FeeDiscountStudent::STATUS_PENDING, $grant->approval_status);
        $this->assertSame(2000.0, $allocation->fresh()->balance());

        $grant->approve();
        $this->assertSame(1500.0, $allocation->fresh()->balance());
    }

    public function test_monthly_schedule_expands_into_instalments(): void
    {
        $definition = $this->master(500, [
            'billing_frequency' => 'monthly',
            'installment_count' => 3,
            'first_due_date' => '2026-04-10',
            'is_schedule_parent' => true,
            'due_date' => null,
        ]);

        $tally = app(FeeScheduleService::class)->generate($definition);

        $this->assertSame(3, $tally['created']);
        $children = FeeMaster::query()->where('parent_fee_master_id', $definition->id)->orderBy('installment_number')->get();
        $this->assertSame(['2026-04-10', '2026-05-10', '2026-06-10'], $children->map(fn ($m) => $m->due_date->toDateString())->all());
        $this->assertSame(0, FeeMaster::query()->billable()->where('id', $definition->id)->count());
    }

    public function test_master_with_payments_cannot_be_archived(): void
    {
        $student = $this->student();
        $master = $this->master(700);
        $allocation = app(FeeAllocationService::class)->allocate($master, $student);
        app(FeeCollectionService::class)->collect($student, [['allocation_id' => $allocation->id, 'amount' => 100]]);

        $this->assertFalse($master->delete());
        $this->assertNotNull(FeeMaster::find($master->id));
    }

    public function test_unpaid_allocations_retire_and_restore_with_their_master(): void
    {
        $student = $this->student();
        $master = $this->master(700);
        $allocation = app(FeeAllocationService::class)->allocate($master, $student);

        $master->delete();
        $this->assertTrue($allocation->fresh()->trashed());

        $master->restore();
        $this->assertFalse($allocation->fresh()->trashed());
    }
}

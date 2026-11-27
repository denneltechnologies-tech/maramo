<?php

namespace Classivo\Fees\Tests\Feature;

use Classivo\Fees\Contracts\CollectionGuard;
use Classivo\Fees\Events\FeePaymentRecorded;
use Classivo\Fees\Events\FeePaymentReverted;
use Classivo\Fees\Exceptions\NothingToCollectException;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeType;
use Classivo\Fees\Services\FeeAllocationService;
use Classivo\Fees\Services\FeeCollectionService;
use Classivo\Fees\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class FeeCollectionTest extends TestCase
{
    public function test_allocation_and_partial_then_full_collection(): void
    {
        Event::fake([FeePaymentRecorded::class]);

        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master(1000), $student);

        $this->assertSame('unpaid', $allocation->status);
        $this->assertSame(1000.0, $allocation->balance());

        $service = app(FeeCollectionService::class);
        $first = $service->collect($student, [['allocation_id' => $allocation->id, 'amount' => 400]], ['mode' => 'cash']);
        $second = $service->collect($student, [['allocation_id' => $allocation->id, 'amount' => 600]], ['mode' => 'cheque', 'reference_no' => 'CHQ-1']);

        $this->assertSame('RCPT1', $first->receipt_no);
        $this->assertSame('RCPT2', $second->receipt_no);
        $this->assertSame('paid', $allocation->fresh()->status);
        $this->assertSame(0.0, $allocation->fresh()->balance());

        Event::assertDispatchedTimes(FeePaymentRecorded::class, 2);
        Event::assertDispatched(FeePaymentRecorded::class, fn (FeePaymentRecorded $e): bool => $e->amount === 600.0 && $e->mode === 'cheque' && $e->head === 'Tuition Fee');
    }

    public function test_gateway_modes_are_refused(): void
    {
        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master(500), $student);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported payment mode');

        app(FeeCollectionService::class)->collect($student, [['allocation_id' => $allocation->id, 'amount' => 100]], ['mode' => 'razorpay']);
    }

    public function test_empty_basket_mints_no_receipt(): void
    {
        $this->expectException(NothingToCollectException::class);

        try {
            app(FeeCollectionService::class)->collect($this->student(), [['amount' => 0]]);
        } finally {
            $this->assertSame(0, FeePayment::count());
        }
    }

    public function test_collection_guard_can_refuse(): void
    {
        $this->app->instance(CollectionGuard::class, new class implements CollectionGuard
        {
            public function assertMayRecord(string $date, int $branchId, string $mode): void
            {
                throw new RuntimeException('Period closed');
            }
        });

        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master(500), $student);

        $this->expectExceptionMessage('Period closed');
        app(FeeCollectionService::class)->collect($student, [['allocation_id' => $allocation->id, 'amount' => 100]]);
    }

    public function test_revert_restores_dues_and_fires_event(): void
    {
        Event::fake([FeePaymentReverted::class]);

        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master(800), $student);
        $service = app(FeeCollectionService::class);

        $payment = $service->collect($student, [['allocation_id' => $allocation->id, 'amount' => 800]]);
        $this->assertSame('paid', $allocation->fresh()->status);

        $service->revert($payment, 'Entered against the wrong student');

        $this->assertTrue($payment->fresh()->isReverted());
        $this->assertSame('unpaid', $allocation->fresh()->status);
        Event::assertDispatched(FeePaymentReverted::class, fn ($e): bool => $e->amount === 800.0);
    }

    public function test_collect_against_dues_applies_oldest_first_and_parks_surplus(): void
    {
        $student = $this->student();
        $alloc = app(FeeAllocationService::class);
        $old = $alloc->allocate($this->master(300, ['due_date' => now()->subMonth()->toDateString()]), $student);
        $new = $alloc->allocate($this->master(500, ['due_date' => now()->addMonth()->toDateString(), 'fee_type_id' => FeeType::create(['name' => 'Lab', 'code' => 'LAB'])->id]), $student);

        $payment = app(FeeCollectionService::class)->collectAgainstDues($student, 1000, ['mode' => 'bank_transfer']);

        $this->assertSame('paid', $old->fresh()->status);
        $this->assertSame('paid', $new->fresh()->status);
        $this->assertSame(1000.0, (float) $payment->paid_amount);
        $this->assertTrue($payment->details()->whereNull('fee_student_allocation_id')->where('amount', 200)->exists());
    }
}

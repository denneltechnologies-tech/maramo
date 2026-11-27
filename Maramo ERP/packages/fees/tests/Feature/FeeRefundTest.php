<?php

namespace Classivo\Fees\Tests\Feature;

use Classivo\Fees\Events\FeeRefunded;
use Classivo\Fees\Models\FeeRefundDecision;
use Classivo\Fees\Services\FeeAllocationService;
use Classivo\Fees\Services\FeeCollectionService;
use Classivo\Fees\Services\FeeRefundService;
use Classivo\Fees\Tests\Support\StubUser as User;
use Classivo\Fees\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class FeeRefundTest extends TestCase
{
    private function user(string $email): User
    {
        return User::create(['name' => 'Staff '.$email, 'email' => $email, 'password' => 'x']);
    }

    public function test_small_refund_is_auto_approved_issued_and_restores_dues(): void
    {
        Event::fake([FeeRefunded::class]);

        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master(3000), $student);
        $payment = app(FeeCollectionService::class)->collect($student, [['allocation_id' => $allocation->id, 'amount' => 3000]]);
        $actor = $this->user('cashier@example.test');

        $service = app(FeeRefundService::class);
        $refund = $service->request($payment, ['amount' => 1000, 'reason_code' => 'tc_issued', 'mode' => 'cheque'], $actor);

        $this->assertSame('approved', $refund->status);
        $this->assertSame(0, $refund->required_approvals);

        $issued = $service->issue($refund, $actor);

        $this->assertSame('issued', $issued->status);
        $this->assertSame('CN1', $issued->credit_note_no);
        $this->assertSame(1000.0, $allocation->fresh()->balance());
        $this->assertSame(2000.0, $service->refundableFor($payment->fresh()));
        $this->assertSame(['requested', 'auto_approved', 'issued'], FeeRefundDecision::query()->orderBy('id')->pluck('decision')->all());
        Event::assertDispatched(FeeRefunded::class, fn (FeeRefunded $e): bool => $e->amount === 1000.0 && $e->creditNoteNo === 'CN1');

        // Issuing again is a no-op.
        $service->issue($issued, $actor);
        Event::assertDispatchedTimes(FeeRefunded::class, 1);
    }

    public function test_large_refund_needs_two_distinct_approvers(): void
    {
        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master(40000), $student);
        $payment = app(FeeCollectionService::class)->collect($student, [['allocation_id' => $allocation->id, 'amount' => 40000]]);
        $first = $this->user('a@example.test');
        $second = $this->user('b@example.test');

        $service = app(FeeRefundService::class);
        $refund = $service->request($payment, ['amount' => 30000, 'reason_code' => 'admission_cancelled'], $first);
        $this->assertSame(2, $refund->required_approvals);

        $refund = $service->approve($refund, $first);
        $this->assertSame('requested', $refund->status);

        try {
            $service->approve($refund, $first);
            $this->fail('The same approver approved twice.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('second approver', $e->getMessage());
        }

        $refund = $service->approve($refund, $second);
        $this->assertSame('approved', $refund->status);
    }

    public function test_cannot_refund_more_than_received(): void
    {
        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master(1000), $student);
        $payment = app(FeeCollectionService::class)->collect($student, [['allocation_id' => $allocation->id, 'amount' => 1000]]);

        $this->expectException(RuntimeException::class);
        app(FeeRefundService::class)->request($payment, ['amount' => 5000, 'reason_code' => 'excess_payment']);
    }

    public function test_credit_note_pdf_renders(): void
    {
        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master(1000), $student);
        $payment = app(FeeCollectionService::class)->collect($student, [['allocation_id' => $allocation->id, 'amount' => 1000]]);
        $actor = $this->user('c@example.test');
        $service = app(FeeRefundService::class);
        $refund = $service->issue($service->request($payment, ['amount' => 200, 'reason_code' => 'wrong_head'], $actor), $actor);

        $this->assertStringStartsWith('%PDF', $service->creditNotePdf($refund)->output());
    }
}

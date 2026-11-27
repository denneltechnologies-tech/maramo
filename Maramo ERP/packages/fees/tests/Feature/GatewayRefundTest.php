<?php

namespace Classivo\Fees\Tests\Feature;

use Classivo\Fees\Events\FeeRefunded;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeRefund;
use Classivo\Fees\Models\FeeRefundDecision;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Services\FeeAllocationService;
use Classivo\Fees\Services\FeeCollectionService;
use Classivo\Fees\Services\FeeRefundService;
use Classivo\Fees\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/** Online receipts and refunds already paid back by a payment gateway. */
class GatewayRefundTest extends TestCase
{
    private function onlineReceipt(float $amount = 3000): FeePayment
    {
        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master($amount), $student);

        return app(FeeCollectionService::class)->collect(
            $student,
            [['allocation_id' => $allocation->id, 'amount' => $amount]],
            ['mode' => 'online', 'reference_no' => 'pay_TEST0001'],
        );
    }

    public function test_online_receipts_can_be_recorded(): void
    {
        $receipt = $this->onlineReceipt();

        $this->assertSame('online', $receipt->mode);
        $this->assertArrayHasKey('online', FeePayment::MODES);
        $this->assertArrayNotHasKey('online', FeePayment::MANUAL_MODES);
    }

    public function test_partial_gateway_refund_is_issued_at_once_and_recorded_once(): void
    {
        Event::fake([FeeRefunded::class]);
        $receipt = $this->onlineReceipt(3000);
        $allocationId = $receipt->details()->value('fee_student_allocation_id');
        $service = app(FeeRefundService::class);

        // Above the approval threshold too: the money has already gone back.
        $refund = $service->recordGatewayRefund($receipt, 1200, 'rfnd_TEST0001', 'razorpay');
        $again = $service->recordGatewayRefund($receipt, 1200, 'rfnd_TEST0001', 'razorpay');

        $this->assertSame($refund->id, $again->id);
        $this->assertSame(1, FeeRefund::count());
        $this->assertSame('issued', $refund->status);
        $this->assertSame('paid', $refund->payout_status);
        $this->assertSame('gateway_refund', $refund->reason_code);
        $this->assertSame('rfnd_TEST0001', $refund->reference_no);
        $this->assertSame('CN1', $refund->credit_note_no);
        $this->assertNull($refund->issued_by);
        $this->assertSame(1800.0, $service->refundableFor($receipt->fresh()));
        $this->assertSame(1200.0, FeeStudentAllocation::find($allocationId)->balance());
        $this->assertSame(['requested', 'auto_approved', 'issued'], FeeRefundDecision::query()->orderBy('id')->pluck('decision')->all());
        Event::assertDispatchedTimes(FeeRefunded::class, 1);

        // A second, different gateway refund stacks; more than is left is refused.
        $service->recordGatewayRefund($receipt, 800, 'rfnd_TEST0002', 'razorpay');
        $this->assertSame(1000.0, $service->refundableFor($receipt->fresh()));

        $this->expectException(RuntimeException::class);
        $service->recordGatewayRefund($receipt, 5000, 'rfnd_TEST0003', 'razorpay');
    }

    public function test_reverted_receipt_cannot_take_a_gateway_refund(): void
    {
        $receipt = $this->onlineReceipt(1000);
        app(FeeCollectionService::class)->revert($receipt, 'test');

        $this->expectException(RuntimeException::class);
        app(FeeRefundService::class)->recordGatewayRefund($receipt->fresh(), 100, 'rfnd_TEST0009', 'stripe');
    }
}

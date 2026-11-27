<?php

namespace Classivo\Fees\Tests\Feature;

use Classivo\Fees\Events\FeeAccrued;
use Classivo\Fees\Models\FeeFine;
use Classivo\Fees\Services\FeeAccrualService;
use Classivo\Fees\Services\FeeAllocationService;
use Classivo\Fees\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class FeeAccrualTest extends TestCase
{
    public function test_fine_evaluator(): void
    {
        $daily = ['type' => 'fixed', 'amount' => 10, 'frequency' => 'daily', 'grace_days' => 5];
        $this->assertSame(0.0, FeeFine::evaluate($daily, 1000, 5));
        $this->assertSame(50.0, FeeFine::evaluate($daily, 1000, 10));
        $this->assertSame(30.0, FeeFine::evaluate($daily, 1000, 10, holidayDays: 2));

        $percent = ['type' => 'percent', 'amount' => 2, 'frequency' => 'monthly', 'grace_days' => 0];
        $this->assertSame(40.0, FeeFine::evaluate($percent, 1000, 31));

        $this->assertSame(['type' => 'fixed', 'amount' => 5.0, 'frequency' => 'daily', 'grace_days' => 0], FeeFine::normalise('daily', null, 5, 0));
    }

    public function test_accrue_charges_the_overdue_fine_once_and_fires_event(): void
    {
        Event::fake([FeeAccrued::class]);

        $student = $this->student();
        $master = $this->master(1000, [
            'due_date' => now()->subDays(20)->toDateString(),
            'fine_type' => 'fixed',
            'fine_amount' => 100,
            'fine_frequency' => 'one_time',
            'fine_after_days' => 7,
        ]);
        $allocation = app(FeeAllocationService::class)->allocate($master, $student);

        $service = app(FeeAccrualService::class);
        $this->assertSame(100.0, $service->accrue($allocation->fresh()));
        $this->assertSame(0.0, $service->accrue($allocation->fresh()));
        $this->assertSame(1100.0, $allocation->fresh()->balance());

        Event::assertDispatchedTimes(FeeAccrued::class, 1);
        Event::assertDispatched(FeeAccrued::class, fn (FeeAccrued $e): bool => $e->delta === 100.0 && $e->studentId === $student->id);
    }

    public function test_accrue_command_is_idempotent(): void
    {
        $student = $this->student();
        $master = $this->master(1000, [
            'due_date' => now()->subDays(20)->toDateString(),
            'fine_type' => 'fixed',
            'fine_amount' => 50,
            'fine_frequency' => 'one_time',
        ]);
        $allocation = app(FeeAllocationService::class)->allocate($master, $student);

        $this->artisan('fees:accrue-fines', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0.0, (float) $allocation->fresh()->fine_amount);

        $this->artisan('fees:accrue-fines')->assertSuccessful();
        $this->artisan('fees:accrue-fines')->assertSuccessful();
        $this->assertSame(50.0, (float) $allocation->fresh()->fine_amount);
    }
}

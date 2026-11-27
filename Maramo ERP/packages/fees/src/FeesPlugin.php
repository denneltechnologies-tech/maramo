<?php

namespace Classivo\Fees;

use Classivo\Fees\Filament\Pages\AssignFees;
use Classivo\Fees\Filament\Pages\CollectFees;
use Classivo\Fees\Filament\Pages\FeeReports;
use Classivo\Fees\Filament\Pages\FeesCarryForward;
use Classivo\Fees\Filament\Pages\FeesReminder;
use Classivo\Fees\Filament\Pages\FeesSessionRollover;
use Classivo\Fees\Filament\Pages\SearchDueFees;
use Classivo\Fees\Filament\Resources\FeeDiscounts\FeeDiscountResource;
use Classivo\Fees\Filament\Resources\FeeDiscountStudents\FeeDiscountStudentResource;
use Classivo\Fees\Filament\Resources\FeeGroups\FeeGroupResource;
use Classivo\Fees\Filament\Resources\FeeMasters\FeeMasterResource;
use Classivo\Fees\Filament\Resources\FeePayments\FeePaymentResource;
use Classivo\Fees\Filament\Resources\FeeRefunds\FeeRefundResource;
use Classivo\Fees\Filament\Resources\FeeTypes\FeeTypeResource;
use Classivo\Fees\Filament\Resources\OfflinePayments\OfflinePaymentResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament plugin registering the fee screens:
 * Fee Types, Fee Groups, Fee Masters, Concessions (catalogue + register),
 * Receipts, Refunds and Offline Payments, plus the Collect Fees counter,
 * Assign Fees, Search Due Fees, the reminder board, Carry Forward, Session
 * Rollover and Fee Reports. Each screen is gated by a fees permission.
 *
 *     $panel->plugin(\Classivo\Fees\FeesPlugin::make())
 */
class FeesPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'classivo-fees';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            FeePaymentResource::class,
            FeeMasterResource::class,
            FeeDiscountResource::class,
            FeeRefundResource::class,
        ]);

        $panel->pages([
            CollectFees::class,
            SearchDueFees::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}

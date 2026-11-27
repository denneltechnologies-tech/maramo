<?php

use Classivo\Fees\Http\Controllers\FeeReceiptController;
use Classivo\Fees\Http\Controllers\OfflinePaymentProofController;
use Illuminate\Support\Facades\Route;

Route::middleware((array) config('fees.routes.middleware', ['web', 'auth']))
    ->prefix((string) config('fees.routes.prefix', 'admin/fees'))
    ->group(function (): void {
        Route::get('receipt/{payment}', [FeeReceiptController::class, 'show'])
            ->whereNumber('payment')
            ->name('fees.receipt');

        Route::get('receipt/{payment}/pdf', [FeeReceiptController::class, 'downloadApp'])
            ->whereNumber('payment')
            ->name('fees.receipt.pdf');

        Route::get('offline-payments/{offlinePayment}/proof', OfflinePaymentProofController::class)
            ->whereNumber('offlinePayment')
            ->name('fees.offline-payment.proof');
    });

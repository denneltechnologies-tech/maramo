<?php

use Classivo\Core\Http\Middleware\ResolveTenantContext;
use Classivo\ReportCards\Http\Controllers\ResultDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', ResolveTenantContext::class])->prefix('report-cards')->name('report-cards.')->group(function () {
    Route::get('exams/{exam}/students/{student}/marksheet', [ResultDocumentController::class, 'marksheet'])->whereNumber(['exam', 'student'])->name('marksheet');
    Route::get('exams/{exam}/sections/{classSection}/marksheets', [ResultDocumentController::class, 'marksheets'])->whereNumber(['exam', 'classSection'])->name('marksheets');
    Route::get('exams/{exam}/sections/{classSection}/tabulation', [ResultDocumentController::class, 'tabulation'])->whereNumber(['exam', 'classSection'])->name('tabulation');
});

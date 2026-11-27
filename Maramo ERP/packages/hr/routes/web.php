<?php

use Classivo\Core\Http\Middleware\ResolveTenantContext;
use Classivo\Hr\Http\Controllers\PayrollPdfController;
use Classivo\Hr\Http\Controllers\StaffDocumentController;
use Illuminate\Support\Facades\Route;

// Payslip PDF. Deliberately NOT behind a back-office-only middleware: a staff
// member legitimately fetches their OWN payslip here. The gate is inside the
// controller: the `payroll.view` ability for payroll staff, ownership for self.
Route::get(trim((string) config('hr.route_prefix', 'admin'), '/').'/hr/staff-documents/{document}', [StaffDocumentController::class, 'download'])
    ->middleware(['web', 'auth', ResolveTenantContext::class])
    ->whereNumber('document')
    ->name('hr.staff-documents.download');

Route::get(trim((string) config('hr.route_prefix', 'admin'), '/').'/payroll/{payroll}/payslip', [PayrollPdfController::class, 'payslip'])
    ->middleware(['web', 'auth', ResolveTenantContext::class])
    ->whereNumber('payroll')
    ->name('payroll.payslip');

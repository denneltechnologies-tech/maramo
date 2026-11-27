<?php

namespace Classivo\Hr\Http\Controllers;

use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Hr\Models\Payroll;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Support\Filename;
use Classivo\Hr\Support\HrPermissions;
use Classivo\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payroll PDF output (spec §15 "Payslip") rendered with barryvdh/laravel-dompdf from
 * the Blade template resources/views/pdf/payslip.blade.php. `?download=1` forces a
 * download; otherwise the PDF streams inline (for Print).
 *
 * Access (see {@see payslip()}): the `payroll.view` ability for staff, OR the staff
 * member the payroll belongs to fetching their own. The route carries no
 * EnsureBackOfficeUser precisely because of that second path.
 */
class PayrollPdfController extends Controller
{
    public function payslip(Request $request, int $payroll): Response
    {
        $user = $request->user();

        abort_if($user === null, 403, __('hr::messages.payroll.forbidden_payslip'));

        // Resolved BY HAND, not by implicit route-model binding: Laravel's
        // middleware priority pins SubstituteBindings immediately behind `auth`,
        // so it runs BEFORE the route's ResolveTenantContext and a bound
        // {payroll} was still looked up with the tenant context unresolved —
        // TenantScope::apply() returns early there, adding NO school filter, so
        // incrementing the id in the URL walked into other schools' payrolls.
        // Same shape as FeeReceiptController::show(). The school_id predicate is
        // ALSO spelled out rather than left to the global scope, so the query
        // still fails closed if the middleware is ever dropped from the route.
        $payroll = Payroll::query()
            ->where('school_id', current_school_id())
            ->findOrFail($payroll);

        // Staff access is an ABILITY, not "holds any non-dedicated-panel role":
        // isBackOfficeUser() is true for a receptionist, a librarian and an
        // admissions clerk, none of whom have any business reading basic pay,
        // allowances, deductions and net salary. `payroll.view` is granted to the
        // roles that actually run payroll (Admin / Accountant / HR Manager in
        // RoleSeeder); Super Admin passes through the Gate::before bypass.
        //
        // The ownership branch is unchanged and stays FIRST: a staff member with
        // only a dedicated-panel role (a Teacher, via the teacher panel's
        // "My Payslips" page) holds no payroll ability at all and must still
        // reach their OWN payslip (plan §10).
        $isOwnPayslip = $payroll->staff?->user_id !== null
            && (int) $payroll->staff->user_id === (int) $user->id;

        abort_unless(
            $isOwnPayslip || HrPermissions::can(HrPermissions::PAYSLIP_DOWNLOAD),
            403,
            __('hr::messages.payroll.forbidden_payslip'),
        );

        $payroll->load(['staff.designation', 'staff.department', 'details']);

        // Issuing a payslip FREEZES it: once the employee is holding a figure, a
        // later regeneration that changed it would produce two documents claiming
        // to be the same month. Gated on ?download=1 so an admin PREVIEWING a
        // draft does not lock it, while every real handover — the download
        // button, the API's download_url, the teacher portal — does. Idempotent.
        if ($request->boolean('download')) {
            app(PayrollService::class)->markIssued($payroll, $user->id);
        }

        // Hindi (and other Devanagari locales) need the bundled font; English keeps DejaVu Sans.
        $devanagari = in_array(app()->getLocale(), (array) config('hr.pdf_devanagari_locales', ['hi']), true);
        $fontDir = (string) realpath(__DIR__.'/../../../resources/fonts');
        $fontCache = storage_path('fonts');
        if (! is_dir($fontCache)) {
            @mkdir($fontCache, 0775, true);
        }

        $html = view('hr::pdf.payslip', [
            'payroll' => $payroll,
            'staff' => $payroll->staff,
            'allowances' => $payroll->details->where('type', 'allowance'),
            'deductions' => $payroll->details->where('type', 'deduction'),
            'school' => School::find($payroll->school_id),
            'branch' => Branch::withoutGlobalScopes()->find($payroll->branch_id),
            'devanagari' => $devanagari,
            'fontDir' => $fontDir,
        ])->render();
        $bytes = app(PdfRenderer::class)->render($html, ['paper' => 'a4', 'dompdf' => [
            // The bundled Noto Sans Devanagari lives inside the package; dompdf only
            // reads fonts under its chroot and caches them in a writable font dir.
            'chroot' => array_values(array_filter([base_path(), $fontDir])),
            'fontDir' => $fontCache,
            'fontCache' => $fontCache,
        ]]);

        $filename = 'Payslip-'.Filename::safe((string) $payroll->staff?->employee_id).'-'.Filename::safe((string) $payroll->period_key).'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$filename.'"',
        ]);
    }
}

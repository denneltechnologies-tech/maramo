<?php

namespace Classivo\Fees\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Services\FeeStatementService;
use Classivo\Fees\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fee receipt PDFs (dompdf).
 *
 *   GET {prefix}/receipt/{payment}       — counter receipt, ?size=a4|a5|thermal, ?download=1
 *   GET {prefix}/receipt/{payment}/pdf   — statement-style A4 receipt, any session
 *
 * Who may open a receipt is the `view-fee-receipt` gate (see FeesServiceProvider):
 * by default, staff holding `fees.collect`, or the student the receipt belongs
 * to. A host app can redefine the gate for guardians.
 */
class FeeReceiptController extends Controller
{
    public function show(Request $request, int $payment): Response
    {
        $payment = FeePayment::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('school_id', current_school_id())
            ->findOrFail($payment);

        abort_unless(Gate::allows('view-fee-receipt', $payment), 403, 'You may not access this receipt.');

        $payment->load(['student', 'details.feeType', 'details.allocation.feeMaster', 'collector']);

        $size = in_array($request->query('size'), ['a4', 'a5', 'thermal'], true)
            ? $request->query('size')
            : 'a4';

        $branch = Branch::withoutGlobalScopes()->find($payment->branch_id);

        $pdf = Pdf::loadView('classivo-fees::pdf.fee-receipt', [
            'payment' => $payment,
            'student' => $payment->student,
            'school' => School::find($payment->school_id),
            'branch' => $branch,
            'size' => $size,
            'currencySymbol' => Money::symbol($branch),
        ]);

        if ($size === 'thermal') {
            $pdf->setPaper([0, 0, 226.77, 650]);
        } else {
            $pdf->setPaper($size);
        }

        $filename = 'Receipt-'.self::safe($payment->receipt_no).'.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }

    public function downloadApp(Request $request, int $payment, FeeStatementService $statement): Response
    {
        $record = $statement->findReceipt($payment);

        abort_if($record === null, 404, 'Receipt not found.');
        abort_if((int) $record->school_id !== current_school_id(), 404, 'Receipt not found.');
        abort_unless(Gate::allows('view-fee-receipt', $record), 403, 'You may not access this receipt.');

        $pdf = $statement->receiptPdf($record);
        $filename = 'receipt-'.self::safe($record->receipt_no).'.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }

    private static function safe(?string $value): string
    {
        $slug = Str::slug((string) $value);

        return $slug !== '' ? $slug : 'receipt';
    }
}

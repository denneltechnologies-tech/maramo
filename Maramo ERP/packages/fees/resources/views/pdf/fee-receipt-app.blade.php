@php
    /**
     * Mobile-app fee receipt (GET /api/v1/fees/receipt/{id}/pdf) — A4 dompdf sheet.
     * Inputs: $payment (with details.feeType, collector), $student, $school, $branch,
     * $sessionName, $currencySymbol, $amountInWords (nullable).
     */
    /** @var \Classivo\Fees\Models\FeePayment $payment */
    $fmt = fn ($v) => ($currencySymbol ?? '₹').' '.number_format((float) $v, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt — {{ $payment->receipt_no }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1e293b; margin: 0; padding: 26px 32px; font-size: 12px; }
        .wrap { border: 2px solid #334155; padding: 20px 24px; }
        .header { text-align: center; border-bottom: 2px solid #334155; padding-bottom: 10px; margin-bottom: 12px; }
        .school { font-size: 20px; font-weight: bold; }
        .branch { font-size: 11px; color: #475569; margin-top: 2px; }
        .title { text-align: center; font-size: 14px; font-weight: bold; text-transform: uppercase;
                 letter-spacing: 2px; margin: 8px 0 12px; }
        table.meta { width: 100%; margin-bottom: 12px; font-size: 12px; }
        table.meta td { padding: 2px 0; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 12px; }
        table.lines th, table.lines td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        table.lines th { background: #f1f5f9; }
        table.lines td.num, table.lines th.num { text-align: right; }
        table.lines tfoot td { font-weight: bold; background: #f8fafc; }
        .words { font-size: 11px; color: #334155; margin: 0 0 10px; }
        .reverted { color: #b91c1c; font-weight: bold; border: 1px dashed #b91c1c; padding: 4px 8px;
                    text-align: center; margin-bottom: 10px; }
        .foot { margin-top: 40px; width: 100%; font-size: 11px; }
        .foot td { vertical-align: bottom; }
        .sign { border-top: 1px solid #334155; padding-top: 4px; text-align: center; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <div class="school">{{ $school?->name ?? config('app.name') }}</div>
        <div class="branch">
            {{ $branch?->name }}@if($branch?->address) — {{ $branch->address }}@endif
        </div>
    </div>

    <div class="title">Fee Receipt</div>

    @if($payment->status === 'reverted')
        <div class="reverted">REVERTED @if($payment->revert_reason) — {{ $payment->revert_reason }} @endif</div>
    @endif

    <table class="meta">
        <tr>
            <td><strong>Receipt No:</strong> {{ $payment->receipt_no }}</td>
            <td style="text-align:right"><strong>Date:</strong> {{ $payment->payment_date?->format('d M Y') }}</td>
        </tr>
        <tr>
            <td><strong>Student:</strong> {{ $student?->full_name }}</td>
            <td style="text-align:right"><strong>Admission No:</strong> {{ $student?->admission_no }}</td>
        </tr>
        <tr>
            <td><strong>Class-Section:</strong> {{ $student?->current_class_section?->name ?? $student?->class_name ?? '—' }}</td>
            <td style="text-align:right"><strong>Session:</strong> {{ $sessionName ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Mode:</strong> {{ \Classivo\Fees\Models\FeePayment::MODES[$payment->mode] ?? ucfirst($payment->mode) }}</td>
            <td style="text-align:right"><strong>Reference:</strong> {{ $payment->reference_no ?: '—' }}</td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>#</th>
                <th>Fee head</th>
                <th class="num">Discount</th>
                <th class="num">Fine</th>
                <th class="num">Paid</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payment->details as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line->feeType?->name ?? $line->label ?? 'Fee' }}</td>
                    <td class="num">{{ $fmt($line->discount_amount) }}</td>
                    <td class="num">{{ $fmt($line->fine_amount) }}</td>
                    <td class="num">{{ $fmt($line->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total</td>
                <td class="num">{{ $fmt($payment->discount_amount) }}</td>
                <td class="num">{{ $fmt($payment->fine_amount) }}</td>
                <td class="num">{{ $fmt($payment->paid_amount) }}</td>
            </tr>
        </tfoot>
    </table>

    @if(! empty($amountInWords))
        <p class="words"><strong>Amount in words:</strong> {{ $amountInWords }}</p>
    @endif

    @if($payment->note)
        <p class="muted" style="margin: 0 0 8px">Note: {{ $payment->note }}</p>
    @endif

    <table class="foot">
        <tr>
            <td>Collected by: {{ $payment->collector?->name ?? '—' }}</td>
            <td style="text-align:right; width:45%"><div class="sign">Authorised Signature</div></td>
        </tr>
    </table>
    <p class="muted" style="text-align:center; margin-top:10px">This is a computer-generated receipt and does not require a signature.</p>
</div>
</body>
</html>

@php
    /** @var \Classivo\Fees\Models\FeePayment $payment */
    $thermal = ($size ?? 'a4') === 'thermal';
    $fmt = fn ($v) => ($currencySymbol ?? '₹').' '.number_format((float) $v, 2);
    $summary = $student !== null ? \Classivo\Fees\Support\FeeDues::summary((int) $student->getKey()) : ['total' => 0, 'paid' => 0, 'discount' => 0, 'fine' => 0, 'balance' => 0];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt — {{ $payment->receipt_no }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1e293b; margin: 0; padding: {{ $thermal ? '8px 10px' : '26px 32px' }};
               font-size: {{ $thermal ? '10px' : '12px' }}; }
        .wrap { @if(! $thermal) border: 2px solid #334155; padding: 18px 22px; @endif }
        .header { text-align: center; border-bottom: 2px solid #334155; padding-bottom: 8px; margin-bottom: 10px; }
        .school { font-size: {{ $thermal ? '13px' : '20px' }}; font-weight: bold; }
        .branch { font-size: {{ $thermal ? '9px' : '11px' }}; color: #475569; margin-top: 2px; }
        .title { text-align: center; font-size: {{ $thermal ? '11px' : '14px' }}; font-weight: bold;
                 text-transform: uppercase; letter-spacing: 1px; margin: 6px 0 10px; }
        table.meta { width: 100%; margin-bottom: 10px; font-size: {{ $thermal ? '9px' : '12px' }}; }
        table.meta td { padding: 1px 0; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: {{ $thermal ? '9px' : '12px' }}; }
        table.lines th, table.lines td { border: 1px solid #cbd5e1; padding: {{ $thermal ? '3px 4px' : '6px 8px' }}; text-align: left; }
        table.lines th { background: #f1f5f9; }
        table.lines td.num, table.lines th.num { text-align: right; }
        .summary { width: 100%; border-collapse: collapse; font-size: {{ $thermal ? '9px' : '12px' }}; }
        .summary td { padding: 3px 6px; }
        .summary td.label { color: #475569; }
        .summary td.val { text-align: right; font-weight: bold; }
        .grand { font-size: {{ $thermal ? '11px' : '14px' }}; }
        .reverted { color: #b91c1c; font-weight: bold; border: 1px dashed #b91c1c; padding: 3px 6px; text-align: center; margin-bottom: 8px; }
        .foot { margin-top: {{ $thermal ? '18px' : '38px' }}; width: 100%; font-size: {{ $thermal ? '9px' : '11px' }}; }
        .foot td { vertical-align: bottom; }
        .sign { border-top: 1px solid #334155; padding-top: 3px; text-align: center; }
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
            <td style="text-align:right"><strong>Mode:</strong> {{ \Classivo\Fees\Models\FeePayment::MODES[$payment->mode] ?? ucfirst($payment->mode) }}</td>
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
    </table>

    <table class="summary">
        <tr><td class="label">Discount</td><td class="val">{{ $fmt($payment->discount_amount) }}</td></tr>
        <tr><td class="label">Fine</td><td class="val">{{ $fmt($payment->fine_amount) }}</td></tr>
        <tr class="grand"><td class="label">Total Paid</td><td class="val">{{ $fmt($payment->paid_amount) }}</td></tr>
        <tr><td class="label">Outstanding balance (all fees)</td><td class="val">{{ $fmt($summary['balance']) }}</td></tr>
    </table>

    @if($payment->note)
        <p class="muted" style="margin-top:8px">Note: {{ $payment->note }}</p>
    @endif

    <table class="foot">
        <tr>
            <td>Collected by: {{ $payment->collector?->name ?? '—' }}</td>
            <td style="text-align:right; width:45%"><div class="sign">Authorised Signature</div></td>
        </tr>
    </table>
    <p class="muted" style="text-align:center; margin-top:10px">This is a computer-generated receipt.</p>
</div>
</body>
</html>

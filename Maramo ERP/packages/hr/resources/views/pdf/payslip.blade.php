<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $staff?->employee_id }} {{ $payroll->period_label }}</title>
    <style>
@if (! empty($devanagari))
        @font-face { font-family: 'Noto Sans Devanagari'; font-weight: normal; src: url('file://{{ $fontDir }}/NotoSansDevanagari-Regular.ttf') format('truetype'); }
        @font-face { font-family: 'Noto Sans Devanagari'; font-weight: bold; src: url('file://{{ $fontDir }}/NotoSansDevanagari-Bold.ttf') format('truetype'); }
        * { font-family: 'Noto Sans Devanagari', DejaVu Sans, sans-serif; }
        .title, .school { letter-spacing: 0 !important; }
        @else
        * { font-family: DejaVu Sans, sans-serif; }
        @endif
        body { color: #1e293b; font-size: 12px; margin: 0; padding: 28px 34px; }
        .sheet { border: 2px solid #334155; padding: 22px 26px; }
        .header { text-align: center; border-bottom: 2px solid #334155; padding-bottom: 12px; margin-bottom: 14px; }
        .school { font-size: 22px; font-weight: bold; letter-spacing: 0.5px; }
        .branch { font-size: 12px; color: #475569; margin-top: 3px; }
        .title { text-align: center; font-size: 15px; font-weight: bold; text-transform: uppercase;
                 letter-spacing: 2px; margin: 10px 0 18px; text-decoration: underline; }
        table.meta { width: 100%; margin-bottom: 14px; }
        table.meta td { padding: 3px 0; font-size: 12px; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.lines th, table.lines td { padding: 6px 8px; border: 1px solid #cbd5e1; }
        table.lines th { background: #f1f5f9; text-align: left; }
        table.lines td.amt { text-align: right; }
        .totals td { padding: 5px 8px; font-size: 12px; }
        .totals td.amt { text-align: right; }
        .net { font-size: 14px; font-weight: bold; border-top: 2px solid #334155; }
        .footer { margin-top: 46px; width: 100%; }
        .footer td { font-size: 12px; vertical-align: bottom; }
        .sign { border-top: 1px solid #334155; padding-top: 4px; text-align: center; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="header">
        <div class="school">{{ $school?->name ?? config('app.name') }}</div>
        <div class="branch">
            {{ $branch?->name }}@if($branch?->address) — {{ $branch->address }}@endif
        </div>
    </div>

    <div class="title">{{ __('hr::views.payslip.title', ['period' => $payroll->period_label]) }}</div>

    <table class="meta">
        <tr>
            <td><strong>{{ __('hr::views.payslip.staff_id') }}</strong> {{ $staff?->employee_id }}</td>
            <td style="text-align: right;"><strong>{{ __('hr::views.payslip.name') }}</strong> {{ $staff?->full_name }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('hr::views.payslip.designation') }}</strong> {{ $staff?->designation?->name ?? '—' }}</td>
            <td style="text-align: right;"><strong>{{ __('hr::views.payslip.department') }}</strong> {{ $staff?->department?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('hr::views.payslip.status') }}</strong> {{ \Classivo\Hr\Support\HrLabels::enum(\Classivo\Hr\Models\Payroll::STATUSES, 'payroll.statuses')[$payroll->status] ?? ucfirst($payroll->status) }}</td>
            <td style="text-align: right;"><strong>{{ __('hr::views.payslip.payment_date') }}</strong> {{ optional($payroll->payment_date)->format('d M Y') ?? '—' }}</td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('hr::views.payslip.earnings') }}</th>
                <th style="text-align: right;">{{ __('hr::views.payslip.amount') }}</th>
                <th>{{ __('hr::views.payslip.deductions') }}</th>
                <th style="text-align: right;">{{ __('hr::views.payslip.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ __('hr::views.payslip.basic_salary') }}</td>
                <td class="amt">{{ number_format((float) $payroll->basic_salary, 2) }}</td>
                <td>{{ $deductions->first()?->name ?? '—' }}</td>
                <td class="amt">{{ $deductions->first() ? number_format((float) $deductions->first()->amount, 2) : '—' }}</td>
            </tr>
            @php
                $earnRows = $allowances->values();
                $dedRows = $deductions->values()->slice(1)->values();
                $max = max($earnRows->count(), $dedRows->count());
            @endphp
            @for ($i = 0; $i < $max; $i++)
                <tr>
                    <td>{{ $earnRows[$i]->name ?? '' }}</td>
                    <td class="amt">{{ isset($earnRows[$i]) ? number_format((float) $earnRows[$i]->amount, 2) : '' }}</td>
                    <td>{{ $dedRows[$i]->name ?? '' }}</td>
                    <td class="amt">{{ isset($dedRows[$i]) ? number_format((float) $dedRows[$i]->amount, 2) : '' }}</td>
                </tr>
            @endfor
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>{{ __('hr::views.payslip.gross') }}</td>
            <td class="amt">{{ number_format((float) $payroll->gross, 2) }}</td>
            <td>{{ __('hr::views.payslip.total_deductions') }}</td>
            <td class="amt">{{ number_format((float) $payroll->deduction_total, 2) }}</td>
        </tr>
        <tr>
            <td>{{ __('hr::views.payslip.tax') }}</td>
            <td class="amt">{{ number_format((float) $payroll->tax, 2) }}</td>
            <td class="net">{{ __('hr::views.payslip.net') }}</td>
            <td class="amt net">{{ number_format((float) $payroll->net_salary, 2) }}</td>
        </tr>
    </table>

    <table class="footer">
        <tr>
            <td class="muted">{{ __('hr::views.payslip.generated_on', ['date' => now()->format('d M Y H:i')]) }}</td>
            <td width="40%" class="sign">{{ __('hr::views.payslip.signatory') }}</td>
        </tr>
    </table>
</div>
</body>
</html>

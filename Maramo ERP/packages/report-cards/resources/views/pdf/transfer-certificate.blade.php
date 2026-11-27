@php $t = fn ($k) => __('report-cards::messages.'.$k); $d = fn ($v) => $v ?? '-'; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $t('tc_title') }} {{ $tc->tcNumber }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1e293b; font-size: 12px; margin: 0; padding: 28px 34px; }
        .sheet { border: 2px solid #334155; padding: 22px 26px; }
        .header { text-align: center; border-bottom: 2px solid #334155; padding-bottom: 12px; margin-bottom: 14px; }
        .school { font-size: 22px; font-weight: bold; letter-spacing: 0.5px; }
        .branch { font-size: 12px; color: #475569; margin-top: 3px; }
        .title { text-align: center; font-size: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; margin: 10px 0 18px; text-decoration: underline; }
        .meta { width: 100%; margin-bottom: 14px; }
        .meta td { padding: 2px 0; font-size: 12px; }
        table.details { width: 100%; border-collapse: collapse; }
        table.details td { padding: 7px 8px; border: 1px solid #cbd5e1; vertical-align: top; }
        table.details td.label { width: 38%; font-weight: bold; background: #f1f5f9; }
        .remarks { margin-top: 16px; }
        .remarks .box { border: 1px solid #cbd5e1; min-height: 42px; padding: 8px; margin-top: 4px; }
        .footer { margin-top: 46px; width: 100%; }
        .footer td { font-size: 12px; vertical-align: bottom; }
        .sign { border-top: 1px solid #334155; padding-top: 4px; text-align: center; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="header">
        <div class="school">{{ $institution->name }}</div>
        <div class="branch">
            {{ $institution->branch }}@if($institution->address) - {{ $institution->address }}@endif
            @if($institution->phone) | {{ $institution->phone }}@endif
        </div>
    </div>
    <div class="title">{{ $t('tc_title') }}</div>
    <table class="meta">
        <tr>
            <td><strong>{{ $t('tc_no') }}:</strong> {{ $tc->tcNumber }}</td>
            <td style="text-align:right;"><strong>{{ $t('admission_no') }}:</strong> {{ $d($student->admissionNo) }}</td>
        </tr>
        <tr>
            <td><strong>{{ $t('date_of_issue') }}:</strong> {{ $d($tc->issuedOn) }}</td>
            <td style="text-align:right;"><strong>{{ $t('date_of_leaving') }}:</strong> {{ $d($tc->dateOfLeaving) }}</td>
        </tr>
    </table>
    <table class="details">
        <tr><td class="label">{{ $t('student_name') }}</td><td>{{ $student->name }}</td></tr>
        <tr><td class="label">{{ $t('father') }}</td><td>{{ $d($student->fatherName) }}</td></tr>
        <tr><td class="label">{{ $t('dob') }}</td><td>{{ $d($student->dateOfBirth) }}</td></tr>
        <tr><td class="label">{{ $t('last_class') }}</td><td>{{ $d($tc->lastClassAttended ?? $student->className) }}</td></tr>
        <tr><td class="label">{{ $t('reason_leaving') }}</td><td>{{ $d($tc->reasonForLeaving) }}</td></tr>
        <tr><td class="label">{{ $t('conduct') }}</td><td>{{ $d($tc->conduct) }}</td></tr>
        <tr><td class="label">{{ $t('working_days') }}</td><td>{{ $d($tc->totalWorkingDays) }} / {{ $d($tc->daysPresent) }}</td></tr>
        <tr><td class="label">{{ $t('promotion') }}</td><td>{{ $d($tc->promotionStatus) }}</td></tr>
        <tr><td class="label">{{ $t('dues_cleared') }}</td><td>{{ $tc->duesCleared ? $t('yes') : $t('no') }}</td></tr>
    </table>
    <div class="remarks">
        <strong>{{ $t('remarks') }}:</strong>
        <div class="box">{{ $tc->remarks }}</div>
    </div>
    <table class="footer">
        <tr>
            <td class="muted">{{ $t('issued_by') }}: {{ $d($tc->issuedBy) }}<br>{{ $t('generated_on') }} {{ $generatedOn }}</td>
            <td width="40%" class="sign">{{ $t('head') }}</td>
        </tr>
    </table>
</div>
</body>
</html>

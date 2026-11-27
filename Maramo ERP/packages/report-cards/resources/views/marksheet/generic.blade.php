@php use Classivo\ReportCards\Support\Css; $t = fn ($k) => __('report-cards::messages.'.$k); @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $t('marksheet') }} - {{ $student->name }} - {{ $data->examName }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1e293b; font-size: 12px; margin: 0; padding: 26px 32px; }
        .sheet { border: 2px solid #334155; padding: 20px 24px; }
        .header { text-align: center; border-bottom: 2px solid #334155; padding-bottom: 10px; margin-bottom: 12px; }
        .school { font-size: 21px; font-weight: bold; }
        .branch { font-size: 11px; color: #475569; margin-top: 3px; }
        .title { text-align: center; font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: 1.5px; margin: 8px 0 14px; text-decoration: underline; }
        table.meta { width: 100%; margin-bottom: 12px; }
        table.meta td { padding: 2px 0; font-size: 12px; }
        table.marks { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.marks th, table.marks td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        table.marks th { background: #f1f5f9; }
        table.marks td.num { text-align: right; }
        .summary { width: 100%; border-collapse: collapse; }
        .summary td { border: 1px solid #cbd5e1; padding: 7px 8px; }
        .summary td.label { font-weight: bold; background: #f1f5f9; width: 22%; }
        .pass { color: #15803d; font-weight: bold; }
        .fail { color: #b91c1c; font-weight: bold; }
        .footer { margin-top: 40px; width: 100%; }
        .footer td { font-size: 12px; vertical-align: bottom; }
        .sign { border-top: 1px solid #334155; padding-top: 4px; text-align: center; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="header">
        <div class="school">{{ $institution->name }}</div>
        <div class="branch">{{ $institution->branch }}@if($institution->address) - {{ $institution->address }}@endif</div>
    </div>

    <div class="title">{{ $t('marksheet') }} - {{ $data->examName }}</div>

    <table class="meta">
        <tr>
            <td><strong>{{ $t('name') }}:</strong> {{ $student->name }}</td>
            <td style="text-align:right;"><strong>{{ $t('admission_no') }}:</strong> {{ $student->admissionNo ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>{{ $t('class_section') }}:</strong> {{ $student->className ?? '-' }}</td>
            <td style="text-align:right;"><strong>{{ $t('roll_no') }}:</strong> {{ $student->rollNo ?? '-' }}</td>
        </tr>
    </table>

    <table class="marks">
        <thead>
            <tr>
                <th>#</th><th>{{ $t('subject') }}</th>
                <th class="num">{{ $t('full_marks') }}</th><th class="num">{{ $t('pass_marks') }}</th>
                <th class="num">{{ $t('obtained') }}</th><th>{{ $t('grade') }}</th><th>{{ $t('result') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data->subjects as $i => $s)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $s->subject }}</td>
                    <td class="num">{{ Css::num($s->fullMarks) }}</td>
                    <td class="num">{{ Css::num($s->passMarks) }}</td>
                    <td class="num">{{ $s->absent ? $t('absent') : Css::num((float) $s->obtained) }}</td>
                    <td>{{ $s->grade ?? '-' }}</td>
                    <td class="{{ $s->passed() ? 'pass' : 'fail' }}">{{ $s->passed() ? $t('pass') : $t('fail') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted" style="text-align:center;">{{ $t('no_marks') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td class="label">{{ $t('total') }}</td>
            <td>{{ Css::num((float) $data->total) }} / {{ Css::num((float) $data->totalFull) }}</td>
            <td class="label">{{ $t('percentage') }}</td>
            <td>{{ $data->percentage !== null ? Css::num($data->percentage).'%' : '-' }}</td>
        </tr>
        <tr>
            <td class="label">{{ $t('grade') }}</td><td>{{ $data->grade ?? '-' }}</td>
            <td class="label">{{ $t('division') }}</td><td>{{ $data->division ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">{{ $t('rank') }}</td><td>{{ $data->rank ?? '-' }}</td>
            <td class="label">{{ $t('result') }}</td>
            <td class="{{ $data->result === 'pass' ? 'pass' : 'fail' }}">{{ strtoupper((string) $data->result) }}</td>
        </tr>
    </table>

    <table class="footer">
        <tr>
            <td class="muted">{{ $t('generated_on') }} {{ $generatedOn }}</td>
            <td width="35%" class="sign">{{ $t('class_teacher') }}</td>
            <td width="35%" class="sign">{{ $t('principal') }}</td>
        </tr>
    </table>
</div>
</body>
</html>

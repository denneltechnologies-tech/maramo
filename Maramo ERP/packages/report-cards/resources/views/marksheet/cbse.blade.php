@php use Classivo\ReportCards\Support\Css; $t = fn ($k) => __('report-cards::messages.'.$k); @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $t('report_card') }} - {{ $student->name }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #111827; font-size: 11px; margin: 0; padding: 22px 28px; }
        .sheet { border: 3px double #1e3a8a; padding: 16px 20px; }
        .header { text-align: center; margin-bottom: 8px; }
        .header img { height: 54px; }
        .school { font-size: 20px; font-weight: bold; color: #1e3a8a; }
        .sub { font-size: 10px; color: #475569; margin-top: 2px; }
        .band { background: #1e3a8a; color: #fff; text-align: center; font-weight: bold; letter-spacing: 1px; padding: 5px; margin: 8px 0 10px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        table.meta td { padding: 3px 4px; border: 1px solid #94a3b8; }
        table.meta td.k { background: #e2e8f0; font-weight: bold; width: 18%; }
        h4 { margin: 12px 0 4px; color: #1e3a8a; }
        table.marks th, table.marks td { border: 1px solid #94a3b8; padding: 5px 6px; }
        table.marks th { background: #dbeafe; text-align: center; }
        table.marks td.c { text-align: center; }
        .pass { color: #15803d; font-weight: bold; }
        .fail { color: #b91c1c; font-weight: bold; }
        .grid td { width: 50%; vertical-align: top; }
        .box { border: 1px solid #94a3b8; padding: 6px 8px; min-height: 34px; }
        .legend { font-size: 9px; color: #475569; margin-top: 6px; }
        .sign-row { margin-top: 36px; }
        .sign-row td { width: 33%; text-align: center; vertical-align: bottom; }
        .sign { border-top: 1px solid #111827; padding-top: 3px; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="header">
        @if($institution->logo)<img src="{{ $institution->logo }}" alt="">@endif
        <div class="school">{{ $institution->name }}</div>
        <div class="sub">
            {{ $institution->branch }}@if($institution->address) | {{ $institution->address }}@endif
            @if($institution->phone) | {{ $institution->phone }}@endif
        </div>
        @if($institution->affiliation)<div class="sub">{{ $institution->affiliation }}</div>@endif
    </div>

    <div class="band">{{ $t('report_card') }} - {{ $data->examName }}@if($student->session) ({{ $student->session }})@endif</div>

    <table class="meta">
        <tr>
            <td class="k">{{ $t('name') }}</td><td>{{ $student->name }}</td>
            <td class="k">{{ $t('class_section') }}</td><td>{{ $student->className ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">{{ $t('admission_no') }}</td><td>{{ $student->admissionNo ?? '-' }}</td>
            <td class="k">{{ $t('roll_no') }}</td><td>{{ $student->rollNo ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">{{ $t('dob') }}</td><td>{{ $student->dateOfBirth ?? '-' }}</td>
            <td class="k">{{ $t('father') }}</td><td>{{ $student->fatherName ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">{{ $t('mother') }}</td><td colspan="3">{{ $student->motherName ?? '-' }}</td>
        </tr>
    </table>

    <h4>{{ $t('scholastic') }}</h4>
    <table class="marks">
        <thead>
            <tr>
                <th style="width:5%">#</th><th style="text-align:left">{{ $t('subject') }}</th>
                <th>{{ $t('full_marks') }}</th><th>{{ $t('obtained') }}</th>
                <th>{{ $t('grade_points') }}</th><th>{{ $t('grade') }}</th><th>{{ $t('result') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data->subjects as $i => $s)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $s->subject }}</td>
                    <td class="c">{{ Css::num($s->fullMarks) }}</td>
                    <td class="c">{{ $s->absent ? $t('absent') : Css::num((float) $s->obtained) }}</td>
                    <td class="c">{{ $s->gradePoints !== null ? Css::num($s->gradePoints) : '-' }}</td>
                    <td class="c">{{ $s->grade ?? '-' }}</td>
                    <td class="c {{ $s->passed() ? 'pass' : 'fail' }}">{{ $s->passed() ? $t('pass') : $t('fail') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="c">{{ $t('no_marks') }}</td></tr>
            @endforelse
            <tr>
                <td colspan="2" style="text-align:right;font-weight:bold">{{ $t('total') }}</td>
                <td class="c"><strong>{{ Css::num((float) $data->totalFull) }}</strong></td>
                <td class="c"><strong>{{ Css::num((float) $data->total) }}</strong></td>
                <td class="c" colspan="3"><strong>{{ $data->percentage !== null ? Css::num($data->percentage).'%' : '-' }}@if($data->grade) | {{ $t('grade') }} {{ $data->grade }}@endif</strong></td>
            </tr>
        </tbody>
    </table>

    @if(count($data->coScholastic))
        <h4>Co-Scholastic Areas</h4>
        <table class="marks">
            <thead><tr><th style="text-align:left">Area</th><th style="width:20%">{{ $t('grade') }}</th></tr></thead>
            <tbody>
                @foreach($data->coScholastic as $area => $g)
                    <tr><td>{{ $area }}</td><td class="c">{{ $g }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="grid" style="margin-top:10px">
        <tr>
            <td style="padding-right:5px">
                <div class="box"><strong>{{ $t('attendance') }}:</strong>
                    {{ $data->daysPresent ?? '-' }} / {{ $data->workingDays ?? '-' }}</div>
            </td>
            <td style="padding-left:5px">
                <div class="box">
                    <strong>{{ $t('result') }}:</strong>
                    <span class="{{ $data->result === 'pass' ? 'pass' : 'fail' }}">{{ strtoupper((string) $data->result) }}</span>
                    @if($data->rank) &nbsp; <strong>{{ $t('rank') }}:</strong> {{ $data->rank }}@endif
                </div>
            </td>
        </tr>
    </table>
    <div class="box" style="margin-top:8px"><strong>{{ $t('remarks') }}:</strong> {{ $data->remarks }}</div>

    <table class="sign-row">
        <tr>
            <td><div class="sign">{{ $t('class_teacher') }}</div></td>
            <td style="font-size:9px;color:#64748b">{{ $t('generated_on') }} {{ $generatedOn }}</td>
            <td><div class="sign">{{ $t('principal') }}</div></td>
        </tr>
    </table>
</div>
</body>
</html>

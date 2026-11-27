@php $t = fn ($k) => __('report-cards::messages.'.$k); @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1e293b; font-size: 10px; margin: 0; padding: 20px 24px; }
        h2 { text-align: center; margin: 0; font-size: 16px; }
        .sub { text-align: center; color: #475569; margin: 3px 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 5px; text-align: center; }
        th { background: #f1f5f9; }
        td.l { text-align: left; }
        .pass { color: #15803d; font-weight: bold; }
        .fail { color: #b91c1c; font-weight: bold; }
    </style>
</head>
<body>
    <h2>{{ $institution->name }}</h2>
    <div class="sub">{{ $t('tabulation') }} - {{ $examName }} - {{ $className }}</div>
    <table>
        <thead>
            <tr>
                <th>{{ $t('roll_no') }}</th><th>{{ $t('name') }}</th>
                @foreach($subjects as $s)<th>{{ $s }}</th>@endforeach
                <th>{{ $t('total') }}</th><th>{{ $t('percentage') }}</th><th>{{ $t('rank') }}</th><th>{{ $t('result') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $r)
                <tr>
                    <td>{{ $r['roll'] ?? '-' }}</td><td class="l">{{ $r['name'] }}</td>
                    @foreach($subjects as $s)<td>{{ $r['marks'][$s] ?? '-' }}</td>@endforeach
                    <td>{{ $r['total'] }}</td><td>{{ $r['percentage'] }}</td><td>{{ $r['rank'] ?? '-' }}</td>
                    <td class="{{ $r['result'] === 'pass' ? 'pass' : 'fail' }}">{{ strtoupper((string) $r['result']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>

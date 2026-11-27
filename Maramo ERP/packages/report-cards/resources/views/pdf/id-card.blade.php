@php
    $headerColor = \Classivo\ReportCards\Support\Css::color($sheet->headerColor);
    $textColor = \Classivo\ReportCards\Support\Css::color($sheet->textColor);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('report-cards::messages.id_cards') }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: {{ $textColor }}; font-size: 10px; margin: 0; padding: 14px; }
        .card { display: inline-block; width: 250px; height: 158px; border: 1.5px solid #cbd5e1; border-radius: 8px; margin: 6px; vertical-align: top; overflow: hidden; }
        .card .bar { background: {{ $headerColor }}; color: #ffffff; padding: 6px 8px; text-align: center; }
        .card .bar .school { font-size: 11px; font-weight: bold; }
        .card .bar .branch { font-size: 8px; }
        .logo { height: 20px; margin-bottom: 2px; }
        .body { padding: 7px 9px; }
        .ident-table { width: 100%; border-collapse: collapse; }
        .ident-table td { vertical-align: top; }
        .photo-cell { width: 58px; }
        .photo { width: 52px; height: 60px; border: 1px solid #cbd5e1; }
        .photo-ph { width: 52px; height: 60px; border: 1px solid #cbd5e1; background: #f1f5f9; }
        .name { font-size: 11px; font-weight: bold; margin-bottom: 3px; }
        .fields td { padding: 1px 0; font-size: 8.5px; }
        .flabel { color: #64748b; padding-right: 4px; white-space: nowrap; }
        .fvalue { font-weight: bold; }
        .qr { text-align: right; }
        .qr img { width: 42px; height: 42px; }
    </style>
</head>
<body>
    @foreach ($cards as $card)
        <div class="card">
            <div class="bar">
                @if ($sheet->showLogo && ! empty($institution->logo))
                    <img class="logo" src="{{ $institution->logo }}" alt="">
                @endif
                <div class="school">{{ $institution->name }}</div>
                @if ($institution->branch)<div class="branch">{{ $institution->branch }}</div>@endif
            </div>
            <div class="body">
                <div class="name">{{ $card['name'] }}</div>
                <table class="ident-table">
                    <tr>
                        @if ($sheet->showPhoto)
                            <td class="photo-cell">
                                @if (! empty($card['photo']))
                                    <img class="photo" src="{{ $card['photo'] }}" alt="">
                                @else
                                    <div class="photo-ph"></div>
                                @endif
                            </td>
                        @endif
                        <td>
                            <table class="fields">
                                @foreach ($card['rows'] as $row)
                                    <tr><td class="flabel">{{ $row['label'] }}</td><td class="fvalue">{{ $row['value'] }}</td></tr>
                                @endforeach
                            </table>
                        </td>
                        @if ($sheet->showQr && ! empty($card['qr']))
                            <td class="qr"><img src="{{ $card['qr'] }}" alt="QR"></td>
                        @endif
                    </tr>
                </table>
            </div>
        </div>
    @endforeach
</body>
</html>

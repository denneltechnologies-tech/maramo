<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Certificate' }}</title>
    <style>
        @page { margin: 0; }
        * { font-family: DejaVu Sans, sans-serif; }
        html, body { margin: 0; padding: 0; color: #1e293b; }
        .page { position: relative; width: 100%; min-height: 100%; box-sizing: border-box; padding: 40px 48px; }
        .bg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; }
        .frame { position: relative; z-index: 1; border: 3px double #334155; padding: 28px 32px; min-height: 60vh; }
        .header { text-align: center; margin-bottom: 10px; }
        .school { font-size: 22px; font-weight: bold; letter-spacing: .5px; }
        .branch { font-size: 12px; color: #475569; margin-top: 2px; }
        .rule { border-bottom: 2px solid #334155; margin: 10px 0 18px; }
        .content { font-size: 14px; line-height: 1.7; }
        .content h1, .content h2, .content h3 { text-align: center; }
        .content p { margin: 8px 0; }
        .content table { border-collapse: collapse; }
        .content img { max-width: 100%; }
    </style>
</head>
<body>
<div class="page">
    @if (! empty($background))
        <img class="bg" src="{{ $background }}" alt="">
    @endif
    <div class="frame">
        <div class="header">
            <div class="school">{{ $institution->name }}</div>
            @if ($institution->branch)<div class="branch">{{ $institution->branch }}</div>@endif
        </div>
        <div class="rule"></div>
        <div class="content">{!! $body !!}</div>
    </div>
</div>
</body>
</html>

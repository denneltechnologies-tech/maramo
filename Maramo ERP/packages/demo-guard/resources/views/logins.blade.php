<!--email_off-->
<div style="border:1px solid #d97706;background:#fffbeb;color:#78350f;border-radius:8px;padding:10px 12px;font-size:13px;line-height:1.5;">
    <strong>{{ __('demo-guard::messages.logins_title') }}</strong>
    @foreach ($logins as $login)
        <div>{{ $login['label'] ?? '' }}: <code>{{ $login['email'] }}</code> / <code>{{ $login['password'] }}</code></div>
    @endforeach
</div>
<!--/email_off-->

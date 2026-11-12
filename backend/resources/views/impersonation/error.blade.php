@php
    // 見 DEVELOP.MD 6.11 第 4 點：任何一步失敗都只顯示對應訊息，不洩漏內部細節。
    // 訊息一律走語言包（開發約定第 11 點），不寫死在 Blade 裡；$reason 的值
    // 是 HqImpersonationService::redeem() 回傳的錯誤碼尾段，對應 api.auth.*。
    $messages = [
        'exchange_code_required' => __('api.auth.exchange_code_required'),
        'exchange_code_invalid' => __('api.auth.exchange_code_invalid'),
        'exchange_site_mismatch' => __('api.auth.exchange_site_mismatch'),
        'no_super_admin' => __('api.auth.no_super_admin'),
        'portal_not_found' => __('api.auth.portal_not_found'),
    ];
@endphp
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8" />
    <meta name="robots" content="noindex" />
    <title>{{ __('api.impersonation.failed_title') }}</title>
</head>
<body>
    <p>{{ $messages[$reason] ?? __('api.impersonation.failed') }}</p>
</body>
</html>

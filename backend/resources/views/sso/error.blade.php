<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8" />
    <title>登入失敗</title>
</head>
<body>
    @php
        $messages = [
            'sso_disabled' => '此站尚未啟用 SSO 登入。',
            'sso_not_configured' => 'SSO 尚未設定完成，請聯絡管理員。',
            'identity_resolve_failed' => '無法驗證您的身份，請重新嘗試或聯絡管理員。',
            'user_not_found' => '找不到對應的帳號，請聯絡管理員開通。',
            'portal_not_found' => '目標端口不存在或未啟用。',
            'portal_access_denied' => '此帳號尚未被授權使用此端口，請聯絡管理員。',
            'portal_admin_only' => '此端口僅開放端口管理員登入。',
        ];
    @endphp
    <p>{{ $messages[$reason] ?? '登入失敗，請聯絡管理員。' }}</p>
</body>
</html>

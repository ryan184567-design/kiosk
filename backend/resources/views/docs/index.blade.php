<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/swagger-ui/swagger-ui.css') }}">
    <link rel="icon" href="{{ asset('vendor/swagger-ui/favicon-32x32.png') }}">
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="{{ asset('vendor/swagger-ui/swagger-ui-bundle.js') }}" nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    <script src="{{ asset('vendor/swagger-ui/swagger-ui-standalone-preset.js') }}" nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.onload = function () {
            window.ui = SwaggerUIBundle({
                url: {{ Illuminate\Support\Js::from($specUrl) }},
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
                plugins: [SwaggerUIBundle.plugins.DownloadUrl],
                layout: 'StandaloneLayout',
                // 見使用者回饋：預設 Schema 分頁巢狀欄位要一層層點開才看得到，
                // 太麻煩，這裡改成展開所有巢狀層級（10 層足夠涵蓋目前最深的
                // response schema），一次看到完整結構，不用逐欄點擊。
                defaultModelExpandDepth: 10,
                defaultModelsExpandDepth: 10,
            });
        };
    </script>
</body>
</html>

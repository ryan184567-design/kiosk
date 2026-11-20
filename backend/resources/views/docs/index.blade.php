<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/swagger-ui/swagger-ui.css') }}">
    <link rel="icon" href="{{ asset('vendor/swagger-ui/favicon-32x32.png') }}">
</head>
<body>
    @if (! empty($signTool))
        <link rel="stylesheet" href="{{ asset('vendor/docs-tools/factory-sign.css') }}">
        <div id="factory-sign-tool">
            <div class="fs-box">
                <div class="fs-head" id="fs-toggle"><span>🔑 簽名計算工具（POST /api/factory/token）</span><span>展開／收合</span></div>
                <div class="fs-body" id="fs-body">
                    <p class="fs-note">
                        sign = HMAC-SHA256(app_secret, <code>app_id|timestamp|nonce</code>)，小寫 hex。
                        計算全程在本機瀏覽器完成，app_secret 不會送出。計算後把下方 JSON 貼到 token 接口 Try it out 的 Request body。
                        timestamp 與伺服器時間差須在 300 秒內，且 nonce 不可重複，每次測試請按「重新產生」。
                    </p>
                    <div class="fs-grid">
                        <div><label for="fs-app-id">app_id</label><input id="fs-app-id" autocomplete="off"></div>
                        <div><label for="fs-secret">app_secret</label><input id="fs-secret" type="password" autocomplete="off"></div>
                        <div><label for="fs-ts">timestamp（秒）</label><input id="fs-ts" autocomplete="off"></div>
                        <div><label for="fs-nonce">nonce（16～64 英數字）</label><input id="fs-nonce" autocomplete="off"></div>
                    </div>
                    <div class="fs-actions">
                        <button type="button" id="fs-calc">計算簽名</button>
                        <button type="button" id="fs-fill">計算並填入下方接口</button>
                        <button type="button" id="fs-refresh" class="fs-secondary">重新產生 timestamp／nonce 並計算</button>
                        <button type="button" id="fs-copy" class="fs-secondary">複製 JSON</button>
                    </div>
                    <div class="fs-error" id="fs-error"></div>
                    <textarea id="fs-out" readonly placeholder="請求 body JSON 會顯示在這裡"></textarea>
                </div>
            </div>
        </div>
        <script src="{{ asset('vendor/docs-tools/factory-sign.js') }}" nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    @endif
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

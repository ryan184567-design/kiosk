@php
    // 見 DEVELOP.MD 6.11 第 2-3 點：主站跳轉登入的子站端落地頁。
    // 這一頁的責任是把剛兌換到的子站 JWT（cookie 已由 Controller 設好）
    // 同步交給管理後台 SPA，然後導向該端口後台首頁。
    $nonce = Illuminate\Support\Facades\Vite::cspNonce();
@endphp
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8" />
    <meta name="robots" content="noindex" />
    <title>{{ __('api.impersonation.landing_title') }}</title>
    {{-- 見 4.2.1：嚴格 CSP 下 inline style 也要帶 nonce，不能用無 nonce 的 <style>。 --}}
    <style nonce="{{ $nonce }}">
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f6f8;
            color: #1f2937;
            font-family: system-ui, -apple-system, "Microsoft JhengHei", sans-serif;
        }

        .card {
            background: #fff;
            padding: 32px 40px;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
            text-align: center;
            max-width: 480px;
        }

        .card__note {
            color: #b45309;
            font-size: 13px;
            margin-top: 16px;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('api.impersonation.landing_title') }}</h1>
        <p>{{ __('api.impersonation.landing_hint') }}</p>
        <p><a href="{{ $target }}">{{ __('api.impersonation.landing_link') }}</a></p>
        {{-- 見 6.11 第 4 點：子站後台建議顯示明顯提示，避免操作人員忘記自己是用最高權限帳號在動作。 --}}
        <p class="card__note">{{ __('api.impersonation.landing_note') }}</p>
    </div>
    <script nonce="{{ $nonce }}">
        (function () {
            // 見 HqImpersonationCallbackController：管理後台 SPA 的 JWT 存在
            // localStorage（不是 cookie），同一來源的這一頁負責把 token 寫進
            // SPA 讀的同一個 key，SPA 載入時才認得這次登入。
            var storageKey = {{ Illuminate\Support\Js::from($storageKey) }};
            var token = {{ Illuminate\Support\Js::from($token) }};
            var target = {{ Illuminate\Support\Js::from($target) }};

            if (storageKey && token) {
                try {
                    window.localStorage.setItem(storageKey, token);
                } catch (e) {
                    // 私密瀏覽模式等寫不進去的情況：cookie 仍有效（見 8.6），
                    // 不因此讓整個流程失敗，上方連結仍可手動進入。
                }
            }

            // 用 replace 而非 assign：交換碼是一次性的，不要把這一頁留在
            // 瀏覽器歷史裡，避免使用者按上一頁又觸發一次已失效的兌換。
            window.location.replace(target);
        })();
    </script>
</body>
</html>

<?php

use App\Support\Api\ApiResponder;
use App\Support\Api\ErrorCode;
use App\Support\Http\Middleware\EnsureFactoryAuthenticated;
use App\Support\Http\Middleware\EnsureFactoryScope;
use App\Support\Http\Middleware\EnsureHqJwtAuthenticated;
use App\Support\Http\Middleware\EnsureHqSuperAdmin;
use App\Support\Http\Middleware\EnsureJwtAuthenticated;
use App\Support\Http\Middleware\EnsureModuleEnabled;
use App\Support\Http\Middleware\EnsurePermission;
use App\Support\Http\Middleware\EnsureSiteContext;
use App\Support\Http\Middleware\SecurityHeaders;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 見 README 4.2.1：全域套用，本地與正式環境同一份規則。
        $middleware->web(append: [SecurityHeaders::class]);

        // 見 README 6.11 第 2-3 點與 8.6：跳轉登入／SSO 落地頁用 cookie 承載
        // 子站 JWT（瀏覽器導頁帶不了 Authorization header），
        // EnsureJwtAuthenticated 在沒有 bearer token 時會 fallback 讀這個
        // cookie。但 Laravel 送出的 cookie 一律先經 EncryptCookies 加密，
        // 而 api 群組預設**沒有**這個 middleware——少了它，API 路徑讀到的會是
        // 密文、JWT 驗證永遠失敗（已實測：帶著落地頁剛設定好的 cookie 呼叫
        // GET /api/user/profile 仍回 401 token 無效，Blade 頁面因為走 web 群組
        // 反而正常，症狀很容易誤判成「cookie 沒設成功」）。這裡把
        // EncryptCookies 補進 api 群組的前段（Sanctum 的 statefulApi 也是
        // 同一種做法）。
        //
        // 安全性：cookie 的身份驗證屬於「環境憑證」，因此這個 cookie 一律
        // HttpOnly + SameSite=Lax（見 HqImpersonationCallbackController），
        // 跨站的 POST/PUT/DELETE 不會帶著它，不會變成 CSRF 破口；不加密
        // cookie 內容（改成 except 排除）則會讓 JWT 以明文躺在瀏覽器裡，
        // 反而更差，故不採用。
        $middleware->api(
            prepend: [EncryptCookies::class],
            append: [SecurityHeaders::class],
        );

        $middleware->alias([
            // 見 README 6.4：只掛在子站路由群組（routes/api.php 內自行套用），
            // app/Hq 路由不掛這個 middleware。
            'site.context' => EnsureSiteContext::class,

            // 見 README 11.1、6.12：子站與主站的 JWT 驗證各自獨立。
            'jwt.auth' => EnsureJwtAuthenticated::class,
            'hq.jwt.auth' => EnsureHqJwtAuthenticated::class,
            'hq.super_admin' => EnsureHqSuperAdmin::class,

            // 見 README 6.9：module:{code}。
            'module' => EnsureModuleEnabled::class,

            // 見 README 7.4、18.2.1：permission.api:{code1},{code2}...，
            // 需排在 jwt.auth 之後。權限碼比對邏輯與網域（api./web. 前綴）
            // 無關，permission.web 直接共用同一個 middleware 類別，不需要
            // 另外寫一份。
            'permission.api' => EnsurePermission::class,
            'permission.web' => EnsurePermission::class,

            // 訪客機等「機器」呼叫方：App 認證與 scope 授權，見 DEVELOP.MD 12.9、docs/APP_DEVELOP.MD。
            // 與用戶 jwt.auth／permission.api 並存，互不影響。
            'factory.auth' => EnsureFactoryAuthenticated::class,
            'factory.scope' => EnsureFactoryScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 見 README 11.2：/api/* 一律回 JSON 錯誤。Laravel 預設只在請求
        // 帶 Accept: application/json（或 X-Requested-With: XMLHttpRequest）
        // 時才視為「想要 JSON」，但前端 fetch() 呼叫預設不帶這個 header——
        // 這會導致 $request->validate() 驗證失敗時 Laravel 誤判成瀏覽器
        // 表單請求，改成 302 redirect back（api.php 路由多半沒有 session，
        // 這支更慘：redirect 到 SPA 首頁的 HTML），前端 res.json() 對著一頁
        // HTML 解析直接拋例外、被吞掉，使用者只看到「點了儲存沒反應」，
        // 沒有任何錯誤訊息——已實測踩過（新增使用者、選單管理都中過招）。
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson());

        // $request->validate() 丟出的 ValidationException 統一轉成跟
        // Controller 手動 $this->fail(ErrorCode::VALIDATION_FAILED, ...)
        // 完全相同的回應格式（見 ApiResponder），前端不用分兩套邏輯判斷
        // 「這是自動驗證失敗還是我自己丟的 RuntimeException」，一律看
        // body.status/error_message 就好。只處理 /api/* 路由，回傳 null
        // 交由 Laravel 預設行為處理其餘（例如 Blade 表單）請求。
        // 訊息帶第一個欄位層級的錯誤（$e->validator->errors()->first()），
        // 讓使用者看得出來是哪個欄位、什麼原因不合法，不能只顯示一句模糊的
        // 「驗證失敗」——這份訊息由 lang/zh_TW/validation.php 產生（含
        // attributes 對照表，把 identity_type_id 這類英文欄位名轉成「身份
        // 別」等中文說法），不是 Laravel 內建、這個專案沒有發布的預設英文
        // 語言檔。真正需要跨欄位或業務規則層級訊息的情境，還是走既有慣例：
        // RuntimeException + lang/zh_TW/api.php 自訂訊息。
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponder::fail(ErrorCode::VALIDATION_FAILED, $e->validator->errors()->first(), 422);
            }
        });
    })->create();

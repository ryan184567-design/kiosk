<?php

/* *****************************************
File: FactoryCallLogger.php
Author: cliffdean
Date: 2026-11-20
Description: 廠商模式（App Token）呼叫記錄：決定要不要記、盡可能完整地收集請求／回應資訊、遮罩敏感值、截斷過長內容，寫入 factory_logs（type = call）
***************************************** */

namespace App\Support\Factory;

use App\Api\Factory\Models\FactoryApp;
use App\Api\Factory\Models\FactoryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

// 記錄規則（App 管理裡的「除錯模式」）：
// - 除錯模式開啟：這個 App 的每次呼叫都記錄（成功、失敗都記）。
// - 除錯模式關閉：只記錄回應狀態碼不是 2xx 的呼叫（4xx／5xx 等失敗）。
//   「成功」以 2xx 判斷：寫入成功常回 201，不能算失敗。
//
// 記錄內容盡可能完整（factory_logs）：
//   索引欄位：app_id、來源 IP、模塊、具體方法（Controller@method）、HTTP 方法、實際路徑、路由樣式、所需 scope、
//            狀態碼、結果、起始時間、耗時、回應大小
//   request_data（JSON）：client（IP 鏈、User-Agent、Referer、協定、主機…）、完整 URL、route_params、
//            query（GET）、form（POST 表單）、json、files（只記檔名／大小／類型，不記內容）、raw_body、所有 headers
//   response_headers（JSON）、response_data（完整回應內容）
// 敏感值（password、secret、token、authorization、cookie…）一律遮罩，不存可直接使用的憑證；
// 每個區塊（headers、query、json…）各自超過 factory.log_max_bytes 會截斷並標註。
class FactoryCallLogger
{
    private const MASKED = '***';

    // 欄位／header 名稱符合就遮罩（不分大小寫）。注意：sign 是一次性簽名（nonce 防重放），保留以利排查簽名問題。
    private const SENSITIVE_KEY = '/(password|passwd|secret|token|authorization|api[_-]?key|cookie)/i';

    // 名稱含 token 但值不是機密（例如換 token 回應的 token_type = Bearer），不遮罩，除錯時才看得到
    private const NOT_SENSITIVE = ['token_type'];

    public function shouldLog(?FactoryApp $app, int $status): bool
    {
        if (! $app) {
            return false;   // 認不出是哪個 App 就不記（避免匿名請求灌爆日誌）
        }

        return $app->is_debug || $status < 200 || $status >= 300;
    }

    public function record(Request $request, Response $response, ?FactoryApp $app): void
    {
        $status = $response->getStatusCode();

        if (! $this->shouldLog($app, $status)) {
            return;
        }

        $finished = microtime(true);
        $started = (float) $request->server->get('REQUEST_TIME_FLOAT', $finished);
        $content = $response->getContent();

        FactoryLog::record(
            'call', $status >= 200 && $status < 300, $app->app_id, $request->ip(),
            $this->requiredScope($request),
            $app->is_debug ? 'debug' : 'non_2xx',
            array_merge(FactoryLog::routeInfo($request), [
                'route' => '/'.ltrim((string) $request->route()?->uri(), '/'),
                'status_code' => $status,
                // createFromTimestampMs 預設是 UTC，必須轉成應用時區，才會和 created_at（now()）一致
                'started_at' => Carbon::createFromTimestampMs((int) round($started * 1000), config('app.timezone')),
                'duration_ms' => max(0, (int) round(($finished - $started) * 1000)),
                'request_data' => $this->encode($this->requestData($request)),
                'response_headers' => $this->encode($this->headers($response->headers->all())),
                'response_data' => $this->responseData($response),
                'response_size' => is_string($content) ? strlen($content) : null,
            ]),
        );
    }

    /** 這支接口要求的 scope（路由上的 factory.scope:xxx），供搜尋與排查「為什麼被拒」。 */
    private function requiredScope(Request $request): ?string
    {
        foreach ($request->route()?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'factory.scope:')) {
                return substr($middleware, strlen('factory.scope:'));
            }
        }

        return null;
    }

    /** 請求的完整資訊。各區塊分開存，後台詳情才能分段顯示。 */
    private function requestData(Request $request): array
    {
        $isJson = $request->isJson();
        $json = $isJson ? $request->json()->all() : [];
        $query = $this->mask($request->query->all());

        return [
            'method' => $request->method(),
            'url' => $request->url().($query ? '?'.http_build_query($query) : ''),
            'client' => array_filter([
                'ip' => $request->ip(),
                'ips' => $request->ips(),                    // X-Forwarded-For 鏈（有信任 proxy 時）
                'user_agent' => $request->userAgent(),
                'referer' => $request->headers->get('referer'),
                'scheme' => $request->getScheme(),
                'host' => $request->getHost(),
                'port' => $request->getPort(),
                'protocol' => $request->server->get('SERVER_PROTOCOL'),
                'server_ip' => $request->server->get('SERVER_ADDR'),
            ], fn ($v) => $v !== null && $v !== ''),
            'content_type' => $request->headers->get('content-type'),
            'content_length' => $request->headers->get('content-length'),
            'route_params' => $this->block($this->mask($request->route()?->parameters() ?? [])),
            'query' => $this->block($query),                                              // GET 參數
            'form' => $this->block($isJson ? [] : $this->mask($request->request->all())), // POST 表單欄位
            'json' => $this->block($this->mask($json)),                                   // JSON body
            'files' => $this->block($this->files($request->allFiles())),                  // 上傳檔案（只記資訊）
            'raw_body' => $this->rawBody($request, $isJson),
            'headers' => $this->block($this->headers($request->headers->all())),
        ];
    }

    /** 不是 JSON、也不是 form 的 body（XML、純文字、JSON 格式錯誤…）存原始內容，避免漏記。 */
    private function rawBody(Request $request, bool $isJson): ?string
    {
        $type = strtolower((string) $request->headers->get('content-type'));
        $parsedAsForm = str_contains($type, 'application/x-www-form-urlencoded') || str_contains($type, 'multipart/form-data');

        if ($parsedAsForm || ($isJson && $request->json()->all() !== [])) {
            return null;
        }

        $raw = $request->getContent();

        if ($raw === '' || ! is_string($raw)) {
            return null;
        }

        return mb_check_encoding($raw, 'UTF-8')
            ? $this->limit($raw)
            : '[非文字內容 '.strlen($raw).' bytes]';
    }

    /** header 名稱一律小寫、多值以逗號串接；敏感 header（Authorization、Cookie…）遮罩。 */
    private function headers(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $values) {
            $name = strtolower((string) $name);
            $out[$name] = $this->isSensitive($name) ? self::MASKED : implode(', ', (array) $values);
        }

        ksort($out);

        return $out;
    }

    /** 上傳檔案只記檔名、大小、類型，不記內容。 */
    private function files(array $files): array
    {
        $out = [];

        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $out[$key] = $this->files($file);
            } elseif ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                $out[$key] = [
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getClientMimeType(),
                    'error' => $file->getError(),
                ];
            }
        }

        return $out;
    }

    private function responseData(Response $response): ?string
    {
        $content = $response->getContent();

        if ($content === false || $content === '') {
            return null;
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            return '[非文字內容 '.strlen($content).' bytes，'.$response->headers->get('content-type').']';
        }

        $decoded = json_decode($content, true);

        // JSON 回應解開後遮罩（例如換 token 回應的 access_token），再編回字串；非 JSON 原樣存
        return $this->limit(is_array($decoded) ? $this->encode($this->mask($decoded)) : $content);
    }

    private function isSensitive(string $key): bool
    {
        return ! in_array(strtolower($key), self::NOT_SENSITIVE, true) && preg_match(self::SENSITIVE_KEY, $key) === 1;
    }

    private function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = self::MASKED;
            } elseif (is_array($value)) {
                $data[$key] = $this->mask($value);
            }
        }

        return $data;
    }

    private function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * 區塊過大時以「截斷標註物件」取代，整體 JSON 仍然合法，後台才能正常解析顯示。
     * 每個區塊各自受 factory.log_max_bytes 限制。
     */
    private function block(array $data): array
    {
        $max = (int) config('factory.log_max_bytes');
        $encoded = $this->encode($data);

        if ($max <= 0 || strlen($encoded) <= $max) {
            return $data;
        }

        return ['_truncated' => true, '_original_bytes' => strlen($encoded), '_preview' => mb_strcut($encoded, 0, $max, 'UTF-8')];
    }

    private function limit(?string $text): ?string
    {
        $max = (int) config('factory.log_max_bytes');

        if ($text === null || $max <= 0 || strlen($text) <= $max) {
            return $text;
        }

        // 以位元組截斷後補上標註；mb_strcut 不會把多位元組字元切成亂碼
        return mb_strcut($text, 0, $max, 'UTF-8').'…[已截斷，原始 '.strlen($text).' bytes]';
    }
}

<?php

/* *****************************************
File: ApiAnnotationValidator.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 12.1/12.5/12.6：Swagger 標註的最小必填規則與格式錯誤處理， permission:sync-api、swagger:generate 共用同一套驗證（不要各自重刻一份， 見 ApiAnnotationParser 開頭註解的同一個理由）。
***************************************** */

namespace App\Support\Api\Doc;

use App\Support\Portal\Models\Portal;
use RuntimeException;

// 見 README 12.1/12.5/12.6：Swagger 標註的最小必填規則與格式錯誤處理，
// permission:sync-api、swagger:generate 共用同一套驗證（不要各自重刻一份，
// 見 ApiAnnotationParser 開頭註解的同一個理由）。任何一項不符合一律丟例外
// 中斷，不可靜默放行成一份不完整或報喜不報憂的文件（嚴格優先、及早失敗，
// 沿用 4.2 對 CSP 的同一套原則）。
class ApiAnnotationValidator
{
    // 'file' 專供 multipart/form-data 上傳欄位使用（見 10.4 檔案上傳
    // 接口），OpenAPI 對應 string + format:binary，見 SwaggerGenerateCommand。
    public const ALLOWED_TYPES = ['int', 'string', 'bool', 'float', 'array', 'object', 'datetime', 'file'];

    public function __construct(private readonly ApiAnnotationParser $parser) {}

    /**
     * @param  string  $httpMethod  @api-method 原始值（如 POST/GET）
     * @param  string  $context  錯誤訊息用的定位字串，例如 AuthController::login
     * @param  bool  $requirePortal  見 README 12.4 第 1 點：app/Hq 底下的
     *                                Controller 因程式碼與資料庫跟子站完全
     *                                獨立，不使用 @api-portal 標註，掃描
     *                                app/Hq 時傳 false 跳過這項檢查。
     *
     * @throws RuntimeException
     */
    public function validate(array $parsed, string $httpMethod, string $context, bool $requirePortal = true): void
    {
        $this->requireNonEmpty($parsed, 'tag', $context);
        $this->requireNonEmpty($parsed, 'summary', $context);

        if ($requirePortal) {
            $this->validatePortal($parsed, $context);
        }

        $this->validateAuth($parsed, $context);
        $this->validateRequestFields($parsed, $httpMethod, $context);
        $this->validateResponseFields($parsed, $context);

        foreach ($this->parser->queryFields($parsed) as $field) {
            $this->validateFieldEntry($field, $parsed, $context, 'query-field');
        }
    }

    private function requireNonEmpty(array $parsed, string $tag, string $context): void
    {
        if (empty($parsed[$tag])) {
            throw new RuntimeException("[{$context}] 缺少必填標註 @api-{$tag}（見 README 12.1）。");
        }
    }

    // 見 DEVELOP.MD 12.9：@api-auth 只能是 app（App 認證 + scope）或 public（不需認證，如換 token）；
    // 標 app 必須同時有 @api-scope，且格式與路由上的 factory.scope:xxx 一致。
    private function validateAuth(array $parsed, string $context): void
    {
        if (! array_key_exists('auth', $parsed)) {
            return;
        }

        if (! in_array($parsed['auth'], ['app', 'public'], true)) {
            throw new RuntimeException("[{$context}] @api-auth 只允許 app 或 public：{$parsed['auth']}");
        }

        if ($parsed['auth'] === 'app' && ! preg_match('/^(\*|[a-z0-9_]+(\.[a-z0-9_]+)*(\.\*)?)$/', (string) ($parsed['scope'] ?? ''))) {
            throw new RuntimeException("[{$context}] @api-auth app 必須搭配格式正確的 @api-scope（例如 guest.apply.read）。");
        }
    }

    private function validatePortal(array $parsed, string $context): void
    {
        if (empty($parsed['portal'])) {
            throw new RuntimeException(
                "[{$context}] 缺少 @api-portal 標註；若此接口刻意不限端口（如登入、公開設定），".
                '請明確標註 @api-portal *（見 12.4）。'
            );
        }

        $portals = $this->parser->portals($parsed);

        if ($portals === ['*']) {
            return;
        }

        foreach ($portals as $portalKey) {
            if ($portalKey !== '*' && ! Portal::query()->where('portal_key', $portalKey)->exists()) {
                throw new RuntimeException("[{$context}] @api-portal 標註了不存在的端口：{$portalKey}");
            }
        }
    }

    private function validateRequestFields(array $parsed, string $httpMethod, string $context): void
    {
        $fields = $this->parser->requestFields($parsed);
        $carriesBody = in_array(strtoupper($httpMethod), ['POST', 'PUT', 'PATCH'], true);

        if ($fields === [] && $carriesBody && ! array_key_exists('no-request-body', $parsed)) {
            throw new RuntimeException(
                "[{$context}] {$httpMethod} 接口沒有 @api-request-field，若本來就沒有 body 入參，".
                '請明確標註 @api-no-request-body（見 12.1、12.5）。'
            );
        }

        foreach ($fields as $field) {
            $this->validateFieldEntry($field, $parsed, $context, 'request-field');
        }

        if ($fields !== [] && empty($parsed['request-example'])) {
            throw new RuntimeException("[{$context}] 有 @api-request-field 卻沒有 @api-request-example，body 入參必須附可直接調用的範例（見 12.5 第 1 點）。");
        }

        // 範例會直接輸出給 Swagger UI 當預設值，必須是合法 JSON；multipart 上傳（有 file 欄位）例外。
        $hasFile = collect($fields)->contains(fn ($f) => $f['type'] === 'file');
        if ($fields !== [] && ! $hasFile && ! is_array(json_decode($parsed['request-example'], true))) {
            throw new RuntimeException("[{$context}] @api-request-example 不是合法的 JSON 物件，Swagger UI 無法當作預設值。");
        }
    }

    private function validateResponseFields(array $parsed, string $context): void
    {
        $fields = $this->parser->responseFields($parsed);

        if ($fields === [] && ! array_key_exists('response-empty', $parsed)) {
            throw new RuntimeException(
                "[{$context}] 沒有 @api-response-field，若這支接口本來就不回傳資料，".
                '請明確標註 @api-response-empty（見 12.1、12.3）。'
            );
        }

        foreach ($fields as $field) {
            $this->validateFieldEntry($field, $parsed, $context, 'response-field');
        }
    }

    private function validateFieldEntry(array $field, array $parsed, string $context, string $tag): void
    {
        if ($field['description'] === '') {
            throw new RuntimeException("[{$context}] @api-{$tag} {$field['path']} 缺少說明文字（見 12.1）。");
        }

        if (str_starts_with($field['type'], 'dict:')) {
            $this->assertDictDeclared($parsed, substr($field['type'], 5), $context);

            return;
        }

        if (! in_array($field['type'], self::ALLOWED_TYPES, true)) {
            throw new RuntimeException(
                "[{$context}] @api-{$tag} {$field['path']} 型別不在允許清單、也不是 dict: 開頭的字典標註：{$field['type']}"
            );
        }
    }

    private function assertDictDeclared(array $parsed, string $dictName, string $context): void
    {
        foreach ($this->parser->dicts($parsed) as $dict) {
            if ($dict['name'] !== $dictName) {
                continue;
            }

            if ($dict['options'] === []) {
                throw new RuntimeException(
                    "[{$context}] @api-dict {$dictName} 選項格式錯誤，需為「值=說明」並用逗號分隔（見 12.5 第 2 點）。"
                );
            }

            return;
        }

        throw new RuntimeException(
            "[{$context}] 欄位型別標註為 dict:{$dictName}，但找不到對應的 @api-dict {$dictName} 選項說明（見 12.5 第 2 點）。"
        );
    }
}

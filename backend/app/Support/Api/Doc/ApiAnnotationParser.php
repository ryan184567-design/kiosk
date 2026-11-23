<?php

/* *****************************************
File: ApiAnnotationParser.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 解析 Controller 方法上的 @api-xxxx docblock 標註，見 README 12 節。
***************************************** */

namespace App\Support\Api\Doc;

use ReflectionMethod;

// 解析 Controller 方法上的 @api-xxxx docblock 標註，見 README 12 節。
// permission:sync-api、swagger:generate 共用同一套解析邏輯，不要各自重刻一份。
class ApiAnnotationParser
{
    // 多行標註：內容延續到下一個 @api-xxx 標籤前，見 12.2。
    private const MULTILINE = ['description', 'response', 'request-example'];

    // 可重複標註：每次出現都收集成一筆，見 12.3、12.5。
    private const REPEATABLE = ['response-field', 'request-field', 'query-field', 'dict'];

    public function parseMethod(ReflectionMethod $method): ?array
    {
        $doc = $method->getDocComment();

        if (! $doc) {
            return null;
        }

        return $this->parse($doc);
    }

    public function parse(string $docComment): array
    {
        $lines = $this->cleanLines($docComment);
        $result = [];
        $currentTag = null;

        foreach ($lines as $line) {
            if (preg_match('/^@api-([a-z-]+)\s*(.*)$/', $line, $m)) {
                $tag = $m[1];
                $value = trim($m[2]);
                $currentTag = $tag;

                if (in_array($tag, self::REPEATABLE, true)) {
                    $result[$tag][] = $value;
                } elseif (in_array($tag, self::MULTILINE, true)) {
                    $result[$tag] = $value === '' ? [] : [$value];
                } else {
                    $result[$tag] = $value;
                }

                continue;
            }

            if ($currentTag && in_array($currentTag, self::MULTILINE, true)) {
                $result[$currentTag][] = $line;
            }
        }

        foreach (self::MULTILINE as $tag) {
            if (isset($result[$tag]) && is_array($result[$tag])) {
                $result[$tag] = trim(implode("\n", $result[$tag]));
            }
        }

        return $result;
    }

    // 見 12.4：逗號分隔的 portals.portal_key 清單。
    public function portals(array $parsed): array
    {
        if (empty($parsed['portal'])) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $parsed['portal']))));
    }

    /**
     * @return array<int, array{path: string, type: string, description: string}>
     */
    public function responseFields(array $parsed): array
    {
        return $this->splitFieldLines($parsed['response-field'] ?? []);
    }

    /**
     * @return array<int, array{path: string, type: string, description: string}>
     */
    public function requestFields(array $parsed): array
    {
        return $this->splitFieldLines($parsed['request-field'] ?? []);
    }

    /**
     * GET／DELETE 的 query string 參數：`@api-query-field {name} {type} {說明}`，
     * 說明含「必填」二字即視為必填，產出 OpenAPI 的 `in: query` parameter。
     *
     * @return array<int, array{path: string, type: string, description: string}>
     */
    public function queryFields(array $parsed): array
    {
        return $this->splitFieldLines($parsed['query-field'] ?? []);
    }

    // 見 12.5 第 2 點：`@api-dict {字典名稱} {值}={說明}[,{值}={說明}...]`，
    // 欄位型別標為 `dict:{字典名稱}` 時，必須有對應的這一行，Swagger UI 才看得到
    // 選項清單本身是什麼，不只是「這是個字典」這種空話。
    public function dicts(array $parsed): array
    {
        $dicts = [];

        foreach ($parsed['dict'] ?? [] as $line) {
            if (! preg_match('/^(\S+)\s+(.+)$/', $line, $m)) {
                continue;
            }

            $options = [];

            foreach (explode(',', $m[2]) as $pair) {
                if (! str_contains($pair, '=')) {
                    continue;
                }

                [$value, $label] = explode('=', $pair, 2);
                $options[trim($value)] = trim($label);
            }

            $dicts[] = ['name' => $m[1], 'options' => $options];
        }

        return $dicts;
    }

    private function splitFieldLines(array $lines): array
    {
        $fields = [];

        foreach ($lines as $line) {
            if (! preg_match('/^(\S+)\s+(\S+)\s*(.*)$/', $line, $m)) {
                continue;
            }

            $fields[] = ['path' => $m[1], 'type' => $m[2], 'description' => trim($m[3])];
        }

        return $fields;
    }

    private function cleanLines(string $docComment): array
    {
        $docComment = trim($docComment);
        $docComment = preg_replace('#^/\*\*#', '', $docComment);
        $docComment = preg_replace('#\*/$#', '', $docComment);

        $lines = explode("\n", $docComment);

        return array_values(array_map(
            fn ($line) => preg_replace('/^\s*\*\s?/', '', rtrim($line)),
            $lines
        ));
    }
}

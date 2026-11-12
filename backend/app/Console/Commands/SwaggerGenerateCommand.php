<?php

/* *****************************************
File: SwaggerGenerateCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 產生 OpenAPI JSON，見 README 12.7。
***************************************** */

namespace App\Console\Commands;

use App\Support\Api\Doc\ApiAnnotationParser;
use App\Support\Api\Doc\ApiAnnotationValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use RuntimeException;

// 產生 OpenAPI JSON，見 README 12.7。子站掃描範圍與 permission:sync-api
// 相同（app/Api 下所有 Controller 註解），共用同一套 ApiAnnotationParser。
// 主站（app/Hq）程式碼/資料庫完全獨立（見 6.5），另外掃描成第三份獨立文件
// （見 12.8 文件受眾隔離的同一個道理——不同體系的 API 不能混在同一份文件），
// 且不使用 @api-portal 標註（見 12.4 第 1 點），驗證時要跳過這項檢查。
// 見 12.8：內部文件排除 /api/factory/*，廠商文件只含 /api/factory/*。
class SwaggerGenerateCommand extends Command
{
    protected $signature = 'swagger:generate {--pretty : 輸出時排版}';

    protected $description = '產生 OpenAPI JSON（見 README 12.7）';

    public function handle(): int
    {
        $parser = app(ApiAnnotationParser::class);
        $validator = app(ApiAnnotationValidator::class);

        $internalPaths = [];
        $partnerPaths = [];

        foreach (glob(app_path('Api/*/Controllers/*.php')) as $file) {
            $moduleStudly = $this->extractModuleStudly($file, 'Api');
            $class = 'App\\Api\\'.$moduleStudly.'\\Controllers\\'.pathinfo($file, PATHINFO_FILENAME);

            $scanResult = $this->scanClass($class, $parser, $validator, requirePortal: true);

            if ($scanResult === null) {
                return self::FAILURE;
            }

            foreach ($scanResult as $path => $verbs) {
                foreach ($verbs as $verb => $operation) {
                    if (str_starts_with($path, '/api/factory/')) {
                        $partnerPaths[$path][$verb] = $operation;
                    } else {
                        $internalPaths[$path][$verb] = $operation;
                    }
                }
            }
        }

        $hqPaths = [];

        foreach (glob(app_path('Hq/*/Controllers/*.php')) as $file) {
            $moduleStudly = $this->extractModuleStudly($file, 'Hq');
            $class = 'App\\Hq\\'.$moduleStudly.'\\Controllers\\'.pathinfo($file, PATHINFO_FILENAME);

            $scanResult = $this->scanClass($class, $parser, $validator, requirePortal: false);

            if ($scanResult === null) {
                return self::FAILURE;
            }

            foreach ($scanResult as $path => $verbs) {
                foreach ($verbs as $verb => $operation) {
                    $hqPaths[$path][$verb] = $operation;
                }
            }
        }

        $this->write(storage_path('app/private/docs/openapi.json'), $internalPaths);
        $this->write(storage_path('app/private/docs/openapi.partner.json'), $partnerPaths);
        $this->write(storage_path('app/private/docs/openapi.hq.json'), $hqPaths);
        $this->publishSwaggerUiAssets();

        $this->info('已產生 storage/app/private/docs/openapi.json、openapi.partner.json、openapi.hq.json，可透過 /docs/openapi.json、/docs/openapi.partner.json、/docs/openapi.hq.json 存取（見 ApiDocsController）。');

        return self::SUCCESS;
    }

    /**
     * 掃描單一 Controller 類別，回傳 {path: {verb: operation}} 巢狀陣列；
     * 驗證失敗時印出錯誤並回傳 null（呼叫端據此中斷整個指令，見 12.6
     * 「標註格式錯誤處理」——一旦有錯就整批失敗，不產生半份不完整的文件）。
     *
     * @return array<string, array<string, array>>|null
     */
    private function scanClass(string $class, ApiAnnotationParser $parser, ApiAnnotationValidator $validator, bool $requirePortal): ?array
    {
        $result = [];

        if (! class_exists($class)) {
            return $result;
        }

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if ($method->class !== $class) {
                continue;
            }

            $parsed = $parser->parseMethod($method);

            if (! $parsed || empty($parsed['method']) || empty($parsed['path'])) {
                continue;
            }

            try {
                $validator->validate($parsed, $parsed['method'], "{$class}::{$method->getName()}", $requirePortal);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return null;
            }

            $operation = $this->buildOperation($parser, $parsed);
            $verb = strtolower($parsed['method']);
            $result[$parsed['path']][$verb] = $operation;
        }

        return $result;
    }

    // 見 README 12.1：Swagger UI 頁面（/docs、/docs/partner）自行 host
    // swagger-ui-dist 的靜態資源，不吃 CDN（比照 4 節安全原則，外部 CDN
    // 不在允許清單內）。這份資源不隨版本常變，跟著 swagger:generate 一起
    // 產生即可，不需要另外開一支指令；node_modules 不存在時（例如正式環境
    // 只跑過 composer install、沒跑 npm install）略過並提示，不讓整個
    // 指令失敗——OpenAPI JSON 才是這支指令的主要產出。
    private function publishSwaggerUiAssets(): void
    {
        $source = base_path('node_modules/swagger-ui-dist');

        if (! File::isDirectory($source)) {
            $this->warn('找不到 node_modules/swagger-ui-dist，略過複製 Swagger UI 靜態資源（先跑過 npm install 才會有 /docs 頁面可用，OpenAPI JSON 已正常產生不受影響）。');

            return;
        }

        $dest = public_path('vendor/swagger-ui');
        File::ensureDirectoryExists($dest);

        foreach (['swagger-ui.css', 'swagger-ui-bundle.js', 'swagger-ui-standalone-preset.js', 'favicon-32x32.png'] as $asset) {
            File::copy("{$source}/{$asset}", "{$dest}/{$asset}");
        }
    }

    private function buildOperation(ApiAnnotationParser $parser, array $parsed): array
    {
        $operation = [
            'tags' => isset($parsed['tag']) ? [$parsed['tag']] : [],
            'summary' => $parsed['summary'] ?? '',
        ];

        if (! empty($parsed['description'])) {
            $operation['description'] = $parsed['description'];
        }

        $portals = $parser->portals($parsed);

        if ($portals) {
            $operation['x-portals'] = $portals;
        }

        $dicts = $this->indexDicts($parser->dicts($parsed));
        $requestFields = $parser->requestFields($parsed);

        if ($requestFields) {
            // 有 file 型別欄位代表這是 multipart 上傳（見 10.4），content-type
            // 要用 multipart/form-data，不能標成 application/json（那樣送不了
            // 檔案二進位內容，Swagger UI 也不會顯示檔案選擇器）。
            $hasFileField = collect($requestFields)->contains(fn ($field) => $field['type'] === 'file');
            $contentType = $hasFileField ? 'multipart/form-data' : 'application/json';

            $operation['requestBody'] = [
                'content' => [
                    $contentType => [
                        'schema' => $this->buildSchema($requestFields, $dicts),
                    ],
                ],
            ];
        }

        $responseFields = $parser->responseFields($parsed);

        $operation['responses'] = [
            '200' => [
                'description' => 'OK',
                'content' => $responseFields ? [
                    'application/json' => ['schema' => $this->buildSchema($responseFields, $dicts)],
                ] : [],
            ],
        ];

        return $operation;
    }

    // name => [值 => 說明]，見 12.5 第 2 點 @api-dict。
    private function indexDicts(array $dicts): array
    {
        $map = [];

        foreach ($dicts as $dict) {
            $map[$dict['name']] = $dict['options'];
        }

        return $map;
    }

    private function buildSchema(array $fields, array $dicts): array
    {
        $properties = [];

        foreach ($fields as $field) {
            $this->setNested($properties, explode('.', $field['path']), $field['type'], $field['description'], $dicts);
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    private function setNested(array &$node, array $segments, string $type, string $description, array $dicts): void
    {
        $segment = array_shift($segments);
        $isArray = str_ends_with($segment, '[]');
        $key = $isArray ? substr($segment, 0, -2) : $segment;

        if (empty($segments)) {
            $node[$key] = $isArray
                ? ['type' => 'array', 'items' => $this->leafSchema($type, $description, $dicts)]
                : $this->leafSchema($type, $description, $dicts);

            return;
        }

        if ($isArray) {
            $node[$key] ??= ['type' => 'array', 'items' => ['type' => 'object', 'properties' => []]];
            $this->setNested($node[$key]['items']['properties'], $segments, $type, $description, $dicts);
        } else {
            $node[$key] ??= ['type' => 'object', 'properties' => []];
            $this->setNested($node[$key]['properties'], $segments, $type, $description, $dicts);
        }
    }

    // 字典欄位（type 為 dict:{name}）直接把選項清單展開寫進 description，
    // Swagger UI 的 Schema 分頁不需要額外外掛就能看到「字典是什麼意思」，
    // 不是只寫「這是個字典」四個字交差，見 12.5 第 2 點。
    private function leafSchema(string $type, string $description, array $dicts): array
    {
        if (str_starts_with($type, 'dict:')) {
            $dictName = substr($type, 5);
            $options = $dicts[$dictName] ?? [];
            $optionsText = collect($options)
                ->map(fn ($label, $value) => "{$value}={$label}")
                ->implode('、');

            return [
                'type' => 'string',
                'description' => trim("{$description}（字典：{$dictName}，可選值：{$optionsText}）"),
            ];
        }

        if ($type === 'file') {
            return ['type' => 'string', 'format' => 'binary', 'description' => $description];
        }

        return ['type' => $this->mapType($type), 'description' => $description];
    }

    private function mapType(string $type): string
    {
        return match ($type) {
            'int' => 'integer',
            'bool' => 'boolean',
            'float' => 'number',
            'datetime' => 'string',
            default => $type,
        };
    }

    private function extractModuleStudly(string $file, string $anchor): string
    {
        $parts = explode(DIRECTORY_SEPARATOR, str_replace('/', DIRECTORY_SEPARATOR, $file));
        $idx = array_search($anchor, $parts, true);

        return $parts[$idx + 1];
    }

    // 見 README 12.1：故意不寫進 public/docs（會與 /docs 路由本身撞名）
    // ——PHP 內建開發伺服器（php artisan serve）遇到請求路徑剛好對應一個
    // 真實存在的目錄（例如 public/docs/）時，會直接回自己的 404，完全不會
    // 進到 Laravel 路由，這是內建伺服器特有的行為，正式環境用 Nginx/Apache
    // 不會有這個問題，但本機開發天天要用，改存在 storage/app/private，
    // 一律透過 ApiDocsController 讀檔回傳，/docs 整棵路徑都交給 Laravel
    // 路由處理，不會有任何路徑跟 public/ 底下的實體檔案／目錄相撞。
    private function write(string $path, array $paths): void
    {
        $doc = [
            'openapi' => '3.0.3',
            'info' => ['title' => config('app.name'), 'version' => '1.0.0'],
            'paths' => $paths,
        ];

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($this->option('pretty') ? JSON_PRETTY_PRINT : 0);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($doc, $flags));
    }
}

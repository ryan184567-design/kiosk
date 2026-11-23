<?php

/* *****************************************
File: SwaggerGenerateCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 產生 OpenAPI JSON，見 README 12.7。
***************************************** */

namespace App\Console\Commands;

use App\Hq\Site\Models\Site;
use App\Support\Api\Doc\ApiAnnotationParser;
use App\Support\Api\Doc\ApiAnnotationValidator;
use App\Support\Api\Doc\ApiExampleFactory;
use App\Support\Hq\HqPresence;
use App\Support\Site\SiteDatabaseConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
    protected $signature = 'swagger:generate
        {--pretty : 輸出時排版}
        {--site= : 多站模式下，驗證 @api-portal 要查哪個子站的 portals 表}';

    protected $description = '產生 OpenAPI JSON（見 README 12.7）';

    private ApiExampleFactory $examples;

    public function handle(): int
    {
        $this->examples = new ApiExampleFactory;

        if (! $this->bindSiteConnection()) {
            return self::FAILURE;
        }

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

        $this->write(storage_path('app/private/docs/openapi.json'), $internalPaths, 'userBearerAuth');
        $this->write(storage_path('app/private/docs/openapi.partner.json'), $partnerPaths, 'appBearerAuth');
        $this->write(storage_path('app/private/docs/openapi.hq.json'), $hqPaths, 'userBearerAuth');
        $this->publishSwaggerUiAssets();

        $this->info('已產生 storage/app/private/docs/openapi.json、openapi.partner.json、openapi.hq.json，可透過 /docs/openapi.json、/docs/openapi.partner.json、/docs/openapi.hq.json 存取（見 ApiDocsController）。');

        return self::SUCCESS;
    }

    // ApiAnnotationValidator 驗證 @api-portal 時會查 portals 表（site_current
    // 連線）。CLI 沒經過 EnsureSiteContext，site_current 還是連到假主機的佔位
    // 設定，必須先套上實際連線，否則會報 site-context-not-resolved.invalid。
    private function bindSiteConnection(): bool
    {
        if (! HqPresence::available()) {
            $connection = SiteDatabaseConfig::singleSiteConnection();
        } else {
            $siteId = $this->option('site');

            // 沒指定就取第一個啟用的子站：各子站 portals 來自同一份種子，
            // 這裡只是拿來驗證 portal_key 是否存在，不影響產出的文件內容。
            $site = $siteId
                ? Site::query()->find($siteId)
                : Site::query()->where('is_enabled', true)->orderBy((new Site)->getKeyName())->first();

            if (! $site) {
                $this->error($siteId ? "找不到站點：{$siteId}" : '找不到任何啟用的子站，請先建立站點或帶 --site={site_id}。');

                return false;
            }

            $connection = SiteDatabaseConfig::connection($site->db_name);
        }

        Config::set('database.connections.site_current', $connection);
        DB::purge('site_current');

        return true;
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

        // 機器（訪客機）呼叫的接口：標 @api-auth app／public，見 DEVELOP.MD 12.9。
        // app 代表需 Bearer access token（factory.auth）加 @api-scope（factory.scope）。
        if (($parsed['auth'] ?? null) === 'public') {
            $operation['security'] = [];
        }

        if (($parsed['auth'] ?? null) === 'app') {
            $operation['security'] = [['appBearerAuth' => []]];
            $operation['x-scope'] = $parsed['scope'];
            $operation['description'] = trim("**App 認證**（訪客機）：需帶 `Authorization: Bearer <access_token>`，且 App 已被授權 scope `{$parsed['scope']}`。

".($operation['description'] ?? ''));
        }

        $dicts = $this->indexDicts($parser->dicts($parsed));
        $requestFields = $parser->requestFields($parsed);

        // path 參數（{id}）：OpenAPI 規定必須宣告，否則 Swagger UI 無法代入路徑；預設 integer。
        // query 參數見 @api-query-field（GET／DELETE 的 query string）。
        $parameters = [];

        preg_match_all('/\{(\w+)\}/', (string) ($parsed['path'] ?? ''), $pathMatches);

        $pathOverrides = collect($parser->pathFields($parsed))->keyBy('path');

        foreach ($pathMatches[1] as $name) {
            $override = $pathOverrides->get($name);
            $schema = $override
                ? $this->leafSchema($override['type'], $override['description'], $dicts, $name)
                : ['type' => 'integer', 'example' => 1];
            unset($schema['description']);

            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => $schema,
                'description' => $override['description'] ?? "路徑參數 {$name}",
            ];
        }

        foreach ($parser->queryFields($parsed) as $field) {
            $schema = $this->leafSchema($field['type'], $field['description'], $dicts, $field['path']);
            $description = $schema['description'] ?? $field['description'];
            unset($schema['description']);
            // query 參數不給預設值：Swagger UI 會把 example 預填進輸入框，變成帶著篩選條件送出；
            // 留空才代表「沒有這個查詢條件」。範例回傳值仍保留，只有查詢參數不預填。
            unset($schema['example']);

            $parameters[] = [
                'name' => $field['path'],
                'in' => 'query',
                'required' => str_contains($field['description'], '必填'),
                'description' => $description,
                'schema' => $schema,
            ];
        }

        if ($parameters) {
            $operation['parameters'] = $parameters;
        }

        if ($requestFields) {
            // 有 file 型別欄位代表這是 multipart 上傳（見 10.4），content-type
            // 要用 multipart/form-data，不能標成 application/json（那樣送不了
            // 檔案二進位內容，Swagger UI 也不會顯示檔案選擇器）。
            $hasFileField = collect($requestFields)->contains(fn ($field) => $field['type'] === 'file');
            $contentType = $hasFileField ? 'multipart/form-data' : 'application/json';

            $media = ['schema' => $this->buildSchema($requestFields, $dicts)];

            // @api-request-example 必須輸出成 OpenAPI 的 example，否則 Swagger UI 的
            // 「Try it out」預設值會是每個欄位都填 "string"，看不出該填什麼。
            // multipart 上傳的說明文字不是 JSON，不輸出（驗證器只對非 multipart 要求 JSON 格式）。
            $example = json_decode((string) ($parsed['request-example'] ?? ''), true);
            if (! $hasFileField) {
                // 標註有明確範例以標註為準，沒有才依欄位自動產生
                $media['example'] = is_array($example) ? $example : $this->examples->tree($requestFields, $dicts);
            }

            $operation['requestBody'] = ['content' => [$contentType => $media]];
        }

        $responseFields = $parser->responseFields($parsed);

        $operation['responses'] = [
            '200' => [
                'description' => 'OK',
                'content' => $responseFields ? [
                    'application/json' => [
                        'schema' => $this->buildSchema($responseFields, $dicts),
                        // 回傳範例含統一外層（status／error_code），內容依欄位名稱自動產生
                        'example' => ['status' => true, 'error_code' => 0] + $this->examples->tree($responseFields, $dicts),
                    ],
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
            $this->setNested($properties, explode('.', $field['path']), $field['type'], $field['description'], $dicts, $field['path']);
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    private function setNested(array &$node, array $segments, string $type, string $description, array $dicts, string $fullPath = ''): void
    {
        $segment = array_shift($segments);
        $isArray = str_ends_with($segment, '[]');
        $key = $isArray ? substr($segment, 0, -2) : $segment;

        if (empty($segments)) {
            $node[$key] = $isArray
                ? ['type' => 'array', 'items' => $this->leafSchema($type, $description, $dicts, $fullPath)]
                : $this->leafSchema($type, $description, $dicts, $fullPath);

            return;
        }

        // 父層可能已先被宣告成葉節點（例如 `visitor array 來訪者明細` 之後才有
        // `visitor[].id`），此時保留原本的 description，補上子結構即可。
        if ($isArray) {
            $node[$key] ??= ['type' => 'array'];
            $node[$key]['type'] = 'array';
            $node[$key]['items'] ??= ['type' => 'object'];
            $node[$key]['items']['type'] = 'object';
            $node[$key]['items']['properties'] ??= [];
            $this->setNested($node[$key]['items']['properties'], $segments, $type, $description, $dicts, $fullPath);
        } else {
            $node[$key] ??= ['type' => 'object'];
            $node[$key]['type'] = 'object';
            $node[$key]['properties'] ??= [];
            $this->setNested($node[$key]['properties'], $segments, $type, $description, $dicts, $fullPath);
        }
    }

    // 字典欄位（type 為 dict:{name}）直接把選項清單展開寫進 description，
    // Swagger UI 的 Schema 分頁不需要額外外掛就能看到「字典是什麼意思」，
    // 不是只寫「這是個字典」四個字交差，見 12.5 第 2 點。
    private function leafSchema(string $type, string $description, array $dicts, string $path = ''): array
    {
        if (str_starts_with($type, 'dict:')) {
            $dictName = substr($type, 5);
            $options = $dicts[$dictName] ?? [];
            $optionsText = collect($options)
                ->map(fn ($label, $value) => "{$value}={$label}")
                ->implode('、');

            // 選項值全是數字（如狀態碼）就標成 integer，與實際入參／回傳一致
            $numeric = $options && collect(array_keys($options))->every(fn ($k) => is_numeric($k));

            $schema = [
                'type' => $numeric ? 'integer' : 'string',
                'description' => trim("{$description}（字典：{$dictName}，可選值：{$optionsText}）"),
            ];

            // 預設值取字典的第一個選項，不要讓 Swagger UI 顯示 "string"
            if ($options) {
                $schema['example'] = $numeric ? (int) array_key_first($options) : (string) array_key_first($options);
            }

            return $schema;
        }

        if ($type === 'file') {
            return ['type' => 'string', 'format' => 'binary', 'description' => $description];
        }

        $schema = ['type' => $this->mapType($type), 'description' => $description];

        // 依欄位名稱、型別與說明給「有意義的值」，避免 Swagger UI 預設顯示 "string"／0
        $example = $this->examples->value($path, $type, $description, $dicts);
        if (! ($example instanceof \stdClass) && $example !== []) {
            $schema['example'] = $example;
        }

        return $schema;
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
    private function write(string $path, array $paths, string $authScheme): void
    {
        $doc = [
            'openapi' => '3.0.3',
            'info' => ['title' => config('app.name'), 'version' => '1.0.0'],
            'paths' => $paths,
            // App（機器）認證（factory.auth）用的 Bearer token，取得方式見 POST /api/factory/token
            // 每份文件只宣告自己用得到的認證方式，否則 Swagger UI 的 Authorize 視窗會出現不相干的欄位：
            // - /docs、/docs/hq（用戶接口）：userBearerAuth，填登入（POST /api/auth/login、/api/hq/auth/login）
            //   回傳的 data.access_token；沒宣告它就沒有 Authorize 按鈕，Try it out 不帶 Authorization header，
            //   需登入的接口一律回 20001「缺少憑證」。
            // - /docs/partner（機器接口）：appBearerAuth，token 取得方式見 POST /api/factory/token。
            // 設成全域預設；標 @api-auth public 的接口在 operation 層覆寫為不需認證。
            'components' => ['securitySchemes' => [
                $authScheme => $authScheme === 'userBearerAuth'
                    ? ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT', 'description' => '填登入回傳的 data.access_token（不用加 Bearer 前綴）']
                    : ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT', 'description' => '填 POST /api/factory/token 回傳的 data.access_token（不用加 Bearer 前綴）'],
            ]],
            'security' => [[$authScheme => []]],
        ];

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($this->option('pretty') ? JSON_PRETTY_PRINT : 0);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($doc, $flags));
    }
}

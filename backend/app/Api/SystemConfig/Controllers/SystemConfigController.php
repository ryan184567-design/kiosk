<?php

/* *****************************************
File: SystemConfigController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 系統環境配置管理，見 README 17。
***************************************** */

namespace App\Api\SystemConfig\Controllers;

use App\Api\SystemConfig\Models\SystemConfig;
use App\Api\SystemConfig\Services\SystemConfigImageService;
use App\Api\SystemConfig\Services\SystemConfigService;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use RuntimeException;

// 系統環境配置管理，見 README 17。不分端口（設定是整站層級，不像 Role/User/
// Menu 綁定 portal_id），權限一律用路由層 permission.api:{code}（見
// app/Support/Http/Middleware/EnsurePermission）檢查目前登入端口是否持有
// 對應權限碼即可，不需要 BaseApiController::resolveTargetPortalId 那套跨端口
// 機制。SystemConfigService 用方法參數注入，理由同 RoleController 開頭註解、
// README 9.2（維持全站一致寫法，這個模塊目前雖無子站覆寫需求）。
class SystemConfigController extends BaseApiController
{
    use PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/system-configs
     * @api-tag SystemConfig
     * @api-summary 列出系統環境配置
     * @api-description
     * 查詢字串參數 `module` 可選，帶了（例如 `site_basic`、`sso`）只回傳
     * `cfg_module` 相符的設定項；不帶回傳全部，供後台依模組分組渲染成
     * 設定頁面（例如「站台基本設定」、「SSO 對接設定」各自一個分頁）。
     * **這支只能查詢，不能新增／刪除設定項**——有哪些設定項存在、型別是
     * 什麼、預設值是什麼，這些是開發階段就寫死在 seeder 裡的（新增一筆
     * 代表程式碼要多讀一個設定名稱，本來就得工程師配合開發），後台管理員
     * 透過這支跟下面的 `PUT` 只能查詢、修改既有設定項的**值**
     * （`cfg_value`），不能自己生出新的設定項。
     * `cfg_option` 的格式依 `cfg_type` 而不同：`enum`/`multi` 是允許的選項
     * 陣列（例如 `["0","1"]`）；`string`/`textarea`/`int` 是最大長度數字；
     * `float` 是小數位數上限；`date`/`datetime`/`time` 是顯示用的日期格式
     * 字串（例如 `YYYY/MM/dd`），不是驗證規則；`dic`/`dic_multi` 是要去查
     * 的字典代碼字串（見 `App\Api\Dictionary` 模組，`GET /api/dictionaries`），
     * 實際允許的值是該字典底下啟用中的項目。
     * 每筆回傳都附 `options` 欄位（下拉選項自動帶出，見 17.2）：`enum`/
     * `multi`/`dic`/`dic_multi` 為 `[{value,label},...]`，前端拿到清單即可
     * 直接渲染下拉，不需要再另外呼叫字典 API；其餘類型 `options` 為空陣列。
     * 分頁參數 `per_page=-1`（預設）代表不分頁，回傳全部。
     * @api-portal *
     * @api-response-field data.items[].id int 設定ID
     * @api-response-field data.items[].cfg_module string 所屬模組
     * @api-response-field data.items[].cfg_name string 設定名稱（英文代碼）
     * @api-response-field data.items[].cfg_title string 中文說明
     * @api-response-field data.items[].cfg_type dict:system_cfg_type 型別，決定 cfg_value 怎麼驗證、cfg_option 怎麼解讀（見上方 description）
     * @api-response-field data.items[].cfg_option string 選項規則，格式依 cfg_type 而定（見上方 description）
     * @api-response-field data.items[].image_spec object image 類型專用：cfg_option 正規化後的完整圖片規格（width/height/mode/format/transparent/background/max_kb/allowed_formats，見 17.5），其餘類型為 null
     * @api-response-field data.items[].cfg_style string 前端渲染用的樣式設定（例如輸入框寬度、行數）
     * @api-response-field data.items[].cfg_value string 目前設定值（multi/dic_multi 為 JSON 陣列字串，例如 ["a","b"]；password 類型一律回空字串，不吐明文／密文，見 has_value）
     * @api-response-field data.items[].has_value bool 是否已設定過值（password 類型專用，讓後台能顯示「已設定」提示而不用回顯實際值；其餘類型固定看 cfg_value 本身即可判斷）
     * @api-response-field data.items[].cfg_default string 預設值
     * @api-response-field data.items[].options array 依 cfg_type 自動解析出的下拉選項 [{value,label},...]，enum/multi/dic/dic_multi 才有內容，其餘為空陣列
     * @api-response-field data.items[].sort int 同模組內的顯示排序
     * @api-response-field data.total int 總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     * @api-dict system_cfg_type string=單行文字,textarea=多行文字,int=整數,float=小數,bool=布林開關,enum=下拉選項,multi=多選,dic=字典下拉,dic_multi=字典多選,date=日期,datetime=日期時間,time=時間,email=信箱,url=網址,color=色碼,password=密碼金鑰,json=JSON結構
     */
    public function index(Request $request, SystemConfigService $configs)
    {
        $result = $this->paginateOrGet(
            $configs->queryByModule($request->string('module')->value() ?: null),
            (int) $request->integer('per_page', -1),
            (int) $request->integer('page', 1),
        );

        // 見 17.2：逐筆自動帶出下拉選項（enum/multi 由 cfg_option 展開、
        // dic/dic_multi 由字典啟用中項目解析，見 17.3），前端不用再另外
        // 呼叫字典 API 才能渲染下拉。
        $options = $configs->resolveOptions($result['items']);

        $result['items'] = collect($result['items'])
            ->map(fn (SystemConfig $config) => $this->present($config, $options[$config->id] ?? [], $configs))
            ->all();

        return $this->success($result);
    }

    /**
     * @api-method PUT
     * @api-path /api/system-configs/{system_config}
     * @api-tag SystemConfig
     * @api-summary 更新系統環境配置的值
     * @api-description
     * 只能改這筆設定的 `cfg_value`，依 `cfg_type` 決定驗證方式：
     * `enum` 送的值必須完全等於 `cfg_option` 陣列裡的其中一個，不符合直接
     * 拒絕；`bool` 只接受 `0`/`1`；`int` 必須是整數；`float` 必須是數字；
     * `string`/`textarea` 若 `cfg_option` 有設定最大長度，超過就拒絕，沒設
     * 定則不限制長度；`json` 必須是合法 JSON；`email`/`url`/`color` 依對應
     * 格式檢查；`multi`/`dic_multi` 送 JSON 陣列字串（例如 `["a","b"]`），
     * 每個成員都必須在允許的選項（`cfg_option`／字典啟用中項目）內；`date`/
     * `datetime`/`time` 目前不驗證內容格式（`cfg_option` 只是顯示用的格式
     * 字串，不是驗證規則）；`dic` 送的值必須是 `cfg_option` 指定字典（見
     * `App\Api\Dictionary` 模組）底下啟用中的項目值之一，字典代碼本身
     * 不存在或已停用會直接拒絕（視為設定結構本身有問題）。
     * `value` 可傳空字串清空設定值（`enum`/`bool`/`dic` 這類必選類型除外，
     * 它們不接受空值；`password` 類型的空字串則是另一種特例——代表「不
     * 更改」，不是清空，因為後台從不回顯密碼明文，留白送出沒辦法區分
     * 「故意清空」與「原樣沒填」）。回傳的 `cfg_value` 是正規化後的值
     * （`multi`/`dic_multi` 一律存回 JSON 陣列字串；`password` 一律回空
     * 字串，見 `has_value`）。
     * @api-portal *
     * @api-request-field value string 新的設定值，可傳空字串清空（enum/bool/dic 不接受空值；password 空字串代表不更改）
     * @api-request-example
     * {"value": "1"}
     * @api-response-field data.id int 設定ID
     * @api-response-field data.cfg_value string 更新後的設定值（password 類型一律回空字串）
     * @api-response-field data.has_value bool 是否已設定過值（password 類型專用）
     */
    public function update(Request $request, int $system_config, SystemConfigService $configs)
    {
        $config = SystemConfig::query()->find($system_config);

        if (! $config) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.system_config.not_found'), 404);
        }

        // 允許空字串：清空設定值是合法操作（footer_text、logo 這類本來就該
        // 能清空）；是否接受空值由 SystemConfigService::normalizeValue 依
        // cfg_type 決定（enum/bool/dic 等必選類型不接受空值）。全域的
        // ConvertEmptyStringsToNull 中介層會把請求裡的 "" 轉成 null，驗證
        // 規則用 nullable 放行後這裡要轉回空字串，否則會傳 null 給型別為
        // 非 nullable string 的 updateValue() 直接炸掉（已實測踩過）。
        $data = $this->validateInput($request, ['value' => 'nullable|string']);

        try {
            $config = $configs->updateValue($this->currentUser($request), $config, $data['value'] ?? '', $request->ip());
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success(['id' => $config->id, 'cfg_value' => $configs->maskedValue($config), 'has_value' => $configs->hasValue($config)]);
    }

    /**
     * @api-method PUT
     * @api-path /api/system-configs/batch
     * @api-tag SystemConfig
     * @api-summary 批量更新系統環境配置的值
     * @api-description
     * 後台改成整頁一次儲存（取代舊版每一列各自獨立 `PUT`），這支包在同一個
     * DB 交易內：任一筆驗證失敗（規則與單筆 `PUT /api/system-configs/{id}`
     * 完全相同）整批回滾、不會寫入任何一筆，並回傳失敗訊息方便定位是哪筆
     * 設定填錯。
     * @api-portal *
     * @api-request-field items[].id int 設定ID
     * @api-request-field items[].value string 新的設定值，可傳空字串清空
     * @api-request-example
     * {"items": [{"id": 1, "value": "WebBase"}, {"id": 2, "value": "#1677ff"}]}
     * @api-response-field data.items[].id int 設定ID
     * @api-response-field data.items[].cfg_value string 更新後的設定值（password 類型一律回空字串）
     * @api-response-field data.items[].has_value bool 是否已設定過值（password 類型專用）
     */
    public function batchUpdate(Request $request, SystemConfigService $configs)
    {
        $data = $this->validateInput($request, [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer',
            'items.*.value' => 'nullable|string',
        ]);

        try {
            $results = $configs->batchUpdate($this->currentUser($request), $data['items'], $request->ip());
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success(['items' => $results]);
    }

    /**
     * @api-method POST
     * @api-path /api/system-configs/{system_config}/image
     * @api-tag SystemConfig
     * @api-summary 上傳圖片並依設定自動產生縮圖
     * @api-description
     * 僅適用 `cfg_type=image` 的設定項（見 17.5）。上傳的原圖存在私有磁碟
     * （供之後「編輯圖片」重新裁切用，不對外公開），並依這個設定項的
     * `cfg_option` 自動產生縮圖存到公開磁碟，`cfg_value` 更新為新圖片網址。
     * 規格鍵與預設值見 `SystemConfig::defaultImageSpec()`／模型端
     * `imageSpec()`（不論是否設定都回傳正規化後的完整規格，`image_spec`
     * 欄位）：`width`／`height` 輸出尺寸、`mode` 為 `cover`（填滿輸出）或
     * `contain`（完整保留＋補背景）、`format` 輸出格式（png／jpeg／webp／
     * gif，預設 png）、`background` 補背景色碼（不設＝透明，JPEG／GIF 因為
     * 沒有 alpha 通道會自動改用白底）、`max_kb` 來源檔大小上限（預設 5MB）、
     * `allowed_formats` 允許上傳的來源格式清單（預設全開）。設定值不合法
     * 時一律回退預設，不會讓上傳流程整組壞掉。
     * @api-portal *
     * @api-request-field image file 圖片檔案（multipart/form-data）
     * @api-request-example
     * (multipart/form-data，欄位 image 為檔案本體，無法用 JSON 表示，見 10.4)
     * @api-response-field data.id int 設定ID
     * @api-response-field data.cfg_value string 新圖片的網址
     * @api-response-field data.image_spec object 這個設定項目前的圖片規格（見上方說明）
     */
    public function uploadImage(Request $request, int $system_config, SystemConfigImageService $images)
    {
        $config = SystemConfig::query()->find($system_config);

        if (! $config) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.system_config.not_found'), 404);
        }

        // 這裡只做「真的是圖片、不是惡意超大檔」的外層防線；實際允許的來源
        // 格式與大小上限依設定項規格（見 SystemConfig::defaultImageSpec），
        // 由 SystemConfigImageService::assertValidUpload 判斷，錯誤訊息才能
        // 明確告訴管理員是哪個規格卡住。
        $data = $this->validateInput($request, ['image' => 'required|file|image|max:20480']);

        try {
            $config = $images->upload($config, $data['image']);
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success([
            'id' => $config->id,
            'cfg_value' => $config->cfg_value,
            'image_spec' => $config->imageSpec(),
        ]);
    }

    /**
     * @api-method PUT
     * @api-path /api/system-configs/{system_config}/image/crop
     * @api-tag SystemConfig
     * @api-summary 從已上傳的原圖重新圈選裁切區域
     * @api-description
     * 「編輯圖片」流程：`x`/`y`/`width`/`height` 是使用者在裁切對話框圈選的
     * 範圍，一律以原圖本身的實際像素為準（前端負責把畫面上的顯示座標換算
     * 回原圖像素座標，不要傳顯示尺寸座標）。**允許超出原圖邊界**（`x`／`y`
     * 可為負、`width`／`height` 可大於原圖）：超出的部分會用背景色或透明
     * 補齊，這就是對話框裡的「擴圖添加背景」（見 17.5、`PhotoClass::expandCrop`）。
     * 可選參數：`output_width`／`output_height` 這次要輸出的尺寸（沒帶＝用
     * 設定項規格，前端對話框可讓使用者微調）、`mode` 為 `cover`（填滿輸出，
     * 框選比例已由前端鎖定成輸出比例）或 `contain`（完整保留框選範圍＋補
     * 背景，框選比例自由）、`background` 補背景色（色碼字串；`transparent`
     * 或省略＝透明，JPEG／GIF 會自動改用白底）、`format` 輸出格式（省略＝
     * 設定項規格）。產生的圖片檔名帶時間戳記，舊檔一併清掉。
     * @api-portal *
     * @api-request-field x int 裁切區域左上角 X 座標（原圖像素，可為負＝向左擴圖）
     * @api-request-field y int 裁切區域左上角 Y 座標（原圖像素，可為負＝向上擴圖）
     * @api-request-field width int 裁切區域寬度（原圖像素，可大於原圖寬度）
     * @api-request-field height int 裁切區域高度（原圖像素，可大於原圖高度）
     * @api-request-field output_width int 輸出寬度（像素），省略＝設定項規格
     * @api-request-field output_height int 輸出高度（像素），省略＝設定項規格
     * @api-request-field mode string cover=填滿輸出（可能裁切）、contain=完整保留＋補背景，省略＝設定項規格
     * @api-request-field background string 補背景色碼，例如 #ffffff；transparent 或省略＝透明（JPEG/GIF 自動改白底）
     * @api-request-field format string 輸出格式 png/jpeg/webp/gif，省略＝設定項規格
     * @api-request-example
     * {"x": -20, "y": 0, "width": 360, "height": 200, "mode": "contain", "background": "transparent", "format": "png"}
     * @api-response-field data.id int 設定ID
     * @api-response-field data.cfg_value string 新圖片的網址
     * @api-response-field data.image_spec object 這個設定項目前的圖片規格
     */
    public function cropImage(Request $request, int $system_config, SystemConfigImageService $images)
    {
        $config = SystemConfig::query()->find($system_config);

        if (! $config) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.system_config.not_found'), 404);
        }

        // x/y 允許負數、width/height 允許超過原圖：框選範圍可以拉出原圖邊界，
        // 超出的部分用背景色／透明補齊（「擴圖添加背景」，見 17.5）。
        $data = $this->validateInput($request, [
            'x' => 'required|integer|min:-100000|max:100000',
            'y' => 'required|integer|min:-100000|max:100000',
            'width' => 'required|integer|min:1|max:200000',
            'height' => 'required|integer|min:1|max:200000',
            'output_width' => 'nullable|integer|min:1|max:10000',
            'output_height' => 'nullable|integer|min:1|max:10000',
            'mode' => 'nullable|string|in:cover,contain',
            'background' => 'nullable|string|max:20',
            'format' => 'nullable|string|in:png,jpeg,jpg,webp,gif',
        ]);

        try {
            $config = $images->crop($config, $data);
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success([
            'id' => $config->id,
            'cfg_value' => $config->cfg_value,
            'image_spec' => $config->imageSpec(),
        ]);
    }

    /**
     * @api-method GET
     * @api-path /api/system-configs/{system_config}/image/original
     * @api-tag SystemConfig
     * @api-summary 取得已上傳的原圖（供「編輯圖片」對話框載入預覽）
     * @api-description
     * 回傳原圖的二進位內容（`Content-Type` 依實際圖片格式），不是 JSON
     * 包裝——原圖存在私有磁碟不對外公開，前端需帶身份驗證 header 呼叫
     * （例如用 `authFetch` 讀成 blob 後轉成 `URL.createObjectURL`），不能
     * 直接當 `<img src>` 使用。尚未上傳過原圖時回 404。
     * @api-portal *
     * @api-no-request-body
     * @api-response-empty
     */
    public function originalImage(int $system_config, SystemConfigImageService $images)
    {
        $config = SystemConfig::query()->find($system_config);

        if (! $config) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.system_config.not_found'), 404);
        }

        try {
            [$binary, $mime] = $images->originalContent($config);
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage(), 404);
        }

        return response($binary, 200)->header('Content-Type', $mime);
    }

    private function present(SystemConfig $config, array $options, SystemConfigService $configs): array
    {
        return [
            'id' => $config->id,
            'cfg_module' => $config->cfg_module,
            'cfg_name' => $config->cfg_name,
            'cfg_title' => $config->cfg_title,
            'cfg_type' => $config->cfg_type,
            'cfg_option' => $config->cfg_option,
            // 見 17.5：image 類型的 cfg_option 已在模型端正規化成完整規格，
            // 前端（設定頁、裁切對話框）直接用它決定輸出尺寸／模式／格式／
            // 背景，不需要自己再兜一套預設值邏輯。非 image 類型為 null。
            'image_spec' => $config->cfg_type === 'image' ? $config->imageSpec() : null,
            'cfg_style' => $config->cfg_style,
            // password 類型一律不吐明文／密文回前端，見
            // SystemConfigService::maskedValue；has_value 讓後台顯示
            // 「已設定」提示，不用回顯實際值也能判斷有沒有填過。
            'cfg_value' => $configs->maskedValue($config),
            'has_value' => $configs->hasValue($config),
            'cfg_default' => $config->cfg_default,
            // 依 cfg_type 自動解析出的下拉選項（見 17.2、17.3）：enum/multi/
            // dic/dic_multi 為 [{value,label},...]，其餘類型為空陣列。
            'options' => $options,
            'sort' => $config->sort,
        ];
    }
}

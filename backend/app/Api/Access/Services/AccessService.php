<?php

namespace App\Api\Access\Services;

use GuzzleHttp\Client;

use Illuminate\Support\Carbon;

use App\Api\Access\Models\AccessSetting;
use App\Api\Access\Models\AccessFloor;
use App\Api\Access\Models\AccessCard;

use RuntimeException;

class AccessService
{
        // 查詢頁用的連線逾時秒數，避免測試環境連不上時卡住畫面太久
    const BUILDING_PLUS_QUERY_TIMEOUT = 5;

    // DelPerson 等待回應秒數：群英刪除人員需同步到卡機，實測 10 秒會逾時、20 秒可成功
    const BUILDING_PLUS_DELETE_TIMEOUT = 20;


    // 8H10D（32 位元卡號以 10 碼十進位表示）→ 4H10D（高 16 位元、低 16 位元各自轉十進位，各補滿 5 碼後相接）
    public function convert_four_h10d_card(string|int $card_no): string
    {
        $value = (int) $this->normalize_card_digits($card_no);

        if ($value > 4294967295) {
            throw new RuntimeException(__('api.access.invalid_card_no'));
        }

        return sprintf('%05d%05d', $value >> 16, $value & 0xFFFF);
    }

    // 4H10D → 8H10D：前 5 碼是高 16 位元、後 5 碼是低 16 位元，各自不得超過 65535
    public function convert_eight_h10d_card(string|int $card_no): string
    {
        $digits = $this->normalize_card_digits($card_no);
        $high = (int) substr($digits, 0, 5);
        $low = (int) substr($digits, 5, 5);

        if ($high > 65535 || $low > 65535) {
            throw new RuntimeException(__('api.access.invalid_card_no'));
        }

        return sprintf('%010d', ($high << 16) | $low);
    }

    // 卡號必須是純數字且不超過 10 碼；不足 10 碼左邊補 0
    private function normalize_card_digits(string|int $card_no): string
    {
        $card_no = (string) $card_no;

        if (! ctype_digit($card_no) || strlen($card_no) > 10) {
            throw new RuntimeException(__('api.access.invalid_card_no'));
        }

        return str_pad($card_no, 10, '0', STR_PAD_LEFT);
    }

    // 取得群英 Building API 帳密驗證字串(Basic Auth)
    protected function get_building_plus_credentials()
    {
        $username = env('BUILDINGPLUS_ACCOUNT_USERNAME', 'SystemAdmin');
        $password = env('BUILDINGPLUS_ACCOUNT_PASSWORD', '123456');
        return base64_encode("$username:$password");
    }

    // 訪客卡建立/刪除使用的組織 ID(.env BUILDINGPLUS_ORGANIZE_ID)；未設定時才依名稱查「訪客」組織
    // 組織裝置(例如住戶的電梯)只對同組織人員生效，要讓訪客使用某組織的裝置時，改這個 ID 即可
    protected function get_guest_organize_id()
    {
        $organizeId = env('BUILDINGPLUS_ORGANIZE_ID');
        return !empty($organizeId) ? $organizeId : $this->GetOrganize("訪客");
    }

    // 獲取 Building+ API 地址
    public function get_building_plus_url(string $type_mode)
    {
        // 到 access_settings 表格找尋 name 為 'BuildingPlus' 的紀錄
        $accessSetting = AccessSetting::query()->where('name', 'BuildingPlus')->first();

        // 檢查是否找到紀錄，並取得 API 網址
        if (!$accessSetting) {
            throw new RuntimeException(__('api.access.not_found_url'), 404);
        }

        $url = $accessSetting->url.$type_mode;

        return $url;
    }


    // 檢查柏聯 Building API 連線狀態(查詢頁用)
    public function CheckConnection()
    {
        $apiUrl = $this->get_building_plus_url("GetOrganize");
        $credentials = $this->get_building_plus_credentials();

        try {
            $client = new Client();
            $client->request('GET', $apiUrl, [
                'headers' => [
                    'Authorization' => "Basic $credentials"
                ],
                'timeout' => self::BUILDING_PLUS_QUERY_TIMEOUT,
                'connect_timeout' => self::BUILDING_PLUS_QUERY_TIMEOUT,
            ]);

            return [
                'connected' => true,
                'message' => '連線成功',
            ];
        } catch (\Exception $e) {
            throw new RuntimeException(__('api.access.url_connection_failed'), 404);
        }
    }

    // 取得指定組織 ID
    public function GetOrganize(string $firm_name)
    {
        $apiUrl = $this->get_building_plus_url("GetOrganize");
        $credentials = $this->get_building_plus_credentials();

        $client = new Client();
        $response = $client->request('GET', $apiUrl, [
            'headers' => [
                'Authorization' => "Basic $credentials"//需先將驗證身分轉碼Base64
            ]
        ]);
        $result = $response->getBody()->getContents();

        // 將 JSON 字符串轉換為 PHP 對象
        $result = json_decode($result);
        $Organizes = $result->Data; //取回的所有公司組織ID及名稱

        foreach ($Organizes as $Organize){
            $OrganizeName = $Organize->OrganizeName; //取出每個組織名

            if($OrganizeName === $firm_name){
                $OrganizeId = $Organize->OrganizeId; //取出組織ID
                $result = $OrganizeId;
                return $result;
            }else{
                $result = null;
            }
        }

        if($result){
            throw new RuntimeException(__('api.access.not_found_organize_id'), 404);
        }
    }

    // 取得組織列表GetOrganize
    public function GetOrganizeList()
    {
        $apiUrl = $this->get_building_plus_url("GetOrganize");

        $credentials = $this->get_building_plus_credentials();

        try {
            $client = new Client();
            $response = $client->request('GET', $apiUrl, [
                'headers' => [
                    'Authorization' => "Basic $credentials"
                ],
                'timeout' => self::BUILDING_PLUS_QUERY_TIMEOUT,
                'connect_timeout' => self::BUILDING_PLUS_QUERY_TIMEOUT,
            ]);

            $result = json_decode($response->getBody()->getContents());
            return (isset($result->Data) && is_array($result->Data)) ? $result->Data : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    // 取得裝置資訊列表GetDevice
    public function GetDevice($params = [])
    {
        $apiUrl = $this->get_building_plus_url("GetDevice");

        $credentials = $this->get_building_plus_credentials();

        try {
            $client = new Client();
            $response = $client->post($apiUrl, [
                'headers' => [
                    'Authorization' => "Basic $credentials"
                ],
                'json' => empty($params) ? (object) [] : $params,
                'timeout' => self::BUILDING_PLUS_QUERY_TIMEOUT,
                'connect_timeout' => self::BUILDING_PLUS_QUERY_TIMEOUT,
            ]);
            $result = json_decode($response->getBody()->getContents());

            return (isset($result->Data) && is_array($result->Data)) ? $result->Data : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    // 依 GetDevice() 的裝置歸屬，把本地設定的卡機/梯控拆成 AddPerson 的 OrganizeDevices 與 ShareDevices：
    // - 共用裝置(OrganizeId = -1)放 ShareDevices，其餘放 OrganizeDevices；查無的裝置(API 失敗或已移除)沿用放 OrganizeDevices
    // - 梯控(有設定可到樓層的裝置)同時放 AccessControlList(依表單設定的通行模式/時段)與 FloorList
    // - 組織裝置須屬於人員所在組織才會生效，不同組織的裝置記 warning 方便現場排查
    protected function split_devices_by_type(string $accessControlList, string $floorList)
    {
        $deviceOrganizeIds = [];

        foreach ((array) $this->GetDevice() as $device) {
            if (isset($device->DeviceId)) {
                $deviceOrganizeIds[$device->DeviceId] = isset($device->OrganizeId) ? (string) $device->OrganizeId : '';
            }
        }

        $isShare = function ($deviceId) use ($deviceOrganizeIds) {
            return isset($deviceOrganizeIds[$deviceId]) && $deviceOrganizeIds[$deviceId] === '-1';
        };

        $result = [
            'organize' => ['AccessControlList' => [], 'FloorList' => []],
            'share' => ['AccessControlList' => [], 'FloorList' => []],
        ];
        foreach ((array) $accessControlList as $row) {
            if (!isset($row['DeviceId'])) {
                continue;
            }

            $result[$isShare($row['DeviceId']) ? 'share' : 'organize']['AccessControlList'][] = $row;
        }
        foreach ((array) $floorList as $floorItem) {
            if (!isset($floorItem['DeviceId'])) {
                continue;
            }

            $result[$isShare($floorItem['DeviceId']) ? 'share' : 'organize']['FloorList'][] = $floorItem;
        }

        // 沒有任何裝置時維持原本的空物件格式
        foreach ($result as $key => $section) {
            if (empty($section['AccessControlList']) && empty($section['FloorList'])) {
                $result[$key] = (object) [];
            }
        }
        return $result;
    }

    // 取得指定樓層的訪客卡片門禁權限設定(本地資料庫)，查無設定回傳 null
    public function GetFloorAccess(int $floor)
    {
        $floorAccess = AccessFloor::query()
            ->where('floor', $floor)
            ->where('status', 1)
            ->first();

        if (!$floorAccess) {
            throw new RuntimeException(__('api.access.access_floor_not_found'), 404);
        }

        $accessControlList = json_decode($floorAccess->access_control_list, true);
        $floorList = json_decode($floorAccess->floor_list, true);

        $floorAccess->access_control_list = is_array($accessControlList) ? $accessControlList : [];
        $floorAccess->floor_list = is_array($floorList) ? $floorList : [];

        return $floorAccess;
    }

    //人員新增設定 AddPerson
    public function AddPerson(int $card_number, int $door_floor, int $insert_card_id, string $PersonId)
    {
        // 卡號轉成4H10D格式
        $four_h10d = $this->convert_four_h10d_card($card_number);
        // 获取 API 地址
        $apiUrl = $this->get_building_plus_url("AddPerson");
        //先獲取訪客唯一值組織ID
        $OrganizeId = $this->get_guest_organize_id();

        $credentials = $this->get_building_plus_credentials();

        //----------------------------------------------------------------------------//
        $reserve_date = date('Y-m-d');
        // 构建要发送的 JSON 数据
        $jsonData = [
            "OrganizeId" => $OrganizeId,//組織(訪客)唯一值ID
            "PersonId" => $PersonId,//人員ID(先暫時用寫入訪可卡號的唯一ID)
            "PersonName" => "訪客".$insert_card_id,
            "RealTimeEventPicture" => "",//訪客暫時先不帶考勤照片
            "RecognitionPicture" => "",//訪客暫時先不帶人臉辨識照片
            "PINCode" => "",//數字密碼都先帶不帶
            "CardIds" => [$four_h10d],//(有限制一定要10碼數字)門禁卡卡號轉4H10D格式碼卡號
            "LicensePlates" => [],//車牌先不帶
            "GroupName" => "",//群組名稱先不帶
            "Email" => "",
            "Remark" => "",//備註
            "ValidEnable" => true,//啟用有效時間
            "ValidBeginTime" => $reserve_date." 00:00",//有效開始時間 例:(2021/03/01 00:00)
            "ValidEndTime" => $reserve_date." 23:59",//有效結束時間 例:(2031/03/01 23:59)
            "AccessType" => 1,//設定通行門禁方式
                                // 0為手動設定，則DeviceSetting將為必填
                                // 1為套用預設門禁
                                // 2則不進行修改
            "DefaultAccessName" => "預設".$door_floor."F訪客",//資料庫絕對條件查詢預設門禁名稱(預設1F訪客).....(預設11F訪客)
            "OrganizeDevices" =>  (object)[], // 空物件，組織裝置、梯控(綁定組織的卡機、電梯QRCode讀頭)
            "ShareDevices" => (object)[], // 空物件，共用裝置、梯控(例如一樓大廳通關機、電梯QRCode讀頭)
        ];

        // 若本地有針對此樓層設定訪客卡片門禁權限，改用手動指定裝置權限(AccessType=0)，不再套用柏聯後台的預設門禁群組
        $floorAccess = $this->GetFloorAccess($door_floor);
        if ($floorAccess) {
            $devices = $this->split_devices_by_type($floorAccess->access_control_list, $floorAccess->floor_list);
            $jsonData["AccessType"] = 0;
            $jsonData["DefaultAccessName"] = "";
            $jsonData["OrganizeDevices"] = $devices['organize'];
            $jsonData["ShareDevices"] = $devices['share'];
        }

        // 送出前先寫入 person_id / reserve_date：
        // 送出後若連線逾時、例外或回應異常，群英端仍可能已建立人員，本地要留著 person_id 才刪得到
        $updated = AccessCard::query()
            ->where('id', $insert_card_id)//找到指定的加卡資料ID
            ->update([
                'person_id' => $PersonId,
                'reserve_date' => $reserve_date,//訪客在前台網站預約的日期、或訪客機抓取當天的日期
                'updated_at' => Carbon::now()
            ]);

        if (!$updated) {
            throw new RuntimeException(__('api.access.add_card_failed'), 404);
        }

        $client = new Client();
        $response = $client->post($apiUrl, [
            'headers' => [
                'Authorization' => "Basic $credentials"//需先將驗證身分轉碼Base64
            ],
            'json' => $jsonData,
        ]);

        // 处理响应结果
        $result = $response->getBody()->getContents();

        $data = json_decode($result, true);

        if (! is_array($data) || (int) ($data['Code'] ?? 0) !== 200) {
            throw new RuntimeException(__('api.access.add_card_api_failed'), 404);
        }

        return true;
    }

    // 刪除柏聯的線上訪客卡資料
    // $timeout：等待回應秒數，群英刪除人員需同步到卡機，裝置多或離線時回應較慢
    public function DeletePersonInOrganize(string $card_no)
    {
        $card = AccessCard::query()->where('card_number', $card_no)->first();

        $organizeId = $this->get_guest_organize_id();

        $timeout = self::BUILDING_PLUS_DELETE_TIMEOUT;

        $apiUrl = $this->get_building_plus_url("DelPerson");

        $credentials = $this->get_building_plus_credentials();

        $jsonData = [
            "OrganizeId" => $organizeId,
            "PersonId" => $card->person_id
        ];

        try {
            $client = new Client();
            $response = $client->delete($apiUrl, [
                'headers' => [
                    'Authorization' => "Basic $credentials"
                ],
                'json' => $jsonData,
                'timeout' => $timeout,
                'connect_timeout' => self::BUILDING_PLUS_QUERY_TIMEOUT
            ]);

            $result = $response->getBody()->getContents();

            $data = json_decode($result, true);

            if (! is_array($data) || (int) ($data['Code'] ?? 0) !== 200) {
                throw new RuntimeException(__('api.access.del_card_api_failed'), 404);
            }

            $updated = $card->update([
                    'reserve_date' => null,
                    'status' => 0,
                    'updated_at' => Carbon::now()
                ]);

            if (!$updated) {
                throw new RuntimeException(__('api.access.del_card_failed'), 404);
            }


            return true;
        } catch (\Exception $e) {
            throw new RuntimeException(__('api.access.del_card_failed'), 404);
        }
    }
}

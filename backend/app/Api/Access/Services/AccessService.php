<?php

namespace App\Api\Access\Services;

use GuzzleHttp\Client;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

use App\Api\Access\Models\AccessSetting;
use App\Api\Access\Models\AccessFloor;

use RuntimeException;

class AccessService
{
        // 查詢頁用的連線逾時秒數，避免測試環境連不上時卡住畫面太久
    const BUILDING_PLUS_QUERY_TIMEOUT = 5;

    // DelPerson 等待回應秒數：群英刪除人員需同步到卡機，實測 10 秒會逾時、20 秒可成功
    const BUILDING_PLUS_DELETE_TIMEOUT = 20;

    // 將卡號從8h10d轉為4h10d
    public function convert_four_h10d_card(int $card_no)
    {
        if(strlen($card_no) < 10){
            $interval = 10 - strlen($card_no);
            for($i = 0; $i < $interval; $i++){
                $card_no = '0'.$card_no;
            }
        }

        // 8H10D->4H4H10D
        $card_no = base_convert($card_no, 10, 16);
        $start_four = substr($card_no, 0, 4);
        $end_four = substr($card_no, 4);
        $start_four = base_convert($start_four, 16, 10);
        $end_four = base_convert($end_four, 16, 10);
        $start_four = str_pad(($start_four), 5, "0", STR_PAD_LEFT);
        $end_four = str_pad(($end_four), 5, "0", STR_PAD_LEFT);
        $four_h10d = $start_four.$end_four;

        return $four_h10d;
    }


    // 取得群英 Building API 帳密驗證字串(Basic Auth)
    protected function get_building_plus_credentials()
    {
        $username = env('BUILDINGPLUS_ACCOUNT_USERNAME', 'SystemAdmin');
        $password = env('BUILDINGPLUS_ACCOUNT_PASSWORD', '123456');
        return base64_encode("$username:$password");
    }

    // 訪客卡建立/刪除使用的群英組織 ID(.env BUILDINGPLUS_ORGANIZE_ID)；未設定時才依名稱查「訪客」組織
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
            return [
                'connected' => false,
                'message' => '連線失敗，無法取得柏聯 Building API 資料：' . $e->getMessage(),
            ];
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
            throw new RuntimeException(__('api.access.not_found_access_floor'), 404);
        }

        $accessControlList = json_decode($floorAccess->access_control_list, true);
        $floorList = json_decode($floorAccess->floor_list, true);

        $floorAccess->access_control_list = is_array($accessControlList) ? $accessControlList : [];
        $floorAccess->floor_list = is_array($floorList) ? $floorList : [];

        return $floorAccess;
    }

    // 4取得通行時段資訊列表 GetSchedule
    public function GetSchedule($params = [])
    {
        $apiUrl = $this->get_building_plus_url("GetSchedule");

        $credentials = $this->get_building_plus_credentials();

        Log::info("GetSchedule request " . json_encode($params, JSON_UNESCAPED_UNICODE));

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
            Log::error("GetSchedule 例外: " . $e->getMessage());
            return [];
        }
    }

    // 5通行時段新增設定 AddSchedule
    public function AddSchedule($OrganizeId,$StartTime,$EndTime,$Date)
    {
        // 获取 API 地址
        $apiUrl = $this->get_building_plus_url("AddSchedule");

        $credentials = $this->get_building_plus_credentials();

        $jsonData = [
            "OrganizeId" => $OrganizeId,
            "Name" => "訪客",
            "DateList" => [
                [
                    "DayType" => 1,//0為每週類型，1為指定日期類型
                    "ControlType" => 0,//0為允許通行，1為禁止通行
                    "StartTime" => $StartTime,
                    "EndTime" => $EndTime,
                    "Date" => $Date
                ]
            ],
            "WeekList" => [
                //訪客暫時只給當日通行不做週預排加入通行時段
                // [
                //     "StartTime" => "00:00",
                //     "EndTime" => "23:59",
                //     "WeekDay" => 0
                // ]
            ]
        ];

        // 使用 Guzzle 发送 HTTP POST 请求
        $client = new Client();
        $response = $client->post($apiUrl, [
            'headers' => [
                'Authorization' => "Basic $credentials"//需先將驗證身分轉碼Base64
            ],
            'json' => $jsonData
        ]);

        // 处理响应结果
        $result = $response->getBody()->getContents();

        return $result;
    }

    //6通行時段修改設定 SetSchedule
    public function SetSchedule()
    {
        // 获取 API 地址
        $apiUrl = $this->get_building_plus_url("SetSchedule");

        $credentials = $this->get_building_plus_credentials();
        // 构建要发送的 JSON 数据"DeviceName": "王曉明"
        $jsonData = [
            "ScheduleId" => "219c16f3-0c7e-4288-83a9-d172b621f7d1",
            "OrganizeId" => "df9dbfaa-6946-4359-be5a-ac0dc56ae5f5",
            "Name" => "新保測試", // 需手动先在 Building+ 后台新增组织群组
            "DateList" => [
                [
                    "DayType" => 1,
                    "ControlType" => 0,
                    "StartTime" => "00:00",
                    "EndTime" => "17:00",
                    "Date" => "2024/09/20"
                ]
            ],
            "WeekList" => [
                [
                    "StartTime" => "05:00",
                    "EndTime" => "23:59",
                    "WeekDay" => 5
                ]
            ]
        ];

        // 使用 Guzzle 发送 HTTP PUT 请求
        $client = new Client();
        $response = $client->put($apiUrl, [
            'headers' => [
                'Authorization' => "Basic $credentials"//需先將驗證身分轉碼Base64
            ],
            'json' => $jsonData,
        ]);

        // 处理响应结果
        $result = $response->getBody()->getContents();
    }

    //7通行時段刪除設定 DelSchedule
    public function DelSchedule()
    {
        // 获取 API 地址
        $apiUrl = $this->get_building_plus_url("DelSchedule");

        $credentials = $this->get_building_plus_credentials();

        // 构建要发送的 JSON 数据"DeviceName": "王曉明"
        $jsonData = [
            "OrganizeId" => "df9dbfaa-6946-4359-be5a-ac0dc56ae5f5",//(要帶組織ID)
            "ScheduleId" => "219c16f3-0c7e-4288-83a9-d172b621f7d1"//(要帶時程ID)
        ];

        // 使用 Guzzle 发送 HTTP POST 请求
        $client = new Client();
        $response = $client->Delete($apiUrl, [
            'headers' => [
                'Authorization' => "Basic $credentials"//需先將驗證身分轉碼Base64
            ],
            'json' => $jsonData,
        ]);

        // 处理响应结果
        $result = $response->getBody()->getContents();
    }

    //8取得人員列表 GetPerson
    public function GetPerson($params = [])
    {
        $apiUrl = $this->get_building_plus_url("GetPerson");

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
            $rawBody = $response->getBody()->getContents();
            $result = json_decode($rawBody);

            return (isset($result->Data) && is_array($result->Data)) ? $result->Data : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    //人員新增設定 AddPerson
    public function AddPerson(int $card_number, int $door_floor, int $insert_card_id, string $email)
    {
        // 卡號轉成4H10D格式
        $four_h10d = $this->convert_four_h10d_card($card_number);

        $reserve_date = date('Y-m-d');
        // 获取 API 地址
        $apiUrl = $this->get_building_plus_url("AddPerson");
        //先獲取訪客唯一值組織ID
        $OrganizeId = $this->get_guest_organize_id();

        $credentials = $this->get_building_plus_credentials();
        //----------------------------------------------------------------------------//
        if($email == ""){
            $personType = "VTOR";//如果沒有Email就是直接透過現場訪客機預約
            $PersonId = $personType.$insert_card_id;
        }else{
            $personType = "NSST";//新保前台訪客網站預約
            $PersonId = $personType.$insert_card_id;
        }
        //----------------------------------------------------------------------------//
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
        $updated = DB::table('guest_virtual_cards')
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

        return $result;
    }

    // 群英端人員依卡號(4H10D)對照 PersonId：[four_h10d => PersonId]，GetPerson 查詢失敗回傳空陣列
    public function GetPersonIdsByCard()
    {
        $map = [];
        foreach ((array) $this->GetPerson() as $person) {
            if (empty($person->PersonId)) {
                continue;
            }
            foreach ($this->GetPersonCardIds($person) as $cardId) {
                $map[$cardId] = (string) $person->PersonId;
            }
        }
        return $map;
    }


    // 訪客卡沒有 person_id 時，用 four_h10d 到群英查實際的 PersonId 並回寫；查不到回傳 null
    // $personIdsByCard：可傳入 GetPersonIdsByCard() 的結果，批次處理時避免重複查詢
    public function ResolveGuestCardPersonId($card, $personIdsByCard = null)
    {
        if (!empty($card->person_id)) {
            return $card->person_id;
        }
        if (empty($card->four_h10d)) {
            return null;
        }
        if ($personIdsByCard === null) {
            $personIdsByCard = $this->GetPersonIdsByCard();
        }
        $fourH10d = trim((string) $card->four_h10d);
        if (!isset($personIdsByCard[$fourH10d])) {
            return null;
        }

        $personId = $personIdsByCard[$fourH10d];
        DB::table('guest_virtual_cards')->where('id', $card->id)->update([
            'person_id' => $personId,
            'updated_at' => Carbon::now()
        ]);
        Log::info("訪客卡 " . $card->card_number . "(" . $fourH10d . ") 依卡號補回 person_id：" . $personId);
        return $personId;
    }

    // 刪除柏聯的線上訪客卡資料
    // 依人員ID刪除群英門禁人員(訪客卡)，成功(2xx)回傳 true
    public function DeletePersonById($personId, $timeout = self::BUILDING_PLUS_DELETE_TIMEOUT)
    {
        // 與建立時相同的組織
        return $this->DeletePersonInOrganize($this->get_guest_organize_id(), $personId, $timeout);
    }


    // 刪除指定組織底下的群英門禁人員，成功(2xx)回傳 true
    // $timeout：等待回應秒數，群英刪除人員需同步到卡機，裝置多或離線時回應較慢
    public function DeletePersonInOrganize($organizeId, $personId, $timeout = self::BUILDING_PLUS_DELETE_TIMEOUT)
    {
        $apiUrl = $this->get_building_plus_url("DelPerson");

        $credentials = $this->get_building_plus_credentials();

        $jsonData = [
            "OrganizeId" => $organizeId,
            "PersonId" => $personId
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

            $statusCode = $response->getStatusCode();

            return ($statusCode >= 200 && $statusCode < 300);
        } catch (\Exception $e) {
            return false;
        }
    }

    // GetPerson() 回應的卡號：Cards 為 [{CardId, LicensePlate}]，固定回傳 5 格，空的 CardId 略過
    public function GetPersonCardIds($person)
    {
        $cardIds = [];
        if (isset($person->Cards) && is_array($person->Cards)) {
            foreach ($person->Cards as $card) {
                if (isset($card->CardId) && trim((string) $card->CardId) !== '') {
                    $cardIds[] = trim((string) $card->CardId);
                }
            }
        }
        return array_values(array_unique($cardIds));
    }

    // 將 GetSchedule() 回傳的 WeekList(巢狀 DayType/DayList 結構) 轉成一行易讀文字，附加到每筆 schedule 的 WeekSummary 屬性(查詢頁用，這個版本的 Blade 不支援 @php 區塊)
    protected function build_schedule_week_summaries($schedules)
    {
        $weekDayNames = array('日', '一', '二', '三', '四', '五', '六');

        foreach ($schedules as $schedule) {
            $summaries = array();

            if (isset($schedule->WeekList) && is_array($schedule->WeekList)) {
                foreach ($schedule->WeekList as $weekType) {
                    if (isset($weekType->DayList) && is_array($weekType->DayList)) {
                        foreach ($weekType->DayList as $day) {
                            $dayLabel = isset($weekDayNames[$day->WeekDay]) ? $weekDayNames[$day->WeekDay] : $day->WeekDay;
                            $summaries[] = "週{$dayLabel} {$day->StartTime}~{$day->EndTime}";
                        }
                    }
                }
            }

            $schedule->WeekSummary = implode('、', $summaries);
        }

        return $schedules;
    }


    public function elevatorList(){
        $elevatorFloors = [];
        foreach ($this->GetElevatorFloorsByDeviceId() as $deviceId => $floorLabels) {
            $elevatorFloors[$deviceId] = [];
            foreach ($floorLabels as $floorCode => $floorLabel) {
                $elevatorFloors[$deviceId][] = ['Floor' => (int) $floorCode, 'Label' => $floorLabel];
            }
        }

        return $elevatorFloors;
    }

    public function getFloorAccessList()
    {
        $devices = (array) $this->GetDevice();
        $devicesById = [];
        foreach ($devices as $device) {
            $devicesById[$device->DeviceId] = $device;
        }

        // $devicesById[] = "4917b746-9835-47f1-8909-b17462d118cb";
        $floorAccessList = AccessFloor::query()->orderBy('floor')->get();
        foreach ($floorAccessList as $floorAccessItem) {
            $this->attachFloorListSummary($floorAccessItem, $devicesById);
        }

        return $floorAccessList;
    }

    // 新增訪客樓層門禁權限設定
    public function addFloorAccess(int $floor, string $access_control_list, string $floor_list, string $remark)
    {
        if ($this->floor_access_taken($floor)) {
            throw new RuntimeException(__('api.access.cannot_add_access_floor'), 404);
        }

        $floorList = $this->buildFloorList($floor_list);

        $result = AccessFloor::query()->create([
            'floor' => $floor,
            'access_control_list' => json_encode(json_decode($access_control_list, true), JSON_UNESCAPED_UNICODE),
            'floor_list' => json_encode($floorList, JSON_UNESCAPED_UNICODE),
            'remark' => $remark,
            'status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $result;
    }

    // 樓層重複檢查(編輯時排除自己)；GetFloorAccess() 依樓層只取第一筆，同樓層多筆設定會讓後面的設定失效
    protected function floor_access_taken(int $floor, $exceptId = null)
    {
        $query = AccessFloor::query()->where('floor', $floor);
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        return $query->exists();
    }

    // 取得單筆訪客樓層門禁權限設定(給編輯表單使用)
    public function getFloorAccessId(int $id)
    {
        $access = AccessFloor::query()->find($id);

        if (! $access) {
            throw new RuntimeException(__('api.access.not_found'), 404);
        }

        $result = $this->expandAccessFields($access);

        return $result;
    }

    // 編輯表單用：把 floor_list(JSON) 依 DeviceId 併回 access_control_list 各卡機列的 Floors，供表單逐列帶出可到樓層
    protected function expandAccessFields(AccessFloor $item)
    {
        $accessControlList = json_decode($item->access_control_list, true);
        $floorList = json_decode($item->floor_list, true);
        $accessControlList = is_array($accessControlList) ? $accessControlList : [];

        $floorsByDeviceId = [];
        if (is_array($floorList)) {
            foreach ($floorList as $floorListItem) {
                if (isset($floorListItem['DeviceId'], $floorListItem['Floors'])) {
                    $floorsByDeviceId[$floorListItem['DeviceId']] = $floorListItem['Floors'];
                }
            }
        }

        foreach ($accessControlList as $index => $row) {
            $deviceId = isset($row['DeviceId']) ? $row['DeviceId'] : null;
            $accessControlList[$index]['Floors'] = isset($floorsByDeviceId[$deviceId]) ? $floorsByDeviceId[$deviceId] : [];
        }
        $item->access_control_list = $accessControlList;

        return $item;
    }

    // 修改訪客樓層門禁權限設定
    public function editFloorAccess(int $id, int $floor, string $access_control_list, string $floor_list, string $remark)
    {
        $access = AccessFloor::query()->find($id);
        if (! $access) {
            throw new RuntimeException(__('api.access.not_found'), 404);
        }

        if ($this->floor_access_taken($floor, $access->id)) {
            throw new RuntimeException(__('api.access.cannot_edit_access_floor'), 404);
        }

        $floorList = $this->buildFloorList($floor_list);

        $result = $access->update([
            'floor' => $floor,
            'access_control_list' => json_encode(json_decode($access_control_list, true), JSON_UNESCAPED_UNICODE),
            'floor_list' => json_encode($floorList, JSON_UNESCAPED_UNICODE),
            'remark' => $remark,
            'updated_at' => Carbon::now(),
        ]);

        return $result;
    }

    // 整理表單送來的梯控樓層，有填樓層的卡機才組進 FloorList(格式同 AddPerson 的 FloorList)
    public function buildFloorList(string $floor_list)
    {
        $floorListArr = json_decode($floor_list, true);
        $result = [];
        foreach ((array) $floorListArr as $floorListItem) {
            if (empty($floorListItem['DeviceId']) || !isset($floorListItem['Floors']) || !is_array($floorListItem['Floors'])) {
                continue;
            }
            $floors = array_values(array_unique(array_map('intval', $floorListItem['Floors'])));
            if (empty($floors)) {
                continue;
            }
            $result[] = [
                'DeviceId' => $floorListItem['DeviceId'],
                'Floors' => $floors,
            ];
        }
        return $result;
    }

    // 刪除訪客樓層門禁權限設定
    public function delFloorAccess(int $id)
    {
        $access = AccessFloor::query()->find($id);
        if (! $access) {
            throw new RuntimeException(__('api.access.not_found'), 404);
        }

        $access->delete();
    }

    // 列表頁用：由 floor_list(JSON) 逐台電梯列出可到樓層(樓層代碼換成樓層名稱，對不到的保留代碼)與梯控卡機數
    protected function attachFloorListSummary(AccessFloor $item, $devicesById = [])
    {
        $floorListDecoded = json_decode($item->floor_list, true);
        $floorsByDevice = [];

        if (is_array($floorListDecoded)) {
            foreach ($floorListDecoded as $floorListItem) {
                if (!isset($floorListItem['DeviceId'], $floorListItem['Floors']) || !is_array($floorListItem['Floors'])) {
                    continue;
                }
                $deviceId = $floorListItem['DeviceId'];
                $device = isset($devicesById[$deviceId]) ? $devicesById[$deviceId] : null;
                $deviceName = ($device && isset($device->DeviceName)) ? $device->DeviceName : $deviceId;
                $floorLabels = $this->GetElevatorFloorLabels($deviceId);

                $floors = array_unique(array_map('intval', $floorListItem['Floors']));
                sort($floors);
                $labels = [];
                foreach ($floors as $floor) {
                    $labels[] = isset($floorLabels[$floor]) ? $floorLabels[$floor] : (string) $floor;
                }
                $floorsByDevice[] = ['device_name' => $deviceName, 'floors' => implode(', ', $labels)];
            }
        }
        $item->floors_by_device = $floorsByDevice;
        $item->ele_group_count = is_array($floorListDecoded) ? count($floorListDecoded) : 0;
        $item->access_control_count = count((array) json_decode($item->access_control_list, true));
    }

    // 依電梯樓層對照(以 DeviceId 比對)，回傳該裝置的 [樓層代碼 => 樓層名稱]；非電梯回傳空陣列
    public function GetElevatorFloorLabels(string $deviceId)
    {
        $floorsByDeviceId = $this->GetElevatorFloorsByDeviceId();
        return isset($floorsByDeviceId[(string) $deviceId]) ? $floorsByDeviceId[(string) $deviceId] : [];
    }

    // 電梯樓層對照整理成 [DeviceId => [樓層代碼 => 樓層名稱]]
    public function GetElevatorFloorsByDeviceId()
    {
        $elevators = $this->ElevatorSetting();
        $result = [];
        foreach ((array) $elevators as $elevator) {
            $floors = isset($elevator['floors']) ? (array) $elevator['floors'] : [];
            foreach (isset($elevator['device_ids']) ? (array) $elevator['device_ids'] : [] as $deviceId) {
                $result[(string) $deviceId] = $floors;
            }
        }
        return $result;
    }

    // 電梯樓層對照(來源：電梯廠商 EANF 設定的 L0116/L1732 樓層名稱)
    // device_ids：這部電梯在群英 GetDevice() 的 DeviceId(可填多台讀頭)，表單/列表依 DeviceId 對到這部電梯
    // floors：群英 FloorList 的樓層代碼 => 樓層名稱，表單依此產生可到樓層勾選框
    public function ElevatorSetting(){
        return
            [
                [
                    'id' => '01',
                    'name' => '無障礙左',
                    'device_ids' => ['4917b746-9835-47f1-8909-b17462d118cb'], // 無障礙電梯(左)
                    'floors' => [
                        1 => 'B4F', 2 => 'B3F', 3 => 'B2F', 4 => 'B1F',
                        5 => '1F', 6 => '2F', 7 => '3F', 8 => '4F', 9 => '5F', 10 => '6F',
                        11 => '7F', 12 => '8F', 13 => '9F', 14 => '10F', 15 => '11F', 16 => '12F',
                        17 => 'RF',
                    ],
                ],
                [
                    'id' => '02',
                    'name' => '緊急右',
                    'device_ids' => ['4027176b-ecb9-4fda-aa02-d7ca8437c269'], // 緊急電梯(右)
                    'floors' => [
                        1 => 'B4F', 2 => 'B3F', 3 => 'B2F', 4 => 'B1F',
                        5 => '1F', 6 => '2F', 7 => '3F', 8 => '4F', 9 => '5F', 10 => '6F',
                        11 => '7F', 12 => '8F', 13 => '9F', 14 => '10F', 15 => '11F', 16 => '12F',
                    ],
                ],
            ];
    }
}

<?php

namespace App\Api\Access\Services;

use Illuminate\Support\Carbon;
use App\Api\Access\Models\AccessFloor;
use App\Api\Access\Services\AccessService;

use RuntimeException;

class AccessFloorService
{
    public function __construct(private AccessService $accessService) {
        $this->accessService = $accessService;
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
        $devices = (array) $this->accessService->GetDevice();
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
            throw new RuntimeException(__('api.access.access_floor_not_found'), 404);
        }

        $result = $this->expandAccessFields($access);

        return $result;
    }

    // 編輯表單用：把 floor_list(JSON) 依 DeviceId 併回 access_control_list 各卡機列的 Floors，供表單逐列帶出可到樓層
    protected function expandAccessFields(AccessFloor $item)
    {
        $accessControlList = json_decode((string) $item->access_control_list, true);
        $floorList = json_decode((string) $item->floor_list, true);
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
            throw new RuntimeException(__('api.access.access_floor_not_found'), 404);
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
            throw new RuntimeException(__('api.access.access_floor_not_found'), 404);
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

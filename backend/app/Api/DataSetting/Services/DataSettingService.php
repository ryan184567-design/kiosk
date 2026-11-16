<?php

namespace App\Api\DataSetting\Services;

use App\Api\DataSetting\Models\Building;
use App\Api\DataSetting\Models\Doorplate;
use App\Api\DataSetting\Models\Floor;

use RuntimeException;

class DataSettingService
{
    // 業務邏輯放這裡，見 3.1「Web 遵循 MVC；API 遵循 MSC」。
    public function addBuilding(string $name, int $onfloor, int $underfloor): Building
    {

        $data = new Building;
        $data->name = $name;
        $data->on_floor = $onfloor;
        $data->under_floor = $underfloor;
        $data->status = 0;
        $data->save();

        $new_id = $data->id;
        // 地上
        for($i = 1; $i <= $onfloor; $i++) {
            $data2 = new Floor;
            $data2->bid = $new_id;
            $data2->location = "地上";
            $data2->floor = $i;
            $data2->status = 0;
            $data2->save();
        }
        // 地下
        for($i = 1; $i <= $underfloor; $i++) {
            $data2 = new Floor;
            $data2->bid = $new_id;
            $data2->location = "地下";
            $data2->floor = $i;
            $data2->status = 0;
            $data2->save();
        }

        return $data;
    }

    public function addDoorplate(int $doorplate, int $floor, int $household): Doorplate
    {

        if (Doorplate::query()->where('doorplate',$doorplate)->where('floor',$floor)->where('household',$household)->exists())
        {
            throw new RuntimeException(__('api.data_setting.doorplate_exists'));
        }

        $doorplate = Doorplate::query()->create([
            'doorplate' => $doorplate,
            'floor' => $floor,
            'household' => $household,
            'status' => 0,
        ]);

        return $doorplate;
    }

    public function updateDoorplate(int $id, int $doorplate, int $floor, int $household): Doorplate
    {
        $check_door = false;
        $Data = Doorplate::where('id',$id)->first();

        if ($Data->doorplate != $doorplate || $Data->floor != $floor || $Data->household != $household) {
            $check_door = Doorplate::where('doorplate', $doorplate)
                                    ->where('floor', $floor)
                                    ->where('household', $household)
                                    ->exists();
        }

        if ($check_door)
        {
            throw new RuntimeException(__('api.data_setting.doorplate_exists'));
        }

        $doorplateData = Doorplate::findOrFail($id);

        $doorplateData->update([
            'doorplate' => $doorplate, // 注意變數名稱不要跟類別名稱打架
            'floor'     => $floor,
            'household' => $household,
            'status'    => 0,
        ]);

        return $doorplateData;
    }

    public function deleteDoorplate(Doorplate $doorplate): void
    {
        $doorplate->delete();
    }
}

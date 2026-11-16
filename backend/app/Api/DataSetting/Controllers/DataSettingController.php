<?php

namespace App\Api\DataSetting\Controllers;

use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;


use App\Api\DataSetting\Models\Doorplate;
use App\Api\DataSetting\Models\Building;
use App\Api\DataSetting\Models\Floor;
use App\Api\DataSetting\Services\DataSettingService;
use App\Support\Api\ErrorCode;
use RuntimeException;
class DataSettingController extends BaseApiController
{
    public function index(Request $request)
    {
        // TODO: $this->validateInput($request, [...]) + 呼叫 Service。
        return $this->success([]);
    }


    public function doorplate()
    {
        $data = Doorplate::get();
        return $data;
    }

    public function addDoorplate (Request $request, DataSettingService $dataSetting)
    {
        $data = $this->validateInput($request, [
            'doorplate' => 'required|int|max:3',
	        'floor'	=> 'required|int|max:2',
	        'household' => 'required|int|max:2',
        ]);

        $doorplate = $dataSetting->addDoorplate(
            $data['doorplate'],
            $data['floor'],
            $data['household']
        );

        return $this->success($this->presentDoorplate($doorplate), 201);
    }

    public function updateDoorplate (Request $request, DataSettingService $dataSetting)
    {
        $data = $this->validateInput($request, [
            'id' => 'required|int',
            'doorplate' => 'required|int|max:3',
	        'floor'	=> 'required|int|max:2',
	        'household' => 'required|int|max:2',
        ]);

        $doorplate = $dataSetting->updateDoorplate(
            $data['id'],
            $data['doorplate'],
            $data['floor'],
            $data['household']
        );

        return $this->success($this->presentDoorplate($doorplate), 201);
    }

    public function deleteDoorplate (request $request, DataSettingService $dataSetting)
    {
        $model = Doorplate::findOrFail($request->id);

        try {
            $dataSetting->deleteDoorplate($model);
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success();
    }

    public function floor(Request $request)
    {
        $building = Building::where('status','0')->select('id','name')->get();

        if(isset($request->mode) && $request->mode != '' ){
            $floor = Floor::join('buildings','floors.bid','=','buildings.id')
                ->select('floors.*','buildings.name AS bname')
                ->where('buildings.status','=',0)
                ->where('floors.bid','=', $request->mode)
                ->get();
        }else{
            $floor = Floor::join('buildings','floors.bid','=','buildings.id')
                ->select('floors.*','buildings.name AS bname')
                ->where('buildings.status','=',0)
                ->get();
        }

        $data = [
            'floor'=>$floor,
            'building'=>$building
        ];

        return $data;
    }

    public function building()
    {
        $data = Building::get();
        return $data;
    }

    public function addBuilding(Request $request, DataSettingService $dataSetting)
    {
        $data = $this->validateInput($request, [
            'name' => 'required|string|max:100',
            'on_floor' => 'required|int|max:2',
            'under_floor' => 'int|max:2',
        ]);

        $building = $dataSetting->addBuilding(
            $data['name'],
            $data['on_floor'],
            $data['under_floor']
        );

        return $this->success($this->presentBuilding($building), 201);
    }

    private function presentBuilding(Building $building): array
    {
        return [
            'id' => $building->id,
            'name' => $building->name,
            'on_floor' => $building->on_floor,
            'under_floor' => $building->under_floor,
        ];
    }

    private function presentDoorplate(Doorplate $doorplate): array
    {
        return [
            'id' => $doorplate->id,
            'doorplate' => $doorplate->doorplate,
            'floor' => $doorplate->floor,
            'household' => $doorplate->household,
        ];
    }
}

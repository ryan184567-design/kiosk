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

    /**
     * @api-method GET
     * @api-path /api/data-setting/doorplate
     * @api-tag DataSetting
     * @api-summary 門牌資料
     * @api-description
     * 獲取所有門牌資訊
     * @api-portal *
     * @api-response-field data[].id int 門牌ID
     * @api-response-field data[].doorplate int 門牌號碼
     * @api-response-field data[].floor int 樓層
     * @api-response-field data[].household int 室/戶/之
     * @api-response-field data[].company_name string 公司名稱
     * @api-response-field data[].status int 使用狀態
     */
    public function doorplate()
    {
        $data = Doorplate::get();
        return $data;
    }

    /**
     * @api-method POST
     * @api-path /api/data-setting/doorplate/create
     * @api-tag DataSetting
     * @api-summary 新增門牌
     * @api-description
     *
     * @api-portal *
     * @api-request-field doorplate int 門牌號碼
     * @api-request-field floor int 樓層
     * @api-request-field household int 室/戶/之
     * @api-request-field company_name string 公司名稱
     * @api-request-example
     * {"doorplate": 132, "floor": 2, "household": 3, "company_name":"有限公司" }
     * @api-response-field data.id int 門牌ID
     * @api-response-field data.doorplate int 門牌號碼
     * @api-response-field data.floor int 樓層
     * @api-response-field data.household int 室/戶/之
     * @api-response-field data.company_name string 公司名稱
     */
    public function addDoorplate (Request $request, DataSettingService $dataSetting)
    {
        $data = $this->validateInput($request, [
            'doorplate' => 'required|int|max:3',
	        'floor'	=> 'required|int|max:2',
	        'household' => 'required|int|max:2',
            'company_name'=> 'string|max:255'
        ]);

        $doorplate = $dataSetting->addDoorplate(
            $data['doorplate'],
            $data['floor'],
            $data['household'],
            $data['company_name']
        );

        return $this->success($this->presentDoorplate($doorplate), 201);
    }

    /**
     * @api-method POST
     * @api-path /api/data-setting/doorplate/update
     * @api-tag DataSetting
     * @api-summary 更新門牌
     * @api-description
     *
     * @api-portal *
     * @api-request-field id int 門牌ID
     * @api-request-field doorplate int 門牌號碼
     * @api-request-field floor int 樓層
     * @api-request-field household int 室/戶/之
     * @api-request-field company_name string 公司名稱
     * @api-request-example
     * {"id": 1, "doorplate": 132, "floor": 2, "household": 3, "company_name":"有限公司" }
     * @api-response-field data.id int 門牌ID
     * @api-response-field data.doorplate int 門牌號碼
     * @api-response-field data.floor int 樓層
     * @api-response-field data.household int 室/戶/之
     * @api-response-field data.company_name string 公司名稱
     */
    public function updateDoorplate(Request $request, DataSettingService $dataSetting)
    {
        $data = $this->validateInput($request, [
            'id' => 'required|int',
            'doorplate' => 'required|int|max:3',
	        'floor'	=> 'required|int|max:2',
	        'household' => 'required|int|max:2',
            'company_name'=> 'string|max:255'
        ]);

        $doorplate = $dataSetting->updateDoorplate(
            $data['id'],
            $data['doorplate'],
            $data['floor'],
            $data['household'],
            $data['company_name'],
        );

        return $this->success($this->presentDoorplate($doorplate), 201);
    }

    /**
     * @api-method POST
     * @api-path /api/data-setting/doorplate/delete
     * @api-tag DataSetting
     * @api-summary 刪除門牌
     * @api-description
     *
     * @api-portal *
     * @api-request-field id int 門牌ID
     * @api-request-example
     * {"id": 1}
     * @api-response-empty
     */
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

    /**
     * @api-method GET
     * @api-path /api/data-setting/floor
     * @api-tag DataSetting
     * @api-summary 樓層資料
     * @api-description
     * 獲取所有樓層資訊
     * @api-portal *
     * @api-request-field bid int 樓別ID
     * @api-request-example
     * {"bid": 1}
     * @api-response-field floor[].bid int 樓別ID
     * @api-response-field floor[].floor int 樓層號碼
     * @api-response-field floor[].location string 地上/地下
     * @api-response-field floor[].image string 平面圖
     * @api-response-field floor[].status int 使用狀態
     * @api-response-field floor[].bname string 大樓名稱
     * @api-response-field building[].id string 樓別ID
     * @api-response-field building[].name string 大樓名稱
     */
    public function floor(Request $request)
    {
        $building = Building::where('status','0')->select('id','name')->get();

        if(isset($request->bid) && $request->bid != '' ){
            $floor = Floor::join('buildings','floors.bid','=','buildings.id')
                ->select('floors.*','buildings.name AS bname')
                ->where('buildings.status','=',0)
                ->where('floors.bid','=', $request->bid)
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

    /**
     * @api-method GET
     * @api-path /api/data-setting/building
     * @api-tag DataSetting
     * @api-summary 樓別資料
     * @api-description
     * 獲取所有大樓資訊
     * @api-portal *
     * @api-response-field data[].id int 樓別ID
     * @api-response-field data[].name string 大樓名稱
     * @api-response-field data[].on_floor int 地上樓層
     * @api-response-field data[].under_floor int 地下樓層
     * @api-response-field data[].status int 使用狀態
     */
    public function building()
    {
        $data = Building::get();
        return $data;
    }

    /**
     * @api-method POST
     * @api-path /api/data-setting/building/add
     * @api-tag DataSetting
     * @api-summary 新增大樓
     * @api-description
     * 新增大樓資訊時，會依據"on_floor"、"under_floor"的值，在資料表"floors"新增相對應的樓層資料
     * @api-portal *
     * @api-request-field name string 大樓名稱
     * @api-request-field on_floor int 地上樓層
     * @api-request-field under_floor int 地下樓層
     * @api-request-example
     * {"name": "大樓", "on_floor": 2, "under_floor": 3 }
     * @api-response-field data.id int 大樓ID
     * @api-response-field data.name string 大樓名稱
     * @api-response-field data.on_floor int 地上樓層
     * @api-response-field data.under_floor int 地下樓層
     */
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
            'company_name' => $doorplate->company_name,
        ];
    }
}

<?php

namespace App\Api\Access\Services;

use GuzzleHttp\Client;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

use App\Api\Access\Models\AccessSetting;
use App\Api\Access\Models\AccessFloor;
use App\Api\Access\Models\AccessCard;

use RuntimeException;

class AccessCardService
{
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

    public function list():Builder
    {
        return AccessCard::query();
    }

    /**
     * 新增卡片
     * @param int $card_number
     * @throws RuntimeException
     * @return AccessCard
     */
    public function add(int $card_number)
    {
        $exists = AccessCard::query()->where('card_number', $card_number)->exists();
        if ($exists) {
            throw new RuntimeException(__('api.access.has_card'), 404);
        }

        $access_number = $this->convert_four_h10d_card($card_number);

        $card = AccessCard::query()->create([
            'card_number' => $card_number,
            'access_number' => $access_number,
            'status' => 0
        ]);

        $card->update([
            'person_id' => 'VTOR' . $card->id,
        ]);

        return $card;
    }

    /**
     * 修改卡片狀態: 用卡號來索引，目前只做修改卡片狀態
     * @param int $id
     * @param string $status
     * @param string $date
     * @throws RuntimeException
     * @return AccessCard|\stdClass
     */
    public function update(int $id, string $status, string $date)
    {
        $card = AccessCard::query()->find($id);
        if (! $card) {
            throw new RuntimeException(__('api.access.card_not_found'), 404);
        }

        $reserve = null;

        if($status == 1){
            $reserve = $date;
        }

        $card->update([
            'status' => $status,
            'reserve_date' => $reserve
        ]);

        return $card;
    }

    /**
     * 刪除卡片
     * @param int $card_number
     * @throws RuntimeException
     * @return void
     */
    public function dalete(int $id)
    {
        $card = AccessCard::query()->find($id);
        if (! $card) {
            throw new RuntimeException(__('api.access.card_not_found'), 404);
        }

        $card->delete();
    }


}

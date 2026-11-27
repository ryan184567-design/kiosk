<?php

namespace App\Api\Access\Services;

use Illuminate\Contracts\Database\Eloquent\Builder;
use App\Api\Access\Models\AccessCard;

use RuntimeException;

class AccessCardService
{

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
        return (new AccessCard)->getConnection()->transaction(function () use ($card_number) {
            // 含軟刪除一起找：已刪除的卡片要還原，不能重新新增（卡號的唯一索引仍佔著）
            $card = AccessCard::withTrashed()->where('card_number', $card_number)->first();

            if ($card) {
                if (! $card->trashed()) {
                    throw new RuntimeException(__('api.access.has_card'), 409);   // 卡號已存在
                }

                // 還原後重設為空閒，並清掉上次使用留下的資料
                $card->restore();
                $card->update([
                    'status' => AccessCard::STATUS_FREE,
                    'person_id' => 'VTOR'. $card->id,
                    'reserve_date' => null,
                ]);

                return $card;
            }

            $card = AccessCard::query()->create([
                'card_number' => $card_number,
                'access_number' => $this->convert_four_h10d_card($card_number),   // 卡片編碼
                'status' => AccessCard::STATUS_FREE,
            ]);

            // person_id 要用到自動產生的 id，所以建立後再補（同一個交易內）
            $card->update(['person_id' => 'VTOR'.$card->id]);

            return $card;
        });
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
     * @param int $id
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

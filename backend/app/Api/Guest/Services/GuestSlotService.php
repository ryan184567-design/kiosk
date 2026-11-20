<?php

namespace App\Api\Guest\Services;

use App\Api\Guest\Models\GuestSlot;
use Illuminate\Contracts\Database\Eloquent\Builder;

use RuntimeException;

class GuestSlotService
{
    public function slots(): Builder
    {
        return GuestSlot::query();
    }

    public function slotApi()
    {
        return GuestSlot::query()->get();
    }


    public function create(string $name, string $remark): GuestSlot
    {
        $slot = GuestSlot::query()->create([
            'name' => $name,
            'remark' => $remark,
        ]);

        return $slot;
    }

    public function update(int $id, string $name, ?string $remark = null, int $status, ?string $reservation_id = null): GuestSlot
    {
        $slot = GuestSlot::query()->find($id);
        if (! $slot) {
            throw new RuntimeException(__('api.guest_slot.not_found'));
        }

        if ($slot->status == 1) {
            throw new RuntimeException(__('api.guest_slot.in_use'));
        }

        $slot->update([
            'name' => $name,
            'reservation_id' => $reservation_id,
            'remark' => $remark,
            'status' => $status,
        ]);

        return $slot;
    }

    public function delete(int $id): void
    {
        $slot = GuestSlot::query()->find($id);
        if (! $slot) {
            throw new RuntimeException(__('api.guest_slot.not_found'));
        }

        $slot->delete();
    }
}

<?php

namespace App\Api\Access\Services;

use Illuminate\Contracts\Database\Eloquent\Builder;
use App\Api\Access\Models\AccessApi;

use RuntimeException;

class AccessApiService
{

    public function list():Builder
    {
        return AccessApi::query();
    }

    /**
     * 新增API
     */
    public function add(string $name, string $url)
    {
        $api = AccessApi::query()->create([
            'name' => $name,
            'url' => $url,
            'type' => "api",
            'status' => 1
        ]);

        return $api;
    }

    /**
     * 修改API
     */
    public function update(int $id, string $name, string $url, int $status)
    {
        $api = AccessApi::query()->find($id);
        if (! $api) {
            throw new RuntimeException(__('api.access.card_not_api'), 404);
        }

        $api->update([
            'name' => $name,
            'url' => $url,
            'status' => $status
        ]);

        return $api;
    }

    /**
     * 刪除API
     */
    public function dalete(int $id)
    {
        $api = AccessApi::query()->find($id);
        if (! $api) {
            throw new RuntimeException(__('api.access.card_not_api'), 404);
        }

        $api->delete();
    }


}

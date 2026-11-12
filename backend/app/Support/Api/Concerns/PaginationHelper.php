<?php

/* *****************************************
File: PaginationHelper.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 統一分頁方法，見 README 開發約定第 9 點：per_page = -1 代表不分頁。
***************************************** */

namespace App\Support\Api\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

// 統一分頁方法，見 README 開發約定第 9 點：per_page = -1 代表不分頁。
trait PaginationHelper
{
    protected function paginateOrGet(Builder $query, int $perPage, int $page = 1): array
    {
        if ($perPage === -1) {
            $items = $query->get();

            return [
                'items' => $items,
                'total' => $items->count(),
                'per_page' => -1,
                'current_page' => 1,
            ];
        }

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return [
            'items' => Collection::make($paginator->items()),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ];
    }
}

<?php

namespace App\Api\Guest\Controllers;

use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;

class GuestController extends BaseApiController
{
    public function index(Request $request)
    {
        // TODO: $this->validateInput($request, [...]) + 呼叫 Service。
        return $this->success([]);
    }
}

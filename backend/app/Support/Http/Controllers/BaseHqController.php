<?php

/* *****************************************
File: BaseHqController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 所有 app/Hq Controller 的基底類，見 README 6.12。
***************************************** */

namespace App\Support\Http\Controllers;

use App\Hq\Auth\Models\HqUser;
use App\Support\Api\ApiResponder;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

// 所有 app/Hq Controller 的基底類，見 README 6.12。與 BaseApiController
// 分開是因為主站完全是另一套使用者體系，不共用 App\Models\User。
abstract class BaseHqController extends Controller
{
    use ValidatesRequests;

    protected function validateInput(Request $request, array $rules, array $messages = []): array
    {
        return $request->validate($rules, $messages);
    }

    protected function success(array $data = [], int $status = 200)
    {
        return ApiResponder::success($data, $status);
    }

    protected function fail(int $errorCode, ?string $message = null, ?int $status = null)
    {
        return ApiResponder::fail($errorCode, $message, $status);
    }

    protected function currentHqUser(Request $request): ?HqUser
    {
        return $request->attributes->get('auth_hq_user');
    }
}

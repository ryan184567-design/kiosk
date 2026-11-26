<?php

namespace App\Api\Access\Controllers;

use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;

use App\Api\Access\Services\AccessService;

class AccessController extends BaseApiController
{
    public function index(Request $request)
    {
        // TODO: $this->validateInput($request, [...]) + 呼叫 Service。
        return $this->success([]);
    }

    public function addCard(Request $request, AccessService $access)
    {
        $data = $this->validateInput($request, [
            'card_number' => ['required', 'int'],
            'floor' => ['required', 'int', 'max:100'],
            'card_id' => ['required', 'int'],
            'email' => ['required', 'email', 'max:150'],
        ]);

        $result = $access->AddPerson(
            $data['card_number'],
            $data['floor'],
            $data['card_id'],
            $data['email'],
        );

        return $this->success();
    }

}

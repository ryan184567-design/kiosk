<?php

namespace App\Api\Guest\Services;

use RuntimeException;

class VisitMachineService
{
    public function apiAuth(string $token){
        $key = '';

        if ($token !== $key) {
            throw new RuntimeException(__('api.visit_machine.api_key_error'));
        }

        return true;
    }

}

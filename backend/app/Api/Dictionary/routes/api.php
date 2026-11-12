<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 資料字典管理，見 README 17.3。
***************************************** */

use App\Api\Dictionary\Controllers\DictionaryController;
use Illuminate\Support\Facades\Route;

// 資料字典管理，見 README 17.3。不分端口，寫法比照 system_config 模塊。
Route::middleware('jwt.auth')->group(function () {
    Route::middleware('permission.api:api.dictionary.index')->get('/dictionaries', [DictionaryController::class, 'index']);
    Route::middleware('permission.api:api.dictionary.store')->post('/dictionaries', [DictionaryController::class, 'store']);
    Route::middleware('permission.api:api.dictionary.update')->put('/dictionaries/{dictionary}', [DictionaryController::class, 'update']);
    Route::middleware('permission.api:api.dictionary.destroy')->delete('/dictionaries/{dictionary}', [DictionaryController::class, 'destroy']);
    Route::middleware('permission.api:api.dictionary.items')->get('/dictionaries/{dictionary}/items', [DictionaryController::class, 'items']);
    Route::middleware('permission.api:api.dictionary.storeItem')->post('/dictionaries/{dictionary}/items', [DictionaryController::class, 'storeItem']);
    Route::middleware('permission.api:api.dictionary.updateItem')->put('/dictionaries/{dictionary}/items/{item}', [DictionaryController::class, 'updateItem']);
    Route::middleware('permission.api:api.dictionary.destroyItem')->delete('/dictionaries/{dictionary}/items/{item}', [DictionaryController::class, 'destroyItem']);
});

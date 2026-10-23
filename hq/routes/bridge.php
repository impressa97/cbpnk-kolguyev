<?php

use App\Http\Controllers\ReforgerWarBridgeController;
use Illuminate\Support\Facades\Route;

Route::middleware('bridge')->group(function () {
    Route::get('/api/reforger/war/orders', [ReforgerWarBridgeController::class, 'orders'])
        ->name('reforger.war.bridge.orders');
    Route::post('/api/reforger/war/state', [ReforgerWarBridgeController::class, 'state'])
        ->name('reforger.war.bridge.state');
});

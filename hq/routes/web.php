<?php

use App\Http\Controllers\ReforgerController;
use App\Http\Controllers\ReforgerWarController;
use App\Http\Controllers\ReforgerWarHqController;
use Illuminate\Support\Facades\Route;

Route::get('/', ReforgerController::class)->name('home');
Route::get('reforger', ReforgerController::class)->name('reforger');
Route::get('desk', [ReforgerController::class, 'desk'])->name('desk');

Route::post('reforger/war/enlist', [ReforgerWarController::class, 'enlist'])
    ->middleware('throttle:20,1')
    ->name('reforger.war.enlist');
Route::post('reforger/war/orders', [ReforgerWarController::class, 'order'])
    ->middleware('throttle:60,1')
    ->name('reforger.war.orders');
Route::post('reforger/war/orders/{order}/cancel', [ReforgerWarController::class, 'cancelOrder'])
    ->whereNumber('order')
    ->middleware('throttle:60,1')
    ->name('reforger.war.orders.cancel');
Route::post('reforger/war/vehicles/{vehicle}/service', [ReforgerWarController::class, 'service'])
    ->whereNumber('vehicle')
    ->middleware('throttle:30,1')
    ->name('reforger.war.vehicles.service');
Route::post('reforger/war/squads/{squad}/split', [ReforgerWarController::class, 'split'])
    ->whereNumber('squad')
    ->middleware('throttle:30,1')
    ->name('reforger.war.squads.split');
Route::post('reforger/war/squads/{squad}/alert', [ReforgerWarController::class, 'alert'])
    ->whereNumber('squad')
    ->middleware('throttle:30,1')
    ->name('reforger.war.squads.alert');
Route::post('reforger/war/squads/{squad}/logistics', [ReforgerWarController::class, 'logistics'])
    ->whereNumber('squad')
    ->middleware('throttle:30,1')
    ->name('reforger.war.squads.logistics');
Route::post('reforger/war/upgrade', [ReforgerWarController::class, 'upgrade'])
    ->middleware('throttle:30,1')
    ->name('reforger.war.upgrade');
Route::post('reforger/war/squads/{squad}/combat', [ReforgerWarController::class, 'combat'])
    ->whereNumber('squad')
    ->middleware('throttle:30,1')
    ->name('reforger.war.squads.combat');
Route::post('reforger/war/squads/{squad}/name', [ReforgerWarController::class, 'rename'])
    ->whereNumber('squad')
    ->middleware('throttle:30,1')
    ->name('reforger.war.squads.name');
Route::post('reforger/war/squads/{squad}/form', [ReforgerWarController::class, 'form'])
    ->whereNumber('squad')
    ->middleware('throttle:30,1')
    ->name('reforger.war.squads.form');
Route::post('reforger/war/soldiers/{soldier}/posture', [ReforgerWarController::class, 'posture'])
    ->whereNumber('soldier')
    ->middleware('throttle:60,1')
    ->name('reforger.war.soldiers.posture');
Route::post('reforger/war/runs', [ReforgerWarController::class, 'run'])
    ->middleware('throttle:30,1')
    ->name('reforger.war.runs');
Route::post('reforger/war/soldiers/{soldier}/detach', [ReforgerWarController::class, 'detach'])
    ->whereNumber('soldier')
    ->middleware('throttle:30,1')
    ->name('reforger.war.soldiers.detach');
Route::post('reforger/war/soldiers/{soldier}/transfer', [ReforgerWarController::class, 'transfer'])
    ->whereNumber('soldier')
    ->middleware('throttle:30,1')
    ->name('reforger.war.soldiers.transfer');
Route::middleware('throttle:30,1')->group(function () {
    Route::post('reforger/war/vehicles', [ReforgerWarHqController::class, 'vehicle'])->name('reforger.war.vehicles');
    Route::post('reforger/war/vehicles/{vehicle}/scrap', [ReforgerWarHqController::class, 'scrap'])->whereNumber('vehicle')->name('reforger.war.vehicles.scrap');
    Route::post('reforger/war/support', [ReforgerWarHqController::class, 'support'])->name('reforger.war.support');
    Route::post('reforger/war/support/{support}/cancel', [ReforgerWarHqController::class, 'cancelSupport'])->whereNumber('support')->name('reforger.war.support.cancel');
    Route::post('reforger/war/tasks', [ReforgerWarHqController::class, 'task'])->name('reforger.war.tasks');
    Route::post('reforger/war/tasks/{task}/cancel', [ReforgerWarHqController::class, 'cancelTask'])->whereNumber('task')->name('reforger.war.tasks.cancel');
});

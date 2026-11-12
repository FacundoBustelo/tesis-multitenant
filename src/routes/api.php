<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RecordController;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/{actorType}/login', [AuthController::class, 'login'])->whereIn('actorType', ['users', 'staff'])->middleware('throttle:login');
    Route::middleware(['auth:sanctum', ResolveTenant::class])->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::apiResource('records', RecordController::class)->whereNumber('record');
    });
});

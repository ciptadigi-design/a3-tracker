<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GovernanceController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\VersionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('request.id')->group(function () {
    Route::get('health', HealthController::class);
    Route::get('version', VersionController::class);
    Route::middleware('web')->group(function () {
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::middleware(['auth', 'active.user'])->group(function () {
            Route::post('auth/logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::get('accounts', [GovernanceController::class, 'accounts']);
            Route::post('accounts', [GovernanceController::class, 'storeAccount']);
            Route::put('accounts/{id}', [GovernanceController::class, 'updateAccount']);
            Route::get('accounts/{id}/branches', [GovernanceController::class, 'branches']);
            Route::post('accounts/{id}/branches', [GovernanceController::class, 'storeBranch']);
            Route::put('accounts/{accountId}/branches/{id}', [GovernanceController::class, 'updateBranch']);
            Route::get('accounts/{id}/members', [GovernanceController::class, 'members']);
            Route::post('accounts/{id}/members', [GovernanceController::class, 'provision']);
            Route::patch('accounts/{accountId}/members/{id}', [GovernanceController::class, 'updateMember']);
            Route::post('platform/bootstrap-superuser', [GovernanceController::class, 'bootstrap']);
        });
    });
});

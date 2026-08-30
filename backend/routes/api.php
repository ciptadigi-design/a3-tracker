<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GovernanceController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\VersionController;
use App\Http\Controllers\Api\OperationsController;
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
            Route::get('manufacturers', [OperationsController::class, 'manufacturers']);
            Route::post('manufacturers', [OperationsController::class, 'storeManufacturer']);
            Route::get('machine-models', [OperationsController::class, 'models']);
            Route::post('machine-models', [OperationsController::class, 'storeModel']);
            Route::get('branches/{branch}/machines', [OperationsController::class, 'machines']);
            Route::post('branches/{branch}/machines', [OperationsController::class, 'storeMachine']);
            Route::get('machines/{id}', [OperationsController::class, 'machine']);
            Route::get('branches/{branch}/operational-people', [OperationsController::class, 'people']);
            Route::post('accounts/{account}/operational-people', [OperationsController::class, 'storePerson']);
            Route::post('operational-people/{person}/branches/{branch}', [OperationsController::class, 'assignPerson']);
            Route::get('machines/{machine}/counters', [OperationsController::class, 'counters']);
            Route::post('machines/{machine}/counters', [OperationsController::class, 'createCounter']);
            Route::get('machines/{machine}/counters/period', [OperationsController::class, 'period']);
        });
    });
});

<?php

use App\Http\Controllers\Api\V1\Admin\AuditAdminController;
use App\Http\Controllers\Api\V1\Admin\DeviceAdminController;
use App\Http\Controllers\Api\V1\Admin\GroupAdminController;
use App\Http\Controllers\Api\V1\Admin\JobAdminController;
use App\Http\Controllers\Api\V1\Admin\PackageAdminController;
use App\Http\Controllers\Api\V1\Admin\PolicyAdminController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceCheckinController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\KeysetController;
use App\Http\Controllers\Api\V1\PackageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::post('/device/enroll', [EnrollmentController::class, 'enroll']);
    Route::post('/device/heartbeat', [DeviceCheckinController::class, 'heartbeat']);
    Route::post('/device/checkin', [DeviceCheckinController::class, 'checkin']);
    Route::get('/device/keyset', [KeysetController::class, 'index']);
    Route::get('/device/policies', [DeviceCheckinController::class, 'policies']);
    Route::post('/device/job-ack', [DeviceCheckinController::class, 'jobAck']);
    Route::post('/device/job-result', [DeviceCheckinController::class, 'jobResult']);
    Route::post('/device/compliance-report', [DeviceCheckinController::class, 'complianceReport']);
    Route::get('/device/packages/{packageVersionId}/download-meta', [PackageController::class, 'downloadMeta']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::prefix('/admin')->group(function () {
            Route::get('/devices', [DeviceAdminController::class, 'index'])->middleware('permission:devices.read');
            Route::get('/devices/{id}', [DeviceAdminController::class, 'show'])->middleware('permission:devices.read');
            Route::patch('/devices/{id}', [DeviceAdminController::class, 'update'])->middleware('permission:devices.write');
            Route::post('/enrollment-tokens', [DeviceAdminController::class, 'createEnrollmentToken'])->middleware('permission:devices.write');

            Route::get('/groups', [GroupAdminController::class, 'index'])->middleware('permission:groups.read');
            Route::post('/groups', [GroupAdminController::class, 'store'])->middleware('permission:groups.write');

            Route::get('/packages', [PackageAdminController::class, 'index'])->middleware('permission:packages.read');
            Route::post('/packages', [PackageAdminController::class, 'store'])->middleware('permission:packages.write');
            Route::post('/packages/{packageId}/versions', [PackageAdminController::class, 'addVersion'])->middleware('permission:packages.write');

            Route::get('/policies', [PolicyAdminController::class, 'index'])->middleware('permission:policies.read');
            Route::post('/policies', [PolicyAdminController::class, 'store'])->middleware('permission:policies.write');
            Route::post('/policies/{policyId}/versions', [PolicyAdminController::class, 'createVersion'])->middleware('permission:policies.write');

            Route::get('/jobs', [JobAdminController::class, 'index'])->middleware('permission:jobs.read');
            Route::post('/jobs', [JobAdminController::class, 'store'])->middleware('permission:jobs.write');

            Route::get('/audit-logs', [AuditAdminController::class, 'index'])->middleware('permission:audit.read');
        });
    });
});

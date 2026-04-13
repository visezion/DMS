<?php

use App\Http\Controllers\Api\V1\Admin\AuditAdminController;
use App\Http\Controllers\Api\V1\Admin\ApprovalController;
use App\Http\Controllers\Api\V1\Admin\AssistantController;
use App\Http\Controllers\Api\V1\Admin\AutonomyPolicyController;
use App\Http\Controllers\Api\V1\Admin\DeviceAdminController;
use App\Http\Controllers\Api\V1\Admin\HealthController;
use App\Http\Controllers\Api\V1\Admin\GroupAdminController;
use App\Http\Controllers\Api\V1\Admin\IncidentController;
use App\Http\Controllers\Api\V1\Admin\JobAdminController;
use App\Http\Controllers\Api\V1\Admin\PackageAdminController;
use App\Http\Controllers\Api\V1\Admin\PolicyAdminController;
use App\Http\Controllers\Api\V1\Admin\RemediationController;
use App\Http\Controllers\Api\V1\Admin\RiskController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceBehaviorLogController;
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
    Route::post('/device/behavior-log', [DeviceBehaviorLogController::class, 'store'])->middleware('throttle:300,1');
    Route::post('/device/remote-support/live-frame', [DeviceCheckinController::class, 'remoteSupportLiveFrame'])->middleware('throttle:600,1');
    Route::post('/device/remote-support/webrtc/signal', [DeviceCheckinController::class, 'remoteSupportWebRtcSignal'])->middleware('throttle:600,1');
    Route::get('/device/remote-support/webrtc/signals', [DeviceCheckinController::class, 'remoteSupportWebRtcSignals'])->middleware('throttle:600,1');
    Route::get('/device/remote-support/webrtc/inputs', [DeviceCheckinController::class, 'remoteSupportWebRtcInputs'])->middleware('throttle:600,1');
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

            Route::get('/health/devices/{deviceId}/summary', [HealthController::class, 'summary'])->middleware('permission:health.read');
            Route::get('/health/devices/{deviceId}/trend', [HealthController::class, 'trend'])->middleware('permission:health.read');
            Route::get('/health/unhealthy', [HealthController::class, 'unhealthy'])->middleware('permission:health.read');
            Route::get('/health/devices/{deviceId}/compare', [HealthController::class, 'compare'])->middleware('permission:health.read');
            Route::get('/health/devices/{deviceId}/telemetry', [HealthController::class, 'telemetry'])->middleware('permission:health.read');

            Route::get('/risk/devices/{deviceId}', [RiskController::class, 'device'])->middleware('permission:risk.read');
            Route::get('/risk/findings', [RiskController::class, 'findings'])->middleware('permission:risk.read');
            Route::post('/risk/findings/{findingId}/suppress', [RiskController::class, 'suppress'])->middleware('permission:risk.write');
            Route::post('/risk/findings/{findingId}/review', [RiskController::class, 'review'])->middleware('permission:risk.write');
            Route::post('/risk/findings/{findingId}/escalate', [RiskController::class, 'escalate'])->middleware('permission:risk.write');

            Route::get('/incidents', [IncidentController::class, 'index'])->middleware('permission:incidents.read');
            Route::get('/incidents/{incidentId}', [IncidentController::class, 'show'])->middleware('permission:incidents.read');
            Route::get('/incidents/{incidentId}/timeline', [IncidentController::class, 'timeline'])->middleware('permission:incidents.read');
            Route::get('/devices/{deviceId}/timeline', [IncidentController::class, 'deviceTimeline'])->middleware('permission:incidents.read');

            Route::post('/assistant/ask', [AssistantController::class, 'ask'])->middleware('permission:assistant.use');
            Route::get('/assistant/sessions/{sessionId}', [AssistantController::class, 'history'])->middleware('permission:assistant.use');
            Route::get('/assistant/messages/{messageId}/evidence', [AssistantController::class, 'evidence'])->middleware('permission:assistant.use');
            Route::post('/assistant/recommendations/{recommendationId}/convert', [AssistantController::class, 'convertRecommendation'])->middleware('permission:assistant.convert');

            Route::get('/remediation/plans', [RemediationController::class, 'index'])->middleware('permission:remediation.read');
            Route::post('/remediation/recommendations/{recommendationId}/plans', [RemediationController::class, 'createFromRecommendation'])->middleware('permission:remediation.plan');
            Route::post('/remediation/plans/{planId}/validate', [RemediationController::class, 'validatePlan'])->middleware('permission:remediation.plan');
            Route::post('/remediation/plans/{planId}/approve', [RemediationController::class, 'approve'])->middleware('permission:remediation.approve');
            Route::post('/remediation/plans/{planId}/execute', [RemediationController::class, 'execute'])->middleware('permission:remediation.execute');
            Route::post('/remediation/actions/{actionId}/rollback', [RemediationController::class, 'rollback'])->middleware('permission:remediation.execute');

            Route::get('/approvals', [ApprovalController::class, 'index'])->middleware('permission:remediation.approve');
            Route::post('/approvals/{approvalId}/approve', [ApprovalController::class, 'approve'])->middleware('permission:remediation.approve');
            Route::post('/approvals/{approvalId}/reject', [ApprovalController::class, 'reject'])->middleware('permission:remediation.approve');

            Route::get('/autonomy/policies', [AutonomyPolicyController::class, 'index'])->middleware('permission:autonomy.manage');
            Route::post('/autonomy/policies', [AutonomyPolicyController::class, 'upsert'])->middleware('permission:autonomy.manage');
        });
    });
});

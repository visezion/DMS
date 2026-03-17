<?php

use App\Http\Controllers\Web\AdminAuthController;
use App\Http\Controllers\Web\BehaviorAiController;
use App\Http\Controllers\Web\AdminConsoleController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');
Route::redirect('/login', '/admin/login')->name('login');

Route::middleware('guest')->group(function () {
    Route::get('/admin/signup', [AdminAuthController::class, 'registerForm'])->name('admin.signup');
    Route::post('/admin/signup', [AdminAuthController::class, 'register'])->name('admin.signup.submit');
    Route::get('/admin/login', [AdminAuthController::class, 'loginForm'])->name('admin.login');
    Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login.submit');
    Route::get('/admin/login/captcha-refresh', [AdminAuthController::class, 'refreshCaptcha'])->name('admin.login.captcha.refresh');
    Route::get('/admin/login/mfa', [AdminAuthController::class, 'mfaForm'])->name('admin.login.mfa.form');
    Route::post('/admin/login/mfa', [AdminAuthController::class, 'verifyMfa'])->name('admin.login.mfa.verify');
    Route::post('/admin/login/mfa/cancel', [AdminAuthController::class, 'cancelMfa'])->name('admin.login.mfa.cancel');
});

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

    Route::get('/', [AdminConsoleController::class, 'dashboard'])->name('dashboard');

    Route::get('/devices', [AdminConsoleController::class, 'devices'])->name('devices');
    Route::get('/enroll-devices', [AdminConsoleController::class, 'enrollDevices'])->name('enroll-devices');
    Route::get('/devices/{deviceId}', [AdminConsoleController::class, 'deviceDetail'])->name('devices.show');
    Route::get('/devices/{deviceId}/live', [AdminConsoleController::class, 'deviceDetailLive'])->name('devices.live');
    Route::patch('/devices/{deviceId}', [AdminConsoleController::class, 'updateDevice'])->name('devices.update');
    Route::delete('/devices/{deviceId}', [AdminConsoleController::class, 'deleteDevice'])->name('devices.delete');
    Route::post('/devices/{deviceId}/force-delete', [AdminConsoleController::class, 'forceDeleteDevice'])->name('devices.force-delete');
    Route::delete('/devices/{deviceId}/policy-assignments/{assignmentId}', [AdminConsoleController::class, 'removeDevicePolicyAssignment'])->name('devices.policies.remove');
    Route::post('/devices/{deviceId}/packages/uninstall', [AdminConsoleController::class, 'uninstallDevicePackage'])->name('devices.packages.uninstall');
    Route::post('/devices/{deviceId}/agent/uninstall', [AdminConsoleController::class, 'uninstallDeviceAgent'])->name('devices.agent.uninstall');
    Route::post('/devices/{deviceId}/reboot', [AdminConsoleController::class, 'rebootDevice'])->name('devices.reboot');
    Route::post('/devices/{deviceId}/reenroll', [AdminConsoleController::class, 'reenrollDevice'])->name('devices.reenroll');
    Route::post('/devices/enrollment-token', [AdminConsoleController::class, 'createEnrollmentToken'])->name('devices.enrollment-token');

    Route::get('/groups', [AdminConsoleController::class, 'groups'])->name('groups');
    Route::get('/groups/create', [AdminConsoleController::class, 'groupsCreate'])->name('groups.create-page');
    Route::get('/groups/{groupId}', [AdminConsoleController::class, 'groupDetail'])->name('groups.show');
    Route::post('/groups', [AdminConsoleController::class, 'createGroup'])->name('groups.create');
    Route::delete('/groups/{groupId}', [AdminConsoleController::class, 'deleteGroup'])->name('groups.delete');
    Route::post('/groups/bulk-assign', [AdminConsoleController::class, 'bulkAssignGroupMembers'])->name('groups.bulk-assign');
    Route::post('/groups/{groupId}/members', [AdminConsoleController::class, 'addGroupMember'])->name('groups.members.add');
    Route::delete('/groups/{groupId}/members/{deviceId}', [AdminConsoleController::class, 'removeGroupMember'])->name('groups.members.remove');
    Route::post('/groups/{groupId}/kiosk-lockdown', [AdminConsoleController::class, 'applyGroupKioskLockdown'])->name('groups.kiosk-lockdown');
    Route::post('/groups/{groupId}/policy-assignments', [AdminConsoleController::class, 'addGroupPolicyAssignment'])->name('groups.policies.add');
    Route::delete('/groups/{groupId}/policy-assignments/{assignmentId}', [AdminConsoleController::class, 'removeGroupPolicyAssignment'])->name('groups.policies.remove');
    Route::post('/groups/{groupId}/package-assignments', [AdminConsoleController::class, 'addGroupPackageAssignment'])->name('groups.packages.add');
    Route::delete('/groups/{groupId}/package-assignments/{jobId}', [AdminConsoleController::class, 'removeGroupPackageAssignment'])->name('groups.packages.remove');

    Route::get('/packages', [AdminConsoleController::class, 'packages'])->name('packages');
    Route::get('/packages/icon/windows-store', [AdminConsoleController::class, 'packageWindowsStoreIcon'])->name('packages.icon.windows-store');
    Route::post('/packages/hash-from-uri', [AdminConsoleController::class, 'packageSha256FromUri'])->name('packages.hash-from-uri');
    Route::get('/packages/{packageId}', [AdminConsoleController::class, 'packageDetail'])->name('packages.show');
    Route::post('/packages', [AdminConsoleController::class, 'createPackage'])->name('packages.create');
    Route::post('/packages/{packageId}/versions', [AdminConsoleController::class, 'createPackageVersion'])->name('packages.versions.create');
    Route::delete('/packages/{packageId}', [AdminConsoleController::class, 'deletePackage'])->name('packages.delete');
    Route::delete('/packages/{packageId}/versions/{versionId}', [AdminConsoleController::class, 'deletePackageVersion'])->name('packages.versions.delete');
    Route::post('/packages/versions/{versionId}/deploy', [AdminConsoleController::class, 'deployPackageVersion'])->name('packages.versions.deploy');

    Route::get('/policies', [AdminConsoleController::class, 'policies'])->name('policies');
    Route::get('/policy-categories', [AdminConsoleController::class, 'policyCategoriesPage'])->name('policy-categories');
    Route::get('/policies/{policyId}', [AdminConsoleController::class, 'policyDetail'])->name('policies.show');
    Route::post('/policies', [AdminConsoleController::class, 'createPolicy'])->name('policies.create');
    Route::get('/catalog', [AdminConsoleController::class, 'catalog'])->name('catalog');
    Route::post('/policies/catalog', [AdminConsoleController::class, 'createPolicyCatalogPreset'])->name('policies.catalog.create');
    Route::patch('/policies/catalog/{catalogKey}', [AdminConsoleController::class, 'updatePolicyCatalogPreset'])->name('policies.catalog.update');
    Route::delete('/policies/catalog/{catalogKey}', [AdminConsoleController::class, 'deletePolicyCatalogPreset'])->name('policies.catalog.delete');
    Route::post('/policies/categories', [AdminConsoleController::class, 'createPolicyCategory'])->name('policies.categories.create');
    Route::patch('/policies/categories', [AdminConsoleController::class, 'updatePolicyCategory'])->name('policies.categories.update');
    Route::delete('/policies/categories', [AdminConsoleController::class, 'deletePolicyCategory'])->name('policies.categories.delete');
    Route::patch('/policies/{policyId}', [AdminConsoleController::class, 'updatePolicy'])->name('policies.update');
    Route::delete('/policies/{policyId}', [AdminConsoleController::class, 'deletePolicy'])->name('policies.delete');
    Route::post('/policies/{policyId}/versions', [AdminConsoleController::class, 'createPolicyVersion'])->name('policies.versions.create');
    Route::patch('/policies/{policyId}/versions/{versionId}', [AdminConsoleController::class, 'updatePolicyVersion'])->name('policies.versions.update');
    Route::delete('/policies/{policyId}/versions/{versionId}', [AdminConsoleController::class, 'deletePolicyVersion'])->name('policies.versions.delete');
    Route::post('/policies/{policyId}/versions/{versionId}/assignments', [AdminConsoleController::class, 'assignPolicyVersion'])->name('policies.versions.assignments.create');
    Route::delete('/policies/{policyId}/versions/{versionId}/assignments/{assignmentId}', [AdminConsoleController::class, 'deletePolicyAssignment'])->name('policies.versions.assignments.delete');

    Route::get('/jobs', [AdminConsoleController::class, 'jobs'])->name('jobs');
    Route::get('/jobs/{jobId}', [AdminConsoleController::class, 'jobDetail'])->name('jobs.show');
    Route::post('/jobs', [AdminConsoleController::class, 'createJob'])->name('jobs.create');
    Route::post('/jobs/{jobId}/rerun', [AdminConsoleController::class, 'rerunJob'])->name('jobs.rerun');
    Route::post('/job-runs/{runId}/rerun', [AdminConsoleController::class, 'rerunJobRun'])->name('job-runs.rerun');
    Route::post('/jobs/store-clear', [AdminConsoleController::class, 'storeAndClearJobs'])->name('jobs.store-clear');
    Route::post('/ops/settings', [AdminConsoleController::class, 'updateOps'])->name('ops.update');
    Route::post('/ops/kill-switch', [AdminConsoleController::class, 'toggleKillSwitch'])->name('ops.kill-switch');
    Route::post('/ops/rotate-signing-key', [AdminConsoleController::class, 'rotateSigningKey'])->name('ops.rotate-key');

    Route::get('/agent', [AdminConsoleController::class, 'agent'])->name('agent');
    Route::post('/agent/releases', [AdminConsoleController::class, 'uploadAgentRelease'])->name('agent.releases.upload');
    Route::post('/agent/releases/autobuild', [AdminConsoleController::class, 'autoBuildAgentRelease'])->name('agent.releases.autobuild');
    Route::post('/agent/releases/{releaseId}/activate', [AdminConsoleController::class, 'activateAgentRelease'])->name('agent.releases.activate');
    Route::delete('/agent/releases/{releaseId}', [AdminConsoleController::class, 'deleteAgentRelease'])->name('agent.releases.delete');
    Route::post('/agent/releases/generate', [AdminConsoleController::class, 'generateAgentInstaller'])->name('agent.releases.generate');
    Route::post('/agent/releases/generate-json', [AdminConsoleController::class, 'generateAgentInstallerJson'])->name('agent.releases.generate-json');
    Route::post('/agent/push-update', [AdminConsoleController::class, 'pushAgentUpdate'])->name('agent.push-update');
    Route::post('/agent/test-connectivity', [AdminConsoleController::class, 'testAgentApiConnectivity'])->name('agent.test-connectivity');
    Route::post('/agent/backend/start', [AdminConsoleController::class, 'startAgentBackendServer'])->name('agent.backend.start');
    Route::get('/agent/backend/status', [AdminConsoleController::class, 'agentBackendServerStatus'])->name('agent.backend.status');
    Route::get('/getting-started', [AdminConsoleController::class, 'gettingStarted'])->name('getting-started');
    Route::get('/docs', [AdminConsoleController::class, 'docs'])->name('docs');
    Route::get('/notes', [AdminConsoleController::class, 'notes'])->name('notes');
    Route::post('/notes', [AdminConsoleController::class, 'createNote'])->name('notes.create');
    Route::patch('/notes/{noteId}', [AdminConsoleController::class, 'updateNote'])->name('notes.update');
    Route::delete('/notes/{noteId}', [AdminConsoleController::class, 'deleteNote'])->name('notes.delete');
    Route::get('/profile', [AdminConsoleController::class, 'profile'])->name('profile');
    Route::post('/profile', [AdminConsoleController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profile/mfa/setup', [AdminConsoleController::class, 'setupProfileMfa'])->name('profile.mfa.setup');
    Route::post('/profile/mfa/enable', [AdminConsoleController::class, 'enableProfileMfa'])->name('profile.mfa.enable');
    Route::post('/profile/mfa/disable', [AdminConsoleController::class, 'disableProfileMfa'])->name('profile.mfa.disable');
    Route::get('/settings', [AdminConsoleController::class, 'settings'])->name('settings');
    Route::get('/security-hardening', [AdminConsoleController::class, 'securityCommandCenter'])->name('security-hardening');
    Route::get('/security-command-center', [AdminConsoleController::class, 'securityCommandCenter'])->name('security-command-center');
    Route::get('/settings/branding', [AdminConsoleController::class, 'branding'])->name('settings.branding');
    Route::post('/settings/branding', [AdminConsoleController::class, 'updateBranding'])->name('settings.branding.update');
    Route::post('/settings/signature-bypass', [AdminConsoleController::class, 'updateSignatureBypass'])->name('settings.signature-bypass');
    Route::post('/settings/auth-policy', [AdminConsoleController::class, 'updateAuthPolicy'])->name('settings.auth-policy');
    Route::post('/settings/https-app-url', [AdminConsoleController::class, 'updateHttpsAppUrl'])->name('settings.https-app-url');
    Route::post('/settings/environment-posture', [AdminConsoleController::class, 'updateEnvironmentPosture'])->name('settings.environment-posture');
    Route::get('/access', [AdminConsoleController::class, 'access'])->name('access');
    Route::get('/saas/dashboard', [AdminConsoleController::class, 'saasDashboard'])->name('saas.dashboard');
    Route::get('/saas/tenants', [AdminConsoleController::class, 'saasTenants'])->name('saas.tenants');
    Route::post('/saas/tenants', [AdminConsoleController::class, 'createTenant'])->name('saas.tenants.create');
    Route::patch('/saas/tenants/{tenantId}', [AdminConsoleController::class, 'updateTenant'])->name('saas.tenants.update');
    Route::post('/saas/tenants/{tenantId}/switch', [AdminConsoleController::class, 'switchTenantContext'])->name('saas.tenants.switch');
    Route::post('/saas/tenants/switch/platform', [AdminConsoleController::class, 'clearTenantContext'])->name('saas.tenants.switch.platform');
    Route::post('/saas/users/tenant', [AdminConsoleController::class, 'assignUserTenant'])->name('saas.users.tenant.assign');
    Route::post('/access/users', [AdminConsoleController::class, 'createStaffUser'])->name('access.users.create');
    Route::post('/access/roles', [AdminConsoleController::class, 'createRole'])->name('access.roles.create');
    Route::patch('/access/roles/{roleId}/permissions', [AdminConsoleController::class, 'updateRolePermissions'])->name('access.roles.permissions.update');
    Route::delete('/access/roles/{roleId}', [AdminConsoleController::class, 'deleteRole'])->name('access.roles.delete');
    Route::patch('/access/users/{userId}/roles', [AdminConsoleController::class, 'assignUserRoles'])->name('access.users.roles.update');
    Route::get('/audit', [AdminConsoleController::class, 'audit'])->name('audit');
    Route::get('/behavior-ai', [BehaviorAiController::class, 'index'])->name('behavior-ai.index');
    Route::post('/behavior-ai/recommendations/{recommendationId}/review', [BehaviorAiController::class, 'reviewRecommendation'])->name('behavior-ai.review');
    Route::post('/behavior-ai/recommendations/approve-all-pending', [BehaviorAiController::class, 'approveAllPendingRecommendations'])->name('behavior-ai.review.approve-all-pending');
    Route::post('/behavior-ai/runtime/start', [BehaviorAiController::class, 'startRuntime'])->name('behavior-ai.runtime.start');
    Route::get('/behavior-ai/runtime/status', [BehaviorAiController::class, 'runtimeStatus'])->name('behavior-ai.runtime.status');
    Route::get('/behavior-ai/live-status', [BehaviorAiController::class, 'liveStatus'])->name('behavior-ai.live-status');
    Route::post('/behavior-ai/train-now', [BehaviorAiController::class, 'queueTrainNow'])->name('behavior-ai.train-now');
    Route::post('/behavior-ai/retrain', [BehaviorAiController::class, 'queueRetrain'])->name('behavior-ai.retrain');
    Route::post('/behavior-ai/replay', [BehaviorAiController::class, 'replayFailedStream'])->name('behavior-ai.replay');
    Route::get('/behavior-baseline', [BehaviorAiController::class, 'baseline'])->name('behavior-baseline.index');
    Route::post('/behavior-baseline/settings', [BehaviorAiController::class, 'updateBaselineSettings'])->name('behavior-baseline.settings');
    Route::post('/behavior-baseline/backfill', [BehaviorAiController::class, 'queueBaselineBackfill'])->name('behavior-baseline.backfill');
    Route::get('/behavior-remediation', [BehaviorAiController::class, 'remediation'])->name('behavior-remediation.index');
    Route::post('/behavior-remediation/settings', [BehaviorAiController::class, 'updateRemediationSettings'])->name('behavior-remediation.settings');
    Route::post('/behavior-remediation/sweep', [BehaviorAiController::class, 'queueRemediationSweep'])->name('behavior-remediation.sweep');
});

Route::get('/agent/releases/{releaseId}/download', [AdminConsoleController::class, 'downloadAgentRelease'])
    ->middleware('signed')
    ->name('agent.release.download');
Route::get('/agent/releases/{releaseId}/install-script', [AdminConsoleController::class, 'agentInstallScript'])
    ->middleware('signed')
    ->name('agent.release.script');
Route::get('/agent/releases/{releaseId}/install-launcher', [AdminConsoleController::class, 'agentInstallLauncher'])
    ->middleware('signed')
    ->name('agent.release.launcher');
Route::get('/packages/files/{packageFileId}/download', [AdminConsoleController::class, 'downloadPackageFile'])
    ->middleware('signed:relative')
    ->name('package.file.download');
Route::get('/packages/files/{packageFileId}/download-public', [AdminConsoleController::class, 'downloadPackageFilePublic'])
    ->name('package.file.download.public');

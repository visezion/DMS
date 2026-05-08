<?php

use App\Http\Controllers\Web\AdminAuthController;
use App\Http\Controllers\Web\AdminAutonomousResponseController;
use App\Http\Controllers\Web\AdminConsoleController;
use App\Http\Controllers\Web\AdminEndpointIntelligenceController;
use Illuminate\Support\Facades\Route;

$standaloneMode = (bool) config('dms.standalone_mode', true);

Route::view('/', 'welcome');
Route::get('/login', function () {
    return redirect()->route('admin.login');
})->name('login');

Route::middleware('guest')->group(function () use ($standaloneMode) {
    if (! $standaloneMode) {
        Route::get('/admin/signup', [AdminAuthController::class, 'registerForm'])->name('admin.signup');
        Route::post('/admin/signup', [AdminAuthController::class, 'register'])->name('admin.signup.submit');
    }
    Route::get('/admin/login', [AdminAuthController::class, 'loginForm'])->name('admin.login');
    Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login.submit');
    Route::get('/admin/login/captcha-refresh', [AdminAuthController::class, 'refreshCaptcha'])->name('admin.login.captcha.refresh');
    Route::get('/admin/login/mfa', [AdminAuthController::class, 'mfaForm'])->name('admin.login.mfa.form');
    Route::post('/admin/login/mfa', [AdminAuthController::class, 'verifyMfa'])->name('admin.login.mfa.verify');
    Route::post('/admin/login/mfa/cancel', [AdminAuthController::class, 'cancelMfa'])->name('admin.login.mfa.cancel');
});

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () use ($standaloneMode) {
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

    Route::get('/', [AdminConsoleController::class, 'dashboard'])->name('dashboard');

    Route::get('/devices', [AdminConsoleController::class, 'devices'])->middleware('permission:devices.read')->name('devices');
    Route::get('/assets', [AdminConsoleController::class, 'assetsOverview'])->middleware('permission:devices.read')->name('assets');
    Route::get('/assets/hardware', [AdminConsoleController::class, 'assetsHardwareInventory'])->middleware('permission:devices.read')->name('assets.hardware');
    Route::get('/assets/software', [AdminConsoleController::class, 'assetsSoftwareInventory'])->middleware('permission:devices.read')->name('assets.software');
    Route::get('/assets/clients', [AdminConsoleController::class, 'assetsClientManagement'])->middleware('permission:devices.read')->name('assets.clients');
    Route::get('/enroll-devices', [AdminConsoleController::class, 'enrollDevices'])->middleware('permission:devices.write')->name('enroll-devices');
    Route::get('/devices/{deviceId}', [AdminConsoleController::class, 'deviceDetail'])->middleware('permission:devices.read')->name('devices.show');
    Route::get('/devices/{deviceId}/live', [AdminConsoleController::class, 'deviceDetailLive'])->middleware('permission:devices.read')->name('devices.live');
    Route::patch('/devices/{deviceId}', [AdminConsoleController::class, 'updateDevice'])->middleware('permission:devices.write')->name('devices.update');
    Route::delete('/devices/{deviceId}', [AdminConsoleController::class, 'deleteDevice'])->middleware('permission:devices.write')->name('devices.delete');
    Route::post('/devices/{deviceId}/force-delete', [AdminConsoleController::class, 'forceDeleteDevice'])->middleware('permission:devices.write')->name('devices.force-delete');
    Route::delete('/devices/{deviceId}/policy-assignments/{assignmentId}', [AdminConsoleController::class, 'removeDevicePolicyAssignment'])->middleware('permission:devices.write')->name('devices.policies.remove');
    Route::post('/devices/{deviceId}/packages/uninstall', [AdminConsoleController::class, 'uninstallDevicePackage'])->middleware('permission:devices.write')->name('devices.packages.uninstall');
    Route::post('/devices/{deviceId}/agent/uninstall', [AdminConsoleController::class, 'uninstallDeviceAgent'])->middleware('permission:devices.write')->name('devices.agent.uninstall');
    Route::post('/devices/{deviceId}/reboot', [AdminConsoleController::class, 'rebootDevice'])->middleware('permission:devices.write')->name('devices.reboot');
    Route::post('/devices/{deviceId}/reenroll', [AdminConsoleController::class, 'reenrollDevice'])->middleware('permission:devices.write')->name('devices.reenroll');
    Route::post('/devices/enrollment-token', [AdminConsoleController::class, 'createEnrollmentToken'])->middleware('permission:devices.write')->name('devices.enrollment-token');

    Route::get('/groups', [AdminConsoleController::class, 'groups'])->middleware('permission:groups.read')->name('groups');
    Route::get('/groups/create', [AdminConsoleController::class, 'groupsCreate'])->middleware('permission:groups.write')->name('groups.create-page');
    Route::get('/groups/{groupId}', [AdminConsoleController::class, 'groupDetail'])->middleware('permission:groups.read')->name('groups.show');
    Route::post('/groups', [AdminConsoleController::class, 'createGroup'])->middleware('permission:groups.write')->name('groups.create');
    Route::delete('/groups/{groupId}', [AdminConsoleController::class, 'deleteGroup'])->middleware('permission:groups.write')->name('groups.delete');
    Route::post('/groups/bulk-assign', [AdminConsoleController::class, 'bulkAssignGroupMembers'])->middleware('permission:groups.write')->name('groups.bulk-assign');
    Route::post('/groups/{groupId}/members', [AdminConsoleController::class, 'addGroupMember'])->middleware('permission:groups.write')->name('groups.members.add');
    Route::delete('/groups/{groupId}/members/{deviceId}', [AdminConsoleController::class, 'removeGroupMember'])->middleware('permission:groups.write')->name('groups.members.remove');
    Route::post('/groups/{groupId}/kiosk-lockdown', [AdminConsoleController::class, 'applyGroupKioskLockdown'])->middleware('permission:groups.write')->name('groups.kiosk-lockdown');
    Route::post('/groups/{groupId}/policy-assignments', [AdminConsoleController::class, 'addGroupPolicyAssignment'])->middleware('permission:groups.write')->name('groups.policies.add');
    Route::delete('/groups/{groupId}/policy-assignments/{assignmentId}', [AdminConsoleController::class, 'removeGroupPolicyAssignment'])->middleware('permission:groups.write')->name('groups.policies.remove');
    Route::post('/groups/{groupId}/package-assignments', [AdminConsoleController::class, 'addGroupPackageAssignment'])->middleware('permission:groups.write')->name('groups.packages.add');
    Route::delete('/groups/{groupId}/package-assignments/{jobId}', [AdminConsoleController::class, 'removeGroupPackageAssignment'])->middleware('permission:groups.write')->name('groups.packages.remove');

    Route::get('/packages', [AdminConsoleController::class, 'packages'])->middleware('permission:packages.read')->name('packages');
    Route::get('/packages/icon/windows-store', [AdminConsoleController::class, 'packageWindowsStoreIcon'])->middleware('permission:packages.read')->name('packages.icon.windows-store');
    Route::post('/packages/hash-from-uri', [AdminConsoleController::class, 'packageSha256FromUri'])->middleware('permission:packages.write')->name('packages.hash-from-uri');
    Route::get('/packages/{packageId}', [AdminConsoleController::class, 'packageDetail'])->middleware('permission:packages.read')->name('packages.show');
    Route::post('/packages', [AdminConsoleController::class, 'createPackage'])->middleware('permission:packages.write')->name('packages.create');
    Route::post('/packages/{packageId}/versions', [AdminConsoleController::class, 'createPackageVersion'])->middleware('permission:packages.write')->name('packages.versions.create');
    Route::delete('/packages/{packageId}', [AdminConsoleController::class, 'deletePackage'])->middleware('permission:packages.write')->name('packages.delete');
    Route::delete('/packages/{packageId}/versions/{versionId}', [AdminConsoleController::class, 'deletePackageVersion'])->middleware('permission:packages.write')->name('packages.versions.delete');
    Route::post('/packages/versions/{versionId}/deploy', [AdminConsoleController::class, 'deployPackageVersion'])->middleware('permission:packages.write')->name('packages.versions.deploy');

    Route::get('/policies', [AdminConsoleController::class, 'policies'])->middleware('permission:policies.read')->name('policies');
    Route::get('/policy-categories', [AdminConsoleController::class, 'policyCategoriesPage'])->middleware('permission:policies.read')->name('policy-categories');
    Route::get('/policies/{policyId}', [AdminConsoleController::class, 'policyDetail'])->middleware('permission:policies.read')->name('policies.show');
    Route::post('/policies', [AdminConsoleController::class, 'createPolicy'])->middleware('permission:policies.write')->name('policies.create');
    Route::get('/catalog', [AdminConsoleController::class, 'catalog'])->middleware('permission:policies.read')->name('catalog');
    Route::post('/policies/catalog', [AdminConsoleController::class, 'createPolicyCatalogPreset'])->middleware('permission:policies.write')->name('policies.catalog.create');
    Route::patch('/policies/catalog/{catalogKey}', [AdminConsoleController::class, 'updatePolicyCatalogPreset'])->middleware('permission:policies.write')->name('policies.catalog.update');
    Route::delete('/policies/catalog/{catalogKey}', [AdminConsoleController::class, 'deletePolicyCatalogPreset'])->middleware('permission:policies.write')->name('policies.catalog.delete');
    Route::post('/policies/categories', [AdminConsoleController::class, 'createPolicyCategory'])->middleware('permission:policies.write')->name('policies.categories.create');
    Route::patch('/policies/categories', [AdminConsoleController::class, 'updatePolicyCategory'])->middleware('permission:policies.write')->name('policies.categories.update');
    Route::delete('/policies/categories', [AdminConsoleController::class, 'deletePolicyCategory'])->middleware('permission:policies.write')->name('policies.categories.delete');
    Route::patch('/policies/{policyId}', [AdminConsoleController::class, 'updatePolicy'])->middleware('permission:policies.write')->name('policies.update');
    Route::delete('/policies/{policyId}', [AdminConsoleController::class, 'deletePolicy'])->middleware('permission:policies.write')->name('policies.delete');
    Route::post('/policies/{policyId}/versions', [AdminConsoleController::class, 'createPolicyVersion'])->middleware('permission:policies.write')->name('policies.versions.create');
    Route::patch('/policies/{policyId}/versions/{versionId}', [AdminConsoleController::class, 'updatePolicyVersion'])->middleware('permission:policies.write')->name('policies.versions.update');
    Route::delete('/policies/{policyId}/versions/{versionId}', [AdminConsoleController::class, 'deletePolicyVersion'])->middleware('permission:policies.write')->name('policies.versions.delete');
    Route::post('/policies/{policyId}/versions/{versionId}/assignments', [AdminConsoleController::class, 'assignPolicyVersion'])->middleware('permission:policies.write')->name('policies.versions.assignments.create');
    Route::delete('/policies/{policyId}/versions/{versionId}/assignments/{assignmentId}', [AdminConsoleController::class, 'deletePolicyAssignment'])->middleware('permission:policies.write')->name('policies.versions.assignments.delete');

    Route::get('/jobs', [AdminConsoleController::class, 'jobs'])->middleware('permission:jobs.read')->name('jobs');
    Route::get('/jobs/{jobId}', [AdminConsoleController::class, 'jobDetail'])->middleware('permission:jobs.read')->name('jobs.show');
    Route::post('/jobs', [AdminConsoleController::class, 'createJob'])->middleware('permission:jobs.write')->name('jobs.create');
    Route::post('/jobs/{jobId}/rerun', [AdminConsoleController::class, 'rerunJob'])->middleware('permission:jobs.write')->name('jobs.rerun');
    Route::post('/job-runs/{runId}/rerun', [AdminConsoleController::class, 'rerunJobRun'])->middleware('permission:jobs.write')->name('job-runs.rerun');
    Route::post('/jobs/store-clear', [AdminConsoleController::class, 'storeAndClearJobs'])->middleware('permission:jobs.write')->name('jobs.store-clear');
    Route::post('/ops/settings', [AdminConsoleController::class, 'updateOps'])->middleware('permission:jobs.write')->name('ops.update');
    Route::post('/ops/kill-switch', [AdminConsoleController::class, 'toggleKillSwitch'])->middleware('permission:jobs.write')->name('ops.kill-switch');
    Route::post('/ops/rotate-signing-key', [AdminConsoleController::class, 'rotateSigningKey'])->middleware('permission:jobs.write')->name('ops.rotate-key');

    Route::middleware('permission:jobs.write')->group(function () {
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
    });
    Route::get('/getting-started', [AdminConsoleController::class, 'gettingStarted'])->name('getting-started');
    Route::get('/docs', [AdminConsoleController::class, 'docs'])->name('docs');
    Route::get('/notes', [AdminConsoleController::class, 'notes'])->name('notes');
    Route::post('/notes', [AdminConsoleController::class, 'createNote'])->name('notes.create');
    Route::patch('/notes/{noteId}', [AdminConsoleController::class, 'updateNote'])->name('notes.update');
    Route::delete('/notes/{noteId}', [AdminConsoleController::class, 'deleteNote'])->name('notes.delete');
    Route::get('/profile', [AdminConsoleController::class, 'profile'])->name('profile');
    Route::post('/profile', [AdminConsoleController::class, 'updateProfile'])->name('profile.update');
    Route::post('/locale', [AdminConsoleController::class, 'updateLocale'])->name('locale.update');
    Route::post('/profile/mfa/setup', [AdminConsoleController::class, 'setupProfileMfa'])->name('profile.mfa.setup');
    Route::post('/profile/mfa/enable', [AdminConsoleController::class, 'enableProfileMfa'])->name('profile.mfa.enable');
    Route::post('/profile/mfa/disable', [AdminConsoleController::class, 'disableProfileMfa'])->name('profile.mfa.disable');
    Route::get('/settings', [AdminConsoleController::class, 'settings'])->name('settings');
    Route::get('/security-hardening', [AdminConsoleController::class, 'securityCommandCenter'])->name('security-hardening');
    Route::get('/security-command-center', [AdminConsoleController::class, 'securityCommandCenter'])->name('security-command-center');
    Route::get('/settings/branding', [AdminConsoleController::class, 'branding'])->name('settings.branding');
    Route::post('/settings/branding', [AdminConsoleController::class, 'updateBranding'])->name('settings.branding.update');
    Route::post('/settings/signature-bypass', [AdminConsoleController::class, 'updateSignatureBypass'])->name('settings.signature-bypass');
    Route::post('/settings/endpoint-intelligence', [AdminConsoleController::class, 'updateEndpointIntelligence'])->name('settings.endpoint-intelligence');
    Route::post('/settings/auth-policy', [AdminConsoleController::class, 'updateAuthPolicy'])->name('settings.auth-policy');
    Route::post('/settings/https-app-url', [AdminConsoleController::class, 'updateHttpsAppUrl'])->name('settings.https-app-url');
    Route::post('/settings/environment-posture', [AdminConsoleController::class, 'updateEnvironmentPosture'])->name('settings.environment-posture');
    Route::get('/access', [AdminConsoleController::class, 'access'])->middleware('permission:access.read')->name('access');
    Route::get('/access/users', [AdminConsoleController::class, 'accessUsers'])->middleware('permission:access.read')->name('access.users');
    Route::get('/access/users/new', [AdminConsoleController::class, 'accessCreateUser'])->middleware('permission:access.write')->name('access.users.new');
    Route::get('/access/roles', [AdminConsoleController::class, 'accessRoles'])->middleware('permission:access.read')->name('access.roles');
    Route::get('/access/permissions', [AdminConsoleController::class, 'accessPermissions'])->middleware('permission:access.read')->name('access.permissions');

    if (! $standaloneMode) {
        Route::get('/saas/dashboard', [AdminConsoleController::class, 'saasDashboard'])->name('saas.dashboard');
        Route::get('/saas/tenants', [AdminConsoleController::class, 'saasTenants'])->name('saas.tenants');
        Route::post('/saas/tenants', [AdminConsoleController::class, 'createTenant'])->name('saas.tenants.create');
        Route::patch('/saas/tenants/{tenantId}', [AdminConsoleController::class, 'updateTenant'])->name('saas.tenants.update');
        Route::post('/saas/tenants/{tenantId}/switch', [AdminConsoleController::class, 'switchTenantContext'])->name('saas.tenants.switch');
        Route::post('/saas/tenants/switch/platform', [AdminConsoleController::class, 'clearTenantContext'])->name('saas.tenants.switch.platform');
        Route::post('/saas/users/tenant', [AdminConsoleController::class, 'assignUserTenant'])->name('saas.users.tenant.assign');
    }

    Route::post('/access/users', [AdminConsoleController::class, 'createStaffUser'])->middleware('permission:access.write')->name('access.users.create');
    Route::post('/access/roles', [AdminConsoleController::class, 'createRole'])->middleware('permission:access.write')->name('access.roles.create');
    Route::patch('/access/roles/{roleId}/permissions', [AdminConsoleController::class, 'updateRolePermissions'])->middleware('permission:access.write')->name('access.roles.permissions.update');
    Route::delete('/access/roles/{roleId}', [AdminConsoleController::class, 'deleteRole'])->middleware('permission:access.write')->name('access.roles.delete');
    Route::patch('/access/users/{userId}', [AdminConsoleController::class, 'updateUser'])->middleware('permission:access.write')->name('access.users.update');
    Route::patch('/access/users/{userId}/roles', [AdminConsoleController::class, 'assignUserRoles'])->middleware('permission:access.write')->name('access.users.roles.update');
    Route::delete('/access/users/{userId}', [AdminConsoleController::class, 'deleteUser'])->middleware('permission:access.write')->name('access.users.delete');
    Route::get('/audit', [AdminConsoleController::class, 'audit'])->middleware('permission:audit.read')->name('audit');

    Route::get('/intelligence/health', [AdminEndpointIntelligenceController::class, 'fleetHealthOverview'])->middleware('permission:health.read')->name('intelligence.health');
    Route::get('/intelligence/health/devices/{deviceId}', [AdminEndpointIntelligenceController::class, 'deviceHealthDetail'])->middleware('permission:health.read')->name('intelligence.health.device');
    Route::get('/intelligence/telemetry/devices/{deviceId}', [AdminEndpointIntelligenceController::class, 'telemetryDetail'])->middleware('permission:health.read')->name('intelligence.telemetry.device');
    Route::get('/intelligence/risk', [AdminEndpointIntelligenceController::class, 'riskDashboard'])->middleware('permission:risk.read')->name('intelligence.risk');
    Route::get('/intelligence/incidents', [AdminEndpointIntelligenceController::class, 'incidentExplorer'])->middleware('permission:incidents.read')->name('intelligence.incidents');
    Route::get('/intelligence/incidents/{incidentId}/timeline', [AdminEndpointIntelligenceController::class, 'incidentTimeline'])->middleware('permission:incidents.read')->name('intelligence.incidents.timeline');
    Route::get('/intelligence/assistant', [AdminEndpointIntelligenceController::class, 'assistant'])->middleware('permission:assistant.use')->name('intelligence.assistant');
    Route::post('/intelligence/assistant/ask', [AdminEndpointIntelligenceController::class, 'askAssistant'])
        ->middleware('permission:assistant.use')
        ->name('intelligence.assistant.ask');
    Route::get('/intelligence/remediation', [AdminEndpointIntelligenceController::class, 'remediationQueue'])->middleware('permission:remediation.read')->name('intelligence.remediation');
    Route::post('/intelligence/remediation/plans/{planId}/validate', [AdminEndpointIntelligenceController::class, 'validateRemediationPlan'])
        ->middleware('permission:remediation.plan')
        ->name('intelligence.remediation.plans.validate');
    Route::post('/intelligence/remediation/plans/{planId}/approve', [AdminEndpointIntelligenceController::class, 'approveRemediationPlan'])
        ->middleware('permission:remediation.approve')
        ->name('intelligence.remediation.plans.approve');
    Route::post('/intelligence/remediation/plans/{planId}/execute', [AdminEndpointIntelligenceController::class, 'executeRemediationPlan'])
        ->middleware('permission:remediation.execute')
        ->name('intelligence.remediation.plans.execute');
    Route::post('/intelligence/remediation/actions/{actionId}/rollback', [AdminEndpointIntelligenceController::class, 'rollbackRemediationAction'])
        ->middleware('permission:remediation.execute')
        ->name('intelligence.remediation.actions.rollback');
    Route::get('/intelligence/approvals', [AdminEndpointIntelligenceController::class, 'approvalCenter'])->middleware('permission:remediation.approve')->name('intelligence.approvals');
    Route::post('/intelligence/approvals/{approvalId}/approve', [AdminEndpointIntelligenceController::class, 'approveRequest'])
        ->middleware('permission:remediation.approve')
        ->name('intelligence.approvals.approve');
    Route::post('/intelligence/approvals/{approvalId}/reject', [AdminEndpointIntelligenceController::class, 'rejectRequest'])
        ->middleware('permission:remediation.approve')
        ->name('intelligence.approvals.reject');
    Route::get('/intelligence/actions', [AdminEndpointIntelligenceController::class, 'actionHistory'])->middleware('permission:remediation.read')->name('intelligence.actions');
    Route::get('/intelligence/autonomy', [AdminEndpointIntelligenceController::class, 'autonomySettings'])->middleware('permission:autonomy.manage')->name('intelligence.autonomy');
    Route::post('/intelligence/autonomy/policies', [AdminEndpointIntelligenceController::class, 'saveAutonomyPolicy'])
        ->middleware('permission:autonomy.manage')
        ->name('intelligence.autonomy.policies.save');
    Route::get('/intelligence/autonomous/decisions', [AdminAutonomousResponseController::class, 'decisions'])->middleware('permission:autonomous.read')->name('intelligence.autonomous.decisions');
    Route::get('/intelligence/autonomous/decisions/{decisionId}', [AdminAutonomousResponseController::class, 'showDecision'])->middleware('permission:autonomous.read')->name('intelligence.autonomous.decisions.show');
    Route::get('/intelligence/autonomous/policies', [AdminAutonomousResponseController::class, 'policies'])->middleware('permission:autonomous.read')->name('intelligence.autonomous.policies');
    Route::post('/intelligence/autonomous/policies', [AdminAutonomousResponseController::class, 'storePolicy'])->middleware('permission:autonomous.manage')->name('intelligence.autonomous.policies.store');
    Route::patch('/intelligence/autonomous/policies/{policyId}', [AdminAutonomousResponseController::class, 'updatePolicy'])->middleware('permission:autonomous.manage')->name('intelligence.autonomous.policies.update');
    Route::get('/intelligence/autonomous/mappings', [AdminAutonomousResponseController::class, 'mappings'])->middleware('permission:autonomous.read')->name('intelligence.autonomous.mappings');
    Route::post('/intelligence/autonomous/mappings', [AdminAutonomousResponseController::class, 'storeMapping'])->middleware('permission:autonomous.manage')->name('intelligence.autonomous.mappings.store');
    Route::patch('/intelligence/autonomous/mappings/{mappingId}', [AdminAutonomousResponseController::class, 'updateMapping'])->middleware('permission:autonomous.manage')->name('intelligence.autonomous.mappings.update');
    Route::get('/intelligence/autonomous/catalog', [AdminAutonomousResponseController::class, 'catalog'])->middleware('permission:autonomous.read')->name('intelligence.autonomous.catalog');
    Route::get('/intelligence/autonomous/simulate', [AdminAutonomousResponseController::class, 'simulate'])->middleware('permission:autonomous.manage')->name('intelligence.autonomous.simulate');
    Route::post('/intelligence/autonomous/simulate', [AdminAutonomousResponseController::class, 'simulateEvaluate'])->middleware('permission:autonomous.manage')->name('intelligence.autonomous.simulate.run');
    Route::post('/intelligence/autonomous/evaluate', [AdminAutonomousResponseController::class, 'evaluate'])->middleware('permission:autonomous.manage')->name('intelligence.autonomous.evaluate');
    Route::post('/intelligence/autonomous/decisions/{decisionId}/approve', [AdminAutonomousResponseController::class, 'approve'])->middleware('permission:autonomous.approve')->name('intelligence.autonomous.decisions.approve');
    Route::post('/intelligence/autonomous/decisions/{decisionId}/reject', [AdminAutonomousResponseController::class, 'reject'])->middleware('permission:autonomous.approve')->name('intelligence.autonomous.decisions.reject');
    Route::post('/intelligence/autonomous/decisions/{decisionId}/execute', [AdminAutonomousResponseController::class, 'execute'])->middleware('permission:autonomous.execute')->name('intelligence.autonomous.decisions.execute');
    Route::post('/intelligence/autonomous/decisions/{decisionId}/rollback', [AdminAutonomousResponseController::class, 'rollback'])->middleware('permission:autonomous.execute')->name('intelligence.autonomous.decisions.rollback');
    Route::get('/intelligence/tuning', [AdminEndpointIntelligenceController::class, 'engineTuning'])->middleware('permission:health.read')->name('intelligence.tuning');
    Route::get('/intelligence/executive/{deviceId}', [AdminEndpointIntelligenceController::class, 'executiveSummary'])->middleware('permission:health.read')->name('intelligence.executive');
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

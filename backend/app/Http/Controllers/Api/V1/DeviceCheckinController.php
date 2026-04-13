<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Common\DeviceIngestionAuthService;
use App\Domain\Common\DeviceIntelligenceDispatchService;
use App\Http\Controllers\Controller;
use App\Models\ComplianceResult;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\DeviceHealthSnapshot;
use App\Models\DmsJob;
use App\Models\JobEvent;
use App\Models\JobRun;
use App\Models\ActionRollback;
use App\Models\Policy;
use App\Models\PolicyRule;
use App\Models\PolicyVersion;
use App\Models\RemoteSupportSession;
use App\Models\RemediationActionResult;
use App\Services\AuditLogger;
use App\Services\CommandEnvelopeSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceCheckinController extends Controller
{
    public function __construct(
        private readonly DeviceIntelligenceDispatchService $dispatchService,
        private readonly DeviceIngestionAuthService $ingestionAuth,
    ) {
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['required', 'uuid'],
            'agent_version' => ['required', 'string'],
            'agent_build' => ['nullable', 'string', 'max:128'],
            'checkin_id' => ['nullable', 'string', 'max:128'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'os_name' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string'],
            'inventory' => ['nullable', 'array'],
            'runtime_diagnostics' => ['nullable', 'array'],
            'uwf_status' => ['nullable', 'array'],
        ]);

        $device = Device::query()->find($payload['device_id']);
        if (! $device) {
            $device = $this->recoverDeletedDeviceRecord($payload);
        }
        $tags = is_array($device->tags) ? $device->tags : [];
        if (! empty($payload['agent_build'])) {
            $tags['agent_build'] = (string) $payload['agent_build'];
        }
        if (isset($payload['inventory']) && is_array($payload['inventory'])) {
            $tags['inventory'] = $payload['inventory'];
            $tags['inventory_updated_at'] = now()->toIso8601String();
        }
        if (isset($payload['runtime_diagnostics']) && is_array($payload['runtime_diagnostics'])) {
            $tags['runtime_diagnostics'] = $payload['runtime_diagnostics'];
            $tags['runtime_diagnostics_updated_at'] = now()->toIso8601String();
        }
        if (! empty($payload['checkin_id'])) {
            $tags['last_checkin_id'] = (string) $payload['checkin_id'];
        }
        $this->mergeRequestIpIntoRuntimeTags($tags, $request);
        $this->mergeUwfStatusIntoTags($tags, $payload);
        $updateData = [
            'agent_version' => $payload['agent_version'],
            'tags' => $tags,
            'status' => 'online',
            'last_seen_at' => now(),
        ];
        $this->applyDeviceIdentityUpdates($updateData, $payload);
        $device->update($updateData);
        $this->dispatchIntelligenceBuild(
            $device->id,
            $this->shouldBuildIntelligenceImmediately($device->id, $payload)
        );
        $bootstrap = $this->bootstrapPayload($device);

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'bootstrap' => $bootstrap,
        ]);
    }

    public function checkin(Request $request, CommandEnvelopeSigner $signer): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['required', 'uuid'],
            'agent_version' => ['nullable', 'string'],
            'agent_build' => ['nullable', 'string', 'max:128'],
            'checkin_id' => ['nullable', 'string', 'max:128'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'os_name' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'inventory' => ['nullable', 'array'],
            'runtime_diagnostics' => ['nullable', 'array'],
            'uwf_status' => ['nullable', 'array'],
        ]);

        $device = Device::query()->find($payload['device_id']);
        if (! $device) {
            $device = $this->recoverDeletedDeviceRecord($payload);
        }
        $tags = is_array($device->tags) ? $device->tags : [];
        if (! empty($payload['agent_build'])) {
            $tags['agent_build'] = (string) $payload['agent_build'];
        }
        if (isset($payload['inventory']) && is_array($payload['inventory'])) {
            $tags['inventory'] = $payload['inventory'];
            $tags['inventory_updated_at'] = now()->toIso8601String();
        }
        if (isset($payload['runtime_diagnostics']) && is_array($payload['runtime_diagnostics'])) {
            $tags['runtime_diagnostics'] = $payload['runtime_diagnostics'];
            $tags['runtime_diagnostics_updated_at'] = now()->toIso8601String();
        }
        if (! empty($payload['checkin_id'])) {
            $tags['last_checkin_id'] = (string) $payload['checkin_id'];
        }
        $this->mergeRequestIpIntoRuntimeTags($tags, $request);
        $this->mergeUwfStatusIntoTags($tags, $payload);
        $updateData = [
            'last_seen_at' => now(),
            'status' => 'online',
            'tags' => $tags,
        ];
        if (! empty($payload['agent_version'])) {
            $updateData['agent_version'] = (string) $payload['agent_version'];
        }
        $this->applyDeviceIdentityUpdates($updateData, $payload);
        $device->update($updateData);
        $this->dispatchIntelligenceBuild(
            $device->id,
            $this->shouldBuildIntelligenceImmediately($device->id, $payload)
        );

        $killSwitch = $this->settingBool('jobs.kill_switch', false);
        $bootstrap = $this->bootstrapPayload($device);
        if ($killSwitch) {
            return response()->json([
                'server_time' => now()->toIso8601String(),
                'commands' => [],
                'bootstrap' => $bootstrap,
                'control' => [
                    'jobs_paused' => true,
                ],
            ]);
        }

        $runs = JobRun::query()
            ->join('jobs', 'jobs.id', '=', 'job_runs.job_id')
            ->where('job_runs.device_id', $device->id)
            ->where('job_runs.status', 'pending')
            ->where(function ($query) {
                $query->whereNull('job_runs.next_retry_at')->orWhere('job_runs.next_retry_at', '<=', now());
            })
            ->orderByRaw("CASE WHEN jobs.job_type IN ('start_webrtc_session', 'start_live_stream') THEN 0 ELSE 1 END")
            ->orderByDesc('jobs.priority')
            ->orderBy('job_runs.created_at')
            ->orderBy('job_runs.id')
            ->select('job_runs.*')
            ->limit(10)
            ->get();

        // Keep sequence in 32-bit safe range for older agents while still monotonic.
        $sequenceBase = now()->timestamp;
        $commandTtlMinutes = max(1, min(60, $this->settingInt('jobs.command_ttl_minutes', 15)));
        $commands = $runs->values()->map(function (JobRun $run, int $index) use ($signer, $sequenceBase, $device, $commandTtlMinutes) {
            $job = DmsJob::query()->find($run->job_id);
            if (! $job) {
                return null;
            }

            $issuedAt = now()->utc();
            $expiresAt = $issuedAt->copy()->addMinutes($commandTtlMinutes);

            $envelope = [
                'schema' => 'dms.command.v1',
                'command_id' => $run->id,
                'device_id' => $run->device_id,
                // Must be monotonically increasing to satisfy replay protection.
                // Retry attempts for the same job_run_id get a new sequence.
                'sequence' => $sequenceBase + $index,
                'nonce' => base64_encode(random_bytes(16)),
                // Keep DateTimeOffset "O" shape (7 fractional digits + offset) for legacy verifier compatibility.
                'issued_at' => $this->toDotNetDateTimeOffset($issuedAt),
                'expires_at' => $this->toDotNetDateTimeOffset($expiresAt),
                'type' => $job->job_type,
                'payload' => $job->payload,
                // Keep payload hash aligned with agent-side hash candidates.
                'payload_sha256' => $this->payloadHashForAgent($job->payload),
            ];

            $attempt = max(0, (int) ($run->attempt_count ?? 0));
            $signatureModes = $this->signatureCompatModes();
            $modeCount = max(1, $signatureModes->count());
            $signatureMode = $this->resolveSignatureModeForRun($device, $run) ?? 'wire_digest';
            $signatureKeyKid = $this->resolveSignatureKeyKidForRun($signer, $attempt, $modeCount);

            $signed = [
                'envelope' => $envelope,
                'signature' => $signer->signEnvelope($envelope, $signatureMode, $signatureKeyKid),
            ];

            JobEvent::query()->create([
                'job_run_id' => $run->id,
                'event_type' => 'dispatched',
                'event_payload' => [
                    'at' => now()->toIso8601String(),
                    'signature_mode' => $signatureMode,
                    'signature_kid' => $signed['signature']['kid'] ?? $signatureKeyKid,
                    'attempt_count' => (int) ($run->attempt_count ?? 0),
                    'payload_sha256' => $envelope['payload_sha256'],
                    'payload_hash_impl' => 'v5-canonical-json',
                    'ttl_minutes' => $commandTtlMinutes,
                    'issued_at' => $envelope['issued_at'],
                    'expires_at' => $envelope['expires_at'],
                ],
            ]);

            return $signed;
        })->filter()->values();

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'commands' => $commands,
            'bootstrap' => $bootstrap,
        ]);
    }

    public function remoteSupportLiveFrame(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['required', 'uuid'],
            'session_id' => ['required', 'uuid'],
            'captured_at_utc' => ['nullable', 'date'],
            'mime_type' => ['nullable', 'string', 'max:64'],
            'image_base64' => ['required', 'string', 'max:10485760'],
            'byte_size' => ['nullable', 'integer', 'min:1', 'max:10485760'],
            'checkin_id' => ['nullable', 'string', 'max:128'],
        ]);

        $device = Device::query()->findOrFail($payload['device_id']);
        $auth = $this->ingestionAuth->authorizeBehaviorLogRequest($device, $request, [
            'checkin_id' => (string) ($payload['checkin_id'] ?? ''),
        ]);
        if (! $auth['allowed']) {
            return response()->json([
                'message' => 'Remote live frame authentication failed.',
                'reason' => $auth['reason'],
            ], 401);
        }

        $session = RemoteSupportSession::query()
            ->where('id', (string) $payload['session_id'])
            ->where('device_id', $device->id)
            ->where('status', 'active')
            ->first();
        if (! $session) {
            return response()->json([
                'ok' => false,
                'reason' => 'remote_support_session_not_active',
            ], 404);
        }

        $mimeType = strtolower(trim((string) ($payload['mime_type'] ?? 'image/jpeg')));
        if (! str_starts_with($mimeType, 'image/')) {
            $mimeType = 'image/jpeg';
        }

        $capturedAtIso = now()->toIso8601String();
        if (! empty($payload['captured_at_utc'])) {
            try {
                $capturedAtIso = \Carbon\Carbon::parse((string) $payload['captured_at_utc'])->toIso8601String();
            } catch (\Throwable) {
                // Keep default current timestamp.
            }
        }

        Cache::put(
            $this->remoteSupportLiveFrameCacheKey($device->id, $session->id),
            [
                'session_id' => $session->id,
                'device_id' => $device->id,
                'mime_type' => $mimeType,
                'image_base64' => (string) $payload['image_base64'],
                'byte_size' => (int) ($payload['byte_size'] ?? 0),
                'captured_at_iso' => $capturedAtIso,
                'run_id' => 'live-'.substr((string) Str::uuid(), 0, 12),
                'updated_at_iso' => now()->toIso8601String(),
            ],
            now()->addSeconds(90)
        );
        Cache::put(
            $this->remoteSupportLiveSessionDeviceCacheKey($device->id),
            $session->id,
            now()->addSeconds(90)
        );

        return response()->json([
            'ok' => true,
            'session_id' => $session->id,
            'auth_mode' => $auth['auth_mode'],
        ]);
    }

    public function remoteSupportWebRtcSignal(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['required', 'uuid'],
            'session_id' => ['required', 'uuid'],
            'type' => ['required', 'in:offer,answer,ice-candidate,renegotiate,bye,status,error,pong'],
            'payload' => ['nullable', 'array'],
            'checkin_id' => ['nullable', 'string', 'max:128'],
        ]);

        $auth = $this->authorizeRemoteSupportSessionRequest($request, $payload);
        if (! $auth['allowed']) {
            return response()->json(['message' => 'Remote WebRTC authentication failed.', 'reason' => $auth['reason']], 401);
        }

        $event = $this->appendRemoteSupportRealtimeEvent(
            (string) $payload['session_id'],
            'signals_agent_to_admin',
            (string) $payload['type'],
            is_array($payload['payload'] ?? null) ? $payload['payload'] : [],
            'agent'
        );

        return response()->json([
            'ok' => true,
            'session_id' => (string) $payload['session_id'],
            'event' => $event,
            'auth_mode' => $auth['auth_mode'],
        ]);
    }

    public function remoteSupportWebRtcSignals(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['required', 'uuid'],
            'session_id' => ['required', 'uuid'],
            'since' => ['nullable', 'integer', 'min:0'],
            'checkin_id' => ['nullable', 'string', 'max:128'],
        ]);

        $auth = $this->authorizeRemoteSupportSessionRequest($request, $payload);
        if (! $auth['allowed']) {
            return response()->json(['message' => 'Remote WebRTC authentication failed.', 'reason' => $auth['reason']], 401);
        }

        $since = (int) ($payload['since'] ?? 0);
        $events = $this->readRemoteSupportRealtimeEvents((string) $payload['session_id'], 'signals_admin_to_agent', $since);

        return response()->json([
            'ok' => true,
            'session_id' => (string) $payload['session_id'],
            'events' => $events,
            'latest_seq' => (int) collect($events)->max(fn ($event) => (int) ($event['seq'] ?? 0)),
            'auth_mode' => $auth['auth_mode'],
        ]);
    }

    public function remoteSupportWebRtcInputs(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['required', 'uuid'],
            'session_id' => ['required', 'uuid'],
            'since' => ['nullable', 'integer', 'min:0'],
            'checkin_id' => ['nullable', 'string', 'max:128'],
        ]);

        $auth = $this->authorizeRemoteSupportSessionRequest($request, $payload);
        if (! $auth['allowed']) {
            return response()->json(['message' => 'Remote WebRTC authentication failed.', 'reason' => $auth['reason']], 401);
        }

        $since = (int) ($payload['since'] ?? 0);
        $events = $this->readRemoteSupportRealtimeEvents((string) $payload['session_id'], 'input_admin_to_agent', $since);

        return response()->json([
            'ok' => true,
            'session_id' => (string) $payload['session_id'],
            'events' => $events,
            'latest_seq' => (int) collect($events)->max(fn ($event) => (int) ($event['seq'] ?? 0)),
            'auth_mode' => $auth['auth_mode'],
        ]);
    }

    public function policies(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['required', 'uuid'],
        ]);

        $device = Device::query()->findOrFail($payload['device_id']);
        $device->update(['last_seen_at' => now(), 'status' => 'online']);

        $groupIds = DB::table('device_group_memberships')
            ->where('device_id', $device->id)
            ->pluck('device_group_id')
            ->all();

        $versionIds = DB::table('policy_assignments')
            ->where(function ($query) use ($device, $groupIds) {
                $query
                    ->where(function ($deviceQuery) use ($device) {
                        $deviceQuery->where('target_type', 'device')->where('target_id', $device->id);
                    });

                if ($groupIds !== []) {
                    $query->orWhere(function ($groupQuery) use ($groupIds) {
                        $groupQuery->where('target_type', 'group')->whereIn('target_id', $groupIds);
                    });
                }
            })
            ->pluck('policy_version_id')
            ->unique()
            ->values()
            ->all();

        $versions = PolicyVersion::query()
            ->where('status', 'active')
            ->whereIn('id', $versionIds)
            ->get();

        $policies = $versions->map(function (PolicyVersion $version) {
            $rules = PolicyRule::query()
                ->where('policy_version_id', $version->id)
                ->orderBy('order_index')
                ->get()
                ->map(fn (PolicyRule $rule) => [
                    'id' => $rule->id,
                    'type' => $rule->rule_type,
                    'mode' => $rule->enforce ? 'enforce' : 'audit',
                    'config' => $rule->rule_config,
                ]);

            return [
                'policy_version_id' => $version->id,
                'policy_id' => $version->policy_id,
                'rules' => $rules,
            ];
        });

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'policies' => $policies,
        ]);
    }

    public function jobAck(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $payload = $request->validate([
            'job_run_id' => ['required', 'uuid'],
            'device_id' => ['required', 'uuid'],
        ]);

        $run = JobRun::query()->where('id', $payload['job_run_id'])->where('device_id', $payload['device_id'])->firstOrFail();
        $run->update(['status' => 'acked', 'acked_at' => now(), 'started_at' => now()]);
        DmsJob::query()->where('id', $run->job_id)->update(['status' => 'running']);

        JobEvent::query()->create([
            'job_run_id' => $run->id,
            'event_type' => 'ack',
            'event_payload' => ['at' => now()->toIso8601String()],
        ]);

        $auditLogger->log('job.ack', 'job_run', $run->id, null, $run->toArray(), null, $run->device_id);

        return response()->json(['ok' => true]);
    }

    public function jobResult(Request $request, AuditLogger $auditLogger, CommandEnvelopeSigner $signer): JsonResponse
    {
        $payload = $request->validate([
            'job_run_id' => ['required', 'uuid'],
            'device_id' => ['required', 'uuid'],
            'status' => ['required', 'in:success,failed,running,non_compliant'],
            'exit_code' => ['nullable', 'integer'],
            'result_payload' => ['nullable', 'array'],
            'execution_duration_ms' => ['nullable', 'integer', 'min:0'],
            'stdout_sha256' => ['nullable', 'string', 'max:128'],
            'stderr_sha256' => ['nullable', 'string', 'max:128'],
            'rollback_hint' => ['nullable', 'array'],
            'artifacts' => ['nullable', 'array'],
            'action_token' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'checkin_id' => ['nullable', 'string', 'max:128'],
        ]);

        $run = JobRun::query()->where('id', $payload['job_run_id'])->where('device_id', $payload['device_id'])->firstOrFail();
        $job = DmsJob::query()->find($run->job_id);
        $device = Device::query()->find($payload['device_id']);
        $status = $payload['status'];
        $resultPayload = $payload['result_payload'] ?? null;
        $exitCode = $payload['exit_code'] ?? null;
        $resultEvidence = [
            'execution_duration_ms' => $payload['execution_duration_ms'] ?? null,
            'stdout_sha256' => $payload['stdout_sha256'] ?? null,
            'stderr_sha256' => $payload['stderr_sha256'] ?? null,
            'rollback_hint' => $payload['rollback_hint'] ?? null,
            'artifacts' => $payload['artifacts'] ?? null,
            'action_token' => $payload['action_token'] ?? null,
            'idempotency_key' => $payload['idempotency_key'] ?? null,
            'checkin_id' => $payload['checkin_id'] ?? null,
        ];
        if (is_array($resultPayload)) {
            $resultPayload['_agent_result_meta'] = array_filter($resultEvidence, fn ($value) => $value !== null && $value !== []);
        }
        if (
            $job
            && $job->job_type === 'apply_policy'
            && is_array($resultPayload)
            && ! $this->isPolicyTransportVerificationFailure($resultPayload)
        ) {
            [$status, $resultPayload, $exitCode] = $this->normalizeApplyPolicyUwfCompatibilityResult($status, $resultPayload, $exitCode);
        }
        $lastError = is_array($resultPayload) ? (string) ($resultPayload['error'] ?? '') : '';
        $attempt = ((int) ($run->attempt_count ?? 0)) + 1;
        $isTransient = strtoupper($lastError) === 'E_TRANSIENT';

        $maxRetries = max(0, (int) ($this->settingInt('jobs.max_retries', 3)));
        $baseBackoffSeconds = max(5, (int) ($this->settingInt('jobs.base_backoff_seconds', 30)));
        $retryable = $status === 'failed' && ($attempt <= $maxRetries || $isTransient);

        if (
            $job
            && in_array((string) $job->job_type, ['start_live_stream', 'start_webrtc_session'], true)
            && strtoupper($lastError) === 'E_UNSUPPORTED'
        ) {
            $retryable = false;
        }
        if (
            $job
            && in_array((string) $job->job_type, ['start_live_stream', 'start_webrtc_session'], true)
            && $status === 'failed'
            && ! $isTransient
        ) {
            $retryable = false;
        }

        $fallbackJobId = null;
        $isUnsupportedAgentUninstall = $job
            && $job->job_type === 'uninstall_agent'
            && $status === 'failed'
            && strtoupper($lastError) === 'E_UNSUPPORTED';
        if ($isUnsupportedAgentUninstall) {
            $retryable = false;
            $fallbackJobId = $this->queueLegacyAgentUninstallFallback((string) $payload['device_id'], $job);
        }

        if ($retryable) {
            $delaySeconds = $isTransient
                ? max(60, min(1800, $this->settingInt('jobs.transient_retry_seconds', 300)))
                : $this->computeRetryDelaySeconds($payload['device_id'], $attempt, $baseBackoffSeconds);
            $run->update([
                'status' => 'pending',
                'attempt_count' => $attempt,
                'finished_at' => now(),
                'next_retry_at' => now()->addSeconds($delaySeconds),
                'exit_code' => $exitCode,
                'result_payload' => $resultPayload,
                'last_error' => $lastError,
            ]);
        } else {
            $run->update([
                'status' => $status,
                'attempt_count' => $attempt,
                'finished_at' => now(),
                'next_retry_at' => null,
                'exit_code' => $exitCode,
                'result_payload' => $resultPayload,
                'last_error' => $lastError,
            ]);
        }

        JobEvent::query()->create([
            'job_run_id' => $run->id,
            'event_type' => 'completed',
            'event_payload' => [
                'status' => $status,
                'exit_code' => $exitCode,
                'attempt_count' => $attempt,
                'retry_scheduled' => $retryable,
                'next_retry_at' => $run->next_retry_at?->toIso8601String(),
                'execution_duration_ms' => $payload['execution_duration_ms'] ?? null,
                'stdout_sha256' => $payload['stdout_sha256'] ?? null,
                'stderr_sha256' => $payload['stderr_sha256'] ?? null,
                'rollback_hint' => $payload['rollback_hint'] ?? null,
                'artifacts' => $payload['artifacts'] ?? null,
                'action_token' => $payload['action_token'] ?? null,
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'checkin_id' => $payload['checkin_id'] ?? null,
            ],
        ]);

        $this->syncRemediationActionOutcome($run, $status, $exitCode, $resultPayload, $payload);

        if ($job && $job->job_type === 'apply_policy' && is_array($resultPayload)) {
            $policyVersionId = (string) ($job->payload['policy_version_id'] ?? '');
            $policyId = PolicyVersion::query()->where('id', $policyVersionId)->value('policy_id');
            $checkId = $this->ensureComplianceCheck($policyId, $policyVersionId);

            $complianceStatus = $status === 'success' ? 'compliant' : 'non_compliant';
            if (($status === 'non_compliant') || ((string) ($resultPayload['compliance_status'] ?? '') === 'non_compliant')) {
                $complianceStatus = 'non_compliant';
            }

            ComplianceResult::query()->create([
                'id' => (string) Str::uuid(),
                'compliance_check_id' => $checkId,
                'device_id' => $payload['device_id'],
                'status' => $complianceStatus,
                'details' => $resultPayload,
                'checked_at' => now(),
            ]);

            if ($device) {
                $this->handleBaselineProfileResult($device, $job, $resultPayload, $run);
            }
        }
        if ($job && $job->job_type === 'reconcile_software_inventory' && is_array($resultPayload)) {
            if ($device) {
                $tags = is_array($device->tags) ? $device->tags : [];
                $tags['software_inventory'] = $resultPayload['inventory'] ?? $resultPayload;
                $tags['software_inventory_updated_at'] = now()->toIso8601String();
                $device->update(['tags' => $tags]);
            }
        }

        if ($job) {
            $this->syncJobStatus($job->id);
        }

        if ($device) {
            $this->dispatchIntelligenceBuild($device->id);
        }

        $auditLogger->log('job.result', 'job_run', $run->id, null, $run->toArray(), null, $run->device_id);

        $deviceDeleted = false;
        if (
            $job
            && in_array((string) $job->job_type, ['uninstall_agent', 'uninstall_exe'], true)
            && $status === 'success'
            && (bool) ($job->payload['delete_device_after_uninstall'] ?? false)
            && (bool) ($job->payload['agent_uninstall'] ?? ($job->job_type === 'uninstall_agent'))
        ) {
            try {
                $this->purgeDeviceRecord((string) $payload['device_id']);
                $deviceDeleted = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'ok' => true,
            'retry_scheduled' => $retryable,
            'next_retry_at' => $run->next_retry_at?->toIso8601String(),
            'device_deleted' => $deviceDeleted,
            'fallback_job_id' => $fallbackJobId,
        ]);
    }

    public function complianceReport(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['required', 'uuid'],
            'results' => ['required', 'array'],
            'results.*.policy_version_id' => ['nullable', 'uuid'],
            'results.*.status' => ['required', 'in:compliant,non_compliant,error'],
            'results.*.details' => ['nullable', 'array'],
            'results.*.checked_at' => ['nullable', 'date'],
        ]);

        Device::query()->findOrFail($payload['device_id']);

        foreach ($payload['results'] as $result) {
            $policyVersionId = (string) ($result['policy_version_id'] ?? '');
            $policyId = $policyVersionId !== ''
                ? PolicyVersion::query()->where('id', $policyVersionId)->value('policy_id')
                : null;
            $checkId = $this->ensureComplianceCheck($policyId, $policyVersionId !== '' ? $policyVersionId : null);

            ComplianceResult::query()->create([
                'id' => (string) Str::uuid(),
                'compliance_check_id' => $checkId,
                'device_id' => $payload['device_id'],
                'status' => $result['status'],
                'details' => $result['details'] ?? null,
                'checked_at' => isset($result['checked_at']) ? $result['checked_at'] : now(),
            ]);
        }

        $this->dispatchIntelligenceBuild($payload['device_id']);

        return response()->json(['ok' => true]);
    }

    private function syncRemediationActionOutcome(JobRun $run, string $status, ?int $exitCode, ?array $resultPayload, array $payload): void
    {
        $actionResult = RemediationActionResult::query()
            ->where(function ($query) use ($run) {
                $query->where('job_run_id', $run->id)->orWhere('job_id', $run->job_id);
            })
            ->latest('created_at')
            ->first();

        if (! $actionResult) {
            return;
        }

        $evidence = is_array($actionResult->evidence) ? $actionResult->evidence : [];
        $evidence = array_merge($evidence, [
            'result_payload' => $resultPayload,
            'execution_duration_ms' => $payload['execution_duration_ms'] ?? null,
            'stdout_sha256' => $payload['stdout_sha256'] ?? null,
            'stderr_sha256' => $payload['stderr_sha256'] ?? null,
            'rollback_hint' => $payload['rollback_hint'] ?? null,
            'artifacts' => $payload['artifacts'] ?? null,
            'action_token' => $payload['action_token'] ?? null,
            'idempotency_key' => $payload['idempotency_key'] ?? null,
            'checkin_id' => $payload['checkin_id'] ?? null,
        ]);

        $actionResult->update([
            'job_run_id' => $run->id,
            'status' => $status,
            'exit_code' => $exitCode,
            'evidence' => array_filter($evidence, fn ($value) => $value !== null),
            'error_text' => is_array($resultPayload) ? ($resultPayload['error'] ?? null) : null,
            'finished_at' => now(),
        ]);

        $action = $actionResult->action;
        if ($action) {
            $action->update([
                'status' => $status === 'success' ? 'completed' : ($status === 'running' ? 'running' : 'failed'),
            ]);
        }

        $rollbackHint = is_array($payload['rollback_hint'] ?? null) ? $payload['rollback_hint'] : [];
        if (($status === 'failed' || $status === 'non_compliant') && ($rollbackHint['possible'] ?? false)) {
            ActionRollback::query()->firstOrCreate(
                [
                    'tenant_id' => $actionResult->tenant_id,
                    'action_result_id' => $actionResult->id,
                    'status' => 'available',
                ],
                [
                    'id' => (string) Str::uuid(),
                    'rollback_action_type' => (string) ($rollbackHint['job_type'] ?? 'manual_review'),
                    'rollback_args' => is_array($rollbackHint) ? $rollbackHint : [],
                    'result' => [],
                    'started_at' => now(),
                ]
            );
        }
    }

    private function ensureComplianceCheck(?string $policyId, ?string $policyVersionId): string
    {
        $name = 'Policy compliance';
        $standaloneMode = (bool) config('dms.standalone_mode', true);
        $tenantId = null;
        if ($policyId) {
            $policyRecord = Policy::query()
                ->where('id', $policyId)
                ->first(['name', 'tenant_id']);
            if ($policyRecord) {
                $name = $policyRecord->name.' compliance';
                $tenantId = $standaloneMode ? null : $policyRecord->tenant_id;
            }
        }

        $key = 'policy:'.($policyVersionId ?: $policyId ?: 'unscoped');
        $existingChecks = DB::table('compliance_checks')->where('check_type', 'policy');
        if ($tenantId === null) {
            $existingChecks->whereNull('tenant_id');
        } else {
            $existingChecks->where('tenant_id', $tenantId);
        }
        $existingChecks = $existingChecks
            ->get(['id', 'definition']);
        foreach ($existingChecks as $check) {
            $definition = json_decode((string) $check->definition, true);
            if (($definition['key'] ?? null) === $key) {
                return (string) $check->id;
            }
        }

        $id = (string) Str::uuid();
        DB::table('compliance_checks')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => $name,
            'check_type' => 'policy',
            'definition' => json_encode([
                'key' => $key,
                'policy_id' => $policyId,
                'policy_version_id' => $policyVersionId,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function normalizeApplyPolicyUwfCompatibilityResult(string $status, array $resultPayload, ?int $exitCode): array
    {
        if (! in_array($status, ['non_compliant', 'failed'], true)) {
            return [$status, $resultPayload, $exitCode];
        }

        $rules = $resultPayload['rules'] ?? null;
        if (! is_array($rules) || $rules === []) {
            return [$status, $resultPayload, $exitCode];
        }

        $allUwfUnsupported = true;
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                $allUwfUnsupported = false;
                break;
            }

            $ruleType = strtolower((string) ($rule['type'] ?? ''));
            if ($ruleType !== 'uwf') {
                $allUwfUnsupported = false;
                break;
            }

            $message = strtolower((string) ($rule['message'] ?? ''));
            $unsupported = str_contains($message, 'requires enterprise/education/iot enterprise')
                || str_contains($message, 'uwf not supported on this windows edition')
                || (str_contains($message, 'edition=windows') && str_contains($message, 'pro'));
            if (! $unsupported) {
                $allUwfUnsupported = false;
                break;
            }
        }

        if (! $allUwfUnsupported) {
            $allUwfRebootProgress = true;
            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    $allUwfRebootProgress = false;
                    break;
                }

                $ruleType = strtolower((string) ($rule['type'] ?? ''));
                if ($ruleType !== 'uwf') {
                    $allUwfRebootProgress = false;
                    break;
                }

                $message = strtolower((string) ($rule['message'] ?? ''));
                $isRebootProgress = str_contains($message, 'reboot queued')
                    || str_contains($message, 'pending reboot')
                    || str_contains($message, 'reboot cooldown active');
                if (! $isRebootProgress) {
                    $allUwfRebootProgress = false;
                    break;
                }
            }

            if (! $allUwfRebootProgress) {
                return [$status, $resultPayload, $exitCode];
            }

            $normalizedRules = array_map(function ($rule) {
                if (! is_array($rule)) {
                    return $rule;
                }
                $message = trim((string) ($rule['message'] ?? ''));
                $rule['compliant'] = true;
                $rule['message'] = $message !== '' ? "accepted (uwf reboot in progress): {$message}" : 'accepted (uwf reboot in progress)';
                return $rule;
            }, $rules);

            $resultPayload['rules'] = $normalizedRules;
            $resultPayload['compliance_status'] = 'compliant';
            $resultPayload['uwf_reboot_in_progress'] = true;
            unset($resultPayload['error']);

            return ['success', $resultPayload, 0];
        }

        $normalizedRules = array_map(function ($rule) {
            if (! is_array($rule)) {
                return $rule;
            }
            $message = (string) ($rule['message'] ?? '');
            $rule['compliant'] = true;
            $rule['message'] = $message !== '' ? "skipped (unsupported platform): {$message}" : 'skipped (unsupported platform)';
            return $rule;
        }, $rules);

        $resultPayload['rules'] = $normalizedRules;
        $resultPayload['compliance_status'] = 'compliant';
        $resultPayload['uwf_skipped_unsupported_platform'] = true;
        unset($resultPayload['error']);

        return ['success', $resultPayload, 0];
    }

    private function isPolicyTransportVerificationFailure(array $resultPayload): bool
    {
        $error = strtoupper(trim((string) ($resultPayload['error'] ?? '')));
        if ($error === '') {
            return false;
        }

        return in_array($error, [
            'E_SIG_INVALID',
            'E_SIG_UNKNOWN_KID',
            'E_PAYLOAD_HASH',
            'E_EXPIRED',
            'E_REPLAY',
        ], true);
    }

    private function handleBaselineProfileResult(Device $device, DmsJob $job, array $resultPayload, JobRun $run): void
    {
        $payloadRules = collect((array) ($job->payload['rules'] ?? []))
            ->filter(fn ($rule) => is_array($rule) && strtolower((string) ($rule['type'] ?? '')) === 'baseline_profile')
            ->values();
        if ($payloadRules->isEmpty()) {
            return;
        }

        $resultRules = collect((array) ($resultPayload['rules'] ?? []))
            ->filter(fn ($rule) => is_array($rule) && strtolower((string) ($rule['type'] ?? '')) === 'baseline_profile')
            ->values();
        if ($resultRules->isEmpty()) {
            return;
        }

        $policyVersionId = (string) ($job->payload['policy_version_id'] ?? '');
        $baselineRuns = [];
        $baselineOrdinal = 0;
        foreach ($resultRules as $resultRule) {
            $payloadRule = $payloadRules->get($baselineOrdinal);
            $baselineOrdinal++;
            if (! is_array($payloadRule)) {
                continue;
            }

            $config = is_array($payloadRule['config'] ?? null) ? $payloadRule['config'] : [];
            $report = is_array($resultRule['baseline_report'] ?? null) ? $resultRule['baseline_report'] : [];
            $drifts = $this->evaluateBaselineDrifts($config, $report);
            $remediationRules = collect((array) ($config['remediation_rules'] ?? []))
                ->filter(fn ($rule) => is_array($rule) && is_array($rule['config'] ?? null) && trim((string) ($rule['type'] ?? '')) !== '')
                ->map(fn ($rule) => [
                    'type' => strtolower(trim((string) $rule['type'])),
                    'config' => (array) ($rule['config'] ?? []),
                    'enforce' => (bool) ($rule['enforce'] ?? true),
                ])
                ->values()
                ->all();

            $queuedRemediationJobId = null;
            if ($drifts !== [] && $remediationRules !== [] && $this->settingBool('policies.baseline_auto_remediate', true)) {
                $queuedRemediationJobId = $this->queueBaselineRemediationJob(
                    $device->id,
                    $policyVersionId,
                    $job,
                    $run,
                    $remediationRules
                );
            }

            $baselineRuns[] = [
                'policy_version_id' => $policyVersionId,
                'source_job_id' => (string) $job->id,
                'source_run_id' => (string) $run->id,
                'drift_count' => count($drifts),
                'drifts' => $drifts,
                'queued_remediation_job_id' => $queuedRemediationJobId,
                'collected_at' => (string) ($report['collected_at'] ?? now()->toIso8601String()),
                'updated_at' => now()->toIso8601String(),
            ];
        }

        if ($baselineRuns === []) {
            return;
        }

        $tags = is_array($device->tags) ? $device->tags : [];
        $history = collect((array) ($tags['baseline_drift_reports'] ?? []))
            ->filter(fn ($row) => is_array($row))
            ->values();
        foreach ($baselineRuns as $row) {
            $history->prepend($row);
        }
        $tags['baseline_drift_reports'] = $history->take(50)->values()->all();
        $tags['baseline_drift_updated_at'] = now()->toIso8601String();
        $device->update(['tags' => $tags]);
    }

    private function evaluateBaselineDrifts(array $config, array $report): array
    {
        $drifts = [];
        $observed = is_array($report['observed'] ?? null) ? $report['observed'] : [];

        $observedFiles = collect((array) ($observed['critical_files'] ?? []))
            ->filter(fn ($row) => is_array($row))
            ->keyBy(fn ($row) => strtolower(trim((string) ($row['path'] ?? ''))));
        foreach ((array) ($config['critical_files'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $path = trim((string) ($item['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $key = strtolower($path);
            $expectedExists = array_key_exists('exists', $item) ? (bool) $item['exists'] : true;
            $expectedSha = strtolower(trim((string) ($item['sha256'] ?? '')));
            $actual = (array) ($observedFiles->get($key) ?? []);
            $actualExists = (bool) ($actual['exists'] ?? false);
            $actualSha = strtolower(trim((string) ($actual['sha256'] ?? '')));

            if ($expectedExists !== $actualExists) {
                $drifts[] = [
                    'kind' => 'file_exists',
                    'path' => $path,
                    'expected' => $expectedExists,
                    'actual' => $actualExists,
                ];
                continue;
            }
            if ($expectedExists && $expectedSha !== '' && $actualSha !== '' && $expectedSha !== $actualSha) {
                $drifts[] = [
                    'kind' => 'file_hash',
                    'path' => $path,
                    'expected' => $expectedSha,
                    'actual' => $actualSha,
                ];
            }
        }

        $observedRegistry = collect((array) ($observed['registry_values'] ?? []))
            ->filter(fn ($row) => is_array($row))
            ->keyBy(function ($row) {
                $path = strtolower(trim((string) ($row['path'] ?? '')));
                $name = strtolower(trim((string) ($row['name'] ?? '')));
                return $path.'|'.$name;
            });
        foreach ((array) ($config['registry_values'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $path = trim((string) ($item['path'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if ($path === '' || $name === '') {
                continue;
            }
            $ensure = strtolower(trim((string) ($item['ensure'] ?? 'present')));
            $expectedValue = array_key_exists('value', $item) ? (string) $item['value'] : null;
            $actual = (array) ($observedRegistry->get(strtolower($path).'|'.strtolower($name)) ?? []);
            $actualExists = (bool) ($actual['exists'] ?? false);
            $actualValue = (string) ($actual['value'] ?? '');

            if ($ensure === 'absent' && $actualExists) {
                $drifts[] = ['kind' => 'registry_absent', 'path' => $path, 'name' => $name, 'expected' => 'absent', 'actual' => 'present'];
                continue;
            }
            if ($ensure !== 'absent' && ! $actualExists) {
                $drifts[] = ['kind' => 'registry_exists', 'path' => $path, 'name' => $name, 'expected' => 'present', 'actual' => 'missing'];
                continue;
            }
            if ($ensure !== 'absent' && $expectedValue !== null && strcasecmp($expectedValue, $actualValue) !== 0) {
                $drifts[] = ['kind' => 'registry_value', 'path' => $path, 'name' => $name, 'expected' => $expectedValue, 'actual' => $actualValue];
            }
        }

        $observedServices = collect((array) ($observed['services'] ?? []))
            ->filter(fn ($row) => is_array($row))
            ->keyBy(fn ($row) => strtolower(trim((string) ($row['name'] ?? ''))));
        foreach ((array) ($config['services'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $ensure = strtolower(trim((string) ($item['ensure'] ?? 'present')));
            $expectedStatus = strtolower(trim((string) ($item['status'] ?? '')));
            $expectedStartMode = strtolower(trim((string) ($item['start_mode'] ?? '')));
            $actual = (array) ($observedServices->get(strtolower($name)) ?? []);
            $actualExists = (bool) ($actual['exists'] ?? false);
            $actualStatus = strtolower(trim((string) ($actual['status'] ?? '')));
            $actualStartMode = strtolower(trim((string) ($actual['start_mode'] ?? '')));

            if ($ensure === 'absent' && $actualExists) {
                $drifts[] = ['kind' => 'service_absent', 'name' => $name, 'expected' => 'absent', 'actual' => 'present'];
                continue;
            }
            if ($ensure !== 'absent' && ! $actualExists) {
                $drifts[] = ['kind' => 'service_exists', 'name' => $name, 'expected' => 'present', 'actual' => 'missing'];
                continue;
            }
            if ($ensure !== 'absent' && $expectedStatus !== '' && $expectedStatus !== $actualStatus) {
                $drifts[] = ['kind' => 'service_status', 'name' => $name, 'expected' => $expectedStatus, 'actual' => $actualStatus];
            }
            if ($ensure !== 'absent' && $expectedStartMode !== '' && $expectedStartMode !== $actualStartMode) {
                $drifts[] = ['kind' => 'service_start_mode', 'name' => $name, 'expected' => $expectedStartMode, 'actual' => $actualStartMode];
            }
        }

        $observedPackages = collect((array) ($observed['installed_packages'] ?? []))
            ->filter(fn ($row) => is_array($row));
        $observedPackageMap = $observedPackages
            ->mapWithKeys(function ($row) {
                $name = strtolower(trim((string) ($row['name'] ?? '')));
                $match = strtolower(trim((string) ($row['match'] ?? 'contains')));
                return [$name.'|'.$match => $row];
            });
        foreach ((array) ($config['installed_packages'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $ensure = strtolower(trim((string) ($item['ensure'] ?? 'present')));
            $match = strtolower(trim((string) ($item['match'] ?? 'contains')));
            $key = strtolower($name).'|'.$match;
            $actualRow = (array) ($observedPackageMap->get($key) ?? []);
            if ($actualRow === []) {
                $fallback = $observedPackages->first(function ($row) use ($name) {
                    return strtolower(trim((string) ($row['name'] ?? ''))) === strtolower($name);
                });
                $actualRow = is_array($fallback) ? $fallback : [];
            }
            $present = (bool) ($actualRow['present'] ?? false);
            if ($ensure === 'absent' && $present) {
                $drifts[] = ['kind' => 'package_absent', 'name' => $name, 'match' => $match, 'expected' => 'absent', 'actual' => 'present'];
            }
            if ($ensure !== 'absent' && ! $present) {
                $drifts[] = ['kind' => 'package_present', 'name' => $name, 'match' => $match, 'expected' => 'present', 'actual' => 'missing'];
            }
        }

        return $drifts;
    }

    private function queueBaselineRemediationJob(string $deviceId, string $policyVersionId, DmsJob $sourceJob, JobRun $sourceRun, array $remediationRules): ?string
    {
        if ($remediationRules === []) {
            return null;
        }

        $exists = DmsJob::query()
            ->where('target_type', 'device')
            ->where('target_id', $deviceId)
            ->where('job_type', 'apply_policy')
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '>=', now()->subMinutes(10))
            ->get(['payload'])
            ->contains(function (DmsJob $job) use ($sourceJob) {
                $payload = is_array($job->payload) ? $job->payload : [];
                return (bool) ($payload['baseline_remediation'] ?? false)
                    && (string) ($payload['baseline_source_job_id'] ?? '') === (string) $sourceJob->id;
            });
        if ($exists) {
            return null;
        }

        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'apply_policy',
            'status' => 'queued',
            'priority' => 90,
            'payload' => [
                'policy_version_id' => $policyVersionId,
                'baseline_remediation' => true,
                'baseline_source_job_id' => (string) $sourceJob->id,
                'baseline_source_run_id' => (string) $sourceRun->id,
                'rules' => $remediationRules,
            ],
            'target_type' => 'device',
            'target_id' => $deviceId,
            'created_by' => null,
        ]);

        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $job->id,
            'device_id' => $deviceId,
            'status' => 'pending',
            'next_retry_at' => null,
        ]);

        return (string) $job->id;
    }

    private function settingInt(string $key, int $default): int
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        return (int) ($setting->value['value'] ?? $default);
    }

    private function settingBool(string $key, bool $default): bool
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        return (bool) ($setting->value['value'] ?? $default);
    }

    private function syncJobStatus(string $jobId): void
    {
        $runs = JobRun::query()->where('job_id', $jobId)->pluck('status');
        if ($runs->isEmpty()) {
            return;
        }

        if ($runs->contains(fn ($s) => in_array($s, ['pending', 'acked', 'running'], true))) {
            DmsJob::query()->where('id', $jobId)->update(['status' => 'queued']);
            return;
        }

        if ($runs->contains('failed') || $runs->contains('non_compliant')) {
            DmsJob::query()->where('id', $jobId)->update(['status' => 'failed']);
            return;
        }

        if ($runs->every(fn ($s) => $s === 'success')) {
            DmsJob::query()->where('id', $jobId)->update(['status' => 'success']);
            return;
        }

        DmsJob::query()->where('id', $jobId)->update(['status' => 'running']);
    }

    private function queueLegacyAgentUninstallFallback(string $deviceId, DmsJob $sourceJob): ?string
    {
        // Avoid duplicate fallback jobs for the same unsupported uninstall_agent command.
        $existing = DmsJob::query()
            ->where('target_type', 'device')
            ->where('target_id', $deviceId)
            ->where('job_type', 'uninstall_exe')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'payload'])
            ->first(function (DmsJob $job) use ($sourceJob) {
                $payload = is_array($job->payload) ? $job->payload : [];
                return (string) ($payload['fallback_from_uninstall_agent_job_id'] ?? '') === (string) $sourceJob->id;
            });
        if ($existing) {
            return (string) $existing->id;
        }

        $serviceName = (string) ($sourceJob->payload['service_name'] ?? 'DMSAgent');
        $installDir = (string) ($sourceJob->payload['install_dir'] ?? 'C:\\Program Files\\DMS Agent');
        $dataDir = (string) ($sourceJob->payload['data_dir'] ?? 'C:\\ProgramData\\DMS');
        $deleteDeviceAfter = (bool) ($sourceJob->payload['delete_device_after_uninstall'] ?? false);

        $serviceEscaped = str_replace('"', '""', $serviceName);
        $installEscaped = str_replace('"', '""', $installDir);
        $dataEscaped = str_replace('"', '""', $dataDir);

        $legacyCmd = 'start "" cmd /c "timeout /t 8 /nobreak >nul'
            .' & sc stop "'.$serviceEscaped.'"'
            .' & timeout /t 3 /nobreak >nul'
            .' & sc delete "'.$serviceEscaped.'"'
            .' & rmdir /s /q "'.$installEscaped.'"'
            .' & rmdir /s /q "'.$dataEscaped.'"'
            .'"';

        $jobId = (string) Str::uuid();
        DmsJob::query()->create([
            'id' => $jobId,
            'job_type' => 'uninstall_exe',
            'status' => 'queued',
            'priority' => (int) ($sourceJob->priority ?? 80),
            'payload' => [
                'command' => $legacyCmd,
                'agent_uninstall' => true,
                'delete_device_after_uninstall' => $deleteDeviceAfter,
                'admin_confirmed' => (bool) ($sourceJob->payload['admin_confirmed'] ?? false),
                'admin_confirmed_at' => (string) ($sourceJob->payload['admin_confirmed_at'] ?? ''),
                'admin_confirmed_by_user_id' => $sourceJob->payload['admin_confirmed_by_user_id'] ?? null,
                'admin_confirmation_ttl_minutes' => (int) ($sourceJob->payload['admin_confirmation_ttl_minutes'] ?? 30),
                'admin_confirmation_nonce' => (string) ($sourceJob->payload['admin_confirmation_nonce'] ?? ''),
                'fallback_from_uninstall_agent_job_id' => (string) $sourceJob->id,
            ],
            'target_type' => 'device',
            'target_id' => $deviceId,
            'created_by' => $sourceJob->created_by,
        ]);

        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $jobId,
            'device_id' => $deviceId,
            'status' => 'pending',
            'next_retry_at' => null,
        ]);

        return $jobId;
    }

    private function purgeDeviceRecord(string $deviceId): void
    {
        $device = Device::query()->find($deviceId);
        if (! $device) {
            return;
        }

        DB::transaction(function () use ($device) {
            DB::table('device_group_memberships')->where('device_id', $device->id)->delete();
            DB::table('device_identities')->where('device_id', $device->id)->delete();
            DB::table('policy_assignments')->where('target_type', 'device')->where('target_id', $device->id)->delete();
            ComplianceResult::query()->where('device_id', $device->id)->delete();
            JobRun::query()->where('device_id', $device->id)->delete();
            $device->delete();
        });
    }

    private function resolveSignatureModeForRun(Device $device, JobRun $run): ?string
    {
        $modes = $this->signatureCompatModes();
        if ($modes->isEmpty()) {
            $modes = collect(['wire_digest', 'digest', 'canonical', 'wire']);
        }

        // Always start with wire_digest to avoid first-attempt signature mismatch retries.
        // Keep fallback order deterministic for subsequent retries.
        $priority = ['wire_digest', 'digest', 'canonical', 'wire'];
        $modes = $modes
            ->filter(fn ($m) => in_array($m, $priority, true))
            ->sortBy(fn ($m) => array_search($m, $priority, true))
            ->values();
        if ($modes->isEmpty()) {
            $modes = collect($priority);
        }

        // Keep one stable signature mode for all retries of the same run.
        // Rotating modes by attempt can produce false E_SIG_INVALID failures.
        return (string) $modes->first();
    }

    private function signatureCompatModeCount(): int
    {
        $modes = $this->signatureCompatModes();

        return max(1, $modes->count());
    }

    private function signatureCompatModes(): \Illuminate\Support\Collection
    {
        $configured = collect(explode(',', (string) env('DMS_SIGNATURE_COMPAT_MODES', 'wire_digest,digest,canonical')))
            ->map(fn ($m) => strtolower(trim((string) $m)))
            ->filter(fn ($m) => in_array($m, ['digest', 'canonical', 'wire_digest', 'wire'], true))
            ->values();

        // Permanent safety: always keep all compatible modes available,
        // even if env value is incomplete.
        return $configured
            ->merge(['digest', 'canonical', 'wire_digest', 'wire'])
            ->unique()
            ->values();
    }

    private function payloadHashForAgent(mixed $payload): string
    {
        // Canonical payload hash: stable key order, compatible with agent candidate hash checks.
        return hash('sha256', $this->canonicalJson($payload));
    }

    private function canonicalJson(mixed $value): string
    {
        $jsonFlags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | $jsonFlags);
        }

        if (is_string($value)) {
            return $this->encodeJsonString($value);
        }

        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if (! $isAssoc) {
                $items = array_map(fn ($item) => $this->canonicalJson($item), $value);
                return '['.implode(',', $items).']';
            }

            $keys = array_keys($value);
            usort($keys, fn ($a, $b) => strcmp((string) $a, (string) $b));
            $parts = [];
            foreach ($keys as $key) {
                $parts[] = $this->encodeJsonString((string) $key).':'.$this->canonicalJson($value[$key]);
            }
            return '{'.implode(',', $parts).'}';
        }

        if (is_object($value)) {
            return $this->canonicalJson((array) $value);
        }

        return json_encode($value, $jsonFlags);
    }

    private function encodeJsonString(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function resolveSignatureKeyKidForRun(CommandEnvelopeSigner $signer, int $attempt, int $modeCount): ?string
    {
        $keys = collect($signer->keyset())
            ->filter(fn ($row) => is_array($row) && strtolower((string) ($row['status'] ?? '')) === 'active')
            ->pluck('kid')
            ->filter(fn ($kid) => is_string($kid) && trim($kid) !== '')
            ->values();
        if ($keys->isEmpty()) {
            return null;
        }

        $keyIndex = intdiv(max(0, $attempt), max(1, $modeCount)) % $keys->count();

        return (string) $keys[$keyIndex];
    }

    private function recoverDeletedDeviceRecord(array $payload): Device
    {
        $enabled = $this->settingBool('devices.allow_orphan_auto_claim', true);
        if (! $enabled) {
            abort(404);
        }

        $runtime = is_array($payload['runtime_diagnostics'] ?? null) ? $payload['runtime_diagnostics'] : [];
        $inventory = is_array($payload['inventory'] ?? null) ? $payload['inventory'] : null;
        $hostname = trim((string) ($payload['hostname'] ?? ''));
        if ($hostname === '') {
            $hostname = trim((string) ($runtime['machine_name'] ?? ''));
        }
        if ($hostname === '') {
            $hostname = 'Recovered-'.substr((string) $payload['device_id'], 0, 8);
        }

        $osName = trim((string) ($payload['os_name'] ?? ''));
        if ($osName === '') {
            $osName = trim((string) ($runtime['os_name'] ?? ''));
        }
        if ($osName === '') {
            $osName = 'Unknown OS';
        }

        $osVersion = trim((string) ($payload['os_version'] ?? ''));
        if ($osVersion === '') {
            $osVersion = trim((string) ($runtime['os_version'] ?? ''));
        }
        $serial = trim((string) ($payload['serial_number'] ?? ''));
        $agentVersion = trim((string) ($payload['agent_version'] ?? ''));

        $tags = [];
        if (! empty($payload['agent_build'])) {
            $tags['agent_build'] = (string) $payload['agent_build'];
        }
        if (is_array($inventory)) {
            $tags['inventory'] = $inventory;
            $tags['inventory_updated_at'] = now()->toIso8601String();
        }
        if (is_array($runtime)) {
            $tags['runtime_diagnostics'] = $runtime;
            $tags['runtime_diagnostics_updated_at'] = now()->toIso8601String();
        }
        $this->mergeUwfStatusIntoTags($tags, $payload);
        $tags['orphan_auto_claimed'] = true;
        $tags['orphan_auto_claimed_at'] = now()->toIso8601String();

        return Device::query()->create([
            'id' => (string) $payload['device_id'],
            'hostname' => $hostname,
            'os_name' => $osName,
            'os_version' => $osVersion !== '' ? $osVersion : null,
            'serial_number' => $serial !== '' ? $serial : null,
            'agent_version' => $agentVersion !== '' ? $agentVersion : 'unknown',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => $tags,
        ]);
    }

    private function applyDeviceIdentityUpdates(array &$updateData, array $payload): void
    {
        $identityHints = $this->extractDeviceIdentityHints($payload);

        $hostname = trim((string) ($payload['hostname'] ?? ''));
        if ($hostname === '') {
            $hostname = trim((string) ($identityHints['hostname'] ?? ''));
        }
        if ($hostname !== '') {
            $updateData['hostname'] = $hostname;
        }

        $osName = trim((string) ($payload['os_name'] ?? ''));
        if ($osName === '') {
            $osName = trim((string) ($identityHints['windows_edition'] ?? ''));
        }
        if ($osName !== '') {
            $updateData['os_name'] = $osName;
        }

        $osVersion = trim((string) ($payload['os_version'] ?? ''));
        if ($osVersion === '') {
            $osVersion = trim((string) ($identityHints['windows_build_number'] ?? ''));
        }
        if ($osVersion !== '') {
            $updateData['os_version'] = $osVersion;
        }

        $serial = trim((string) ($payload['serial_number'] ?? ''));
        if ($serial === '') {
            $serial = trim((string) ($identityHints['serial_number'] ?? ''));
        }
        if ($serial !== '') {
            $updateData['serial_number'] = $serial;
        }

        if ($identityHints !== []) {
            $tags = is_array($updateData['tags'] ?? null) ? $updateData['tags'] : [];
            $tags['device_identity'] = array_filter($identityHints, function ($value) {
                if (is_bool($value)) {
                    return true;
                }

                return $value !== null && $value !== '';
            });
            $tags['device_identity_updated_at'] = now()->toIso8601String();
            $updateData['tags'] = $tags;
        }
    }

    private function extractDeviceIdentityHints(array $payload): array
    {
        $inventory = is_array($payload['inventory'] ?? null) ? $payload['inventory'] : [];
        $deviceIdentity = is_array($inventory['device_identity'] ?? null) ? $inventory['device_identity'] : [];
        $windowsIdentity = is_array(data_get($inventory, 'windows_telemetry.basic_device_identity')) ? data_get($inventory, 'windows_telemetry.basic_device_identity') : [];

        $resolved = [
            'hostname' => trim((string) ($deviceIdentity['hostname'] ?? $windowsIdentity['hostname'] ?? '')),
            'serial_number' => trim((string) ($deviceIdentity['serial_number'] ?? $windowsIdentity['serial_number'] ?? '')),
            'manufacturer' => trim((string) ($deviceIdentity['manufacturer'] ?? $windowsIdentity['manufacturer'] ?? '')),
            'model' => trim((string) ($deviceIdentity['model'] ?? $windowsIdentity['model'] ?? '')),
            'windows_edition' => trim((string) ($deviceIdentity['windows_edition'] ?? $windowsIdentity['windows_edition'] ?? '')),
            'windows_build_number' => trim((string) ($deviceIdentity['windows_build_number'] ?? $windowsIdentity['windows_build_number'] ?? '')),
            'bios_uefi_version' => trim((string) ($deviceIdentity['bios_uefi_version'] ?? $windowsIdentity['bios_uefi_version'] ?? '')),
            'physical_location' => trim((string) ($deviceIdentity['physical_location'] ?? $windowsIdentity['physical_location'] ?? '')),
            'domain_joined' => (bool) ($deviceIdentity['domain_joined'] ?? $windowsIdentity['domain_joined'] ?? false),
            'azure_ad_joined' => (bool) ($deviceIdentity['azure_ad_joined'] ?? $windowsIdentity['azure_ad_joined'] ?? false),
        ];

        return array_filter($resolved, function ($value) {
            if (is_bool($value)) {
                return true;
            }

            return $value !== null && $value !== '';
        });
    }

    private function mergeUwfStatusIntoTags(array &$tags, array $payload): void
    {
        $runtime = is_array($payload['runtime_diagnostics'] ?? null) ? $payload['runtime_diagnostics'] : null;
        $uwfStatus = is_array($payload['uwf_status'] ?? null) ? $payload['uwf_status'] : null;
        if (! is_array($uwfStatus)) {
            if (! is_array($runtime)) {
                return;
            }
            $uwfStatus = [
                'feature_enabled' => $runtime['uwf_feature_enabled'] ?? null,
                'filter_enabled' => $runtime['uwf_filter_enabled'] ?? null,
                'filter_next_enabled' => $runtime['uwf_filter_next_enabled'] ?? null,
                'volume_c_protected' => $runtime['uwf_volume_c_protected'] ?? null,
                'volume_c_next_protected' => $runtime['uwf_volume_c_next_protected'] ?? null,
                'feature_state' => $runtime['uwf_feature_state'] ?? null,
                'last_check_error' => $runtime['uwf_last_check_error'] ?? null,
                'supported' => $runtime['uwf_supported'] ?? null,
                'tool_available' => $runtime['uwf_tool_available'] ?? null,
            ];
        }

        $tags['uwf_status'] = $uwfStatus;
        $tags['uwf_status_updated_at'] = now()->toIso8601String();
    }

    private function mergeRequestIpIntoRuntimeTags(array &$tags, Request $request): void
    {
        $requestIp = trim((string) $request->ip());
        if ($requestIp === '') {
            return;
        }

        $runtime = is_array($tags['runtime_diagnostics'] ?? null) ? $tags['runtime_diagnostics'] : [];
        $existingIp = trim((string) ($runtime['ip_address'] ?? ''));
        if ($existingIp === '') {
            $runtime['ip_address'] = $requestIp;
        }
        $runtime['request_ip_address'] = $requestIp;
        $network = is_array($runtime['network'] ?? null) ? $runtime['network'] : [];
        $network['request_ip'] = $requestIp;
        $runtime['network'] = $network;

        $tags['runtime_diagnostics'] = $runtime;
        $tags['runtime_diagnostics_updated_at'] = now()->toIso8601String();
    }

    private function shouldBuildIntelligenceImmediately(string $deviceId, array $payload): bool
    {
        $hasSnapshot = DeviceHealthSnapshot::query()->where('device_id', $deviceId)->exists();
        if ($hasSnapshot) {
            return false;
        }

        return is_array($payload['inventory'] ?? null)
            || is_array($payload['runtime_diagnostics'] ?? null)
            || is_array($payload['uwf_status'] ?? null);
    }

    private function dispatchIntelligenceBuild(string $deviceId, bool $immediate = false): void
    {
        $this->dispatchService->dispatch($deviceId, $immediate);
    }

    /**
     * @return array<string,mixed>
     */
    private function bootstrapPayload(Device $device): array
    {
        $checkinInterval = $this->resolveDeviceCheckinIntervalSeconds($device);
        $nonceWindow = max(60, (int) config('services.endpoint_intelligence.nonce_window_seconds', 300));

        return [
            'checkin_interval_seconds' => $checkinInterval,
            'nonce_window_seconds' => $nonceWindow,
            'behavior_ingest_token' => $this->ingestionAuth->ensureBehaviorIngestToken($device),
        ];
    }

    private function resolveDeviceCheckinIntervalSeconds(Device $device): int
    {
        $defaultInterval = max(15, min(300, (int) config('services.endpoint_intelligence.checkin_interval_seconds', 60)));
        $remoteInterval = max(1, min(30, (int) config('services.remote_support.active_checkin_interval_seconds', 1)));
        $activeWindowSeconds = max(5, min(300, (int) config('services.remote_support.active_capture_window_seconds', 45)));
        $remoteSessionActive = RemoteSupportSession::query()
            ->where('device_id', $device->id)
            ->where('status', 'active')
            ->exists();

        $remoteCaptureActive = RemoteSupportSession::query()
            ->where('device_id', $device->id)
            ->where('status', 'active')
            ->where('last_capture_requested_at', '>=', now()->subSeconds($activeWindowSeconds))
            ->exists();

        $remoteJobActive = JobRun::query()
            ->join('jobs', 'jobs.id', '=', 'job_runs.job_id')
            ->where('job_runs.device_id', $device->id)
            ->whereIn('jobs.job_type', ['start_live_stream', 'start_webrtc_session'])
            ->where(function ($query) {
                $query
                    ->whereIn('job_runs.status', ['acked', 'running'])
                    ->orWhere(function ($pending) {
                        $pending
                            ->where('job_runs.status', 'pending')
                            ->whereNull('job_runs.finished_at');
                    });
            })
            ->exists();

        $liveSessionCached = trim((string) Cache::get($this->remoteSupportLiveSessionDeviceCacheKey($device->id), '')) !== '';

        if ($remoteSessionActive || $remoteCaptureActive || $remoteJobActive || $liveSessionCached) {
            return $remoteInterval;
        }

        return $defaultInterval;
    }

    private function remoteSupportLiveFrameCacheKey(string $deviceId, string $sessionId): string
    {
        return 'remote_support:live_frame:'.$deviceId.':'.$sessionId;
    }

    private function remoteSupportLiveSessionDeviceCacheKey(string $deviceId): string
    {
        return 'remote_support:live_session_device:'.$deviceId;
    }

    private function remoteSupportRealtimeStreamCacheKey(string $sessionId, string $stream): string
    {
        return 'remote_support:realtime:'.$stream.':'.$sessionId;
    }

    private function remoteSupportRealtimeSeqCacheKey(string $sessionId, string $stream): string
    {
        return 'remote_support:realtime:seq:'.$stream.':'.$sessionId;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{allowed:bool,reason:string,auth_mode:?string}
     */
    private function authorizeRemoteSupportSessionRequest(Request $request, array $payload): array
    {
        $device = Device::query()->findOrFail((string) $payload['device_id']);
        $auth = $this->ingestionAuth->authorizeBehaviorLogRequest($device, $request, [
            'checkin_id' => (string) ($payload['checkin_id'] ?? ''),
        ]);
        if (! $auth['allowed']) {
            return $auth;
        }

        $session = RemoteSupportSession::query()
            ->where('id', (string) $payload['session_id'])
            ->where('device_id', $device->id)
            ->where('status', 'active')
            ->first();
        if (! $session) {
            return ['allowed' => false, 'reason' => 'remote_support_session_not_active', 'auth_mode' => null];
        }

        return $auth;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function appendRemoteSupportRealtimeEvent(string $sessionId, string $stream, string $type, array $payload, string $source): array
    {
        $seqKey = $this->remoteSupportRealtimeSeqCacheKey($sessionId, $stream);
        $seq = (int) Cache::increment($seqKey);
        if ($seq <= 0) {
            $seq = 1;
        }
        Cache::put($seqKey, $seq, now()->addMinutes(30));

        $key = $this->remoteSupportRealtimeStreamCacheKey($sessionId, $stream);
        $events = Cache::get($key, []);
        if (! is_array($events)) {
            $events = [];
        }

        $event = [
            'seq' => $seq,
            'type' => $type,
            'payload' => $payload,
            'source' => $source,
            'created_at_iso' => now()->toIso8601String(),
        ];
        $events[] = $event;
        if (count($events) > 500) {
            $events = array_slice($events, -500);
        }

        Cache::put($key, $events, now()->addMinutes(30));
        return $event;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readRemoteSupportRealtimeEvents(string $sessionId, string $stream, int $since): array
    {
        $events = Cache::get($this->remoteSupportRealtimeStreamCacheKey($sessionId, $stream), []);
        if (! is_array($events) || $events === []) {
            return [];
        }

        return collect($events)
            ->filter(fn ($event) => is_array($event) && (int) ($event['seq'] ?? 0) > $since)
            ->values()
            ->all();
    }

    private function signatureCompatCandidateCount(CommandEnvelopeSigner $signer): int
    {
        $modeCount = $this->signatureCompatModeCount();
        $keyCount = collect($signer->keyset())->pluck('kid')->filter()->count();

        return max(1, $modeCount * max(1, $keyCount));
    }

    private function computeRetryDelaySeconds(string $deviceId, int $attempt, int $baseBackoffSeconds): int
    {
        $device = Device::query()->find($deviceId);
        $onlineWindowMinutes = max(1, (int) ($this->settingInt('jobs.online_window_minutes', 2)));
        $onlineRetrySeconds = max(3, (int) ($this->settingInt('jobs.online_retry_seconds', 8)));
        $isOnlineNow = $device
            && strtolower((string) ($device->status ?? '')) === 'online'
            && $device->last_seen_at
            && $device->last_seen_at->gt(now()->subMinutes($onlineWindowMinutes));

        if ($isOnlineNow) {
            return $onlineRetrySeconds;
        }

        return min(900, $baseBackoffSeconds * (2 ** max(0, $attempt - 1)));
    }

    private function toDotNetDateTimeOffset(\Carbon\CarbonInterface $time): string
    {
        $utc = $time->copy()->utc();
        $fraction = str_pad((string) $utc->microsecond, 6, '0', STR_PAD_LEFT).'0';

        return $utc->format('Y-m-d\TH:i:s').'.'.$fraction.'+00:00';
    }
}

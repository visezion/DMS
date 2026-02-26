<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ComplianceResult;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\DmsJob;
use App\Models\JobEvent;
use App\Models\JobRun;
use App\Models\Policy;
use App\Models\PolicyRule;
use App\Models\PolicyVersion;
use App\Services\AuditLogger;
use App\Services\CommandEnvelopeSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceCheckinController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['required', 'uuid'],
            'agent_version' => ['required', 'string'],
            'agent_build' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', 'string'],
            'inventory' => ['nullable', 'array'],
            'runtime_diagnostics' => ['nullable', 'array'],
        ]);

        $device = Device::query()->findOrFail($payload['device_id']);
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
        $device->update([
            'agent_version' => $payload['agent_version'],
            'tags' => $tags,
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String()]);
    }

    public function checkin(Request $request, CommandEnvelopeSigner $signer): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['required', 'uuid'],
            'agent_version' => ['nullable', 'string'],
            'agent_build' => ['nullable', 'string', 'max:128'],
            'inventory' => ['nullable', 'array'],
            'runtime_diagnostics' => ['nullable', 'array'],
        ]);

        $device = Device::query()->findOrFail($payload['device_id']);
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
        $updateData = [
            'last_seen_at' => now(),
            'status' => 'online',
            'tags' => $tags,
        ];
        if (! empty($payload['agent_version'])) {
            $updateData['agent_version'] = (string) $payload['agent_version'];
        }
        $device->update($updateData);

        $killSwitch = $this->settingBool('jobs.kill_switch', false);
        if ($killSwitch) {
            return response()->json([
                'server_time' => now()->toIso8601String(),
                'commands' => [],
                'control' => [
                    'jobs_paused' => true,
                ],
            ]);
        }

        $runs = JobRun::query()
            ->where('device_id', $device->id)
            ->whereIn('status', ['pending'])
            ->where(function ($query) {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(10)
            ->get();

        // Keep sequence in 32-bit safe range for older agents while still monotonic.
        $sequenceBase = now()->timestamp;
        $commands = $runs->values()->map(function (JobRun $run, int $index) use ($signer, $sequenceBase, $device) {
            $job = DmsJob::query()->find($run->job_id);
            if (! $job) {
                return null;
            }

            $issuedAt = now()->utc();
            $expiresAt = $issuedAt->copy()->addMinutes(5);

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
            $signatureMode = $this->resolveSignatureModeForRun($device, $run) ?? 'digest';
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
                ],
            ]);

            return $signed;
        })->filter()->values();

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'commands' => $commands,
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
        ]);

        $run = JobRun::query()->where('id', $payload['job_run_id'])->where('device_id', $payload['device_id'])->firstOrFail();
        $job = DmsJob::query()->find($run->job_id);
        $status = $payload['status'];
        $resultPayload = $payload['result_payload'] ?? null;
        $lastError = is_array($resultPayload) ? (string) ($resultPayload['error'] ?? '') : '';
        $attempt = ((int) ($run->attempt_count ?? 0)) + 1;

        $maxRetries = max(0, (int) ($this->settingInt('jobs.max_retries', 3)));
        $baseBackoffSeconds = max(5, (int) ($this->settingInt('jobs.base_backoff_seconds', 30)));
        $retryable = $status === 'failed' && $attempt <= $maxRetries;
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
            $delaySeconds = $this->computeRetryDelaySeconds($payload['device_id'], $attempt, $baseBackoffSeconds);
            $run->update([
                'status' => 'pending',
                'attempt_count' => $attempt,
                'finished_at' => now(),
                'next_retry_at' => now()->addSeconds($delaySeconds),
                'exit_code' => $payload['exit_code'] ?? null,
                'result_payload' => $resultPayload,
                'last_error' => $lastError,
            ]);
        } else {
            $run->update([
                'status' => $status,
                'attempt_count' => $attempt,
                'finished_at' => now(),
                'next_retry_at' => null,
                'exit_code' => $payload['exit_code'] ?? null,
                'result_payload' => $resultPayload,
                'last_error' => $lastError,
            ]);
        }

        JobEvent::query()->create([
            'job_run_id' => $run->id,
            'event_type' => 'completed',
            'event_payload' => [
                'status' => $status,
                'exit_code' => $payload['exit_code'] ?? null,
                'attempt_count' => $attempt,
                'retry_scheduled' => $retryable,
                'next_retry_at' => $run->next_retry_at?->toIso8601String(),
            ],
        ]);

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
        }
        if ($job && $job->job_type === 'reconcile_software_inventory' && is_array($resultPayload)) {
            $device = Device::query()->find($payload['device_id']);
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

        return response()->json(['ok' => true]);
    }

    private function ensureComplianceCheck(?string $policyId, ?string $policyVersionId): string
    {
        $name = 'Policy compliance';
        if ($policyId) {
            $policyName = Policy::query()->where('id', $policyId)->value('name');
            if ($policyName) {
                $name = $policyName.' compliance';
            }
        }

        $key = 'policy:'.($policyVersionId ?: $policyId ?: 'unscoped');
        $existingChecks = DB::table('compliance_checks')
            ->where('check_type', 'policy')
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
            'tenant_id' => null,
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
            $modes = collect(['digest']);
        }

        $agentVersion = strtolower(trim((string) ($device->agent_version ?? '')));
        $looksLegacy = $agentVersion !== '' && version_compare($agentVersion, '1.0.9', '<');
        if ($looksLegacy) {
            // Legacy agents can fail digest-mode verification depending on build/runtime.
            // Prefer wire-compatible modes first for older builds.
            $modes = $modes
                ->filter(fn ($m) => in_array($m, ['wire_digest', 'digest', 'canonical', 'wire'], true))
                ->values();
            if ($modes->isEmpty()) {
                $modes = collect(['wire_digest', 'digest', 'canonical', 'wire']);
            } else {
                $priority = ['wire_digest', 'digest', 'canonical', 'wire'];
                $modes = $modes
                    ->sortBy(fn ($m) => array_search($m, $priority, true))
                    ->values();
            }
        }

        $attempt = max(0, (int) ($run->attempt_count ?? 0));
        return (string) $modes[$attempt % $modes->count()];
    }

    private function signatureCompatModeCount(): int
    {
        $modes = $this->signatureCompatModes();

        return max(1, $modes->count());
    }

    private function signatureCompatModes(): \Illuminate\Support\Collection
    {
        $configured = collect(explode(',', (string) env('DMS_SIGNATURE_COMPAT_MODES', 'digest,canonical')))
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

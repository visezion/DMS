<?php

namespace App\Services\BehaviorPipeline;

use App\Jobs\AppendBehaviorEventsToDatasetJob;
use App\Jobs\ProcessBehaviorEventStreamJob;
use App\Models\AiEventStream;
use App\Models\Device;
use App\Models\DeviceBehaviorLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventIngestionService
{
    /**
     * @param array<int,array<string,mixed>> $events
     * @return array{accepted:int,event_ids:array<int,string>,stream_ids:array<int,string>}
     */
    public function ingest(Device $device, array $events): array
    {
        if ($events === []) {
            return ['accepted' => 0, 'event_ids' => [], 'stream_ids' => []];
        }

        $now = now();
        $behaviorRows = [];
        $streamRows = [];

        foreach ($events as $event) {
            $normalized = $this->normalizeEvent($event);
            $eventId = (string) Str::uuid();
            $streamId = (string) Str::uuid();

            $behaviorRows[] = [
                'id' => $eventId,
                'device_id' => $device->id,
                'event_type' => $normalized['event_type'],
                'occurred_at' => $normalized['occurred_at']->toDateTimeString(),
                'user_name' => $normalized['user_name'],
                'process_name' => $normalized['process_name'],
                'file_path' => $normalized['file_path'],
                'metadata' => json_encode($normalized['metadata'], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $streamRows[] = [
                'id' => $streamId,
                'device_id' => $device->id,
                'behavior_log_id' => $eventId,
                'event_type' => $normalized['event_type'],
                'occurred_at' => $normalized['occurred_at']->toDateTimeString(),
                'payload' => json_encode([
                    'event_type' => $normalized['event_type'],
                    'occurred_at' => $normalized['occurred_at']->toIso8601String(),
                    'user_name' => $normalized['user_name'],
                    'process_name' => $normalized['process_name'],
                    'file_path' => $normalized['file_path'],
                    'metadata' => $normalized['metadata'],
                ], JSON_UNESCAPED_UNICODE),
                'status' => 'queued',
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($behaviorRows, $streamRows): void {
            DeviceBehaviorLog::query()->insert($behaviorRows);
            AiEventStream::query()->insert($streamRows);
        });

        $eventIds = array_values(array_map(fn (array $row) => (string) $row['id'], $behaviorRows));
        $streamIds = array_values(array_map(fn (array $row) => (string) $row['id'], $streamRows));

        AppendBehaviorEventsToDatasetJob::dispatch($eventIds)->onQueue('horizon');
        foreach ($streamIds as $streamId) {
            ProcessBehaviorEventStreamJob::dispatch($streamId)->onQueue('horizon');
        }

        return [
            'accepted' => count($behaviorRows),
            'event_ids' => $eventIds,
            'stream_ids' => $streamIds,
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @return array{event_type:string,occurred_at:Carbon,user_name:?string,process_name:?string,file_path:?string,metadata:array<string,mixed>}
     */
    private function normalizeEvent(array $event): array
    {
        $eventType = trim((string) ($event['event_type'] ?? ''));
        if (! in_array($eventType, ['user_logon', 'app_launch', 'file_access'], true)) {
            throw new \InvalidArgumentException('Event type is invalid.');
        }

        $occurredAtRaw = (string) ($event['occurred_at'] ?? '');
        if ($occurredAtRaw === '') {
            throw new \InvalidArgumentException('Event occurred_at is required.');
        }

        $occurredAt = Carbon::parse($occurredAtRaw);
        $userName = isset($event['user_name']) ? trim((string) $event['user_name']) : null;
        $processName = isset($event['process_name']) ? trim((string) $event['process_name']) : null;
        $filePath = isset($event['file_path']) ? trim((string) $event['file_path']) : null;

        $metadata = $event['metadata'] ?? [];
        if (! is_array($metadata)) {
            throw new \InvalidArgumentException('Event metadata must be an object.');
        }
        $metadata = $this->tagManagedTelemetryMetadata($metadata, $userName, $processName, $filePath);

        return [
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'user_name' => $userName,
            'process_name' => $processName,
            'file_path' => $filePath,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function tagManagedTelemetryMetadata(array $metadata, ?string $userName, ?string $processName, ?string $filePath): array
    {
        $source = mb_strtolower(trim((string) ($metadata['source'] ?? '')));
        $actor = mb_strtolower(trim((string) ($metadata['actor'] ?? '')));
        $proc = mb_strtolower(trim((string) ($processName ?? '')));
        $path = mb_strtolower(trim((string) ($filePath ?? '')));
        $user = trim((string) ($userName ?? ''));

        $trustedActorHints = ['agent', 'dms-agent', 'dmsagent', 'trusted_agent', 'telemetry'];
        $automationProcesses = ['powershell.exe', 'cmd.exe', 'sc.exe', 'quser.exe', 'query.exe'];

        $tags = [];
        $metadataTags = $metadata['tags'] ?? [];
        if (is_array($metadataTags)) {
            foreach ($metadataTags as $tag) {
                if (is_scalar($tag)) {
                    $value = trim((string) $tag);
                    if ($value !== '') {
                        $tags[] = $value;
                    }
                }
            }
        }

        $isTrustedActor = false;
        foreach ($trustedActorHints as $hint) {
            if (($source !== '' && str_contains($source, $hint)) || ($actor !== '' && str_contains($actor, $hint))) {
                $isTrustedActor = true;
                break;
            }
        }

        if ($isTrustedActor) {
            $tags[] = 'trusted_agent_activity';
            $tags[] = 'managed_device_telemetry';
        }

        if ($user !== '' && str_ends_with($user, '$')) {
            $tags[] = 'machine_account';
        }

        foreach ($automationProcesses as $candidate) {
            if (str_contains($proc, $candidate) || ($path !== '' && str_contains($path, $candidate))) {
                $tags[] = 'expected_admin_automation';
                break;
            }
        }

        $normalized = [];
        foreach ($tags as $tag) {
            $value = trim((string) $tag);
            if ($value === '') {
                continue;
            }
            $normalized[] = $value;
        }
        $normalized = array_values(array_unique($normalized));

        if ($normalized !== []) {
            $metadata['tags'] = $normalized;
        }

        if ($isTrustedActor && ! isset($metadata['actor'])) {
            $metadata['actor'] = 'trusted_agent';
        }

        return $metadata;
    }
}

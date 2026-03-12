<?php

namespace App\Services\BehaviorPipeline;

use App\Models\DeviceBehaviorLog;

class BehaviorFeatureBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(DeviceBehaviorLog $event): array
    {
        $occurredAt = $event->occurred_at ?? now();
        $eventType = trim((string) ($event->event_type ?? 'unknown'));
        $filePath = trim((string) ($event->file_path ?? ''));
        $userRaw = trim((string) ($event->user_name ?? ''));
        $processRaw = trim((string) ($event->process_name ?? ''));
        $extension = mb_strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'none';
        }

        $metadata = $event->metadata;
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $appName = $this->deriveAppName($eventType, $processRaw, $filePath, $metadata);
        $parentProcessRaw = $this->firstMetadataString($metadata, ['parent_process', 'parent_process_name', 'parent_image', 'parent_process_path']);
        $commandLineRaw = $this->firstMetadataString($metadata, ['command_line', 'cmdline', 'command']);
        $signerRaw = $this->firstMetadataString($metadata, ['signer', 'publisher', 'signature.publisher', 'file_signer']);
        $actorRaw = $this->firstMetadataString($metadata, ['actor', 'source_actor']);
        $metadataTags = $this->metadataTags($metadata);
        $commandSequence = $this->metadataCommandSequence($metadata);

        return [
            'event_type' => $eventType,
            'hour' => (int) $occurredAt->hour,
            'day_of_week' => (int) $occurredAt->dayOfWeek,
            'user_name' => $userRaw !== '' ? mb_strtolower($userRaw) : 'unknown',
            'user_name_raw' => $userRaw,
            'process_name' => $processRaw !== '' ? mb_strtolower($processRaw) : 'unknown',
            'process_name_raw' => $processRaw,
            'file_path' => $filePath,
            'file_extension' => $extension,
            'app_name' => $appName,
            'device_id' => (string) $event->device_id,
            'parent_process' => $parentProcessRaw !== '' ? mb_strtolower($parentProcessRaw) : null,
            'parent_process_raw' => $parentProcessRaw !== '' ? $parentProcessRaw : null,
            'command_line' => $commandLineRaw !== '' ? $commandLineRaw : null,
            'signer' => $signerRaw !== '' ? mb_strtolower($signerRaw) : null,
            'signer_raw' => $signerRaw !== '' ? $signerRaw : null,
            'actor' => $actorRaw !== '' ? mb_strtolower($actorRaw) : null,
            'actor_raw' => $actorRaw !== '' ? $actorRaw : null,
            'tags' => $metadataTags,
            'command_sequence' => $commandSequence,
            'is_machine_account' => $userRaw !== '' && str_ends_with($userRaw, '$'),
            'metadata' => $metadata,
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function deriveAppName(string $eventType, string $processRaw, string $filePath, array $metadata): ?string
    {
        if (mb_strtolower($eventType) !== 'app_launch') {
            return null;
        }

        $candidates = [
            trim((string) ($metadata['app_name'] ?? '')),
            trim((string) ($metadata['application'] ?? '')),
            trim((string) ($metadata['process_name'] ?? '')),
            $processRaw,
            $filePath,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $normalized = $this->basenameWithExtension($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function basenameWithExtension(string $value): ?string
    {
        $clean = trim($value, " \t\n\r\0\x0B\"'");
        if ($clean === '') {
            return null;
        }

        $base = basename(str_replace('\\', '/', $clean));
        $base = trim($base, " \t\n\r\0\x0B\"'");
        return $base !== '' ? $base : null;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function firstMetadataString(array $metadata, array $paths): string
    {
        foreach ($paths as $path) {
            $node = $metadata;
            $ok = true;
            foreach (explode('.', $path) as $segment) {
                if (is_array($node) && array_key_exists($segment, $node)) {
                    $node = $node[$segment];
                } else {
                    $ok = false;
                    break;
                }
            }
            if (! $ok || ! is_scalar($node)) {
                continue;
            }
            $value = trim((string) $node);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<int,string>
     */
    private function metadataTags(array $metadata): array
    {
        $tags = $metadata['tags'] ?? [];
        if (! is_array($tags)) {
            return [];
        }

        $normalized = [];
        foreach ($tags as $tag) {
            if (! is_scalar($tag)) {
                continue;
            }
            $value = mb_strtolower(trim((string) $tag));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<int,string>
     */
    private function metadataCommandSequence(array $metadata): array
    {
        $sequence = $metadata['command_sequence'] ?? ($metadata['process_chain'] ?? []);
        if (! is_array($sequence)) {
            return [];
        }

        $normalized = [];
        foreach ($sequence as $step) {
            if (! is_scalar($step)) {
                continue;
            }
            $value = mb_strtolower(trim((string) $step));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}

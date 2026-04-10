<?php

namespace App\Domain\Assistant;

use InvalidArgumentException;

class AssistantSchemaValidator
{
    public function validate(array $payload): array
    {
        $payload = $this->normalizeRootPayload($payload);

        $required = [
            'reasoning_summary',
            'known_facts',
            'inferences',
            'confidence_score',
            'risk_level',
            'recommended_actions',
            'requires_human_review',
            'citations',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new InvalidArgumentException('Assistant response is missing required key: '.$key);
            }
        }

        $payload['known_facts'] = $this->normalizeList($payload['known_facts']);
        $payload['inferences'] = $this->normalizeList($payload['inferences']);
        $payload['recommended_actions'] = $this->normalizeList($payload['recommended_actions']);
        $payload['citations'] = $this->normalizeStringList($payload['citations']);

        if (! is_array($payload['known_facts']) || ! is_array($payload['inferences']) || ! is_array($payload['recommended_actions']) || ! is_array($payload['citations'])) {
            throw new InvalidArgumentException('Assistant response contains invalid collection fields.');
        }

        $payload['known_facts'] = array_map(function (mixed $fact): array {
            $fact = is_array($fact) ? $fact : [];

            return [
                'statement' => (string) ($fact['statement'] ?? ''),
                'citations' => $this->normalizeStringList($fact['citations'] ?? []),
            ];
        }, $payload['known_facts']);

        $payload['inferences'] = array_map(function (mixed $inference): array {
            $inference = is_array($inference) ? $inference : [];

            return [
                'statement' => (string) ($inference['statement'] ?? ''),
                'confidence' => max(0, min(1, (float) ($inference['confidence'] ?? 0))),
                'citations' => $this->normalizeStringList($inference['citations'] ?? []),
            ];
        }, $payload['inferences']);

        $payload['recommended_actions'] = array_map(function (mixed $action): array {
            $action = is_array($action) ? $action : [];

            return [
                'action_type' => (string) ($action['action_type'] ?? ''),
                'target_scope' => is_array($action['target_scope'] ?? null) ? $action['target_scope'] : [],
                'arguments' => is_array($action['arguments'] ?? null) ? $action['arguments'] : [],
                'why_this_action' => (string) ($action['why_this_action'] ?? ''),
                'rollback_possible' => (bool) ($action['rollback_possible'] ?? false),
                'approval_required' => (bool) ($action['approval_required'] ?? false),
            ];
        }, $payload['recommended_actions']);

        $payload['known_facts'] = array_values(array_filter($payload['known_facts'], static fn (array $fact): bool => trim((string) ($fact['statement'] ?? '')) !== ''));
        $payload['inferences'] = array_values(array_filter($payload['inferences'], static fn (array $inference): bool => trim((string) ($inference['statement'] ?? '')) !== ''));
        $payload['recommended_actions'] = array_values(array_filter($payload['recommended_actions'], static fn (array $action): bool => trim((string) ($action['action_type'] ?? '')) !== ''));

        $payload['confidence_score'] = max(0, min(1, (float) $payload['confidence_score']));
        $payload['risk_level'] = in_array($payload['risk_level'], ['low', 'medium', 'high', 'critical'], true)
            ? $payload['risk_level']
            : 'medium';
        $payload['requires_human_review'] = (bool) $payload['requires_human_review'];
        $payload['why_this_action'] = (string) ($payload['why_this_action'] ?? $payload['reasoning_summary']);
        $payload['rollback_possible'] = (bool) ($payload['rollback_possible'] ?? false);
        $payload['approval_required'] = (bool) ($payload['approval_required'] ?? false);
        $payload['context_gaps'] = is_array($payload['context_gaps'] ?? null) ? $payload['context_gaps'] : [];

        return $payload;
    }

    private function normalizeRootPayload(array $payload): array
    {
        if (isset($payload['answer']) && is_array($payload['answer']) && ! array_key_exists('reasoning_summary', $payload)) {
            return $payload['answer'];
        }

        if (isset($payload['data']) && is_array($payload['data']) && ! array_key_exists('reasoning_summary', $payload)) {
            return $payload['data'];
        }

        return $payload;
    }

    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (! is_array($value)) {
            return [];
        }

        return array_is_list($value) ? $value : array_values($value);
    }

    private function normalizeStringList(mixed $value): array
    {
        $value = $this->normalizeList($value);

        return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== ''));
    }
}

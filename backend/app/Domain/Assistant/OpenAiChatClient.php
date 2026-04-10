<?php

namespace App\Domain\Assistant;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;

class OpenAiChatClient
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.openai.api_key')) !== '';
    }

    public function generateJson(array $messages, array $options = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $primaryModel = trim((string) ($options['model'] ?? config('services.openai.assistant_model', config('services.openai.model', 'gpt-4o-mini'))));
        $fallbackModel = trim((string) ($options['fallback_model'] ?? config('services.openai.assistant_fallback_model', config('services.openai.model', 'gpt-4o-mini'))));

        $models = array_values(array_filter(array_unique([$primaryModel, $fallbackModel]), static fn (string $model): bool => $model !== ''));
        if ($models === []) {
            $models = [(string) config('services.openai.model', 'gpt-4o-mini')];
        }

        $lastError = null;

        foreach ($models as $model) {
            try {
                return $this->requestModelJson($messages, $model);
            } catch (\Throwable $exception) {
                $lastError = $exception;
            }
        }

        if ($lastError instanceof \Throwable) {
            throw new RuntimeException('OpenAI request failed: '.$lastError->getMessage(), 0, $lastError);
        }

        throw new RuntimeException('OpenAI request failed before a response could be processed.');
    }

    private function requestModelJson(array $messages, string $model): array
    {
        $schemaResponse = $this->sendChatCompletion($messages, $model, true);
        if (! $schemaResponse->successful()) {
            $schemaResponse = $this->sendChatCompletion($messages, $model, false);
        }

        if (! $schemaResponse->successful()) {
            throw new RuntimeException('OpenAI request failed with status '.$schemaResponse->status().'.');
        }

        $content = $this->extractContent($schemaResponse->json());
        if ($content === '') {
            throw new RuntimeException('OpenAI response content was empty.');
        }

        $decoded = $this->decodeJsonContent($content);
        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI response was not valid JSON.');
        }

        return [
            'payload' => $decoded,
            'token_usage' => $schemaResponse->json('usage') ?? [],
            'model' => (string) ($schemaResponse->json('model') ?? $model),
        ];
    }

    private function sendChatCompletion(array $messages, string $model, bool $useSchemaFormat): Response
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float) config('services.openai.assistant_temperature', 0.15),
        ];

        $maxCompletionTokens = (int) config('services.openai.assistant_max_completion_tokens', 1200);
        if ($maxCompletionTokens > 0) {
            $payload['max_tokens'] = $maxCompletionTokens;
        }

        if ($useSchemaFormat) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'endpoint_assistant_answer',
                    'strict' => true,
                    'schema' => $this->assistantJsonSchema(),
                ],
            ];
        } else {
            $payload['response_format'] = [
                'type' => 'json_object',
            ];
        }

        $request = Http::timeout((int) config('services.openai.assistant_timeout', config('services.openai.timeout', 12)))
            ->connectTimeout((int) config('services.openai.assistant_connect_timeout', 2))
            ->withToken((string) config('services.openai.api_key'))
            ->baseUrl((string) config('services.openai.base_url'));

        $retryTimes = max(0, (int) config('services.openai.assistant_retry_times', 0));
        if ($retryTimes > 0) {
            $request = $this->applyRetry($request, $retryTimes);
        }

        return $request->post('/chat/completions', $payload);
    }

    private function applyRetry(PendingRequest $request, int $retryTimes): PendingRequest
    {
        return $request->retry(
            $retryTimes,
            (int) config('services.openai.assistant_retry_sleep_ms', 120),
            null,
            false
        );
    }

    private function extractContent(array $response): string
    {
        $content = data_get($response, 'choices.0.message.content');

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                    continue;
                }

                if (is_array($part)) {
                    $text = $part['text'] ?? data_get($part, 'content');
                    if (is_string($text) && trim($text) !== '') {
                        $parts[] = $text;
                    }
                }
            }

            $content = implode("\n", $parts);
        }

        return is_string($content) ? trim($content) : '';
    }

    private function decodeJsonContent(string $content): ?array
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $content, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($content, $start, ($end - $start) + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function assistantJsonSchema(): array
    {
        return [
            'type' => 'object',
            'required' => [
                'reasoning_summary',
                'known_facts',
                'inferences',
                'confidence_score',
                'risk_level',
                'recommended_actions',
                'why_this_action',
                'rollback_possible',
                'approval_required',
                'requires_human_review',
                'context_gaps',
                'citations',
            ],
            'additionalProperties' => false,
            'properties' => [
                'reasoning_summary' => ['type' => 'string'],
                'known_facts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['statement', 'citations'],
                        'additionalProperties' => false,
                        'properties' => [
                            'statement' => ['type' => 'string'],
                            'citations' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'inferences' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['statement', 'confidence', 'citations'],
                        'additionalProperties' => false,
                        'properties' => [
                            'statement' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                            'citations' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'confidence_score' => ['type' => 'number'],
                'risk_level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                'recommended_actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => [
                            'action_type',
                            'target_scope',
                            'arguments',
                            'why_this_action',
                            'rollback_possible',
                            'approval_required',
                        ],
                        'additionalProperties' => false,
                        'properties' => [
                            'action_type' => ['type' => 'string'],
                            'target_scope' => ['type' => 'object'],
                            'arguments' => ['type' => 'object'],
                            'why_this_action' => ['type' => 'string'],
                            'rollback_possible' => ['type' => 'boolean'],
                            'approval_required' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'why_this_action' => ['type' => 'string'],
                'rollback_possible' => ['type' => 'boolean'],
                'approval_required' => ['type' => 'boolean'],
                'requires_human_review' => ['type' => 'boolean'],
                'context_gaps' => ['type' => 'array', 'items' => ['type' => 'string']],
                'citations' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}

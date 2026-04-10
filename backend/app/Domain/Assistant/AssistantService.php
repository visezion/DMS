<?php

namespace App\Domain\Assistant;

use App\Domain\Remediation\ActionCatalog;
use App\Models\AiInvestigation;
use App\Models\AiRecommendation;
use App\Models\AssistantMessage;
use App\Models\AssistantSession;
use App\Models\CorrelatedIncident;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DeviceHealthScore;
use App\Models\DeviceHealthSnapshot;
use App\Models\DeviceRiskScore;
use App\Models\OperatorConversation;
use App\Models\PackageModel;
use App\Models\PackageVersion;
use App\Models\ThreatFinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Illuminate\Support\Str;

class AssistantService
{
    public function __construct(
        private readonly OpenAiChatClient $openAi,
        private readonly AssistantSchemaValidator $validator,
        private readonly ActionCatalog $actionCatalog
    ) {
    }

    public function ask(array $input, ?User $actor = null): array
    {
        $question = trim((string) ($input['question'] ?? ''));
        $mode = $this->resolveMode((string) ($input['mode'] ?? ''), $question);

        if ($question === '') {
            throw new RuntimeException('Question is required.');
        }

        $device = ! empty($input['device_id']) ? Device::query()->findOrFail($input['device_id']) : null;
        $incident = ! empty($input['incident_id']) ? CorrelatedIncident::query()->findOrFail($input['incident_id']) : null;
        $group = ! empty($input['group_id']) ? DeviceGroup::query()->findOrFail($input['group_id']) : null;
        $package = ! empty($input['package_id']) ? PackageModel::query()->findOrFail($input['package_id']) : null;
        $inferredScope = $this->inferScopeFromQuestion($question);

        if (! $device && ! empty($inferredScope['device_id'])) {
            $device = Device::query()->find($inferredScope['device_id']);
        }
        if (! $group && ! empty($inferredScope['group_id'])) {
            $group = DeviceGroup::query()->find($inferredScope['group_id']);
        }
        if (! $package && ! empty($inferredScope['package_id'])) {
            $package = PackageModel::query()->find($inferredScope['package_id']);
        }

        $conversation = ! empty($input['conversation_id'])
            ? OperatorConversation::query()->findOrFail($input['conversation_id'])
            : null;

        if (! $conversation) {
            $conversation = OperatorConversation::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $device?->tenant_id
                    ?? $incident?->tenant_id
                    ?? $group?->tenant_id
                    ?? $package?->tenant_id,
                'operator_user_id' => $actor?->id,
                'title' => mb_substr($question, 0, 120),
                'scope' => $this->mergeConversationScope([], $device, $incident, $group, $package),
                'status' => 'active',
                'last_message_at' => now(),
            ]);
        } else {
            $scope = is_array($conversation->scope) ? $conversation->scope : [];
            $nextScope = $this->mergeConversationScope($scope, $device, $incident, $group, $package);
            if ($nextScope !== $scope) {
                $conversation->update(['scope' => $nextScope]);
            }
        }

        $scope = is_array($conversation->scope) ? $conversation->scope : [];
        if (! $device && ! empty($scope['device_id'])) {
            $device = Device::query()->find($scope['device_id']);
        }
        if (! $incident && ! empty($scope['incident_id'])) {
            $incident = CorrelatedIncident::query()->find($scope['incident_id']);
        }
        if (! $group && ! empty($scope['group_id'])) {
            $group = DeviceGroup::query()->find($scope['group_id']);
        }
        if (! $package && ! empty($scope['package_id'])) {
            $package = PackageModel::query()->find($scope['package_id']);
        }

        $historyLimit = max(2, (int) config('services.openai.assistant_history_messages', 8));
        $historyMessages = $this->loadConversationMessages($conversation->id, $historyLimit);
        $effectiveQuestion = $this->resolveEffectiveQuestion($question, $historyMessages);

        $health = $device
            ? DeviceHealthScore::query()->where('device_id', $device->id)->latest('scored_at')->first()
            : null;
        $healthSnapshot = $device
            ? DeviceHealthSnapshot::query()
                ->where('device_id', $device->id)
                ->orderByDesc('snapshot_at')
                ->orderByDesc('created_at')
                ->first()
            : null;
        $risk = $device
            ? DeviceRiskScore::query()->where('device_id', $device->id)->latest('scored_at')->first()
            : null;
        $findings = $device
            ? ThreatFinding::query()
                ->where('device_id', $device->id)
                ->whereIn('status', ['open', 'investigating'])
                ->latest('last_seen_at')
                ->limit(10)
                ->get()
            : collect();

        $groupContext = $group ? $this->groupContext($group) : null;
        $packageContext = $package ? $this->packageContext($package) : null;
        $fleetContext = $this->fleetContext($effectiveQuestion);

        $session = AssistantSession::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'mode' => $mode,
            'context_hash' => hash('sha256', json_encode([
                'device_id' => $device?->id,
                'incident_id' => $incident?->id,
                'group_id' => $group?->id,
                'package_id' => $package?->id,
                'question' => $question,
                'effective_question' => $effectiveQuestion,
                'history_count' => count($historyMessages),
            ], JSON_THROW_ON_ERROR)),
            'status' => 'running',
            'started_at' => now(),
        ]);

        AssistantMessage::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $conversation->tenant_id,
            'session_id' => $session->id,
            'role' => 'user',
            'content' => $question,
            'citations' => [],
            'token_usage' => [],
            'created_at' => now(),
        ]);

        $context = [
            'mode' => $mode,
            'question' => $effectiveQuestion,
            'raw_question' => $question,
            'conversation_scope' => $this->mergeConversationScope($scope, $device, $incident, $group, $package),
            'conversation_history' => $historyMessages,
            'device' => $device ? [
                'id' => $device->id,
                'hostname' => $device->hostname,
                'os_name' => $device->os_name,
                'os_version' => $device->os_version,
                'status' => $device->status,
            ] : null,
            'group' => $groupContext,
            'package' => $packageContext,
            'fleet' => $fleetContext,
            'incident' => $incident ? [
                'id' => $incident->id,
                'title' => $incident->title,
                'summary' => $incident->summary,
                'severity' => $incident->severity,
            ] : null,
            'health' => $health ? [
                'score' => $health->score,
                'band' => $health->band,
                'contributors' => $health->contributors,
            ] : null,
            'telemetry' => $this->telemetryContext($healthSnapshot?->raw_payload),
            'risk' => $risk ? [
                'score' => $risk->score,
                'severity' => $risk->severity,
                'factor_breakdown' => $risk->factor_breakdown,
            ] : null,
            'findings' => $findings->map(fn (ThreatFinding $finding) => [
                'id' => $finding->id,
                'type' => $finding->finding_type,
                'severity' => $finding->severity,
                'summary' => data_get($finding->evidence ?? [], 'summary', $finding->finding_type),
                'confidence' => $finding->confidence,
            ])->values()->all(),
            'action_catalog' => $this->actionCatalog->all(),
            'allowed_action_catalog' => array_keys($this->actionCatalog->all()),
        ];

        $investigation = AiInvestigation::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $conversation->tenant_id,
            'incident_id' => $incident?->id,
            'device_id' => $device?->id,
            'requested_by_user_id' => $actor?->id,
            'mode' => $mode,
            'prompt_version' => 'v2',
            'model' => (string) config('services.openai.assistant_model', config('services.openai.model', 'gpt-4o-mini')),
            'context_hash' => $session->context_hash,
            'token_usage' => [],
            'status' => 'running',
            'started_at' => now(),
        ]);

        $answer = $this->deterministicFallback($context);
        $tokenUsage = [];
        $model = 'deterministic-fallback';

        if ($this->openAi->isConfigured()) {
            try {
                $promptMessages = $this->buildOpenAiPromptMessages($context, $historyMessages, $question, $mode);
                $response = $this->openAi->generateJson($promptMessages, [
                    'model' => (string) config('services.openai.assistant_model', config('services.openai.model', 'gpt-4o-mini')),
                    'fallback_model' => (string) config('services.openai.assistant_fallback_model', config('services.openai.model', 'gpt-4o-mini')),
                ]);
                $answer = $this->validator->validate($response['payload']);
                $answer = $this->sanitizeRecommendedActions($answer, $device?->id);
                $answer = $this->backfillGroundingFromFallback($answer, $context);
                if ($this->isLowInformationAnswer($answer, $context)) {
                    $fallback = $this->deterministicFallback($context);
                    $answer = $this->mergeWithFallbackAnswer($answer, $fallback);
                    $answer['context_gaps'][] = 'Model response was generic; grounded fleet snapshot fallback was applied.';
                }
                $tokenUsage = is_array($response['token_usage'] ?? null) ? $response['token_usage'] : [];
                $model = (string) ($response['model'] ?? $model);
            } catch (\Throwable $exception) {
                Log::warning('Assistant OpenAI request failed; using deterministic fallback.', [
                    'conversation_id' => $conversation->id,
                    'session_id' => $session->id,
                    'investigation_id' => $investigation->id,
                    'error_class' => get_class($exception),
                    'error_message' => $exception->getMessage(),
                ]);
                $exposeProviderError = (bool) config('services.openai.assistant_expose_provider_errors', false);
                $answer['context_gaps'][] = $exposeProviderError
                    ? 'OpenAI call failed: '.$exception->getMessage()
                    : 'OpenAI call failed; deterministic fallback was used.';
            }
        } else {
            $answer['context_gaps'][] = 'OpenAI API key is not configured; deterministic fallback was used.';
        }

        $investigation->update([
            'model' => $model,
            'token_usage' => $tokenUsage,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $recommendation = AiRecommendation::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $conversation->tenant_id,
            'investigation_id' => $investigation->id,
            'reasoning_summary' => (string) $answer['reasoning_summary'],
            'evidence' => $answer['citations'],
            'confidence_score' => (float) $answer['confidence_score'],
            'risk_level' => (string) $answer['risk_level'],
            'recommended_actions' => $answer['recommended_actions'],
            'why_this_action' => (string) ($answer['why_this_action'] ?? $answer['reasoning_summary']),
            'rollback_possible' => (bool) ($answer['rollback_possible'] ?? false),
            'approval_required' => (bool) ($answer['approval_required'] ?? true),
            'status' => 'generated',
        ]);

        AssistantMessage::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $conversation->tenant_id,
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => (string) $answer['reasoning_summary'],
            'citations' => $answer['citations'],
            'token_usage' => $tokenUsage,
            'created_at' => now(),
        ]);

        $session->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);

        return [
            'conversation_id' => $conversation->id,
            'session_id' => $session->id,
            'investigation_id' => $investigation->id,
            'recommendation_id' => $recommendation->id,
            'answer' => $answer,
        ];
    }

    /**
     * @param  array<int,array{role:string,content:string}>  $historyMessages
     * @return array<int,array{role:string,content:string}>
     */
    private function buildOpenAiPromptMessages(array $context, array $historyMessages, string $question, string $mode): array
    {
        $modelContext = $this->compactContextForModel($context);
        $modeDirective = match ($mode) {
            'guided_fix' => 'Prioritize safe and reversible steps with explicit approval requirements and guardrails.',
            'recommend' => 'Focus on prioritized actions and operator decision support.',
            'explain' => 'Focus on diagnosis clarity and explain cause/evidence in plain language.',
            default => 'Focus on incident triage and risk-grounded next steps.',
        };

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are DMS AI Ops Assistant for endpoint intelligence. Use only provided context and conversation history. Never claim access to data not present in context.',
            ],
            [
                'role' => 'system',
                'content' => 'Return strict JSON with these top-level keys only: reasoning_summary, known_facts, inferences, confidence_score, risk_level, recommended_actions, why_this_action, rollback_possible, approval_required, requires_human_review, context_gaps, citations. No markdown.',
            ],
            [
                'role' => 'system',
                'content' => 'Quality policy: every known_fact and inference must be grounded in citations. If context is missing or ambiguous, include explicit context_gaps instead of guessing. recommended_actions action_type MUST be one of allowed_action_catalog.',
            ],
            [
                'role' => 'system',
                'content' => 'Answer policy: do not restate user intent. reasoning_summary must include concrete context details (counts, risk levels, or entity names) when available. If user says short follow-up words like "yes", continue the prior topic from conversation history.',
            ],
            [
                'role' => 'system',
                'content' => 'Mode directive: '.$modeDirective,
            ],
            [
                'role' => 'system',
                'content' => 'Authoritative context JSON: '.json_encode($modelContext, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ],
        ];

        foreach ($historyMessages as $message) {
            $messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $question,
        ];

        return $messages;
    }

    private function compactContextForModel(array $context): array
    {
        $modelContext = $context;

        unset($modelContext['conversation_history'], $modelContext['raw_question']);
        if (is_array($modelContext['findings'] ?? null)) {
            $modelContext['findings'] = array_values(array_slice($modelContext['findings'], 0, 6));
        }

        if (is_array($modelContext['fleet'] ?? null)) {
            if (is_array($modelContext['fleet']['top_risky_devices'] ?? null)) {
                $modelContext['fleet']['top_risky_devices'] = array_values(array_slice($modelContext['fleet']['top_risky_devices'], 0, 5));
            }
            if (is_array($modelContext['fleet']['groups'] ?? null)) {
                $modelContext['fleet']['groups'] = array_values(array_slice($modelContext['fleet']['groups'], 0, 5));
            }
            if (is_array($modelContext['fleet']['packages'] ?? null)) {
                $modelContext['fleet']['packages'] = array_values(array_slice($modelContext['fleet']['packages'], 0, 5));
            }
        }

        if (is_array($modelContext['telemetry'] ?? null)) {
            $modelContext['telemetry'] = $this->truncateForModel($modelContext['telemetry'], 5, 0);
        }

        return $this->truncateForModel($modelContext, 12, 0);
    }

    private function truncateForModel(mixed $value, int $maxItems, int $depth): mixed
    {
        if ($depth >= 4) {
            return '[truncated]';
        }

        if (is_string($value)) {
            return mb_strlen($value) > 600 ? (mb_substr($value, 0, 600).'...') : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return collect($value)
                ->take($maxItems)
                ->map(fn (mixed $item): mixed => $this->truncateForModel($item, $maxItems, $depth + 1))
                ->values()
                ->all();
        }

        $compacted = [];
        foreach (array_slice($value, 0, $maxItems, true) as $key => $item) {
            $compacted[$key] = $this->truncateForModel($item, $maxItems, $depth + 1);
        }

        return $compacted;
    }

    /**
     * @param  array<int,array{role:string,content:string}>  $historyMessages
     */
    private function resolveEffectiveQuestion(string $question, array $historyMessages): string
    {
        $trimmed = trim($question);
        if ($trimmed === '') {
            return $question;
        }

        $normalized = strtolower($trimmed);
        $shortFollowUps = ['yes', 'yeah', 'yep', 'ok', 'okay', 'sure', 'continue', 'go on', 'more', 'details'];
        if (! in_array($normalized, $shortFollowUps, true)) {
            return $question;
        }

        $priorUserMessage = collect($historyMessages)
            ->reverse()
            ->first(function (array $message) use ($normalized): bool {
                if (($message['role'] ?? '') !== 'user') {
                    return false;
                }

                $content = strtolower(trim((string) ($message['content'] ?? '')));

                return $content !== '' && $content !== $normalized;
            });

        if (! is_array($priorUserMessage)) {
            return $question;
        }

        return (string) ($priorUserMessage['content'] ?? $question);
    }

    private function isLowInformationAnswer(array $answer, array $context): bool
    {
        $summary = strtolower(trim((string) ($answer['reasoning_summary'] ?? '')));
        if ($summary === '') {
            return true;
        }

        $genericMarkers = [
            'the inquiry',
            'the user is inquiring',
            'does not provide specific context',
            'seeks to identify',
            'indicating a need',
        ];
        foreach ($genericMarkers as $marker) {
            if (str_contains($summary, $marker)) {
                return true;
            }
        }

        $knownFacts = is_array($answer['known_facts'] ?? null) ? $answer['known_facts'] : [];
        $inferences = is_array($answer['inferences'] ?? null) ? $answer['inferences'] : [];
        $recommendedActions = is_array($answer['recommended_actions'] ?? null) ? $answer['recommended_actions'] : [];

        $topRiskHostnames = collect(data_get($context, 'fleet.top_risky_devices', []))
            ->map(static fn (mixed $row): string => strtolower(trim((string) data_get($row, 'hostname', ''))))
            ->filter(static fn (string $hostname): bool => $hostname !== '')
            ->values();

        $containsConcreteHostname = $topRiskHostnames->contains(
            fn (string $hostname): bool => str_contains($summary, $hostname)
        );
        $hasConcreteSummarySignal = preg_match('/\d/', $summary) === 1
            || $containsConcreteHostname
            || (data_get($context, 'device.hostname') && str_contains($summary, strtolower((string) data_get($context, 'device.hostname'))));

        if (! $hasConcreteSummarySignal && count($knownFacts) <= 1 && count($recommendedActions) === 0 && count($inferences) === 0) {
            return true;
        }

        return false;
    }

    private function mergeWithFallbackAnswer(array $answer, array $fallback): array
    {
        $answer['reasoning_summary'] = (string) ($fallback['reasoning_summary'] ?? $answer['reasoning_summary'] ?? '');
        $answer['known_facts'] = $fallback['known_facts'] ?? [];
        $answer['inferences'] = ($answer['inferences'] ?? []) === [] ? ($fallback['inferences'] ?? []) : $answer['inferences'];
        $answer['recommended_actions'] = ($answer['recommended_actions'] ?? []) === [] ? ($fallback['recommended_actions'] ?? []) : $answer['recommended_actions'];
        $answer['why_this_action'] = ($answer['recommended_actions'] ?? []) === []
            ? (string) ($fallback['why_this_action'] ?? $answer['why_this_action'] ?? '')
            : (string) ($answer['why_this_action'] ?? '');
        $answer['rollback_possible'] = (bool) (($answer['rollback_possible'] ?? false) || ($fallback['rollback_possible'] ?? false));
        $answer['approval_required'] = (bool) (($answer['approval_required'] ?? false) || ($fallback['approval_required'] ?? false));
        $answer['risk_level'] = (string) ($fallback['risk_level'] ?? $answer['risk_level'] ?? 'medium');
        $answer['confidence_score'] = max((float) ($answer['confidence_score'] ?? 0), (float) ($fallback['confidence_score'] ?? 0));
        $answer['citations'] = array_values(array_unique(array_merge(
            is_array($answer['citations'] ?? null) ? $answer['citations'] : [],
            is_array($fallback['citations'] ?? null) ? $fallback['citations'] : []
        )));

        return $answer;
    }

    private function resolveMode(string $requestedMode, string $question): string
    {
        $requestedMode = trim($requestedMode);
        if (in_array($requestedMode, ['explain', 'investigate', 'recommend', 'guided_fix'], true)) {
            return $requestedMode;
        }

        $normalizedQuestion = strtolower($question);
        if (
            str_contains($normalizedQuestion, 'fix')
            || str_contains($normalizedQuestion, 'resolve')
            || str_contains($normalizedQuestion, 'remediate')
            || str_contains($normalizedQuestion, 'how do i')
        ) {
            return 'guided_fix';
        }

        if (
            str_contains($normalizedQuestion, 'recommend')
            || str_contains($normalizedQuestion, 'next step')
            || str_contains($normalizedQuestion, 'what should')
            || str_contains($normalizedQuestion, 'should we')
        ) {
            return 'recommend';
        }

        if (
            str_contains($normalizedQuestion, 'explain')
            || str_contains($normalizedQuestion, 'why ')
            || str_contains($normalizedQuestion, 'what happened')
        ) {
            return 'explain';
        }

        return 'investigate';
    }

    /**
     * @return array{device_id?:string,group_id?:string,package_id?:string}
     */
    private function inferScopeFromQuestion(string $question): array
    {
        $matches = $this->discoverQuestionMatches($question);
        $normalizedQuestion = strtolower($question);
        $scope = [];

        $deviceMatches = collect($matches['devices'] ?? [])->filter(function (array $device) use ($normalizedQuestion): bool {
            return $this->questionContainsEntity($normalizedQuestion, (string) ($device['hostname'] ?? ''))
                || $this->questionContainsEntity($normalizedQuestion, (string) ($device['id'] ?? ''));
        })->values();
        if ($deviceMatches->count() === 1) {
            $scope['device_id'] = (string) data_get($deviceMatches->first(), 'id');
        }

        $groupMatches = collect($matches['groups'] ?? [])->filter(function (array $group) use ($normalizedQuestion): bool {
            return $this->questionContainsEntity($normalizedQuestion, (string) ($group['name'] ?? ''))
                || $this->questionContainsEntity($normalizedQuestion, (string) ($group['id'] ?? ''));
        })->values();
        if ($groupMatches->count() === 1) {
            $scope['group_id'] = (string) data_get($groupMatches->first(), 'id');
        }

        $packageMatches = collect($matches['packages'] ?? [])->filter(function (array $package) use ($normalizedQuestion): bool {
            return $this->questionContainsEntity($normalizedQuestion, (string) ($package['name'] ?? ''))
                || $this->questionContainsEntity($normalizedQuestion, (string) ($package['slug'] ?? ''))
                || $this->questionContainsEntity($normalizedQuestion, (string) ($package['id'] ?? ''));
        })->values();
        if ($packageMatches->count() === 1) {
            $scope['package_id'] = (string) data_get($packageMatches->first(), 'id');
        }

        return $scope;
    }

    private function questionContainsEntity(string $normalizedQuestion, string $candidate): bool
    {
        $candidate = strtolower(trim($candidate));
        if ($candidate === '' || mb_strlen($candidate) < 3) {
            return false;
        }

        return str_contains($normalizedQuestion, $candidate);
    }

    private function sanitizeRecommendedActions(array $answer, ?string $defaultDeviceId = null): array
    {
        $actions = [];
        $dropped = 0;

        foreach ($answer['recommended_actions'] ?? [] as $action) {
            $actionType = $this->canonicalizeActionType((string) ($action['action_type'] ?? ''), $action);

            if ($actionType === '' || ! $this->actionCatalog->has($actionType)) {
                $dropped++;
                continue;
            }

            $targetScope = is_array($action['target_scope'] ?? null) ? $action['target_scope'] : [];
            if (($targetScope['type'] ?? null) === null && $defaultDeviceId) {
                $targetScope = [
                    'type' => 'device',
                    'id' => $defaultDeviceId,
                ];
            }

            $actions[] = [
                'action_type' => $actionType,
                'target_scope' => $targetScope,
                'arguments' => is_array($action['arguments'] ?? null) ? $action['arguments'] : [],
                'why_this_action' => (string) ($action['why_this_action'] ?? $answer['why_this_action'] ?? ''),
                'rollback_possible' => (bool) ($action['rollback_possible'] ?? false),
                'approval_required' => (bool) ($action['approval_required'] ?? (bool) data_get($this->actionCatalog->get($actionType), 'approval_required', false)),
            ];
        }

        if ($dropped > 0) {
            $answer['context_gaps'][] = $dropped.' OpenAI-suggested action(s) were dropped because they are not in the internal action catalog.';
        }

        $answer['recommended_actions'] = $actions;
        $answer['approval_required'] = collect($actions)->contains(fn (array $action): bool => (bool) ($action['approval_required'] ?? false));
        $answer['rollback_possible'] = collect($actions)->contains(fn (array $action): bool => (bool) ($action['rollback_possible'] ?? false));

        if ($actions === [] && trim((string) ($answer['why_this_action'] ?? '')) === '') {
            $answer['why_this_action'] = 'No safe automated action was justified from current context.';
        }

        return $answer;
    }

    private function canonicalizeActionType(string $rawActionType, array $action = []): string
    {
        $normalized = strtolower(trim($rawActionType));
        $normalized = str_replace(['-', ' '], '_', $normalized);

        $map = [
            'restart_services' => 'restart_service',
            'restart_windows_service' => 'restart_service',
            'restart_service' => 'restart_service',
            'kill_process' => 'kill_process',
            'terminate_process' => 'kill_process',
            'end_process' => 'kill_process',
            'clear_temp_files' => 'cleanup_temp_files',
            'cleanup_temp_files' => 'cleanup_temp_files',
            'delete_temp_files' => 'cleanup_temp_files',
            'trigger_updates' => 'trigger_windows_update',
            'trigger_windows_update' => 'trigger_windows_update',
            'run_windows_update' => 'trigger_windows_update',
            'update_windows' => 'trigger_windows_update',
            'rerun_inventory' => 're_run_inventory',
            're_run_inventory' => 're_run_inventory',
            'refresh_inventory' => 're_run_inventory',
            'inventory_refresh' => 're_run_inventory',
            'reenable_security_control' => 're_enable_security_control',
            're_enable_security_control' => 're_enable_security_control',
            'enable_defender' => 're_enable_security_control',
            'run_approved_command' => 'run_approved_command',
            'approved_command' => 'run_approved_command',
            'schedule_restart' => 'schedule_reboot',
            'schedule_reboot' => 'schedule_reboot',
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        $why = strtolower(trim((string) ($action['why_this_action'] ?? '')));
        $args = strtolower(json_encode($action['arguments'] ?? [], JSON_UNESCAPED_SLASHES) ?: '');
        $composite = $normalized.' '.$why.' '.$args;

        return match (true) {
            str_contains($composite, 'temp') && str_contains($composite, 'file') => 'cleanup_temp_files',
            str_contains($composite, 'inventory') || str_contains($composite, 'telemetry refresh') => 're_run_inventory',
            str_contains($composite, 'update') && str_contains($composite, 'windows') => 'trigger_windows_update',
            str_contains($composite, 'restart') && str_contains($composite, 'service') => 'restart_service',
            str_contains($composite, 'kill') && str_contains($composite, 'process') => 'kill_process',
            str_contains($composite, 'defender') || str_contains($composite, 'security control') => 're_enable_security_control',
            default => $normalized,
        };
    }

    private function backfillGroundingFromFallback(array $answer, array $context): array
    {
        $fallback = $this->deterministicFallback($context);

        if (($answer['known_facts'] ?? []) === []) {
            $answer['known_facts'] = $fallback['known_facts'];
            $answer['context_gaps'][] = 'Model returned no grounded facts; deterministic facts were used instead.';
        }

        if (($answer['citations'] ?? []) === []) {
            $answer['citations'] = $fallback['citations'];
        }

        return $answer;
    }

    private function mergeConversationScope(
        array $scope,
        ?Device $device,
        ?CorrelatedIncident $incident,
        ?DeviceGroup $group,
        ?PackageModel $package
    ): array {
        $next = is_array($scope) ? $scope : [];
        if ($device) {
            $next['device_id'] = $device->id;
        }
        if ($incident) {
            $next['incident_id'] = $incident->id;
        }
        if ($group) {
            $next['group_id'] = $group->id;
        }
        if ($package) {
            $next['package_id'] = $package->id;
        }

        return array_filter($next, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<int,array{role:string,content:string}>
     */
    private function loadConversationMessages(string $conversationId, int $limit = 12): array
    {
        $sessionIds = AssistantSession::query()
            ->where('conversation_id', $conversationId)
            ->orderByDesc('started_at')
            ->limit(60)
            ->pluck('id');

        if ($sessionIds->isEmpty()) {
            return [];
        }

        return AssistantMessage::query()
            ->whereIn('session_id', $sessionIds)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['role', 'content', 'created_at'])
            ->reverse()
            ->map(function (AssistantMessage $message): array {
                $role = in_array($message->role, ['user', 'assistant'], true) ? $message->role : 'assistant';

                return [
                    'role' => $role,
                    'content' => trim((string) $message->content),
                ];
            })
            ->filter(static fn (array $message): bool => $message['content'] !== '')
            ->values()
            ->all();
    }

    private function groupContext(DeviceGroup $group): array
    {
        $memberCount = (int) DB::table('device_group_memberships')
            ->where('device_group_id', $group->id)
            ->count();

        $memberIds = DB::table('device_group_memberships')
            ->where('device_group_id', $group->id)
            ->limit(250)
            ->pluck('device_id');

        $members = $memberIds->isEmpty()
            ? collect()
            : Device::query()
                ->whereIn('id', $memberIds)
                ->get(['id', 'hostname', 'status', 'os_name', 'os_version']);

        $latestRisk = $memberIds->isEmpty()
            ? collect()
            : DeviceRiskScore::query()
                ->whereIn('device_id', $memberIds)
                ->orderByDesc('scored_at')
                ->get(['device_id', 'score', 'severity', 'scored_at'])
                ->unique('device_id');

        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'member_count' => $memberCount,
            'high_risk_member_count' => $latestRisk->filter(fn (DeviceRiskScore $risk): bool => (float) $risk->score >= 60)->count(),
            'member_sample' => $members->take(12)->map(fn (Device $device) => [
                'id' => $device->id,
                'hostname' => $device->hostname,
                'status' => $device->status,
                'os_name' => $device->os_name,
                'os_version' => $device->os_version,
            ])->values()->all(),
        ];
    }

    private function packageContext(PackageModel $package): array
    {
        $versions = PackageVersion::query()
            ->where('package_id', $package->id)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['version', 'channel', 'is_deprecated', 'created_at']);

        return [
            'id' => $package->id,
            'name' => $package->name,
            'slug' => $package->slug,
            'publisher' => $package->publisher,
            'package_type' => $package->package_type,
            'is_active' => (bool) $package->is_active,
            'versions' => $versions->map(fn (PackageVersion $version) => [
                'version' => $version->version,
                'channel' => $version->channel,
                'is_deprecated' => (bool) $version->is_deprecated,
                'created_at' => $this->formatDateTime($version->created_at),
            ])->values()->all(),
        ];
    }

    private function fleetContext(string $question): array
    {
        $deviceCount = Device::query()->count();
        $onlineDeviceCount = Device::query()->where('status', 'online')->count();
        $groupCount = DeviceGroup::query()->count();
        $packageCount = PackageModel::query()->count();
        $activePackageCount = PackageModel::query()->where('is_active', true)->count();

        $recentRisk = DeviceRiskScore::query()
            ->orderByDesc('scored_at')
            ->limit(300)
            ->get(['device_id', 'score', 'severity', 'scored_at']);
        $topRisk = $recentRisk
            ->sortByDesc(fn (DeviceRiskScore $row): float => (float) $row->score)
            ->unique('device_id')
            ->take(8)
            ->values();
        $topRiskDeviceNames = Device::query()
            ->whereIn('id', $topRisk->pluck('device_id')->values())
            ->pluck('hostname', 'id');

        $groupRows = DeviceGroup::query()
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'name']);
        $groupMemberCounts = $groupRows->isEmpty()
            ? collect()
            : DB::table('device_group_memberships')
                ->selectRaw('device_group_id, COUNT(*) as member_count')
                ->whereIn('device_group_id', $groupRows->pluck('id')->values())
                ->groupBy('device_group_id')
                ->pluck('member_count', 'device_group_id');

        $packageRows = PackageModel::query()
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'name', 'slug', 'publisher', 'package_type', 'is_active']);
        $latestVersions = $packageRows->isEmpty()
            ? collect()
            : PackageVersion::query()
                ->whereIn('package_id', $packageRows->pluck('id')->values())
                ->orderByDesc('created_at')
                ->get(['package_id', 'version', 'channel', 'created_at'])
                ->groupBy('package_id')
                ->map(fn ($rows) => $rows->first());

        return [
            'counts' => [
                'devices_total' => $deviceCount,
                'devices_online' => $onlineDeviceCount,
                'groups_total' => $groupCount,
                'packages_total' => $packageCount,
                'packages_active' => $activePackageCount,
                'open_findings_total' => ThreatFinding::query()->where('status', 'open')->count(),
            ],
            'top_risky_devices' => $topRisk->map(fn (DeviceRiskScore $row): array => [
                'device_id' => $row->device_id,
                'hostname' => $topRiskDeviceNames[$row->device_id] ?? null,
                'score' => (float) $row->score,
                'severity' => (string) $row->severity,
                'scored_at' => $this->formatDateTime($row->scored_at),
            ])->values()->all(),
            'groups' => $groupRows->map(fn (DeviceGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'member_count' => (int) ($groupMemberCounts[$group->id] ?? 0),
            ])->values()->all(),
            'packages' => $packageRows->map(function (PackageModel $package) use ($latestVersions): array {
                /** @var PackageVersion|null $latest */
                $latest = $latestVersions->get($package->id);

                return [
                    'id' => $package->id,
                    'name' => $package->name,
                    'slug' => $package->slug,
                    'publisher' => $package->publisher,
                    'package_type' => $package->package_type,
                    'is_active' => (bool) $package->is_active,
                    'latest_version' => $latest?->version,
                    'latest_channel' => $latest?->channel,
                ];
            })->values()->all(),
            'question_matches' => $this->discoverQuestionMatches($question),
        ];
    }

    private function discoverQuestionMatches(string $question): array
    {
        $tokens = $this->tokenizeQuestion($question);
        if ($tokens === []) {
            return [
                'tokens' => [],
                'devices' => [],
                'groups' => [],
                'packages' => [],
            ];
        }

        $devices = Device::query()
            ->where(function ($query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';
                    $query
                        ->orWhere('hostname', 'like', $like)
                        ->orWhere('serial_number', 'like', $like)
                        ->orWhere('os_name', 'like', $like);
                }
            })
            ->limit(5)
            ->get(['id', 'hostname', 'status', 'os_name']);

        $groups = DeviceGroup::query()
            ->where(function ($query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';
                    $query
                        ->orWhere('name', 'like', $like)
                        ->orWhere('description', 'like', $like);
                }
            })
            ->limit(5)
            ->get(['id', 'name']);

        $packages = PackageModel::query()
            ->where(function ($query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';
                    $query
                        ->orWhere('name', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhere('publisher', 'like', $like);
                }
            })
            ->limit(5)
            ->get(['id', 'name', 'slug', 'publisher']);

        return [
            'tokens' => $tokens,
            'devices' => $devices->map(fn (Device $device): array => [
                'id' => $device->id,
                'hostname' => $device->hostname,
                'status' => $device->status,
                'os_name' => $device->os_name,
            ])->values()->all(),
            'groups' => $groups->map(fn (DeviceGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
            ])->values()->all(),
            'packages' => $packages->map(fn (PackageModel $package): array => [
                'id' => $package->id,
                'name' => $package->name,
                'slug' => $package->slug,
                'publisher' => $package->publisher,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function tokenizeQuestion(string $question): array
    {
        if (! preg_match_all('/[a-z0-9._-]{3,}/i', $question, $matches)) {
            return [];
        }

        return collect($matches[0] ?? [])
            ->map(fn (mixed $token): string => strtolower(trim((string) $token)))
            ->filter(fn (string $token): bool => $token !== '')
            ->unique()
            ->take(10)
            ->values()
            ->all();
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }

    private function telemetryContext(?array $rawPayload): ?array
    {
        if (! is_array($rawPayload) || $rawPayload === []) {
            return null;
        }

        $redactBehaviorDetails = (bool) config('services.openai.assistant_redact_behavior_details', true);
        $recentEvents = collect(data_get($rawPayload, 'behavior_summary.recent_events', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->take(200);
        $topEventTypes = $recentEvents
            ->groupBy(fn (array $event): string => (string) ($event['event_type'] ?? 'unknown'))
            ->map(fn ($events): int => count($events))
            ->sortDesc()
            ->take(12)
            ->toArray();
        $sampledProcesses = $recentEvents
            ->map(fn (array $event): string => strtolower(trim((string) ($event['process_name'] ?? ''))))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->take(8)
            ->values()
            ->all();

        return [
            'identity' => data_get($rawPayload, 'identity'),
            'system_health_and_performance' => [
                'cpu_usage_percent' => data_get($rawPayload, 'windows_telemetry.system_health_and_performance.cpu_usage_percent'),
                'memory_usage_percent' => data_get($rawPayload, 'windows_telemetry.system_health_and_performance.memory_usage_percent'),
                'uptime_seconds' => data_get($rawPayload, 'windows_telemetry.system_health_and_performance.uptime_seconds'),
                'boot_time_utc' => data_get($rawPayload, 'windows_telemetry.system_health_and_performance.boot_time_utc'),
                'disk_space_per_drive' => data_get($rawPayload, 'windows_telemetry.system_health_and_performance.disk_space_per_drive'),
                'frequent_crashes_24h' => data_get($rawPayload, 'windows_telemetry.system_health_and_performance.frequent_crashes_24h'),
                'service_failures_24h' => data_get($rawPayload, 'windows_telemetry.system_health_and_performance.service_failures_24h'),
            ],
            'security_posture' => [
                'microsoft_defender_status' => data_get($rawPayload, 'windows_telemetry.security_posture.microsoft_defender_status'),
                'firewall_status' => data_get($rawPayload, 'windows_telemetry.security_posture.firewall_status'),
                'bitlocker_encryption_status' => data_get($rawPayload, 'windows_telemetry.security_posture.bitlocker_encryption_status'),
                'windows_update_status' => data_get($rawPayload, 'windows_telemetry.security_posture.windows_update_status'),
                'local_admin_accounts' => data_get($rawPayload, 'windows_telemetry.security_posture.local_admin_accounts'),
            ],
            'authentication_and_user_activity' => [
                'login_events' => data_get($rawPayload, 'windows_telemetry.authentication_and_user_activity.login_events'),
                'failed_auth_bursts' => data_get($rawPayload, 'windows_telemetry.authentication_and_user_activity.failed_auth_bursts'),
            ],
            'network_telemetry' => [
                'frequent_outbound_destinations_count' => count(data_get($rawPayload, 'windows_telemetry.network_telemetry.frequent_outbound_destinations', [])),
                'unusual_external_communication_count' => count(data_get($rawPayload, 'windows_telemetry.network_telemetry.unusual_external_communication', [])),
                'network_profile' => data_get($rawPayload, 'windows_telemetry.network_telemetry.network_profile'),
            ],
            'configuration_and_policy_state' => data_get($rawPayload, 'windows_telemetry.configuration_and_policy_state'),
            'smart_operational_data' => data_get($rawPayload, 'windows_telemetry.smart_operational_data'),
            'behavior_summary' => [
                'recent_event_count' => data_get($rawPayload, 'behavior_summary.recent_event_count'),
                'top_event_types' => $topEventTypes,
                'sampled_processes' => $sampledProcesses,
                'recent_events' => $redactBehaviorDetails
                    ? []
                    : $recentEvents->map(fn (array $event): array => [
                        'event_type' => (string) ($event['event_type'] ?? ''),
                        'occurred_at' => (string) ($event['occurred_at'] ?? ''),
                        'process_name' => (string) ($event['process_name'] ?? ''),
                    ])->values()->all(),
            ],
            'telemetry_coverage' => data_get($rawPayload, 'telemetry_coverage'),
        ];
    }

    private function deterministicFallback(array $context): array
    {
        $question = trim((string) data_get($context, 'question', data_get($context, 'raw_question', '')));
        $questionIntent = $this->inferQuestionIntent($question);
        $risk = data_get($context, 'risk.score', 0);
        $health = data_get($context, 'health.score', 100);
        $findings = collect($context['findings'] ?? []);
        $criticalFinding = $findings->firstWhere('severity', 'high') ?? $findings->first();
        $fleetCounts = is_array(data_get($context, 'fleet.counts')) ? data_get($context, 'fleet.counts') : [];
        $questionMatches = is_array(data_get($context, 'fleet.question_matches')) ? data_get($context, 'fleet.question_matches') : [];
        $topRiskyDevices = collect(data_get($context, 'fleet.top_risky_devices', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->take(3)
            ->values();

        $openFindingsTotal = (int) ($fleetCounts['open_findings_total'] ?? 0);
        $devicesTotal = (int) ($fleetCounts['devices_total'] ?? 0);
        $devicesOnline = (int) ($fleetCounts['devices_online'] ?? 0);
        $topRiskySummary = $topRiskyDevices
            ->map(function (array $row): string {
                $hostname = trim((string) ($row['hostname'] ?? $row['device_id'] ?? 'unknown-device'));
                $score = is_numeric($row['score'] ?? null) ? round((float) $row['score'], 1) : null;
                $severity = trim((string) ($row['severity'] ?? 'unknown'));

                if ($score === null) {
                    return $hostname.' ('.$severity.')';
                }

                return $hostname.' (risk '.$score.', '.$severity.')';
            })
            ->implode(', ');

        $knownFacts = [];
        if (! empty($context['device'])) {
            $knownFacts[] = [
                'statement' => 'The device is '.$context['device']['hostname'].' running '.$context['device']['os_name'].' '.$context['device']['os_version'].'.',
                'citations' => ['device:'.($context['device']['id'] ?? 'unknown')],
            ];
        }
        if (! empty($context['group'])) {
            $knownFacts[] = [
                'statement' => 'The selected group '.$context['group']['name'].' has '.(int) data_get($context, 'group.member_count', 0).' member devices.',
                'citations' => ['group:'.(data_get($context, 'group.id') ?? 'unknown')],
            ];
        }
        if (! empty($context['package'])) {
            $knownFacts[] = [
                'statement' => 'The selected package is '.$context['package']['name'].' (slug: '.($context['package']['slug'] ?? 'n/a').').',
                'citations' => ['package:'.(data_get($context, 'package.id') ?? 'unknown')],
            ];
        }
        if ($fleetCounts !== []) {
            $knownFacts[] = [
                'statement' => 'Fleet scope currently includes '.(int) ($fleetCounts['devices_total'] ?? 0).' devices and '.(int) ($fleetCounts['open_findings_total'] ?? 0).' open findings.',
                'citations' => ['fleet:counts'],
            ];
        }
        if ($topRiskyDevices->isNotEmpty()) {
            $knownFacts[] = [
                'statement' => 'Top risky devices right now: '.$topRiskySummary.'.',
                'citations' => ['fleet:top_risky_devices'],
            ];
        }
        if (
            ! empty($questionMatches['devices'])
            || ! empty($questionMatches['groups'])
            || ! empty($questionMatches['packages'])
        ) {
            $knownFacts[] = [
                'statement' => 'The question text matched '.count($questionMatches['devices'] ?? []).' devices, '.count($questionMatches['groups'] ?? []).' groups, and '.count($questionMatches['packages'] ?? []).' packages in current inventory.',
                'citations' => ['fleet:question_matches'],
            ];
        }
        if (is_numeric($risk)) {
            $knownFacts[] = [
                'statement' => 'The current risk score is '.round((float) $risk, 2).'.',
                'citations' => ['risk:latest'],
            ];
        }
        if (is_numeric($health)) {
            $knownFacts[] = [
                'statement' => 'The current health score is '.round((float) $health, 2).'.',
                'citations' => ['health:latest'],
            ];
        }

        $recommendedActions = [];
        if ($criticalFinding) {
            $targetScope = data_get($context, 'device.id')
                ? ['type' => 'device', 'id' => data_get($context, 'device.id')]
                : ['type' => 'fleet', 'id' => 'fleet'];
            $actionType = data_get($context, 'device.id')
                ? ($criticalFinding['type'] === 'defender_disabled' ? 're_enable_security_control' : 're_run_inventory')
                : 'open_approval_request';

            $recommendedActions[] = [
                'action_type' => $actionType,
                'target_scope' => $targetScope,
                'arguments' => $actionType === 're_enable_security_control'
                    ? ['control' => 'defender']
                    : ($actionType === 're_run_inventory'
                        ? ['reason' => 'refresh-telemetry']
                        : ['reason' => 'review-fleet-risk']),
                'why_this_action' => 'The recommendation is tied to the most severe active finding.',
                'rollback_possible' => false,
                'approval_required' => $criticalFinding['severity'] !== 'low',
            ];
        }

        $reasoningSummary = '';
        if ($questionIntent === 'greeting') {
            $reasoningSummary = 'Hello. Fleet snapshot: '.$openFindingsTotal.' open findings across '.$devicesTotal.' devices ('.$devicesOnline.' online).';
            if ($topRiskySummary !== '') {
                $reasoningSummary .= ' Highest risk devices: '.$topRiskySummary.'.';
            }
        } elseif (empty($context['device']) && in_array($questionIntent, ['fleet_status', 'risk_today', 'general'], true)) {
            if ($openFindingsTotal > 0 || $topRiskySummary !== '') {
                $reasoningSummary = 'Today, there are '.$openFindingsTotal.' open findings across '.$devicesTotal.' devices ('.$devicesOnline.' online).';
                if ($topRiskySummary !== '') {
                    $reasoningSummary .= ' Highest risk devices right now: '.$topRiskySummary.'.';
                }
            } else {
                $reasoningSummary = 'Today, I do not see active open findings in the current fleet snapshot.';
            }
        } else {
            $reasoningSummary = $findings->isEmpty()
                ? (
                    empty($context['device'])
                        ? 'No active high-confidence threat is grounded for a specific device in this request. Ask for fleet risks, or mention a device, group, or package for targeted analysis.'
                        : 'Current telemetry does not show an active high-confidence threat on '.(string) data_get($context, 'device.hostname', 'this device').'. Continue monitoring and refresh telemetry if this device is under investigation.'
                )
                : 'Current telemetry indicates elevated risk'.(
                    data_get($context, 'device.hostname')
                        ? ' on '.data_get($context, 'device.hostname')
                        : ' across the fleet'
                ).' driven by active findings that should be reviewed before broader action.';
        }

        $riskLevel = ((float) $risk) >= 80 ? 'critical' : (((float) $risk) >= 60 ? 'high' : (((float) $risk) >= 30 ? 'medium' : 'low'));
        if (empty($context['device']) && $topRiskyDevices->isNotEmpty()) {
            $fleetRiskLevel = collect($topRiskyDevices)->contains(fn (array $row): bool => strtolower((string) ($row['severity'] ?? '')) === 'critical')
                ? 'critical'
                : (collect($topRiskyDevices)->contains(fn (array $row): bool => strtolower((string) ($row['severity'] ?? '')) === 'high')
                    ? 'high'
                    : (collect($topRiskyDevices)->contains(fn (array $row): bool => strtolower((string) ($row['severity'] ?? '')) === 'medium')
                        ? 'medium'
                        : 'low'));
            $riskLevel = $fleetRiskLevel;
        }

        return [
            'reasoning_summary' => $reasoningSummary,
            'known_facts' => $knownFacts,
            'inferences' => $findings->isEmpty()
                ? ($openFindingsTotal > 0 ? [[
                    'statement' => 'The fleet likely needs operator triage because open findings remain unresolved.',
                    'confidence' => 0.66,
                    'citations' => ['fleet:counts'],
                ]] : [])
                : [[
                    'statement' => data_get($context, 'device.hostname')
                        ? 'The device likely needs operator review because at least one active finding is present.'
                        : 'The fleet likely needs operator review because active findings are present.',
                    'confidence' => 0.72,
                    'citations' => ['finding:'.($criticalFinding['id'] ?? 'latest')],
                ]],
            'confidence_score' => $findings->isEmpty() ? 0.62 : 0.76,
            'risk_level' => $riskLevel,
            'recommended_actions' => $recommendedActions,
            'why_this_action' => $recommendedActions === [] ? 'No safe automated action was justified from current context.' : 'The action is targeted at the most severe active issue visible in current telemetry.',
            'rollback_possible' => false,
            'approval_required' => $recommendedActions !== [],
            'requires_human_review' => true,
            'context_gaps' => [],
            'citations' => array_values(array_unique(array_merge(
                data_get($context, 'device.id') ? ['device:'.data_get($context, 'device.id')] : ['fleet:counts'],
                $topRiskyDevices->isNotEmpty() ? ['fleet:top_risky_devices'] : [],
                $findings->map(fn (array $finding) => 'finding:'.$finding['id'])->all()
            ))),
        ];
    }

    private function inferQuestionIntent(string $question): string
    {
        $normalized = strtolower(trim($question));
        if ($normalized === '') {
            return 'general';
        }

        if (in_array($normalized, ['hi', 'hello', 'hey', 'good morning', 'good afternoon'], true)) {
            return 'greeting';
        }

        if (
            str_contains($normalized, 'today')
            || str_contains($normalized, 'what is bad')
            || str_contains($normalized, "what's bad")
            || str_contains($normalized, 'what is wrong')
            || str_contains($normalized, "what's wrong")
        ) {
            return 'risk_today';
        }

        if (
            str_contains($normalized, 'status')
            || str_contains($normalized, 'going on')
            || str_contains($normalized, 'issue')
            || str_contains($normalized, 'problem')
            || str_contains($normalized, 'risk')
        ) {
            return 'fleet_status';
        }

        return 'general';
    }
}

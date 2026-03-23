<?php

namespace App\Services\AiPower;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NaturalLanguageCommandService
{
    /**
     * @param array<string,mixed> $plan
     * @return array{
     *   command:string,
     *   run_as:string,
     *   timeout_seconds:int,
     *   confidence:float,
     *   rationale:string,
     *   source:string
     * }|null
     */
    public function suggestPolicyCommand(string $instruction, array $plan = []): ?array
    {
        $fromOpenAi = $this->suggestPolicyCommandWithOpenAi($instruction, $plan);
        if (is_array($fromOpenAi)) {
            $normalized = $this->normalizePolicySuggestion($fromOpenAi, 'openai');
            if (trim((string) ($normalized['command'] ?? '')) !== '') {
                return $normalized;
            }
        }

        $fallback = $this->suggestPolicyCommandFallback($instruction, $plan);
        if (is_array($fallback)) {
            $normalized = $this->normalizePolicySuggestion($fallback, 'fallback');
            if (trim((string) ($normalized['command'] ?? '')) !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return array{
     *   ok:bool,
     *   errors:list<string>,
     *   warnings:list<string>,
     *   score:float,
     *   run_as:string,
     *   timeout_seconds:int
     * }
     */
    public function testPolicyCommand(
        string $command,
        string $runAs = 'system',
        int $timeoutSeconds = 300,
        string $instruction = ''
    ): array {
        $errors = [];
        $warnings = [];

        $command = trim($command);
        $runAs = mb_strtolower(trim($runAs));
        if (! in_array($runAs, ['default', 'elevated', 'system'], true)) {
            $errors[] = 'run_as must be default, elevated, or system.';
            $runAs = 'system';
        }

        $timeoutSeconds = max(30, min(3600, (int) $timeoutSeconds));
        if ($command === '') {
            $errors[] = 'Command cannot be empty.';
        }
        if (mb_strlen($command) > 12000) {
            $errors[] = 'Command is too long (max 12000 characters).';
        }

        $dangerPatterns = [
            '/\bformat\s+[a-z]:/i' => 'Formatting disks is not allowed in policy command preflight.',
            '/\brd\s+\/s\b/i' => 'Recursive folder deletion is blocked in policy command preflight.',
            '/\bdel\s+\/s\b/i' => 'Recursive file deletion is blocked in policy command preflight.',
            '/\brm\s+-rf\s+\//i' => 'Root recursive deletion is blocked in policy command preflight.',
            '/\bdiskpart\b/i' => 'Disk partitioning commands are blocked in policy command preflight.',
            '/\bbcdedit\s+\/delete\b/i' => 'Boot configuration delete commands are blocked in policy command preflight.',
        ];
        foreach ($dangerPatterns as $pattern => $message) {
            if (preg_match($pattern, $command) === 1) {
                $errors[] = $message;
            }
        }

        $singleQuotes = substr_count($command, "'");
        $doubleQuotes = substr_count($command, '"');
        if ($singleQuotes % 2 !== 0 || $doubleQuotes % 2 !== 0) {
            $warnings[] = 'Command may have unbalanced quotes.';
        }
        if (preg_match('/\b(reg add|powershell|cmd\.exe|gpupdate|sc\s+config|schtasks)\b/i', $command) !== 1) {
            $warnings[] = 'Command does not look like a standard managed endpoint operation.';
        }

        $score = 1.0;
        $score -= min(0.7, count($errors) * 0.35);
        $score -= min(0.25, count($warnings) * 0.05);
        $score = max(0.0, min(1.0, $score));

        $aiReview = $this->reviewPolicyCommandWithOpenAi($command, $instruction);
        if (is_array($aiReview)) {
            $aiPass = (bool) ($aiReview['pass'] ?? true);
            $aiConfidence = max(0.0, min(1.0, (float) ($aiReview['confidence'] ?? 0.0)));
            $aiErrors = is_array($aiReview['errors'] ?? null) ? $aiReview['errors'] : [];
            $aiWarnings = is_array($aiReview['warnings'] ?? null) ? $aiReview['warnings'] : [];

            foreach ($aiWarnings as $warning) {
                $w = trim((string) $warning);
                if ($w !== '') {
                    $warnings[] = mb_substr($w, 0, 200);
                }
            }

            if (! $aiPass && $aiConfidence >= 0.70) {
                foreach ($aiErrors as $error) {
                    $e = trim((string) $error);
                    if ($e !== '') {
                        $errors[] = mb_substr($e, 0, 200);
                    }
                }
                if ($aiErrors === []) {
                    $errors[] = 'AI review flagged this policy command as unsafe or invalid.';
                }
            }
        }

        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));
        $score = max(0.0, min(1.0, $score - min(0.3, count($errors) * 0.1)));

        return [
            'ok' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'score' => round($score, 4),
            'run_as' => $runAs,
            'timeout_seconds' => $timeoutSeconds,
        ];
    }

    /**
     * @return array{
     *   intent:string,
     *   target_type:string,
     *   target_query:string,
     *   policy_name:string,
     *   policy_query:string,
     *   policy_category:string,
     *   policy_command:string,
     *   script:string,
     *   run_as:string,
     *   timeout_seconds:int,
     *   priority:int,
     *   confidence:float,
     *   rationale:string,
     *   command_slug:string,
     *   risk_level:string,
     *   requires_approval:bool,
     *   rollback_command:string,
     *   catalog_version:string,
     *   source:string
     * }
     */
    public function interpret(string $instruction): array
    {
        $instruction = trim($instruction);
        if ($instruction === '') {
            return $this->unknown('Instruction is empty.', 'fallback');
        }

        $lower = mb_strtolower($instruction);
        $openAi = $this->interpretWithOpenAi($instruction);
        if (is_array($openAi)) {
            $normalized = $this->normalize($openAi, 'openai');
            $fallback = $this->interpretFallback($instruction);
            if ($this->shouldPreferFallbackSoftwareUninstallPlan($instruction, $normalized, $fallback)) {
                return $fallback;
            }
            if (
                (string) ($normalized['intent'] ?? 'unknown') === 'project_inventory'
                && (string) ($fallback['intent'] ?? 'unknown') === 'ai_query'
                && (
                    $this->looksLikeDeviceListQuery($lower)
                    || $this->looksLikeGroupScopedQuery($instruction, $lower)
                    || preg_match('/\b(how\s+many\s+devices?\s+are\s+online|online\s+devices?|available\s+devices?|devices?\s+available)\b/u', $lower) === 1
                )
            ) {
                return $fallback;
            }
            if ($this->shouldPreferFallbackAnalyticsQuery($instruction, $normalized, $fallback)) {
                return $fallback;
            }
            if ($this->shouldPreferFallbackFleetIpQuery($instruction, $normalized, $fallback)) {
                return $fallback;
            }
            if ($this->shouldPreferFallbackPlan($normalized, $fallback)) {
                return $fallback;
            }
            if ($this->shouldPreferFallbackDeviceLookup($instruction, $normalized)) {
                return $fallback;
            }
            if ((string) ($normalized['intent'] ?? 'unknown') !== 'unknown') {
                return $normalized;
            }

            if ((string) ($fallback['intent'] ?? 'unknown') !== 'unknown') {
                return $fallback;
            }

            return $normalized;
        }

        return $this->interpretFallback($instruction);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function interpretWithOpenAi(string $instruction): ?array
    {
        $apiKey = trim((string) config('services.openai.api_key', ''));
        $enabled = (bool) config('services.openai.ai_power_enabled', true);
        if ($apiKey === '' || ! $enabled) {
            return null;
        }

        $baseUrl = trim((string) config('services.openai.base_url', 'https://api.openai.com/v1'));
        $model = trim((string) config('services.openai.ai_power_model', config('services.openai.model', 'gpt-4o-mini')));
        $timeout = max(5, min(30, (int) config('services.openai.ai_power_timeout_seconds', config('services.openai.timeout_seconds', 12))));
        $promptVersion = trim((string) config('services.openai.ai_power_prompt_version', 'v2'));
        $promptExtra = trim((string) config('services.openai.ai_power_prompt_extra', ''));

        $prompt = implode("\n", [
            'Convert user instruction into strict JSON.',
            'Prompt version: '.$promptVersion,
            'Allowed intents: reboot_device, run_command_device, uninstall_agent_device, create_policy, apply_policy, get_device_status, project_inventory, ai_query, unknown.',
            'Role:',
            '- You are the AI planner for an enterprise device management and security platform.',
            '- Parse natural language robustly, including typos, short forms, and conversational phrasing.',
            'Intent routing rules:',
            '- reboot_device: explicit restart/reboot of a device/group/all devices.',
            '- run_command_device: explicit command/script/diagnostic/service/process/software operation.',
            '- uninstall_agent_device: explicit uninstall/remove agent.',
            '- create_policy: create/new/make policy requests, including when policy command must be inferred from requirement text.',
            '- apply_policy: assign/apply existing policy to a device/group/all devices.',
            '- get_device_status: single-device status/state/health/last-seen/IP lookup.',
            '- project_inventory: requests to list/show all functions, routes, values, inventory, or system capabilities.',
            '- ai_query: analytics/reporting/intelligence questions (health, anomaly, security, software, patching, network, user behavior, compliance, incident/root-cause, recommendation, trend/report).',
            '- unknown: only when intent is genuinely unclear after best effort.',
            'Disambiguation rules:',
            '- Questions like "Which devices need restart?" are ai_query (analytics), not reboot execution.',
            '- Questions about one named host IP/status must be get_device_status with target_query.',
            '- Broad/summarized questions across many devices should be ai_query.',
            '- Read-only inventory/analytics questions (for example: "List installed software on HOST") must be ai_query, not run_command_device.',
            '- If user asks to "create policy disable usb", keep create_policy even if explicit command text is missing.',
            'Target extraction rules:',
            '- target_type should be device or group when target exists.',
            '- target_query should be exact hostname/group name/id when present.',
            '- If scope is all/every/all connected devices, use target_type=group and target_query=all.',
            '- If no explicit target exists for analytics, keep target_query empty.',
            '- Never emit placeholder target values like "-", "unknown", "n/a", or "none". Use empty string instead.',
            'Payload rules:',
            '- For reboot_device/uninstall_agent_device/get_device_status/project_inventory/ai_query, script must be empty string.',
            '- For run_command_device, provide script when possible; otherwise leave script empty and lower confidence.',
            '- For create_policy, include policy_name and policy_command when clearly available; policy_command may be empty only if user gave requirement without command text.',
            '- For apply_policy, include policy_query and target_type/target_query.',
            '- policy_category should default to operations/ai-power when unspecified.',
            '- run_as must be default|elevated|system.',
            '- timeout_seconds must be 30..3600.',
            '- priority must be 1..1000.',
            '- confidence must be 0..1.',
            '- command_slug should be a short snake_case operation label when known.',
            '- risk_level must be low|medium|high. Set high for security-impacting/destructive actions.',
            '- requires_approval must be true for high-risk actions.',
            '- rollback_command should be provided when a known safe rollback exists.',
            'Confidence rubric:',
            '- 0.90-1.00: explicit action and clear target.',
            '- 0.70-0.89: intent clear, minor ambiguity.',
            '- 0.40-0.69: partially inferred with missing explicit details.',
            '- 0.00-0.39: unclear/unsafe to execute.',
            'Output keys only:',
            'intent, target_type, target_query, policy_name, policy_query, policy_category, policy_command, script, run_as, timeout_seconds, priority, confidence, rationale, command_slug, risk_level, requires_approval, rollback_command.',
            'No markdown, no extra keys.',
            'Instruction:',
            $instruction,
        ]);
        if ($promptExtra !== '') {
            $prompt .= "\n".'Additional planner instructions:'."\n".$promptExtra;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->post(rtrim($baseUrl, '/').'/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a deterministic command planner for endpoint operations (prompt '.$promptVersion.'). Output only valid JSON for safe execution planning.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('AI Power parser request failed.', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);

                return null;
            }

            $raw = data_get($response->json(), 'choices.0.message.content');
            if (is_array($raw)) {
                $raw = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (! is_string($raw) || trim($raw) === '') {
                return null;
            }

            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Log::warning('AI Power parser exception.', [
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        }
    }

    /**
     * @return array{
     *   intent:string,
     *   target_type:string,
     *   target_query:string,
     *   policy_name:string,
     *   policy_query:string,
     *   policy_category:string,
     *   policy_command:string,
     *   script:string,
     *   run_as:string,
     *   timeout_seconds:int,
     *   priority:int,
     *   confidence:float,
     *   rationale:string,
     *   source:string
     * }
     */
    private function interpretFallback(string $instruction): array
    {
        $lower = mb_strtolower($instruction);
        $intent = 'unknown';
        if (str_contains($lower, 'uninstall agent') || str_contains($lower, 'remove agent')) {
            $intent = 'uninstall_agent_device';
        } elseif ($this->looksLikeRunCommandIntent($lower)) {
            $intent = 'run_command_device';
        } elseif (str_contains($lower, 'reboot') || str_contains($lower, 'restart') || str_contains($lower, 'restrt') || str_contains($lower, 'restar')) {
            $intent = $this->isRebootAnalyticsQuestion($instruction, $lower) ? 'ai_query' : 'reboot_device';
        } elseif (
            str_contains($lower, 'create policy')
            || str_contains($lower, 'create a policy')
            || str_contains($lower, 'new policy')
            || str_contains($lower, 'make policy')
            || str_contains($lower, 'make a policy')
        ) {
            $intent = 'create_policy';
        } elseif (
            str_contains($lower, 'apply policy')
            || str_contains($lower, 'apply a policy')
            || str_contains($lower, 'assign policy')
            || str_contains($lower, 'assign a policy')
        ) {
            $intent = 'apply_policy';
        } elseif ($this->looksLikeGroupScopedQuery($instruction, $lower)) {
            $intent = 'ai_query';
        } elseif ($this->looksLikeFleetIpListQuery($instruction, $lower)) {
            $intent = 'ai_query';
        } elseif (
            $this->looksLikeDeviceListQuery($lower)
        ) {
            $intent = 'ai_query';
        } elseif (
            str_contains($lower, 'what can you do')
            || str_contains($lower, 'capabilities')
            || str_contains($lower, 'all function')
            || str_contains($lower, 'all functions')
            || str_contains($lower, 'all value')
            || str_contains($lower, 'all values')
            || str_contains($lower, 'project values')
            || str_contains($lower, 'project inventory')
            || str_contains($lower, 'access all function')
        ) {
            $intent = 'project_inventory';
        } elseif ($this->looksLikeStatusLookup($instruction, $lower)) {
            $intent = 'get_device_status';
        } elseif ($this->looksLikeAiQuery($lower)) {
            $intent = 'ai_query';
        }

        $script = '';
        $runAs = 'system';
        $timeoutSeconds = 300;
        $confidence = 0.45;
        $rationale = 'Parsed by fallback heuristic parser.';
        $commandSlug = '';
        $riskLevel = '';
        $requiresApproval = false;
        $rollbackCommand = '';
        if ($intent === 'run_command_device') {
            if (preg_match('/["\'](.+?)["\']/', $instruction, $match) === 1) {
                $script = trim((string) ($match[1] ?? ''));
            } elseif (preg_match('/run\s+(.+?)\s+on\s+(?:device|host|hostname)\s+([a-z0-9._\-]+)/i', $instruction, $match) === 1) {
                $script = trim((string) ($match[1] ?? ''));
            }
            if ($script === '') {
                $template = $this->inferRunCommandTemplate($instruction, $lower);
                if (is_array($template)) {
                    $script = trim((string) ($template['script'] ?? ''));
                    $runAs = trim((string) ($template['run_as'] ?? 'system')) !== '' ? (string) $template['run_as'] : 'system';
                    $timeoutSeconds = (int) ($template['timeout_seconds'] ?? 300);
                    $confidence = max(0.0, min(1.0, (float) ($template['confidence'] ?? 0.45)));
                    $rationale = trim((string) ($template['rationale'] ?? 'Parsed by fallback heuristic parser.'));
                    $commandSlug = trim((string) ($template['command_slug'] ?? ''));
                    $riskLevel = trim((string) ($template['risk_level'] ?? ''));
                    $requiresApproval = (bool) ($template['requires_approval'] ?? false);
                    $rollbackCommand = trim((string) ($template['rollback_command'] ?? ''));
                }
            } elseif ($script !== '') {
                $confidence = 0.76;
                $rationale = 'Detected explicit command payload in instruction.';
            }
        }

        $policyName = '';
        if (preg_match('/(?:create|new|make)\s+(?:a\s+)?policy\s+["\']?([^"\']{2,120})["\']?/i', $instruction, $match) === 1) {
            $policyName = trim((string) ($match[1] ?? ''));
        }

        $policyQuery = '';
        if (preg_match('/(?:apply|assign)\s+(?:a\s+)?policy\s+["\']?([^"\']{2,120})["\']?/i', $instruction, $match) === 1) {
            $policyQuery = trim((string) ($match[1] ?? ''));
        }
        if ($policyName !== '' && $policyQuery === '') {
            $policyQuery = $policyName;
        }

        $policyCommand = '';
        if (preg_match('/(?:policy\s+command|command)\s+["\'](.+?)["\']/i', $instruction, $match) === 1) {
            $policyCommand = trim((string) ($match[1] ?? ''));
        } elseif ($intent === 'create_policy' && preg_match('/["\'](.+?)["\']/', $instruction, $match) === 1) {
            $policyCommand = trim((string) ($match[1] ?? ''));
        }

        $targetType = 'device';
        if (preg_match('/\bgroup\b/i', $instruction) === 1) {
            $targetType = 'group';
        }

        $targetQuery = '';
        if (preg_match('/\bgroup\s+["\']([^"\']{2,120})["\']/i', $instruction, $match) === 1) {
            $targetType = 'group';
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (
            $targetQuery === ''
            && $intent === 'ai_query'
            && preg_match('/^\s*(?:show|list|display|give|tell|which|what(?:\s+are)?)\s+(?:all\s+)?([a-z0-9][a-z0-9._\-\s]{1,80}?)\s+group\s+(?:devices?|machines?|hosts?|pcs?|computers?)\b/i', $instruction, $match) === 1
        ) {
            $targetType = 'group';
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (
            $targetQuery === ''
            && $intent === 'ai_query'
            && preg_match('/^\s*(?:all\s+)?([a-z0-9][a-z0-9._\-\s]{1,80}?)\s+group\s+(?:devices?|machines?|hosts?|pcs?|computers?)\b/i', $instruction, $match) === 1
        ) {
            $targetType = 'group';
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (
            $targetQuery === ''
            && $intent === 'ai_query'
            && preg_match('/\bin\s+(?:the\s+)?([a-z0-9][a-z0-9._\-\s]{1,80})\s+group\b/i', $instruction, $match) === 1
        ) {
            $targetType = 'group';
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (
            $targetQuery === ''
            && $intent === 'ai_query'
            && preg_match('/\bhow\s+many\b.*\bdevices?\b.*\bin\s+(?:the\s+)?([a-z0-9][a-z0-9._\-\s]{1,80})(?:[?.!]|$)/i', $instruction, $match) === 1
        ) {
            $targetType = 'group';
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (preg_match('/(?:device|host|hostname|group)\s+(?!has\b|have\b|is\b|are\b|the\b|a\b|an\b|this\b|that\b)([a-z0-9._\-]{2,}|[a-f0-9\-]{36})/i', $instruction, $match) === 1) {
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (
            $intent === 'get_device_status'
            && preg_match('/(?:status|state|health|last\s*seen|ip(?:\s*address)?)\s+(?:of|for)\s+([a-z0-9._\-]{2,}|[a-f0-9\-]{36})/i', $instruction, $match) === 1
        ) {
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (
            $intent === 'get_device_status'
            && preg_match('/(?:for|of)\s+([a-z0-9._\-]{2,}|[a-f0-9\-]{36})\s*$/i', $instruction, $match) === 1
        ) {
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (
            $intent === 'get_device_status'
            && preg_match('/\b((?:\d{1,3}\.){2,3}\d{1,3})\b/', $instruction, $match) === 1
        ) {
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (
            $intent === 'get_device_status'
            && preg_match('/\b([a-z0-9._\-]*[-][a-z0-9._\-]{2,}|[a-z0-9._\-]*\d[a-z0-9._\-]*)\b.*\b(network\s*(?:ip|interface|adapter)|interface|adapter|ip(?:\s*address)?|mac(?:\s*address)?)\b/i', $instruction, $match) === 1
        ) {
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (preg_match('/\b([a-f0-9]{8}\-[a-f0-9]{4}\-[a-f0-9]{4}\-[a-f0-9]{4}\-[a-f0-9]{12})\b/i', $instruction, $match) === 1) {
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (
            $targetQuery === ''
            && in_array($intent, ['reboot_device', 'uninstall_agent_device', 'run_command_device', 'get_device_status'], true)
            && preg_match('/(?:\b(?:reboot|restart|uninstall|remove|run)\b)\s+([a-z0-9._\-]{2,}|[a-f0-9\-]{36})\s*$/i', $instruction, $match) === 1
        ) {
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (
            $targetQuery === ''
            && preg_match('/\b(all|every|everyone)\s+(?:connected\s+)?(?:device|devices|machines|hosts|pcs?|computers)\b/i', $instruction) === 1
        ) {
            $targetType = 'group';
            $targetQuery = 'all';
        } elseif (
            $targetQuery === ''
            && preg_match('/(?:on|to|for)\s+all\s+([a-z0-9._\-]{2,})\s+(?:devices|machines|hosts|pcs?|computers)\b/i', $instruction, $match) === 1
        ) {
            $targetType = 'group';
            $targetQuery = trim((string) ($match[1] ?? ''));
        } elseif (
            $targetQuery === ''
            && preg_match('/(?:on|to|for)\s+all\s+([a-z0-9][a-z0-9._\-\s]{1,80}?)\s+(?:devices|machines|hosts|pcs?|computers)\b/i', $instruction, $match) === 1
        ) {
            $targetType = 'group';
            $targetQuery = trim((string) ($match[1] ?? ''));
        }
        if ($targetType === 'group' && $targetQuery !== '') {
            $targetQuery = trim((string) preg_replace('/\s+/', ' ', $targetQuery));
            $targetQuery = trim((string) preg_replace('/\b(?:group|devices?|machines?|hosts?|pcs?|computers?)\b.*$/i', '', $targetQuery));
            $targetQuery = trim($targetQuery, " \t\n\r\0\x0B\"'");
        }

        if ($intent === 'unknown') {
            return $this->unknown('Could not confidently parse instruction.', 'fallback');
        }

        return $this->normalize([
            'intent' => $intent,
            'target_type' => $targetType,
            'target_query' => $targetQuery,
            'policy_name' => $policyName,
            'policy_query' => $policyQuery,
            'policy_category' => 'operations/ai-power',
            'policy_command' => $policyCommand,
            'script' => $script,
            'run_as' => $runAs,
            'timeout_seconds' => $timeoutSeconds,
            'priority' => 100,
            'confidence' => $confidence,
            'rationale' => $rationale,
            'command_slug' => $commandSlug,
            'risk_level' => $riskLevel,
            'requires_approval' => $requiresApproval,
            'rollback_command' => $rollbackCommand,
        ], 'fallback');
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{
     *   intent:string,
     *   target_type:string,
     *   target_query:string,
     *   policy_name:string,
     *   policy_query:string,
     *   policy_category:string,
     *   policy_command:string,
     *   script:string,
     *   run_as:string,
     *   timeout_seconds:int,
     *   priority:int,
     *   confidence:float,
     *   rationale:string,
     *   command_slug:string,
     *   risk_level:string,
     *   requires_approval:bool,
     *   rollback_command:string,
     *   catalog_version:string,
     *   source:string
     * }
     */
    private function normalize(array $decoded, string $source): array
    {
        $intent = mb_strtolower(trim((string) ($decoded['intent'] ?? 'unknown')));
        if (! in_array($intent, ['reboot_device', 'run_command_device', 'uninstall_agent_device', 'create_policy', 'apply_policy', 'get_device_status', 'project_inventory', 'ai_query', 'unknown'], true)) {
            $intent = 'unknown';
        }

        $targetType = mb_strtolower(trim((string) ($decoded['target_type'] ?? 'device')));
        if (! in_array($targetType, ['device', 'group'], true)) {
            $targetType = 'device';
        }
        $targetQuery = trim((string) ($decoded['target_query'] ?? ''));
        if ($this->isPlaceholderToken($targetQuery)) {
            $targetQuery = '';
        }
        $policyName = trim((string) ($decoded['policy_name'] ?? ''));
        if ($this->isPlaceholderToken($policyName)) {
            $policyName = '';
        }
        $policyQuery = trim((string) ($decoded['policy_query'] ?? ''));
        if ($this->isPlaceholderToken($policyQuery)) {
            $policyQuery = '';
        }
        $policyCategory = trim((string) ($decoded['policy_category'] ?? 'operations/ai-power'));
        if ($policyCategory === '') {
            $policyCategory = 'operations/ai-power';
        }
        $policyCommand = trim((string) ($decoded['policy_command'] ?? ''));
        $script = trim((string) ($decoded['script'] ?? ''));
        $runAs = mb_strtolower(trim((string) ($decoded['run_as'] ?? 'default')));
        if (! in_array($runAs, ['default', 'elevated', 'system'], true)) {
            $runAs = 'default';
        }

        $timeoutSeconds = (int) ($decoded['timeout_seconds'] ?? 300);
        $timeoutSeconds = max(30, min(3600, $timeoutSeconds));

        $priority = (int) ($decoded['priority'] ?? 100);
        $priority = max(1, min(1000, $priority));

        $rationale = trim((string) ($decoded['rationale'] ?? ''));
        $commandSlug = trim((string) ($decoded['command_slug'] ?? ''));
        if ($this->isPlaceholderToken($commandSlug)) {
            $commandSlug = '';
        }
        $riskLevel = mb_strtolower(trim((string) ($decoded['risk_level'] ?? '')));
        if (! in_array($riskLevel, ['low', 'medium', 'high'], true)) {
            $riskLevel = '';
        }
        $requiresApprovalProvided = array_key_exists('requires_approval', $decoded);
        $requiresApproval = $requiresApprovalProvided ? (bool) $decoded['requires_approval'] : false;
        $rollbackCommand = trim((string) ($decoded['rollback_command'] ?? ''));
        if ($this->isPlaceholderToken($rollbackCommand)) {
            $rollbackCommand = '';
        }

        if ($intent !== 'run_command_device') {
            $script = '';
        } else {
            $script = mb_substr($script, 0, 6000);
        }
        if ($intent !== 'create_policy') {
            $policyCommand = '';
        } else {
            $policyCommand = mb_substr($policyCommand, 0, 6000);
            if ($policyName === '') {
                $policyName = 'AI Policy '.now()->format('Ymd-His');
            }
            if ($policyQuery === '') {
                $policyQuery = $policyName;
            }
        }
        if ($intent !== 'create_policy' && $intent !== 'apply_policy') {
            $policyName = '';
            $policyQuery = '';
        }

        $riskProfile = $this->intentRiskProfile($intent);
        if ($riskLevel === '') {
            $riskLevel = (string) ($riskProfile['risk_level'] ?? 'low');
        }
        if (! $requiresApprovalProvided) {
            $requiresApproval = (bool) ($riskProfile['requires_approval'] ?? false);
        }
        if ($rollbackCommand === '') {
            $rollbackCommand = (string) ($riskProfile['rollback_command'] ?? '');
        }
        if ($commandSlug === '') {
            $commandSlug = match ($intent) {
                'run_command_device' => 'run_command',
                'reboot_device' => 'reboot_device',
                'uninstall_agent_device' => 'uninstall_agent',
                'create_policy' => 'create_policy',
                'apply_policy' => 'apply_policy',
                'get_device_status' => 'get_device_status',
                'project_inventory' => 'project_inventory',
                'ai_query' => 'ai_query',
                default => '',
            };
        }

        $riskCommandText = $intent === 'run_command_device' ? $script : ($intent === 'create_policy' ? $policyCommand : '');
        if ($riskCommandText !== '' && $this->matchesHighRiskCommandPattern($riskCommandText)) {
            $riskLevel = 'high';
            $requiresApproval = true;
        }

        if (array_key_exists('confidence', $decoded) && is_numeric($decoded['confidence'])) {
            $confidence = (float) $decoded['confidence'];
            $confidence = max(0.0, min(1.0, $confidence));
        } else {
            $confidence = $this->inferConfidence(
                $intent,
                $targetQuery,
                $script,
                $policyName,
                $policyQuery,
                $policyCommand
            );
        }

        return [
            'intent' => $intent,
            'target_type' => $targetType,
            'target_query' => $targetQuery,
            'policy_name' => mb_substr($policyName, 0, 120),
            'policy_query' => mb_substr($policyQuery, 0, 120),
            'policy_category' => mb_substr($policyCategory, 0, 100),
            'policy_command' => $policyCommand,
            'script' => $script,
            'run_as' => $runAs,
            'timeout_seconds' => $timeoutSeconds,
            'priority' => $priority,
            'confidence' => round($confidence, 4),
            'rationale' => mb_substr($rationale, 0, 500),
            'command_slug' => mb_substr($commandSlug, 0, 120),
            'risk_level' => $riskLevel,
            'requires_approval' => $requiresApproval,
            'rollback_command' => mb_substr($rollbackCommand, 0, 180),
            'catalog_version' => (string) config('dms_commands.version', 'v1'),
            'source' => $source,
        ];
    }

    private function inferConfidence(
        string $intent,
        string $targetQuery,
        string $script,
        string $policyName,
        string $policyQuery,
        string $policyCommand
    ): float {
        $targetPresent = trim($targetQuery) !== '';
        $scriptPresent = trim($script) !== '';
        $policyNamePresent = trim($policyName) !== '';
        $policyQueryPresent = trim($policyQuery) !== '';
        $policyCommandPresent = trim($policyCommand) !== '';

        return match ($intent) {
            'reboot_device', 'uninstall_agent_device' => $targetPresent ? 0.72 : 0.20,
            'run_command_device' => ($targetPresent && $scriptPresent) ? 0.78 : 0.22,
            'create_policy' => ($policyCommandPresent && $policyNamePresent) ? 0.76 : 0.24,
            'apply_policy' => ($targetPresent && $policyQueryPresent) ? 0.75 : 0.23,
            'get_device_status' => $targetPresent ? 0.88 : 0.22,
            'project_inventory' => 0.95,
            'ai_query' => 0.88,
            default => 0.0,
        };
    }

    private function looksLikeStatusLookup(string $instruction, string $lower): bool
    {
        if ($this->looksLikeFleetIpListQuery($instruction, $lower)) {
            return false;
        }

        if (
            str_contains($lower, 'status')
            || str_contains($lower, 'state')
            || str_contains($lower, 'last seen')
            || preg_match('/\bip(?:\s*address)?\b/u', $lower) === 1
            || preg_match('/\b(network\s*(?:ip|interface|adapter)|interface|adapter|mac(?:\s*address)?)\b/u', $lower) === 1
        ) {
            if (preg_match('/(?:device|host|hostname|computer)\s+(?!has\b|have\b|is\b|are\b|the\b|a\b|an\b|this\b|that\b|ip\b|status\b|state\b|health\b|network\b|interface\b|adapter\b|mac\b)[a-z0-9._\-]{2,}/i', $instruction) === 1) {
                return true;
            }
            if (preg_match('/(?:status|state|health|last\s*seen|ip(?:\s*address)?)\s+(?:of|for)\s+[a-z0-9._\-]{2,}/i', $instruction) === 1) {
                return true;
            }
            if (preg_match('/\b(?:what(?:\s+is)?|show|tell)\b.*\bip(?:\s*address)?\b.*\b(?:of|for)\s+[a-z0-9._\-]{2,}/i', $instruction) === 1) {
                return true;
            }
            if (preg_match('/\b(?:whatis|what\'?s|what\s*is)\b.*\b(?:status|state|health|ip(?:\s*address)?)\b.*\b(?:of|for)\s+[a-z0-9._\-]{2,}/i', $instruction) === 1) {
                return true;
            }
            if (preg_match('/\b(?:whatis|what\'?s|what\s*is)\s+the\s+ip(?:\s*address)?\s+(?:for|of)?\s*[a-z0-9._\-]{2,}\b/i', $instruction) === 1) {
                return true;
            }
            if (preg_match('/\b(?:find|which|whic|show|who|what(?:\s+is)?)\b.*\bip(?:\s*address)?\b.*\b\d{1,3}(?:\.\d{1,3}){2,3}\b/i', $instruction) === 1) {
                return true;
            }
            if (preg_match('/\b([a-z0-9._\-]*[-][a-z0-9._\-]{2,}|[a-z0-9._\-]*\d[a-z0-9._\-]*)\b.*\b(network\s*(?:ip|interface|adapter)|interface|adapter|ip(?:\s*address)?|mac(?:\s*address)?)\b/i', $instruction) === 1) {
                return true;
            }
            if (preg_match('/\b[a-f0-9]{8}\-[a-f0-9]{4}\-[a-f0-9]{4}\-[a-f0-9]{4}\-[a-f0-9]{12}\b/i', $instruction) === 1) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeFleetIpListQuery(string $instruction, string $lower): bool
    {
        if (preg_match('/\b(ip|ip address|network ip)\b/u', $lower) !== 1) {
            return false;
        }

        if (preg_match('/\b(all|active|online|connected|fleet|devices|machines|computers|hosts)\b/u', $lower) !== 1) {
            return false;
        }

        $hasExplicitHost = $this->hasExplicitHostCandidate($instruction);
        if (
            $hasExplicitHost
            && preg_match('/\b(all\s+devices|all\s+machines|all\s+computers|all\s+hosts|fleet)\b/u', $lower) !== 1
        ) {
            return false;
        }

        if (preg_match('/\b(?:of|for)\s+[a-z0-9._\-]{2,}\b/u', $lower) === 1) {
            return false;
        }

        if (preg_match('/\b(?:device|host|hostname|computer)\s+(?!all\b|active\b|online\b|connected\b|devices?\b|machines?\b|computers?\b|hosts?\b|ip\b)[a-z0-9._\-]{2,}/i', $instruction) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $normalized
     */
    private function shouldPreferFallbackDeviceLookup(string $instruction, array $normalized): bool
    {
        $intent = (string) ($normalized['intent'] ?? 'unknown');
        if ($intent !== 'ai_query') {
            return false;
        }

        $targetQuery = trim((string) ($normalized['target_query'] ?? ''));
        if ($targetQuery !== '' && ! $this->isPlaceholderToken($targetQuery)) {
            return false;
        }

        $lower = mb_strtolower($instruction);
        if (! $this->looksLikeStatusLookup($instruction, $lower)) {
            return false;
        }

        $hasExplicitHost = $this->hasExplicitHostCandidate($instruction);
        if (! $hasExplicitHost && preg_match('/\b(all|devices|fleet|summary|trend|trends|changed|anomal(?:y|ies))\b/u', $lower) === 1) {
            return false;
        }

        return preg_match('/\b(?:of|for)\s+[a-z0-9._\-]{2,}\b/u', $lower) === 1
            || preg_match('/\b(?:device|host|hostname|computer)\s+[a-z0-9._\-]{2,}\b/u', $lower) === 1
            || $hasExplicitHost;
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $fallback
     */
    private function shouldPreferFallbackFleetIpQuery(string $instruction, array $normalized, array $fallback): bool
    {
        if (! $this->looksLikeFleetIpListQuery($instruction, mb_strtolower($instruction))) {
            return false;
        }

        $normalizedIntent = (string) ($normalized['intent'] ?? 'unknown');
        $fallbackIntent = (string) ($fallback['intent'] ?? 'unknown');

        if ($normalizedIntent !== 'get_device_status') {
            return false;
        }

        return $fallbackIntent === 'ai_query';
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $fallback
     */
    private function shouldPreferFallbackAnalyticsQuery(string $instruction, array $normalized, array $fallback): bool
    {
        $normalizedIntent = (string) ($normalized['intent'] ?? 'unknown');
        $fallbackIntent = (string) ($fallback['intent'] ?? 'unknown');
        if ($normalizedIntent !== 'run_command_device' || $fallbackIntent !== 'ai_query') {
            return false;
        }

        $lower = mb_strtolower($instruction);
        $hasExecutionVerb = preg_match('/\b(reboot|restart|uninstall|remove\s+agent|install\s+(?!ed)|apply\s+policy|assign\s+policy|run\s+command|execute|shutdown|shut\s+down|kill|clear\s+temp|restart\s+service)\b/u', $lower) === 1;
        if ($hasExecutionVerb) {
            return false;
        }

        $looksQuestion = preg_match('/^\s*(which|what|show|list|who|why|how)\b/u', $lower) === 1
            || str_ends_with(trim($instruction), '?');
        $hasAnalyticsCue = preg_match('/\b(installed software|software inventory|outdated software|recently installed|failed updates|missing updates|patch compliance|compliance|offline|high cpu|memory|disk|failed login|status|summary|report|anomal|security)\b/u', $lower) === 1;
        if (! $looksQuestion && ! $hasAnalyticsCue) {
            return false;
        }

        $script = trim((string) ($normalized['script'] ?? ''));
        if ($script === '') {
            return true;
        }

        return $looksQuestion && $hasAnalyticsCue;
    }

    private function hasExplicitHostCandidate(string $instruction): bool
    {
        return preg_match('/\b([a-z0-9._\-]*[-][a-z0-9._\-]{2,}|[a-z0-9._\-]*\d[a-z0-9._\-]*)\b/i', $instruction) === 1;
    }

    private function isPlaceholderToken(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['-', '--', 'n/a', 'na', 'none', 'null', 'nil', 'unknown', 'unspecified'], true);
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $fallback
     */
    private function shouldPreferFallbackPlan(array $normalized, array $fallback): bool
    {
        $normalizedIntent = (string) ($normalized['intent'] ?? 'unknown');
        $fallbackIntent = (string) ($fallback['intent'] ?? 'unknown');
        if (! in_array($normalizedIntent, ['ai_query', 'unknown'], true)) {
            return false;
        }
        if (! in_array($fallbackIntent, ['reboot_device', 'run_command_device', 'uninstall_agent_device', 'create_policy', 'apply_policy', 'get_device_status', 'project_inventory'], true)) {
            return false;
        }
        if ($fallbackIntent === 'run_command_device' && trim((string) ($fallback['script'] ?? '')) === '') {
            return false;
        }
        if (in_array($fallbackIntent, ['reboot_device', 'uninstall_agent_device', 'get_device_status'], true) && trim((string) ($fallback['target_query'] ?? '')) === '') {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $fallback
     */
    private function shouldPreferFallbackSoftwareUninstallPlan(string $instruction, array $normalized, array $fallback): bool
    {
        if ((string) ($normalized['intent'] ?? 'unknown') !== 'uninstall_agent_device') {
            return false;
        }

        $lower = mb_strtolower($instruction);
        if (preg_match('/\b(uninstall|remove)\b/u', $lower) !== 1 || preg_match('/\bagent\b/u', $lower) === 1) {
            return false;
        }

        $fallbackIntent = (string) ($fallback['intent'] ?? 'unknown');
        $fallbackScript = trim((string) ($fallback['script'] ?? ''));
        if ($fallbackIntent === 'run_command_device' && $fallbackScript !== '') {
            return true;
        }

        return preg_match('/\b(software|application|app|package|program|winget|msi|exe)\b/u', $lower) === 1;
    }

    private function isRebootAnalyticsQuestion(string $instruction, string $lower): bool
    {
        $startsAsQuestion = preg_match('/^\s*(which|what|why|show|give|are|is|who|how)\b/i', $instruction) === 1;
        $hasAnalyticHint = str_contains($lower, 'need a restart')
            || str_contains($lower, 'need restart')
            || str_contains($lower, 'systems need')
            || str_contains($lower, 'devices need')
            || str_contains($lower, 'which systems')
            || str_contains($lower, 'which devices');
        $hasExplicitExecutionHint = preg_match('/\b(reboot|restart|restrt)\s+(this|device|host|hostname|all|every|group)\b/i', $instruction) === 1
            || preg_match('/\b(confirm|please|now|execute)\b/i', $instruction) === 1;

        return ($startsAsQuestion || $hasAnalyticHint) && ! $hasExplicitExecutionHint;
    }

    private function looksLikeRunCommandIntent(string $lower): bool
    {
        if (str_contains($lower, 'run command')
            || str_contains($lower, 'powershell')
            || str_contains($lower, 'cmd.exe')
            || str_contains($lower, 'restart service')
            || str_contains($lower, 'print service')
            || str_contains($lower, 'spooler')
            || str_contains($lower, 'clear temp')
            || str_contains($lower, 'diagnostic')
            || str_contains($lower, 'health scan')
            || str_contains($lower, 'security scan')
            || str_contains($lower, 'shut down')
            || str_contains($lower, 'shutdown')
            || str_contains($lower, 'kill process')
            || str_contains($lower, 'taskkill')
            || str_contains($lower, 'install chrome')
            || str_contains($lower, 'update all outdated')
            || str_contains($lower, 'winget')
        ) {
            return true;
        }
        if (
            preg_match('/\b(remove|uninstall)\b/u', $lower) === 1
            && preg_match('/\b(agent|policy)\b/u', $lower) !== 1
        ) {
            return true;
        }

        foreach ($this->runCommandTemplatesFromCatalog() as $template) {
            $phrases = is_array($template['phrases'] ?? null) ? $template['phrases'] : [];
            foreach ($phrases as $phrase) {
                $needle = mb_strtolower(trim((string) $phrase));
                if ($needle !== '' && str_contains($lower, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{
     *   script:string,
     *   run_as:string,
     *   timeout_seconds:int,
     *   confidence:float,
     *   rationale:string,
     *   command_slug?:string,
     *   risk_level?:string,
     *   requires_approval?:bool,
     *   rollback_command?:string
     * }|null
     */
    private function inferRunCommandTemplate(string $instruction, string $lower): ?array
    {
        if (
            preg_match('/\b(?:remove|uninstall)\b(?!\s+agent\b)\s+["\']?(.+?)["\']?(?:\s+(?:on|from|for)\b.*)?$/iu', trim($instruction), $match) === 1
        ) {
            $appName = trim((string) ($match[1] ?? ''));
            $appName = trim($appName, " \t\n\r\0\x0B\"'");
            if ($appName !== '') {
                $escaped = str_replace('"', '\"', $appName);

                return [
                    'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "winget uninstall --name \"'.$escaped.'\" --silent --accept-source-agreements"',
                    'run_as' => 'system',
                    'timeout_seconds' => 1200,
                    'confidence' => 0.72,
                    'rationale' => 'Mapped software removal request to winget uninstall command.',
                    'command_slug' => 'uninstall_software',
                    'risk_level' => 'medium',
                    'requires_approval' => false,
                    'rollback_command' => 'install_software',
                ];
            }
        }

        if (preg_match('/(?:kill|end|stop)\s+(?:the\s+)?process(?:\s+named)?\s+["\']?([a-z0-9_.-]{2,})["\']?/i', $instruction, $match) === 1) {
            $process = trim((string) ($match[1] ?? ''));
            if ($process !== '') {
                if (! str_contains($process, '.')) {
                    $process .= '.exe';
                }

                return [
                    'script' => 'taskkill /F /IM '.$process,
                    'run_as' => 'system',
                    'timeout_seconds' => 180,
                    'confidence' => 0.79,
                    'rationale' => 'Mapped process-termination request to taskkill command.',
                    'command_slug' => 'stop_process',
                    'risk_level' => 'medium',
                    'requires_approval' => false,
                    'rollback_command' => '',
                ];
            }
        }

        foreach ($this->runCommandTemplatesFromCatalog() as $template) {
            $phrases = is_array($template['phrases'] ?? null) ? $template['phrases'] : [];
            foreach ($phrases as $phrase) {
                $needle = mb_strtolower(trim((string) $phrase));
                if ($needle === '' || ! str_contains($lower, $needle)) {
                    continue;
                }

                return [
                    'script' => trim((string) ($template['script'] ?? '')),
                    'run_as' => trim((string) ($template['run_as'] ?? 'system')),
                    'timeout_seconds' => (int) ($template['timeout_seconds'] ?? 300),
                    'confidence' => (float) ($template['confidence'] ?? 0.70),
                    'rationale' => trim((string) ($template['rationale'] ?? 'Mapped request to command template.')),
                    'command_slug' => trim((string) ($template['slug'] ?? '')),
                    'risk_level' => trim((string) ($template['risk_level'] ?? '')),
                    'requires_approval' => (bool) ($template['requires_approval'] ?? false),
                    'rollback_command' => trim((string) ($template['rollback_command'] ?? '')),
                ];
            }
        }

        return null;
    }

    private function looksLikeAiQuery(string $lower): bool
    {
        return $this->looksLikeDeviceListQuery($lower)
            || preg_match('/\bhow\s+many\b.*\bdevices?\b.*\bin\b/u', $lower) === 1
            || preg_match('/\bhow\s+many\b.*\bdevices?\b.*\bonline\b/u', $lower) === 1
            || preg_match('/\bhow\s+many\b.*\bdevices?\b.*\boffline\b/u', $lower) === 1
            || preg_match('/^\s*in\s+[a-z0-9._\-\s]{2,}\s+group[?.! ]*$/u', $lower) === 1
            || str_contains($lower, 'unhealthy')
            || str_contains($lower, 'high cpu')
            || str_contains($lower, 'slow')
            || str_contains($lower, 'memory')
            || str_contains($lower, 'running out of memory')
            || str_contains($lower, 'low disk')
            || str_contains($lower, 'disk space')
            || str_contains($lower, 'checked in')
            || str_contains($lower, 'not checked in')
            || str_contains($lower, 'health summary')
            || str_contains($lower, 'restart')
            || str_contains($lower, 'crash')
            || str_contains($lower, 'overheating')
            || str_contains($lower, 'abnormal')
            || str_contains($lower, 'anomal')
            || str_contains($lower, 'unusual')
            || str_contains($lower, 'behaving differently')
            || str_contains($lower, 'behave differently')
            || str_contains($lower, 'different from others')
            || str_contains($lower, 'suspicious')
            || str_contains($lower, 'failed login')
            || str_contains($lower, 'admin account')
            || str_contains($lower, 'antivirus')
            || str_contains($lower, 'security risk')
            || str_contains($lower, 'malware')
            || str_contains($lower, 'usb storage')
            || str_contains($lower, 'remote access')
            || str_contains($lower, 'installed software')
            || str_contains($lower, 'outdated software')
            || str_contains($lower, 'unauthorized software')
            || str_contains($lower, 'failed software installation')
            || str_contains($lower, 'patch')
            || str_contains($lower, 'update')
            || str_contains($lower, 'critical patches')
            || str_contains($lower, 'update success rate')
            || str_contains($lower, 'offline')
            || str_contains($lower, 'network')
            || str_contains($lower, 'connectivity')
            || str_contains($lower, 'dns')
            || str_contains($lower, 'ip changed')
            || str_contains($lower, 'changed ip')
            || str_contains($lower, 'changed ip address')
            || str_contains($lower, 'login history')
            || str_contains($lower, 'logged in')
            || str_contains($lower, 'logged in today')
            || str_contains($lower, 'inactive users')
            || str_contains($lower, 'shared devices')
            || str_contains($lower, 'shared by multiple users')
            || str_contains($lower, 'multiple users')
            || str_contains($lower, 'session duration')
            || str_contains($lower, 'policy violation')
            || str_contains($lower, 'not compliant')
            || str_contains($lower, 'non compliant')
            || str_contains($lower, 'non-compliant')
            || str_contains($lower, 'noncompliant')
            || str_contains($lower, 'compliance')
            || str_contains($lower, 'compare policy')
            || str_contains($lower, 'departments')
            || str_contains($lower, 'bitlocker')
            || str_contains($lower, 'firewall')
            || str_contains($lower, 'root cause')
            || str_contains($lower, 'timeline')
            || str_contains($lower, 'recommend')
            || str_contains($lower, 'risk level')
            || str_contains($lower, 'critical')
            || str_contains($lower, 'issues')
            || str_contains($lower, 'report')
            || str_contains($lower, 'summary')
            || str_contains($lower, 'top issues')
            || str_contains($lower, 'executive')
            || str_contains($lower, 'predict')
            || str_contains($lower, 'hidden problems')
            || str_contains($lower, 'preventive maintenance')
            || str_contains($lower, 'worst devices')
            || str_contains($lower, 'urgent attention')
            || str_contains($lower, 'what changed yesterday')
            || str_contains($lower, 'anything i should worry')
            || str_contains($lower, 'are we safe');
    }

    private function looksLikeGroupScopedQuery(string $instruction, string $lower): bool
    {
        return preg_match('/^\s*in\s+[a-z0-9._\-\s]{2,}\s+group[?.! ]*$/u', $lower) === 1
            || preg_match('/\bhow\s+many\b.*\bdevices?\b.*\bin\b/u', $lower) === 1
            || preg_match('/\bdevices?\b.*\bin\s+[a-z0-9._\-\s]{2,}\s+group\b/u', $lower) === 1
            || preg_match('/\bhow\s+many\b.*\bdevices?\b.*\bgroup\b/u', $lower) === 1
            || preg_match('/\bgroup\b.*\bdevices?\b/u', $lower) === 1
            || preg_match('/\bdevices?\b.*\bgroup\b/u', $lower) === 1
            || preg_match('/^\s*(show|list|display)\s+(?!me\b|my\b|our\b|all\b)[a-z0-9][a-z0-9._\-\s]{1,80}\s+(devices?|machines?|hosts?|pcs?|computers?)\s*[?.! ]*$/u', $lower) === 1;
    }

    private function looksLikeDeviceListQuery(string $lower): bool
    {
        return str_contains($lower, 'device names')
            || str_contains($lower, 'device name')
            || str_contains($lower, 'all device name')
            || str_contains($lower, 'all devices name')
            || str_contains($lower, 'all device names')
            || str_contains($lower, 'all devices names')
            || str_contains($lower, 'list all devices')
            || str_contains($lower, 'show all devices')
            || str_contains($lower, 'how all device name')
            || str_contains($lower, 'show names')
            || str_contains($lower, 'list names')
            || str_contains($lower, 'device names')
            || str_contains($lower, 'available devices')
            || str_contains($lower, 'devices available')
            || str_contains($lower, 'show all available devices')
            || str_contains($lower, 'show all availiable devices')
            || str_contains($lower, 'what are the devices available')
            || str_contains($lower, 'what are devices available')
            || str_contains($lower, 'what are all device names');
    }

    private function isGenericGroupQualifier(string $value): bool
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, [
            'all',
            'all available',
            'available',
            'availiable',
            'me',
            'my',
            'our',
            'we',
            'us',
            'online',
            'offline',
            'connected',
            'active',
            'current',
            'current available',
            'the',
        ], true);
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>|null
     */
    private function suggestPolicyCommandWithOpenAi(string $instruction, array $plan): ?array
    {
        $apiKey = trim((string) config('services.openai.api_key', ''));
        $enabled = (bool) config('services.openai.ai_power_enabled', true);
        if ($apiKey === '' || ! $enabled) {
            return null;
        }

        $baseUrl = trim((string) config('services.openai.base_url', 'https://api.openai.com/v1'));
        $model = trim((string) config('services.openai.ai_power_model', config('services.openai.model', 'gpt-4o-mini')));
        $timeout = max(5, min(30, (int) config('services.openai.ai_power_timeout_seconds', config('services.openai.timeout_seconds', 12))));
        $promptVersion = trim((string) config('services.openai.ai_power_policy_prompt_version', config('services.openai.ai_power_prompt_version', 'v2')));
        $promptExtra = trim((string) config('services.openai.ai_power_policy_prompt_extra', config('services.openai.ai_power_prompt_extra', '')));

        $policyName = trim((string) ($plan['policy_name'] ?? ''));
        $policyCategory = trim((string) ($plan['policy_category'] ?? 'operations/ai-power'));
        $targetType = trim((string) ($plan['target_type'] ?? 'device'));
        $targetQuery = trim((string) ($plan['target_query'] ?? ''));

        $prompt = implode("\n", [
            'Generate a single endpoint policy command based on this natural-language requirement.',
            'Prompt version: '.$promptVersion,
            'Return strict JSON with keys only: command, run_as, timeout_seconds, confidence, rationale.',
            'Constraints:',
            '- command must be a practical Windows endpoint command (cmd/powershell/reg/gpupdate/sc/schtasks).',
            '- no destructive data wipe or partitioning operations.',
            '- prefer deterministic and idempotent commands suitable for managed fleet deployment.',
            '- avoid interactive commands requiring user input/UI.',
            '- if requirement is ambiguous, generate safest reasonable command and lower confidence.',
            '- run_as must be default|elevated|system.',
            '- timeout_seconds must be 30..3600.',
            '- confidence must be 0..1.',
            'Policy intent examples to cover:',
            '- usb/device-control, hardening, update/maintenance, service management, command restrictions.',
            '- phrase typos should still map to a valid command when intent is obvious (ex: disble usb).',
            'Context:',
            'policy_name='.$policyName,
            'policy_category='.$policyCategory,
            'target_type='.$targetType,
            'target_query='.$targetQuery,
            'instruction='.$instruction,
        ]);
        if ($promptExtra !== '') {
            $prompt .= "\n".'Additional policy-generation instructions:'."\n".$promptExtra;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->post(rtrim($baseUrl, '/').'/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You design safe, fleet-ready Windows policy commands for enterprise IT administrators (prompt '.$promptVersion.').',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('AI Power policy command generation request failed.', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);

                return null;
            }

            $raw = data_get($response->json(), 'choices.0.message.content');
            if (is_array($raw)) {
                $raw = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (! is_string($raw) || trim($raw) === '') {
                return null;
            }

            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Log::warning('AI Power policy command generation exception.', [
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        }
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>|null
     */
    private function suggestPolicyCommandFallback(string $instruction, array $plan): ?array
    {
        $lower = mb_strtolower($instruction.' '.((string) ($plan['policy_name'] ?? '')));
        foreach ($this->policySuggestionsFromCatalog() as $suggestion) {
            $phrases = is_array($suggestion['phrases'] ?? null) ? $suggestion['phrases'] : [];
            foreach ($phrases as $phrase) {
                $needle = mb_strtolower(trim((string) $phrase));
                if ($needle === '' || ! str_contains($lower, $needle)) {
                    continue;
                }

                return [
                    'command' => (string) ($suggestion['command'] ?? ''),
                    'run_as' => (string) ($suggestion['run_as'] ?? 'system'),
                    'timeout_seconds' => (int) ($suggestion['timeout_seconds'] ?? 300),
                    'confidence' => (float) ($suggestion['confidence'] ?? 0.70),
                    'rationale' => (string) ($suggestion['rationale'] ?? 'Detected policy requirement from command catalog.'),
                ];
            }
        }

        if (
            str_contains($lower, 'disable usb')
            || str_contains($lower, 'block usb')
            || str_contains($lower, 'disbale usb')
            || str_contains($lower, 'disble usb')
            || str_contains($lower, 'diable usb')
            || str_contains($lower, 'usb disbale')
            || str_contains($lower, 'usb disble')
        ) {
            return [
                'command' => 'reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\RemovableStorageDevices" /v Deny_All /t REG_DWORD /d 1 /f',
                'run_as' => 'system',
                'timeout_seconds' => 300,
                'confidence' => 0.82,
                'rationale' => 'Detected USB disable/block requirement.',
            ];
        }
        if (
            str_contains($lower, 'enable usb')
            || str_contains($lower, 'allow usb')
            || str_contains($lower, 'enble usb')
            || str_contains($lower, 'usb enble')
        ) {
            return [
                'command' => 'reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\RemovableStorageDevices" /v Deny_All /t REG_DWORD /d 0 /f',
                'run_as' => 'system',
                'timeout_seconds' => 300,
                'confidence' => 0.80,
                'rationale' => 'Detected USB allow/enable requirement.',
            ];
        }
        if (str_contains($lower, 'gpupdate')) {
            return [
                'command' => 'gpupdate /force',
                'run_as' => 'system',
                'timeout_seconds' => 300,
                'confidence' => 0.78,
                'rationale' => 'Detected GPUpdate policy command requirement.',
            ];
        }
        if (str_contains($lower, 'disable cmd') || str_contains($lower, 'block cmd')) {
            return [
                'command' => 'reg add "HKCU\Software\Policies\Microsoft\Windows\System" /v DisableCMD /t REG_DWORD /d 1 /f',
                'run_as' => 'system',
                'timeout_seconds' => 300,
                'confidence' => 0.76,
                'rationale' => 'Detected command prompt restriction requirement.',
            ];
        }
        if (str_contains($lower, 'disable control panel')) {
            return [
                'command' => 'reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Policies\Explorer" /v NoControlPanel /t REG_DWORD /d 1 /f',
                'run_as' => 'system',
                'timeout_seconds' => 300,
                'confidence' => 0.74,
                'rationale' => 'Detected control panel lock requirement.',
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{
     *   command:string,
     *   run_as:string,
     *   timeout_seconds:int,
     *   confidence:float,
     *   rationale:string,
     *   source:string
     * }
     */
    private function normalizePolicySuggestion(array $raw, string $source): array
    {
        $command = trim((string) ($raw['command'] ?? ''));
        $runAs = mb_strtolower(trim((string) ($raw['run_as'] ?? 'system')));
        if (! in_array($runAs, ['default', 'elevated', 'system'], true)) {
            $runAs = 'system';
        }
        $timeout = max(30, min(3600, (int) ($raw['timeout_seconds'] ?? 300)));
        $confidence = max(0.0, min(1.0, (float) ($raw['confidence'] ?? 0.0)));
        $rationale = trim((string) ($raw['rationale'] ?? ''));

        return [
            'command' => mb_substr($command, 0, 12000),
            'run_as' => $runAs,
            'timeout_seconds' => $timeout,
            'confidence' => round($confidence, 4),
            'rationale' => mb_substr($rationale, 0, 500),
            'source' => $source,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function reviewPolicyCommandWithOpenAi(string $command, string $instruction): ?array
    {
        $apiKey = trim((string) config('services.openai.api_key', ''));
        $enabled = (bool) config('services.openai.ai_power_enabled', true);
        if ($apiKey === '' || ! $enabled) {
            return null;
        }

        $baseUrl = trim((string) config('services.openai.base_url', 'https://api.openai.com/v1'));
        $model = trim((string) config('services.openai.ai_power_model', config('services.openai.model', 'gpt-4o-mini')));
        $timeout = max(5, min(30, (int) config('services.openai.ai_power_timeout_seconds', config('services.openai.timeout_seconds', 12))));
        $promptVersion = trim((string) config('services.openai.ai_power_review_prompt_version', config('services.openai.ai_power_prompt_version', 'v2')));
        $promptExtra = trim((string) config('services.openai.ai_power_review_prompt_extra', config('services.openai.ai_power_prompt_extra', '')));

        $prompt = implode("\n", [
            'Review this endpoint policy command for safe/valid use.',
            'Prompt version: '.$promptVersion,
            'Return strict JSON with keys only: pass, confidence, errors, warnings.',
            '- pass: true or false',
            '- confidence: 0..1',
            '- errors: array of blocking issues',
            '- warnings: array of non-blocking issues',
            'Validation criteria:',
            '- block destructive operations (format/disk partition/boot corruption/data wipe).',
            '- block clearly unsafe escalation persistence patterns unless explicitly required by policy intent.',
            '- warn on weak quoting, fragile shell syntax, or suspicious command chaining.',
            '- warn if command is unlikely to execute successfully under endpoint policy runner.',
            'instruction='.$instruction,
            'command='.$command,
        ]);
        if ($promptExtra !== '') {
            $prompt .= "\n".'Additional policy-review instructions:'."\n".$promptExtra;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->post(rtrim($baseUrl, '/').'/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You review endpoint commands for policy deployment safety (prompt '.$promptVersion.').',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                return null;
            }

            $raw = data_get($response->json(), 'choices.0.message.content');
            if (is_array($raw)) {
                $raw = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (! is_string($raw) || trim($raw) === '') {
                return null;
            }

            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function runCommandTemplatesFromCatalog(): array
    {
        $templates = config('dms_commands.run_command_templates', []);

        return is_array($templates) ? array_values(array_filter($templates, 'is_array')) : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function policySuggestionsFromCatalog(): array
    {
        $rows = config('dms_commands.policy_suggestions', []);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @return array{risk_level:string,requires_approval:bool,rollback_command:string}
     */
    private function intentRiskProfile(string $intent): array
    {
        $all = config('dms_commands.risk_by_intent', []);
        if (is_array($all)) {
            $candidate = $all[$intent] ?? null;
            if (is_array($candidate)) {
                $risk = mb_strtolower(trim((string) ($candidate['risk_level'] ?? 'low')));
                if (! in_array($risk, ['low', 'medium', 'high'], true)) {
                    $risk = 'low';
                }

                return [
                    'risk_level' => $risk,
                    'requires_approval' => (bool) ($candidate['requires_approval'] ?? false),
                    'rollback_command' => trim((string) ($candidate['rollback_command'] ?? '')),
                ];
            }
        }

        return ['risk_level' => 'low', 'requires_approval' => false, 'rollback_command' => ''];
    }

    private function matchesHighRiskCommandPattern(string $command): bool
    {
        $patterns = config('dms_commands.high_risk_patterns', []);
        if (! is_array($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            $regex = trim((string) $pattern);
            if ($regex === '') {
                continue;
            }
            if (@preg_match($regex, $command) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   intent:string,
     *   target_type:string,
     *   target_query:string,
     *   policy_name:string,
     *   policy_query:string,
     *   policy_category:string,
     *   policy_command:string,
     *   script:string,
     *   run_as:string,
     *   timeout_seconds:int,
     *   priority:int,
     *   confidence:float,
     *   rationale:string,
     *   command_slug:string,
     *   risk_level:string,
     *   requires_approval:bool,
     *   rollback_command:string,
     *   catalog_version:string,
     *   source:string
     * }
     */
    private function unknown(string $rationale, string $source): array
    {
        return [
            'intent' => 'unknown',
            'target_type' => 'device',
            'target_query' => '',
            'policy_name' => '',
            'policy_query' => '',
            'policy_category' => 'operations/ai-power',
            'policy_command' => '',
            'script' => '',
            'run_as' => 'default',
            'timeout_seconds' => 300,
            'priority' => 100,
            'confidence' => 0.0,
            'rationale' => $rationale,
            'command_slug' => '',
            'risk_level' => 'low',
            'requires_approval' => false,
            'rollback_command' => '',
            'catalog_version' => (string) config('dms_commands.version', 'v1'),
            'source' => $source,
        ];
    }
}

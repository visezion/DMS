<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdminNote;
use App\Models\AgentRelease;
use App\Models\AuditLog;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DmsJob;
use App\Models\JobRun;
use App\Models\PackageModel;
use App\Models\Policy;
use App\Models\PolicyRule;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\AiPower\AiFunctionSystemService;
use App\Services\AiPower\NaturalLanguageCommandService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class AiPowerController extends Controller
{
    public function index(Request $request): View
    {
        $recentJobs = DmsJob::query()
            ->where('created_by', $request->user()?->id)
            ->whereIn('job_type', ['run_command', 'uninstall_agent', 'apply_policy'])
            ->latest('created_at')
            ->limit(12)
            ->get()
            ->map(function (DmsJob $job): array {
                $payload = is_array($job->payload) ? $job->payload : [];
                $meta = is_array($payload['ai_power'] ?? null) ? $payload['ai_power'] : [];

                return [
                    'id' => (string) $job->id,
                    'job_type' => (string) $job->job_type,
                    'status' => (string) $job->status,
                    'target_id' => (string) $job->target_id,
                    'intent' => (string) ($meta['intent'] ?? ''),
                    'instruction' => mb_substr((string) ($meta['instruction'] ?? ''), 0, 220),
                    'created_at' => $job->created_at,
                ];
            });

        return view('admin.ai-power', [
            'ai_power_result' => $request->session()->get('ai_power_result', $request->session()->get('ai_power_last_result')),
            'ai_power_chat' => $request->session()->get('ai_power_chat', []),
            'recent_ai_power_jobs' => $recentJobs,
        ]);
    }

    public function execute(
        Request $request,
        NaturalLanguageCommandService $interpreter,
        AiFunctionSystemService $aiFunctionSystem,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $data = $request->validate([
            'instruction' => ['required', 'string', 'max:4000'],
            'execute_now' => ['nullable', 'boolean'],
        ]);

        $instruction = trim((string) $data['instruction']);
        $chat = $this->startChatHistory($request, $instruction);
        if ($this->isDetailsOnlyRequest($instruction)) {
            return $this->replyWithLastResultDetails($request, $chat);
        }
        $affirmativeReply = $this->handleAffirmativeOnlyFollowUp($request, $instruction, $chat);
        if ($affirmativeReply instanceof RedirectResponse) {
            return $affirmativeReply;
        }
        $createGroupBulkReply = $this->handleCreateGroupAndAssignAllDevicesInstruction($request, $instruction, $chat, $auditLogger);
        if ($createGroupBulkReply instanceof RedirectResponse) {
            return $createGroupBulkReply;
        }
        $groupMembershipReply = $this->handleCreateGroupAndAssignDeviceInstruction($request, $instruction, $chat, $auditLogger);
        if ($groupMembershipReply instanceof RedirectResponse) {
            return $groupMembershipReply;
        }
        $existingGroupAssignReply = $this->handleAddDeviceToExistingGroupInstruction($request, $instruction, $chat, $auditLogger);
        if ($existingGroupAssignReply instanceof RedirectResponse) {
            return $existingGroupAssignReply;
        }
        $createGroupOnlyReply = $this->handleCreateGroupOnlyInstruction($request, $instruction, $chat, $auditLogger);
        if ($createGroupOnlyReply instanceof RedirectResponse) {
            return $createGroupOnlyReply;
        }

        $executeNow = (bool) ($data['execute_now'] ?? false);
        $plan = $interpreter->interpret($instruction);
        $plan = $this->applyConversationContext($request, $plan, $instruction);

        $result = [
            'instruction' => $instruction,
            'execute_now' => $executeNow,
            'plan' => $plan,
            'executed' => false,
        ];

        $followUpReply = $this->answerFromPreviousAiListContext($request, $instruction, $result, $chat);
        if ($followUpReply instanceof RedirectResponse) {
            return $followUpReply;
        }

        if ((string) ($plan['intent'] ?? 'unknown') === 'unknown') {
            if ($this->isGreetingMessage($instruction)) {
                $result['_suppress_summary'] = true;

                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'Hello. I can help with device status, health, security, policies, and actions. Try: "status of KURSU-ST110" or "restart device KURSU-ST110".',
                    'AI assistant is ready.'
                );
            }
            if ($this->isGratitudeMessage($instruction)) {
                $result['_suppress_summary'] = true;

                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'You are welcome. If you want, ask another question like "which devices are non-compliant?" or "status of KURSU-ST110".',
                    'AI assistant is ready.'
                );
            }

            $result['_suppress_summary'] = true;

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I did not fully understand that yet. Please include exact target and action (or a direct question), for example: "restart device KURSU-ST110" or "what is the status of KURSU-ST110".',
                'Please clarify your request with exact target and action.'
            );
        }

        $minConfidence = 0.35;
        $confidence = max(0.0, min(1.0, (float) ($plan['confidence'] ?? 0.0)));
        $plan['confidence'] = $confidence;
        $result['plan'] = $plan;

        $intent = (string) ($plan['intent'] ?? 'unknown');
        if ($intent === 'ai_query') {
            return $this->executeAiQueryIntent(
                $request,
                $plan,
                $result,
                $instruction,
                $auditLogger,
                $aiFunctionSystem,
                $chat
            );
        }
        if (in_array($intent, ['create_policy', 'apply_policy'], true)) {
            return $this->executePolicyIntent(
                $request,
                $plan,
                $result,
                $executeNow,
                $instruction,
                $auditLogger,
                $interpreter,
                $confidence,
                $minConfidence,
                $chat
            );
        }
        if ($intent === 'project_inventory') {
            $inventory = $this->buildProjectInventory();
            $result['project_inventory'] = $inventory;

            $auditLogger->log('ai_power.project_inventory.lookup', 'settings', 'control_plane', null, [
                'instruction' => $instruction,
                'plan' => $plan,
                'inventory_summary' => [
                    'total_admin_routes' => (int) ($inventory['summary']['total_admin_routes'] ?? 0),
                    'areas' => (int) count((array) ($inventory['areas'] ?? [])),
                ],
            ], $request->user()?->id);

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I generated a full project inventory with available functions, route areas, and current values/settings.',
                'AI inventory generated: functions and project values are available below.'
            );
        }

        if (
            in_array($intent, ['reboot_device', 'run_command_device', 'uninstall_agent_device', 'get_device_status'], true)
            && (string) ($plan['target_type'] ?? 'device') === 'group'
            && trim((string) ($plan['target_query'] ?? '')) !== ''
            && ! in_array(
                mb_strtolower(trim((string) ($plan['target_query'] ?? ''))),
                ['all', 'all-devices', 'all_devices', 'every', 'everyone', '*'],
                true
            )
        ) {
            return $this->executeGroupDevicesIntent(
                $request,
                $plan,
                $result,
                $executeNow,
                $instruction,
                $auditLogger,
                $interpreter,
                $confidence,
                $minConfidence,
                $chat
            );
        }

        if (in_array($intent, ['reboot_device', 'run_command_device', 'uninstall_agent_device', 'get_device_status'], true)
            && $this->isAllDevicesTarget($plan, $instruction)
        ) {
            return $this->executeAllDevicesIntent(
                $request,
                $plan,
                $result,
                $executeNow,
                $instruction,
                $auditLogger,
                $interpreter,
                $confidence,
                $minConfidence,
                $chat
            );
        }

        $resolution = $this->resolveDevice((string) ($plan['target_query'] ?? ''));
        if (! ($resolution['ok'] ?? false)) {
            $result['resolution'] = $resolution;
            $clarify = 'I could not resolve the target device. Please provide the exact hostname or device ID.';
            if (is_array($resolution['matches'] ?? null) && count($resolution['matches']) > 0) {
                $first = $resolution['matches'][0];
                $example = (string) ($first['hostname'] ?? ($first['name'] ?? ($first['id'] ?? '')));
                if ($example !== '') {
                    $clarify .= ' Example: use "reboot device '.$example.'".';
                }
            }

            return $this->replyBack(
                $request,
                $result,
                $chat,
                $clarify,
                null,
                (string) ($resolution['error'] ?? 'Unable to resolve target device.')
            );
        }

        /** @var Device $device */
        $device = $resolution['device'];
        $lookupMeta = is_array($resolution['lookup'] ?? null) ? $resolution['lookup'] : [];
        $result['resolution'] = [
            'ok' => true,
            'device' => [
                'id' => (string) $device->id,
                'hostname' => (string) ($device->hostname ?? ''),
                'status' => (string) ($device->status ?? ''),
            ],
        ];
        if ($lookupMeta !== []) {
            $result['resolution']['lookup'] = $lookupMeta;
            $result['ip_lookup'] = $lookupMeta;
        }

        if ($intent === 'get_device_status') {
            $statusText = $this->effectiveDeviceStatus($device);
            $deviceIp = $this->extractDevicePrimaryIp($device);
            $networkInterfaces = $this->extractDeviceNetworkInterfaces($device);
            $wantsNetworkDetails = preg_match('/\b(network\s*(?:ip|interface|adapter)|interface|adapter|mac(?:\s*address)?)\b/i', $instruction) === 1;
            $result['_suppress_summary'] = true;

            $result['device_status'] = [
                'device_id' => (string) $device->id,
                'hostname' => (string) ($device->hostname ?? ''),
                'status' => $statusText,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'os_name' => (string) ($device->os_name ?? ''),
                'os_version' => (string) ($device->os_version ?? ''),
                'agent_version' => (string) ($device->agent_version ?? ''),
                'ip_address' => $deviceIp,
                'network_interfaces' => $networkInterfaces,
            ];

            $auditLogger->log('ai_power.device_status.lookup', 'device', $device->id, null, [
                'instruction' => $instruction,
                'plan' => $plan,
                'target_device_id' => $device->id,
            ], $request->user()?->id);

            $humanLastSeen = $device->last_seen_at?->toDateTimeString() ?? 'never';
            $ipText = $deviceIp !== '' ? $deviceIp : 'unknown';
            $hostnameText = (string) ($device->hostname ?? $device->id);
            $interfaceText = '';
            if ($wantsNetworkDetails) {
                if ($networkInterfaces !== []) {
                    $interfaceText = ' Interfaces: '.implode('; ', array_slice($networkInterfaces, 0, 4)).'.';
                } else {
                    $interfaceText = ' No network interface details were reported by the agent.';
                }
            }
            $lookupQuery = trim((string) ($lookupMeta['query'] ?? ''));
            $lookupMatch = trim((string) ($lookupMeta['match'] ?? ''));
            $lookupMatchedIp = trim((string) ($lookupMeta['matched_ip'] ?? ''));

            $primaryReply = 'Device '.$hostnameText.' is '.$statusText.'. IP: '.$ipText.'. Last seen '.$humanLastSeen.'.'.$interfaceText;
            $compactReply = 'Device '.$hostnameText.' is '.$statusText.'. IP: '.$ipText.'. Last seen: '.$humanLastSeen.'.';
            if ($lookupQuery !== '') {
                if ($lookupMatch === 'exact') {
                    $primaryReply = 'IP '.$lookupQuery.' belongs to '.$hostnameText.'. Device is '.$statusText.'. Last seen '.$humanLastSeen.'.';
                    if ($ipText !== 'unknown' && $ipText !== $lookupQuery) {
                        $primaryReply .= ' Reported IP: '.$ipText.'.';
                    }
                    $primaryReply .= $interfaceText;
                    $compactReply = 'IP lookup: '.$lookupQuery.' belongs to '.$hostnameText.' ('.$ipText.').';
                } elseif ($lookupMatch === 'prefix') {
                    $matchedLabel = $lookupMatchedIp !== '' ? $lookupMatchedIp : $ipText;
                    $primaryReply = 'IP prefix '.$lookupQuery.' matches '.$hostnameText.' ('.$matchedLabel.'). Device is '.$statusText.'. Last seen '.$humanLastSeen.'.'.$interfaceText;
                    $compactReply = 'IP lookup: '.$lookupQuery.' matched '.$hostnameText.' ('.$matchedLabel.').';
                }
            }

            return $this->replyBack(
                $request,
                $result,
                $chat,
                $primaryReply,
                $compactReply
            );
        }

        if ($executeNow && $confidence < $minConfidence) {
            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I resolved the target as '.($device->hostname ?? $device->id).', but confidence is low. Please confirm with an explicit command like "reboot device '.($device->hostname ?? $device->id).'"',
                null,
                'Plan confidence is too low to execute safely. Resolved target: '.($device->hostname ?? $device->id).'. Please rephrase with explicit action details.'
            );
        }

        if (! $executeNow) {
            $auditLogger->log('ai_power.command.preview', 'device', $device->id, null, [
                'instruction' => $instruction,
                'plan' => $plan,
                'target_device_id' => $device->id,
            ], $request->user()?->id);

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I prepared a plan for '.($device->hostname ?? $device->id).'. If it looks right, click Execute Now.',
                'AI plan generated. Review it and click Execute now when ready.'
            );
        }

        [$jobType, $payload] = $this->buildJobPayload($plan, $instruction, $request->user());
        if ($jobType === '' || ! is_array($payload)) {
            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I could not map this to a supported execution action. Please request reboot, run command, uninstall, status, create/apply policy, inventory, or ask an analytics question.',
                null,
                'Unsupported AI action.'
            );
        }

        if ($jobType === 'run_command') {
            $payload = $this->normalizeRunCommandPayload($payload, $request->user()?->id);
            $script = trim((string) ($payload['script'] ?? ''));
            if ($script === '') {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'The run command request is missing the script. Example: run command "gpupdate /force" on device LAB-01.',
                    null,
                    'run_command requires a non-empty script.'
                );
            }

            $commandTest = $interpreter->testPolicyCommand(
                $script,
                (string) ($payload['run_as'] ?? 'default'),
                (int) ($payload['timeout_seconds'] ?? 300),
                $instruction
            );
            $result['run_command_test'] = $commandTest;
            if (! (bool) ($commandTest['ok'] ?? false)) {
                $errors = is_array($commandTest['errors'] ?? null) ? $commandTest['errors'] : [];
                $errorText = count($errors) > 0 ? implode(' | ', array_map(fn ($e): string => (string) $e, array_slice($errors, 0, 3))) : 'Run command preflight test failed.';

                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I tested this command and it failed safety preflight checks. Please revise the request.',
                    null,
                    'Run command test failed: '.$errorText
                );
            }
        }

        if ($executeNow && $this->planRequiresExplicitApproval($plan) && ! $this->hasApprovalConfirmation($instruction)) {
            $targetLabel = (string) ($device->hostname ?? $device->id);
            $confirmationPhrase = $this->approvalConfirmationPhrase($plan, 'device '.$targetLabel);
            $result['confirmation_required'] = [
                'scope' => 'device',
                'device_count' => 1,
                'confirmation_phrase' => $confirmationPhrase,
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'This is a high-risk action for '.$targetLabel.'. To continue, reply: "'.$confirmationPhrase.'"',
                'Confirmation required for high-risk action.'
            );
        }

        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => $jobType,
            'status' => 'queued',
            'priority' => (int) ($plan['priority'] ?? 100),
            'payload' => $payload,
            'target_type' => 'device',
            'target_id' => $device->id,
            'created_by' => $request->user()?->id,
        ]);

        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $job->id,
            'device_id' => $device->id,
            'status' => 'pending',
            'next_retry_at' => null,
        ]);

        $result['executed'] = true;
        $result['job'] = [
            'id' => (string) $job->id,
            'job_type' => (string) $job->job_type,
            'status' => (string) $job->status,
            'target_id' => (string) $job->target_id,
        ];

        $auditLogger->log('ai_power.command.execute', 'job', $job->id, null, [
            'instruction' => $instruction,
            'plan' => $plan,
            'target_device_id' => $device->id,
            'job_type' => $jobType,
        ], $request->user()?->id);

        return $this->replyIndex(
            $request,
            $result,
            $chat,
            'Done. I queued '.$job->job_type.' for '.($device->hostname ?? $device->id).'.',
            'AI command executed and job queued.'
        );
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $result
     * @param list<array{role:string,message:string,at:string}> $chat
     */
    private function executeAiQueryIntent(
        Request $request,
        array $plan,
        array $result,
        string $instruction,
        AuditLogger $auditLogger,
        AiFunctionSystemService $aiFunctionSystem,
        array $chat
    ): RedirectResponse {
        $functionResult = $aiFunctionSystem->answer($instruction, $plan);
        $result['ai_function'] = $functionResult;

        $auditLogger->log('ai_power.function.query', 'settings', 'ai_power', null, [
            'instruction' => $instruction,
            'plan' => $plan,
            'function_result' => [
                'domain' => (string) ($functionResult['domain'] ?? 'unknown'),
                'topic' => (string) ($functionResult['topic'] ?? 'unknown'),
                'metrics_count' => count((array) ($functionResult['metrics'] ?? [])),
                'items_count' => count((array) ($functionResult['items'] ?? [])),
            ],
        ], $request->user()?->id);

        $needsClarification = (bool) ($functionResult['needs_clarification'] ?? false);
        if ($needsClarification) {
            $clarification = trim((string) ($functionResult['clarification'] ?? 'Please provide a more specific request.'));

            return $this->replyBack(
                $request,
                $result,
                $chat,
                $clarification,
                null,
                null
            );
        }

        $summary = trim((string) ($functionResult['summary'] ?? 'I completed your AI analysis request.'));
        $assistantMessage = $summary !== '' ? $summary : 'I completed your AI analysis request.';
        $assistantMessage = $this->appendAiFunctionTopItemsSummary($assistantMessage, $functionResult, $instruction);

        return $this->replyBack(
            $request,
            $result,
            $chat,
            $assistantMessage,
            'AI function analysis completed.'
        );
    }

    /**
     * @param array<string,mixed> $functionResult
     */
    private function appendAiFunctionTopItemsSummary(string $message, array $functionResult, string $instruction): string
    {
        if (! $this->instructionLooksLikeListQuestion($instruction)) {
            return $message;
        }

        $domain = mb_strtolower(trim((string) ($functionResult['domain'] ?? '')));
        if ($domain === 'software') {
            return $this->appendSoftwareTopItemsSummary($message, $functionResult, $instruction);
        }

        $items = is_array($functionResult['items'] ?? null) ? $functionResult['items'] : [];
        $labels = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '' || mb_strtolower($label) === 'unknown-device') {
                continue;
            }
            $labels[] = $label;
        }
        $labels = array_values(array_unique($labels));
        if ($labels === []) {
            return $message;
        }

        $shown = array_slice($labels, 0, 6);
        $moreCount = count($labels) - count($shown);
        $domain = mb_strtolower(trim((string) ($functionResult['domain'] ?? '')));
        $entity = $domain === 'user' ? 'Users' : 'Devices';
        $suffix = $moreCount > 0 ? ' and '.$moreCount.' more' : '';

        return rtrim($message).' '.$entity.': '.implode(', ', $shown).$suffix.'.';
    }

    /**
     * @param array<string,mixed> $functionResult
     */
    private function appendSoftwareTopItemsSummary(string $message, array $functionResult, string $instruction): string
    {
        $items = is_array($functionResult['items'] ?? null) ? $functionResult['items'] : [];
        $labels = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '' || mb_strtolower($label) === 'unknown-device') {
                continue;
            }
            $labels[] = $label;
        }
        $labels = array_values(array_unique($labels));
        if ($labels === []) {
            return $message;
        }

        $lowerInstruction = mb_strtolower(trim($instruction));
        $asksForDevices = preg_match('/\b(which|what|show|list)\b.*\bdevices?\b/u', $lowerInstruction) === 1
            || str_contains($lowerInstruction, 'devices have outdated')
            || str_contains($lowerInstruction, 'devices have unauthorized');

        if ($asksForDevices) {
            $hosts = [];
            foreach ($labels as $label) {
                $parts = explode(' - ', $label, 2);
                $hosts[] = trim((string) ($parts[0] ?? $label));
            }
            $hosts = array_values(array_unique(array_filter($hosts, fn ($h): bool => trim((string) $h) !== '')));
            if ($hosts === []) {
                return $message;
            }

            $shown = array_slice($hosts, 0, 8);
            $moreCount = count($hosts) - count($shown);
            $suffix = $moreCount > 0 ? ' and '.$moreCount.' more' : '';

            return rtrim($message).' Devices: '.implode(', ', $shown).$suffix.'.';
        }

        $apps = [];
        foreach ($labels as $label) {
            $apps[] = trim((string) $label);
        }
        $apps = array_values(array_unique(array_filter($apps, fn ($app): bool => trim((string) $app) !== '')));
        if ($apps === []) {
            return $message;
        }

        $shown = array_slice($apps, 0, 8);
        $moreCount = count($apps) - count($shown);
        $suffix = $moreCount > 0 ? ' and '.$moreCount.' more' : '';
        $host = trim((string) data_get($functionResult, 'context.target.device.hostname', ''));
        $entity = $host !== '' ? 'Applications on '.$host : 'Applications';

        return rtrim($message).' '.$entity.': '.implode(', ', $shown).$suffix.'.';
    }

    private function instructionLooksLikeListQuestion(string $instruction): bool
    {
        $text = mb_strtolower(trim($instruction));
        if ($text === '') {
            return false;
        }

        return preg_match('/\b(which|show|list|what|who|name|names)\b/u', $text) === 1
            || str_contains($text, 'has any')
            || str_contains($text, 'any new')
            || preg_match('/\b(devices?|machines?|computers?|users?)\b/u', $text) === 1;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $result
     */
    private function executePolicyIntent(
        Request $request,
        array $plan,
        array $result,
        bool $executeNow,
        string $instruction,
        AuditLogger $auditLogger,
        NaturalLanguageCommandService $interpreter,
        float $confidence,
        float $minConfidence,
        array $chat
    ): RedirectResponse {
        $intent = (string) ($plan['intent'] ?? 'unknown');
        $targetType = (string) ($plan['target_type'] ?? 'device');
        if (! in_array($targetType, ['device', 'group'], true)) {
            $targetType = 'device';
        }
        $allDevicesTarget = $this->isAllDevicesTarget($plan, $instruction);

        $targetQuery = trim((string) ($plan['target_query'] ?? ''));
        $resolution = null;
        if ($targetQuery !== '' && ! ($intent === 'apply_policy' && $allDevicesTarget)) {
            $resolution = $this->resolveTarget($targetType, $targetQuery);
            $result['resolution'] = $resolution;
            if (! ($resolution['ok'] ?? false)) {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I could not resolve that target. Please provide the exact device/group name or ID.',
                    null,
                    (string) ($resolution['error'] ?? 'Unable to resolve target.')
                );
            }
        }

        if ($intent === 'create_policy') {
            $policyName = trim((string) ($plan['policy_name'] ?? ''));
            $policyCommand = trim((string) ($plan['policy_command'] ?? ''));

            $generatedPolicyCommand = null;
            if ($policyCommand === '') {
                $generatedPolicyCommand = $interpreter->suggestPolicyCommand($instruction, $plan);
                if (is_array($generatedPolicyCommand)) {
                    $policyCommand = trim((string) ($generatedPolicyCommand['command'] ?? ''));
                    if ($policyCommand !== '') {
                        $plan['policy_command'] = $policyCommand;
                        $result['plan'] = $plan;
                        $result['policy_command_generated'] = $generatedPolicyCommand;
                    }
                }
            }

            $policyName = $this->normalizeAiPolicyName($policyName, $instruction, $policyCommand);
            $plan['policy_name'] = $policyName;
            $result['plan'] = $plan;

            if ($policyCommand === '') {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'Please include the policy command. Example: create policy "Nightly GPUpdate" command "gpupdate /force" and apply to group Labs.',
                    null,
                    'Create policy requires a policy command in the instruction.'
                );
            }

            $ruleRunAs = (string) ($plan['run_as'] ?? 'system');
            $ruleTimeout = (int) ($plan['timeout_seconds'] ?? 300);
            $policyTest = $interpreter->testPolicyCommand(
                $policyCommand,
                $ruleRunAs,
                $ruleTimeout,
                $instruction
            );
            $result['policy_test'] = $policyTest;
            if (! (bool) ($policyTest['ok'] ?? false)) {
                $errors = is_array($policyTest['errors'] ?? null) ? $policyTest['errors'] : [];
                $errorText = count($errors) > 0 ? implode(' | ', array_map(fn ($e): string => (string) $e, array_slice($errors, 0, 3))) : 'Policy command preflight test failed.';

                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I tested the policy command and it failed preflight checks. Please revise the instruction or command.',
                    null,
                    'Policy command test failed: '.$errorText
                );
            }

            $confidence = $this->effectiveCreatePolicyConfidence(
                $confidence,
                $policyTest,
                $generatedPolicyCommand,
                $policyName,
                $policyCommand
            );
            $plan['confidence'] = $confidence;
            $result['plan'] = $plan;

            if (! $executeNow) {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I prepared the policy creation plan. If this looks right, click Execute Now.',
                    'AI policy plan generated. Review and click Execute now to create/apply.'
                );
            }

            if ($confidence < $minConfidence) {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I still need a clearer policy request before saving. Please include what should be enforced (example: "create policy disable usb").',
                    null,
                    'Plan confidence is too low to execute safely. Please rephrase with clearer policy intent.'
                );
            }

            $policyCategory = trim((string) ($plan['policy_category'] ?? 'operations/ai-power'));
            if ($policyCategory === '') {
                $policyCategory = 'operations/ai-power';
            }

            $policy = Policy::query()->create([
                'id' => (string) Str::uuid(),
                'name' => mb_substr($policyName, 0, 255),
                'slug' => $this->nextUniquePolicySlug((string) Str::slug($policyName)),
                'category' => mb_substr($policyCategory, 0, 100),
                'status' => 'active',
            ]);

            $versionNumber = max(1, ((int) PolicyVersion::query()->where('policy_id', $policy->id)->max('version_number')) + 1);
            $version = PolicyVersion::query()->create([
                'id' => (string) Str::uuid(),
                'policy_id' => $policy->id,
                'version_number' => $versionNumber,
                'status' => 'active',
                'created_by' => $request->user()?->id,
                'published_at' => now(),
            ]);

            $ruleConfig = $this->buildCommandRuleConfig(
                $policyCommand,
                (string) ($plan['run_as'] ?? 'system'),
                (int) ($plan['timeout_seconds'] ?? 300)
            );
            PolicyRule::query()->create([
                'id' => (string) Str::uuid(),
                'policy_version_id' => $version->id,
                'order_index' => 0,
                'rule_type' => 'command',
                'rule_config' => $ruleConfig,
                'enforce' => true,
            ]);

            $job = null;
            $createdAssignment = false;
            if (is_array($resolution) && ($resolution['ok'] ?? false) === true && isset($resolution['target_id'])) {
                $createdAssignment = $this->createPolicyAssignment(
                    (string) $version->id,
                    $targetType,
                    (string) $resolution['target_id']
                );
                $job = $this->queueApplyPolicyJob(
                    $version,
                    $targetType,
                    (string) $resolution['target_id'],
                    $request->user()?->id,
                    [
                        'instruction' => $instruction,
                        'intent' => $intent,
                        'source' => (string) ($plan['source'] ?? 'unknown'),
                        'confidence' => (float) ($plan['confidence'] ?? 0.0),
                    ]
                );
            }

            $result['executed'] = true;
            $result['policy'] = [
                'id' => (string) $policy->id,
                'name' => (string) $policy->name,
                'slug' => (string) $policy->slug,
                'category' => (string) $policy->category,
                'version_id' => (string) $version->id,
                'version_number' => (int) $version->version_number,
                'assigned' => $createdAssignment,
            ];
            if ($job instanceof DmsJob) {
                $result['job'] = [
                    'id' => (string) $job->id,
                    'job_type' => (string) $job->job_type,
                    'status' => (string) $job->status,
                    'target_id' => (string) $job->target_id,
                ];
            }

            $auditLogger->log('ai_power.policy.create_and_apply', 'policy', $policy->id, null, [
                'instruction' => $instruction,
                'plan' => $plan,
                'policy_id' => $policy->id,
                'policy_version_id' => $version->id,
                'target_type' => $targetType,
                'target_id' => is_array($resolution) ? ($resolution['target_id'] ?? null) : null,
                'created_assignment' => $createdAssignment,
                'job_id' => $job?->id,
            ], $request->user()?->id);

            return $this->replyIndex(
                $request,
                $result,
                $chat,
                $job
                    ? 'Policy '.$policy->name.' created, assigned, and apply job queued.'
                    : 'Policy '.$policy->name.' created.',
                'AI policy created'.($job ? ', assigned, and queued.' : '.')
            );
        }

        if ($intent === 'apply_policy') {
            $policyQuery = trim((string) ($plan['policy_query'] ?? ''));
            if ($policyQuery === '') {
                $policyQuery = trim((string) ($plan['policy_name'] ?? ''));
            }
            if ($policyQuery === '') {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'Please specify which policy to apply and to which device/group.',
                    null,
                    'Apply policy requires policy name/slug/version and target.'
                );
            }

            $policyResolution = $this->resolvePolicyVersion($policyQuery);
            $result['policy_resolution'] = $policyResolution;
            if (! ($policyResolution['ok'] ?? false)) {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I could not resolve that policy. Please provide exact policy slug, name, or version ID.',
                    null,
                    (string) ($policyResolution['error'] ?? 'Unable to resolve policy.')
                );
            }
            /** @var PolicyVersion $version */
            $version = $policyResolution['policy_version'];

            if ($allDevicesTarget) {
                $connectedOnly = $this->isConnectedOnlyTargetRequest($instruction);
                $deviceQuery = Device::query();
                if ($connectedOnly) {
                    $deviceQuery
                        ->whereRaw('LOWER(status) = ?', ['online'])
                        ->whereNotNull('last_seen_at')
                        ->where('last_seen_at', '>=', now()->subMinutes($this->deviceOnlineWindowMinutes()));
                }
                $devices = $deviceQuery
                    ->orderBy('hostname')
                    ->get(['id', 'hostname', 'status']);

                $result['resolution'] = [
                    'ok' => true,
                    'target_type' => 'fleet',
                    'target_id' => 'all',
                    'target_label' => $connectedOnly ? 'all connected devices' : 'all devices',
                    'count' => $devices->count(),
                    'sample' => $devices->take(5)->map(fn (Device $d): array => [
                        'id' => (string) $d->id,
                        'hostname' => (string) ($d->hostname ?? ''),
                        'status' => (string) ($d->status ?? ''),
                    ])->values()->all(),
                ];

                if ($devices->isEmpty()) {
                    return $this->replyBack(
                        $request,
                        $result,
                        $chat,
                        $connectedOnly
                            ? 'There are no connected devices right now to apply this policy.'
                            : 'There are no devices available to apply this policy.',
                        null,
                        $connectedOnly ? 'No connected devices found.' : 'No devices found.'
                    );
                }

                if (! $executeNow) {
                    return $this->replyBack(
                        $request,
                        $result,
                        $chat,
                        'I prepared a fleet apply-policy plan for '.$devices->count().' device(s).',
                        'AI apply-policy fleet plan generated.'
                    );
                }

                if ($confidence < $minConfidence) {
                    return $this->replyBack(
                        $request,
                        $result,
                        $chat,
                        'Confidence is low. Please rephrase with exact policy and scope.',
                        null,
                        'Plan confidence is too low to execute safely. Please rephrase with exact policy and fleet scope.'
                    );
                }

                if ($devices->count() > 1 && ! $this->hasBulkConfirmation($instruction, 'apply_policy')) {
                    $confirmationPhrase = $connectedOnly
                        ? 'confirm apply policy to all connected devices'
                        : 'confirm apply policy to all devices';
                    $result['confirmation_required'] = [
                        'scope' => $connectedOnly ? 'all_connected' : 'all_devices',
                        'device_count' => $devices->count(),
                        'confirmation_phrase' => $confirmationPhrase,
                    ];

                    return $this->replyBack(
                        $request,
                        $result,
                        $chat,
                        'This will apply policy to '.$devices->count().' devices. Reply "'.$confirmationPhrase.'" to continue.',
                        'Confirmation required for fleet policy application.'
                    );
                }

                $createdAssignments = 0;
                $createdJobs = [];
                foreach ($devices as $device) {
                    if ($this->createPolicyAssignment($version->id, 'device', (string) $device->id)) {
                        $createdAssignments++;
                    }
                    $job = $this->queueApplyPolicyJob(
                        $version,
                        'device',
                        (string) $device->id,
                        $request->user()?->id,
                        [
                            'instruction' => $instruction,
                            'intent' => $intent,
                            'source' => (string) ($plan['source'] ?? 'unknown'),
                            'confidence' => (float) ($plan['confidence'] ?? 0.0),
                        ]
                    );
                    $createdJobs[] = $job;
                }

                if ($createdJobs === []) {
                    return $this->replyBack(
                        $request,
                        $result,
                        $chat,
                        'No apply-policy jobs were queued due to validation constraints.',
                        null,
                        'No jobs were queued for the fleet target.'
                    );
                }

                $result['executed'] = true;
                $result['policy'] = [
                    'id' => (string) ($policyResolution['policy_id'] ?? ''),
                    'name' => (string) ($policyResolution['policy_name'] ?? ''),
                    'version_id' => (string) $version->id,
                    'version_number' => (int) ($version->version_number ?? 0),
                    'assigned' => $createdAssignments > 0,
                ];
                $result['bulk_job'] = [
                    'count' => count($createdJobs),
                    'sample_job_ids' => array_map(
                        fn (DmsJob $job): string => (string) $job->id,
                        array_slice($createdJobs, 0, 5)
                    ),
                    'intent' => $intent,
                    'scope' => $connectedOnly ? 'all_connected' : 'all_devices',
                ];
                $result['job'] = [
                    'id' => (string) $createdJobs[0]->id,
                    'job_type' => (string) $createdJobs[0]->job_type,
                    'status' => (string) $createdJobs[0]->status,
                    'target_id' => (string) $createdJobs[0]->target_id,
                ];

                $auditLogger->log('ai_power.policy.apply_fleet', 'policy_version', $version->id, null, [
                    'instruction' => $instruction,
                    'plan' => $plan,
                    'fleet_count' => count($createdJobs),
                    'scope' => $connectedOnly ? 'all_connected' : 'all_devices',
                    'created_assignments' => $createdAssignments,
                ], $request->user()?->id);

                return $this->replyIndex(
                    $request,
                    $result,
                    $chat,
                    'Policy apply queued for '.count($createdJobs).' device(s).',
                    'Fleet policy application queued for '.count($createdJobs).' device(s).'
                );
            }

            if (! is_array($resolution) || ! ($resolution['ok'] ?? false)) {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I need a valid target device or group before applying policy.',
                    null,
                    'Apply policy requires a valid target device/group.'
                );
            }

            if (! $executeNow) {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I prepared the apply-policy plan. Click Execute Now to queue it.',
                    'AI apply-policy plan generated. Click Execute now to queue.'
                );
            }

            if ($confidence < $minConfidence) {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'Confidence is low. Please rephrase with exact policy and target.',
                    null,
                    'Plan confidence is too low to execute safely. Please rephrase with exact policy and target device/group.'
                );
            }

            $targetId = (string) ($resolution['target_id'] ?? '');
            $createdAssignment = $this->createPolicyAssignment($version->id, $targetType, $targetId);
            $job = $this->queueApplyPolicyJob(
                $version,
                $targetType,
                $targetId,
                $request->user()?->id,
                [
                    'instruction' => $instruction,
                    'intent' => $intent,
                    'source' => (string) ($plan['source'] ?? 'unknown'),
                    'confidence' => (float) ($plan['confidence'] ?? 0.0),
                ]
            );

            $result['executed'] = true;
            $result['policy'] = [
                'id' => (string) ($policyResolution['policy_id'] ?? ''),
                'name' => (string) ($policyResolution['policy_name'] ?? ''),
                'version_id' => (string) $version->id,
                'version_number' => (int) ($version->version_number ?? 0),
                'assigned' => $createdAssignment,
            ];
            $result['job'] = [
                'id' => (string) $job->id,
                'job_type' => (string) $job->job_type,
                'status' => (string) $job->status,
                'target_id' => (string) $job->target_id,
            ];

            $auditLogger->log('ai_power.policy.apply', 'policy_version', $version->id, null, [
                'instruction' => $instruction,
                'plan' => $plan,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'created_assignment' => $createdAssignment,
                'job_id' => $job->id,
            ], $request->user()?->id);

            return $this->replyIndex(
                $request,
                $result,
                $chat,
                $createdAssignment
                    ? 'Policy applied and job queued for execution.'
                    : 'Policy was already assigned; apply job queued again.',
                $createdAssignment ? 'Policy assigned and apply job queued.' : 'Policy already assigned. Apply job queued.'
            );
        }

        return $this->replyBack(
            $request,
            $result,
            $chat,
            'I could not map this policy request. Try: apply policy "name" to device HOST01.',
            null,
            'Unsupported policy intent.'
        );
    }

    /**
     * @param mixed $raw
     * @return list<array{role:string,message:string,at:string}>
     */
    private function normalizeChatHistory(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $chat = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $role = (string) ($entry['role'] ?? '');
            if (! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $message = trim((string) ($entry['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $chat[] = [
                'role' => $role,
                'message' => mb_substr($message, 0, 12000),
                'at' => (string) ($entry['at'] ?? now()->toIso8601String()),
            ];
        }

        return array_values(array_slice($chat, -40));
    }

    /**
     * @param list<array{role:string,message:string,at:string}> $chat
     * @return list<array{role:string,message:string,at:string}>
     */
    private function appendChatMessage(array $chat, string $role, string $message): array
    {
        $clean = trim($message);
        if ($clean === '' || ! in_array($role, ['user', 'assistant'], true)) {
            return array_values(array_slice($chat, -40));
        }

        $chat[] = [
            'role' => $role,
            'message' => mb_substr($clean, 0, 12000),
            'at' => now()->toIso8601String(),
        ];

        return array_values(array_slice($chat, -40));
    }

    /**
     * @return list<array{role:string,message:string,at:string}>
     */
    private function startChatHistory(Request $request, string $instruction): array
    {
        $chat = $this->normalizeChatHistory($request->session()->get('ai_power_chat', []));

        return $this->appendChatMessage($chat, 'user', $instruction);
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function applyConversationContext(Request $request, array $plan, string $instruction): array
    {
        $context = $this->lastConversationContext($request);
        if ($context['target_query'] === '' && $context['policy_query'] === '') {
            return $plan;
        }

        $intent = (string) ($plan['intent'] ?? 'unknown');
        $targetQuery = trim((string) ($plan['target_query'] ?? ''));
        $policyQuery = trim((string) ($plan['policy_query'] ?? ''));
        $referencesPrevious = $this->instructionReferencesPreviousContext($instruction);
        $implicitSoftwareFollowUp = $this->shouldUseImplicitSoftwareDeviceContext($request, $plan, $instruction, $context);
        $usePreviousTargetContext = $referencesPrevious || $implicitSoftwareFollowUp;
        if ($this->isReferentialToken($targetQuery)) {
            $targetQuery = '';
            $plan['target_query'] = '';
        }
        if ($this->isReferentialToken($policyQuery)) {
            $policyQuery = '';
            $plan['policy_query'] = '';
        }

        $canUseImplicitTargetOverride = $usePreviousTargetContext
            && $intent === 'run_command_device'
            && $this->looksLikeNonDeviceTargetQuery($targetQuery);

        if (
            in_array($intent, ['reboot_device', 'run_command_device', 'uninstall_agent_device', 'get_device_status', 'apply_policy'], true)
            && $context['target_query'] !== ''
            && ($targetQuery === '' || $canUseImplicitTargetOverride)
            && $usePreviousTargetContext
        ) {
            $plan['target_query'] = $context['target_query'];
            $plan['target_type'] = $context['target_type'] !== '' ? $context['target_type'] : ((string) ($plan['target_type'] ?? 'device'));
            $plan['confidence'] = max((float) ($plan['confidence'] ?? 0.0), 0.62);
            $plan['rationale'] = $this->appendRationale(
                (string) ($plan['rationale'] ?? ''),
                'Used previous chat target context.'
            );
            if (trim((string) ($plan['source'] ?? '')) === '') {
                $plan['source'] = 'conversation';
            }
        }

        if (
            $intent === 'apply_policy'
            && $policyQuery === ''
            && $context['policy_query'] !== ''
            && $referencesPrevious
        ) {
            $plan['policy_query'] = $context['policy_query'];
            if (trim((string) ($plan['policy_name'] ?? '')) === '') {
                $plan['policy_name'] = $context['policy_query'];
            }
            $plan['confidence'] = max((float) ($plan['confidence'] ?? 0.0), 0.62);
            $plan['rationale'] = $this->appendRationale(
                (string) ($plan['rationale'] ?? ''),
                'Used previous chat policy context.'
            );
            if (trim((string) ($plan['source'] ?? '')) === '') {
                $plan['source'] = 'conversation';
            }
        }

        if (
            $intent === 'unknown'
            && $context['target_query'] !== ''
            && $usePreviousTargetContext
        ) {
            $inferredIntent = $this->inferFollowUpIntent($instruction);
            if ($inferredIntent !== 'unknown') {
                $plan['intent'] = $inferredIntent;
                $plan['target_query'] = $context['target_query'];
                $plan['target_type'] = $context['target_type'] !== '' ? $context['target_type'] : 'device';
                if ($inferredIntent === 'apply_policy' && trim((string) ($plan['policy_query'] ?? '')) === '' && $context['policy_query'] !== '') {
                    $plan['policy_query'] = $context['policy_query'];
                }
                $plan['confidence'] = max((float) ($plan['confidence'] ?? 0.0), 0.58);
                $plan['source'] = 'conversation';
                $plan['rationale'] = $this->appendRationale(
                    (string) ($plan['rationale'] ?? ''),
                    'Recovered follow-up intent from conversation context.'
                );
            }
        }

        return $plan;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array{target_query:string,target_type:string,policy_query:string} $context
     */
    private function shouldUseImplicitSoftwareDeviceContext(
        Request $request,
        array $plan,
        string $instruction,
        array $context
    ): bool {
        $intent = (string) ($plan['intent'] ?? 'unknown');
        if (! in_array($intent, ['run_command_device', 'unknown'], true)) {
            return false;
        }
        if ($context['target_query'] === '' || $context['target_type'] !== 'device') {
            return false;
        }
        $targetQuery = trim((string) ($plan['target_query'] ?? ''));
        if ($targetQuery !== '' && ! $this->looksLikeNonDeviceTargetQuery($targetQuery)) {
            return false;
        }

        $lower = mb_strtolower(trim($instruction));
        if ($lower === '') {
            return false;
        }
        if (preg_match('/\b(agent|policy)\b/u', $lower) === 1) {
            return false;
        }
        if (preg_match('/^\s*(which|what|why|how|show|list)\b/u', $lower) === 1) {
            return false;
        }
        if (preg_match('/\b(device|host|hostname|computer)\s+[a-z0-9._\-]{2,}\b/u', $lower) === 1) {
            return false;
        }
        if (preg_match('/\b(all|every)\s+(devices?|machines?|hosts?|computers?)\b/u', $lower) === 1) {
            return false;
        }
        if (preg_match('/\bgroup\b/u', $lower) === 1) {
            return false;
        }

        return preg_match('/\b(remove|uninstall|install|update|upgrade|run|restart|stop|start|kill|clear)\b/u', $lower) === 1;
    }

    private function looksLikeNonDeviceTargetQuery(string $targetQuery): bool
    {
        $targetQuery = trim($targetQuery);
        if ($targetQuery === '') {
            return false;
        }
        if (Str::isUuid($targetQuery)) {
            return false;
        }
        if (preg_match('/^[a-z0-9._-]{2,}$/i', $targetQuery) === 1) {
            return false;
        }

        return preg_match('/[\s()]/', $targetQuery) === 1
            || preg_match('/\d+\.\d+/', $targetQuery) === 1
            || preg_match('/[^a-z0-9._\-\s]/i', $targetQuery) === 1;
    }

    /**
     * @return array{target_query:string,target_type:string,policy_query:string}
     */
    private function lastConversationContext(Request $request): array
    {
        $last = $request->session()->get('ai_power_last_result', $request->session()->get('ai_power_result'));
        if (! is_array($last)) {
            return [
                'target_query' => '',
                'target_type' => 'device',
                'policy_query' => '',
            ];
        }

        $targetQuery = '';
        foreach ([
            data_get($last, 'plan.target_query'),
            data_get($last, 'resolution.device.hostname'),
            data_get($last, 'ai_function.context.target.device.hostname'),
            data_get($last, 'ai_function.context.target.device.id'),
            data_get($last, 'resolution.target_label'),
        ] as $candidate) {
            $clean = $this->cleanContextValue((string) ($candidate ?? ''));
            if ($clean !== '') {
                $targetQuery = $clean;
                break;
            }
        }
        if (in_array(mb_strtolower($targetQuery), ['all', 'all devices', 'all connected devices', 'every', 'everyone', '*'], true)) {
            $targetQuery = '';
        }

        $targetType = mb_strtolower(trim((string) (data_get($last, 'plan.target_type') ?? data_get($last, 'resolution.target_type') ?? 'device')));
        if (! in_array($targetType, ['device', 'group'], true)) {
            $targetType = 'device';
        }

        $policyQuery = '';
        foreach ([
            data_get($last, 'plan.policy_query'),
            data_get($last, 'policy.slug'),
            data_get($last, 'policy.name'),
            data_get($last, 'policy_resolution.policy_slug'),
            data_get($last, 'policy_resolution.policy_name'),
        ] as $candidate) {
            $clean = $this->cleanContextValue((string) ($candidate ?? ''));
            if ($clean !== '') {
                $policyQuery = $clean;
                break;
            }
        }

        return [
            'target_query' => $targetQuery,
            'target_type' => $targetType,
            'policy_query' => $policyQuery,
        ];
    }

    private function instructionReferencesPreviousContext(string $instruction): bool
    {
        $text = mb_strtolower($instruction);

        return preg_match('/\b(this|that|same|it|again|previous|last)\b/u', $text) === 1
            || preg_match('/\b(this|that|same)\s+(device|group|policy)\b/u', $text) === 1
            || preg_match('/\b(apply|assign)\s+(it|that)\b/u', $text) === 1;
    }

    private function inferFollowUpIntent(string $instruction): string
    {
        $lower = mb_strtolower($instruction);
        if (preg_match('/\b(reboot|restart|restrt|restar)\b/u', $lower) === 1) {
            return 'reboot_device';
        }
        if (
            preg_match('/\b(uninstall|remove)\b/u', $lower) === 1
            && preg_match('/\bagent\b/u', $lower) === 1
        ) {
            return 'uninstall_agent_device';
        }
        if (
            preg_match('/\b(remove|uninstall)\b/u', $lower) === 1
            && preg_match('/\b(agent|policy)\b/u', $lower) !== 1
        ) {
            return 'run_command_device';
        }
        if (
            preg_match('/\b(run|command|script|powershell|cmd\.exe)\b/u', $lower) === 1
            || preg_match('/\b(print service|spooler|diagnostic|health scan|security scan)\b/u', $lower) === 1
        ) {
            return 'run_command_device';
        }
        if (preg_match('/\b(status|state|health|last seen|ip(?:\s*address)?)\b/u', $lower) === 1) {
            return 'get_device_status';
        }
        if (preg_match('/\b(apply|assign)\b/u', $lower) === 1 && preg_match('/\bpolicy\b/u', $lower) === 1) {
            return 'apply_policy';
        }

        return 'unknown';
    }

    private function cleanContextValue(string $value): string
    {
        $clean = trim($value);
        if ($clean === '') {
            return '';
        }

        return in_array(mb_strtolower($clean), ['-', '--', 'n/a', 'na', 'none', 'null', 'nil', 'unknown', 'unspecified'], true)
            ? ''
            : $clean;
    }

    private function appendRationale(string $base, string $extra): string
    {
        $base = trim($base);
        $extra = trim($extra);
        if ($extra === '') {
            return $base;
        }
        if ($base === '') {
            return $extra;
        }

        return rtrim($base, '. ').'. '.$extra;
    }

    private function isReferentialToken(string $value): bool
    {
        $token = mb_strtolower(trim($value));
        if ($token === '') {
            return false;
        }

        return in_array($token, ['this', 'that', 'it', 'same', 'previous', 'last', 'current', 'here', 'there'], true)
            || preg_match('/^(this|that|same|previous|last|current)\s+(device|group|policy)$/u', $token) === 1;
    }

    /**
     * @param list<array{role:string,message:string,at:string}> $chat
     */
    private function replyWithLastResultDetails(Request $request, array $chat): RedirectResponse
    {
        $last = $request->session()->get('ai_power_last_result', $request->session()->get('ai_power_result'));
        if (! is_array($last) || $last === []) {
            return $this->replyBack(
                $request,
                ['instruction' => 'details', 'plan' => [], '_force_details' => false],
                $chat,
                'There is no previous result yet. Send a command or question first.',
                'No previous AI result found.'
            );
        }

        $last['_force_details'] = true;
        if (! array_key_exists('instruction', $last)) {
            $last['instruction'] = 'details';
        }

        return $this->replyBack(
            $request,
            $last,
            $chat,
            'Here are the full details from the last AI result.',
            'Detailed AI result displayed.'
        );
    }

    private function isDetailsOnlyRequest(string $instruction): bool
    {
        $text = mb_strtolower(trim($instruction));
        if ($text === '') {
            return false;
        }

        return preg_match('/^(details?|show details?|full details?|more details?|explain( more)?|debug( info)?|show plan|full plan)$/u', $text) === 1
            || preg_match('/\b(show|give)\s+(me\s+)?(full|detailed)\s+(plan|result|details?)\b/u', $text) === 1;
    }

    /**
     * @param list<array{role:string,message:string,at:string}> $chat
     */
    private function handleAffirmativeOnlyFollowUp(
        Request $request,
        string $instruction,
        array $chat
    ): ?RedirectResponse {
        $text = mb_strtolower(trim($instruction));
        if ($text === '' || preg_match('/^(yes|ok|okay|sure|yep|ya)$/u', $text) !== 1) {
            return null;
        }

        $last = $request->session()->get('ai_power_last_result', $request->session()->get('ai_power_result'));
        if (! is_array($last) || $last === []) {
            return null;
        }

        $confirmationPhrase = trim((string) data_get($last, 'confirmation_required.confirmation_phrase', ''));
        if ($confirmationPhrase !== '') {
            return $this->replyBack(
                $request,
                ['instruction' => $instruction, 'plan' => [], '_suppress_summary' => true],
                $chat,
                'To continue safely, reply exactly: "'.$confirmationPhrase.'".',
                'Confirmation phrase required.'
            );
        }

        return $this->replyWithLastResultDetails($request, $chat);
    }

    private function isGreetingMessage(string $instruction): bool
    {
        $text = mb_strtolower(trim($instruction));
        if ($text === '') {
            return false;
        }

        return preg_match('/^(hello|hi|hey|yo|sup|hola|good\s*(morning|afternoon|evening))\b[!. ]*$/u', $text) === 1
            || preg_match('/^h+e+l+o+\b[!. ]*$/u', $text) === 1
            || preg_match('/^(hello|hi|hey)\s+(ai|assistant|bot)\b[!. ]*$/u', $text) === 1;
    }

    private function isGratitudeMessage(string $instruction): bool
    {
        $text = mb_strtolower(trim($instruction));
        if ($text === '') {
            return false;
        }

        return preg_match('/^(thank\s*you|thanks|thx|ty)\b(?:\s+(ai|assistant|bot|so much|a lot))?[!. ]*$/u', $text) === 1;
    }

    /**
     * @param list<array{role:string,message:string,at:string}> $chat
     */
    private function handleCreateGroupAndAssignDeviceInstruction(
        Request $request,
        string $instruction,
        array $chat,
        AuditLogger $auditLogger
    ): ?RedirectResponse {
        if (! $this->looksLikeCreateGroupAndAssignInstruction($instruction)) {
            return null;
        }

        $groupName = $this->extractGroupNameFromCreateInstruction($instruction);
        $deviceQuery = $this->extractDeviceTargetFromGroupAssignInstruction($instruction);
        if ($groupName === '' || $deviceQuery === '') {
            return null;
        }

        $deviceResolution = $this->resolveDevice($deviceQuery);
        if (! (bool) ($deviceResolution['ok'] ?? false) || ! isset($deviceResolution['device'])) {
            $result = [
                'instruction' => $instruction,
                'executed' => false,
                'plan' => [
                    'intent' => 'group_membership',
                    'source' => 'ai_direct',
                    'confidence' => 0.45,
                    'rationale' => 'Could not resolve requested device for group assignment.',
                ],
                'resolution' => $deviceResolution,
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I could not resolve that device. Please provide exact hostname or device ID.',
                null,
                (string) ($deviceResolution['error'] ?? 'Unable to resolve target device.')
            );
        }

        /** @var Device $device */
        $device = $deviceResolution['device'];

        $groupResolution = $this->resolveGroup($groupName);
        $createdGroup = false;
        $groupId = '';
        $groupLabel = '';
        if ((bool) ($groupResolution['ok'] ?? false)) {
            $groupId = (string) ($groupResolution['target_id'] ?? '');
            $groupLabel = (string) ($groupResolution['target_label'] ?? $groupName);
        } elseif (str_starts_with((string) ($groupResolution['error'] ?? ''), 'No group matched target query:')) {
            $group = DeviceGroup::query()->create([
                'id' => (string) Str::uuid(),
                'name' => mb_substr($groupName, 0, 255),
                'description' => 'Created by AI Power',
            ]);
            $createdGroup = true;
            $groupId = (string) $group->id;
            $groupLabel = (string) ($group->name ?? $groupName);
        } else {
            $result = [
                'instruction' => $instruction,
                'executed' => false,
                'plan' => [
                    'intent' => 'group_membership',
                    'source' => 'ai_direct',
                    'confidence' => 0.50,
                    'rationale' => 'Group resolution returned ambiguous result.',
                ],
                'resolution' => $groupResolution,
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I could not resolve the target group. Please provide exact group name.',
                null,
                (string) ($groupResolution['error'] ?? 'Unable to resolve group.')
            );
        }

        if ($groupId === '') {
            return null;
        }

        $membershipExists = DB::table('device_group_memberships')
            ->where('device_group_id', $groupId)
            ->where('device_id', $device->id)
            ->exists();
        if (! $membershipExists) {
            DB::table('device_group_memberships')->insert([
                'device_group_id' => $groupId,
                'device_id' => $device->id,
                'created_at' => now(),
            ]);
        }

        $result = [
            'instruction' => $instruction,
            'executed' => true,
            '_suppress_summary' => true,
            'plan' => [
                'intent' => 'group_membership',
                'source' => 'ai_direct',
                'confidence' => 0.96,
                'target_type' => 'group',
                'target_query' => $groupLabel,
                'rationale' => 'Detected create-group-and-add-device instruction and applied directly.',
            ],
            'group' => [
                'id' => $groupId,
                'name' => $groupLabel,
                'created' => $createdGroup,
            ],
            'resolution' => [
                'ok' => true,
                'target_type' => 'group',
                'target_id' => $groupId,
                'target_label' => $groupLabel,
                'device' => [
                    'id' => (string) $device->id,
                    'hostname' => (string) ($device->hostname ?? ''),
                    'status' => (string) ($device->status ?? ''),
                ],
            ],
        ];

        $auditLogger->log('ai_power.group.create_and_assign', 'device_group', $groupId, null, [
            'instruction' => $instruction,
            'group_created' => $createdGroup,
            'group_name' => $groupLabel,
            'device_id' => $device->id,
            'membership_created' => ! $membershipExists,
        ], $request->user()?->id);

        $verb = $createdGroup ? 'created and ' : '';
        $membershipText = $membershipExists ? 'Device was already in the group.' : 'Device was added to the group.';
        $hostLabel = (string) ($device->hostname ?? $device->id);

        return $this->replyBack(
            $request,
            $result,
            $chat,
            'Group '.$groupLabel.' '.$verb.'updated for '.$hostLabel.'. '.$membershipText,
            'Group membership updated successfully.'
        );
    }

    private function looksLikeCreateGroupAndAssignInstruction(string $instruction): bool
    {
        $text = mb_strtolower(trim($instruction));
        if ($text === '') {
            return false;
        }

        return preg_match('/\bcreate\b.*\bgroup\b.*\badd\b.*\bto\b.*\bgroup\b/u', $text) === 1;
    }

    /**
     * @param list<array{role:string,message:string,at:string}> $chat
     */
    private function handleCreateGroupOnlyInstruction(
        Request $request,
        string $instruction,
        array $chat,
        AuditLogger $auditLogger
    ): ?RedirectResponse {
        if (! $this->looksLikeCreateGroupOnlyInstruction($instruction)) {
            return null;
        }

        $groupName = $this->extractGroupNameFromCreateInstruction($instruction);
        if ($groupName === '') {
            return null;
        }

        $groupResolution = $this->resolveGroup($groupName);
        $created = false;
        if ((bool) ($groupResolution['ok'] ?? false)) {
            $groupId = (string) ($groupResolution['target_id'] ?? '');
            $groupLabel = (string) ($groupResolution['target_label'] ?? $groupName);
        } elseif (str_starts_with((string) ($groupResolution['error'] ?? ''), 'No group matched target query:')) {
            $group = DeviceGroup::query()->create([
                'id' => (string) Str::uuid(),
                'name' => mb_substr($groupName, 0, 255),
                'description' => 'Created by AI Power',
            ]);
            $groupId = (string) $group->id;
            $groupLabel = (string) ($group->name ?? $groupName);
            $created = true;
        } else {
            $result = [
                'instruction' => $instruction,
                'executed' => false,
                '_suppress_summary' => true,
                'plan' => [
                    'intent' => 'group_membership',
                    'source' => 'ai_direct',
                    'confidence' => 0.52,
                    'target_type' => 'group',
                    'target_query' => $groupName,
                    'rationale' => 'Create-group instruction was ambiguous and needs clearer group name.',
                ],
                'resolution' => $groupResolution,
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I could not resolve that group name clearly. Please provide an exact group name.',
                null,
                (string) ($groupResolution['error'] ?? 'Unable to resolve target group.')
            );
        }

        $result = [
            'instruction' => $instruction,
            'executed' => true,
            '_suppress_summary' => true,
            'plan' => [
                'intent' => 'group_membership',
                'source' => 'ai_direct',
                'confidence' => 0.95,
                'target_type' => 'group',
                'target_query' => $groupLabel,
                'rationale' => $created ? 'Created requested group.' : 'Group already existed and was confirmed.',
            ],
            'group' => [
                'id' => $groupId,
                'name' => $groupLabel,
                'created' => $created,
            ],
            'resolution' => [
                'ok' => true,
                'target_type' => 'group',
                'target_id' => $groupId,
                'target_label' => $groupLabel,
            ],
        ];

        $auditLogger->log('ai_power.group.create_only', 'device_group', $groupId, null, [
            'instruction' => $instruction,
            'group_name' => $groupLabel,
            'group_created' => $created,
        ], $request->user()?->id);

        return $this->replyBack(
            $request,
            $result,
            $chat,
            $created ? 'Group '.$groupLabel.' created.' : 'Group '.$groupLabel.' already exists.',
            $created ? 'Group created successfully.' : 'Group already exists.'
        );
    }

    private function looksLikeCreateGroupOnlyInstruction(string $instruction): bool
    {
        $text = mb_strtolower(trim($instruction));
        if ($text === '') {
            return false;
        }
        if (preg_match('/\bpolicy\b/u', $text) === 1) {
            return false;
        }
        if (preg_match('/\bcreate\b/u', $text) !== 1 || preg_match('/\bgroup\b/u', $text) !== 1) {
            return false;
        }
        if (preg_match('/\b(add|assign|move|put|include)\b/u', $text) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array{role:string,message:string,at:string}> $chat
     */
    private function handleCreateGroupAndAssignAllDevicesInstruction(
        Request $request,
        string $instruction,
        array $chat,
        AuditLogger $auditLogger
    ): ?RedirectResponse {
        if (! $this->looksLikeCreateGroupAndAssignAllDevicesInstruction($instruction)) {
            return null;
        }

        $groupName = $this->extractGroupNameFromCreateInstruction($instruction);
        if ($groupName === '') {
            return null;
        }

        $groupResolution = $this->resolveGroup($groupName);
        $createdGroup = false;
        if ((bool) ($groupResolution['ok'] ?? false)) {
            $groupId = (string) ($groupResolution['target_id'] ?? '');
            $groupLabel = (string) ($groupResolution['target_label'] ?? $groupName);
        } elseif (str_starts_with((string) ($groupResolution['error'] ?? ''), 'No group matched target query:')) {
            $group = DeviceGroup::query()->create([
                'id' => (string) Str::uuid(),
                'name' => mb_substr($groupName, 0, 255),
                'description' => 'Created by AI Power',
            ]);
            $groupId = (string) $group->id;
            $groupLabel = (string) ($group->name ?? $groupName);
            $createdGroup = true;
        } else {
            $result = [
                'instruction' => $instruction,
                'executed' => false,
                '_suppress_summary' => true,
                'plan' => [
                    'intent' => 'group_membership',
                    'source' => 'ai_direct',
                    'confidence' => 0.50,
                    'target_type' => 'group',
                    'target_query' => $groupName,
                    'rationale' => 'Could not resolve target group for bulk assignment.',
                ],
                'resolution' => $groupResolution,
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I could not resolve the group name clearly. Please provide the exact name.',
                null,
                (string) ($groupResolution['error'] ?? 'Unable to resolve target group.')
            );
        }

        $assignConnectedOnly = preg_match('/\b(current|available|connected|online)\b/u', mb_strtolower($instruction)) === 1;
        $deviceQuery = Device::query()->orderBy('hostname');
        if ($assignConnectedOnly) {
            $deviceQuery
                ->whereRaw('LOWER(status) = ?', ['online'])
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '>=', now()->subMinutes($this->deviceOnlineWindowMinutes()));
        }
        $devices = $deviceQuery->get(['id', 'hostname', 'status']);

        if ($devices->isEmpty()) {
            $result = [
                'instruction' => $instruction,
                'executed' => false,
                '_suppress_summary' => true,
                'plan' => [
                    'intent' => 'group_membership',
                    'source' => 'ai_direct',
                    'confidence' => 0.60,
                    'target_type' => 'group',
                    'target_query' => $groupLabel,
                    'rationale' => 'No devices matched requested availability scope.',
                ],
                'group' => [
                    'id' => $groupId,
                    'name' => $groupLabel,
                    'created' => $createdGroup,
                ],
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'Group '.$groupLabel.' is ready, but no devices matched the requested scope right now.',
                null,
                $assignConnectedOnly ? 'No connected devices found.' : 'No devices found.'
            );
        }

        $added = 0;
        $already = 0;
        foreach ($devices as $device) {
            $membershipExists = DB::table('device_group_memberships')
                ->where('device_group_id', $groupId)
                ->where('device_id', (string) $device->id)
                ->exists();
            if ($membershipExists) {
                $already++;
                continue;
            }

            DB::table('device_group_memberships')->insert([
                'device_group_id' => $groupId,
                'device_id' => (string) $device->id,
                'created_at' => now(),
            ]);
            $added++;
        }

        $sampleDevices = $devices->take(5)->map(fn (Device $d): string => (string) ($d->hostname ?? $d->id))->values()->all();
        $result = [
            'instruction' => $instruction,
            'executed' => true,
            '_suppress_summary' => true,
            'plan' => [
                'intent' => 'group_membership',
                'source' => 'ai_direct',
                'confidence' => 0.96,
                'target_type' => 'group',
                'target_query' => $groupLabel,
                'rationale' => 'Detected create-group-and-assign-all-devices instruction and applied directly.',
            ],
            'group' => [
                'id' => $groupId,
                'name' => $groupLabel,
                'created' => $createdGroup,
            ],
            'resolution' => [
                'ok' => true,
                'target_type' => 'group',
                'target_id' => $groupId,
                'target_label' => $groupLabel,
                'count' => $devices->count(),
                'sample' => $sampleDevices,
            ],
        ];

        $auditLogger->log('ai_power.group.create_and_assign_all', 'device_group', $groupId, null, [
            'instruction' => $instruction,
            'group_name' => $groupLabel,
            'group_created' => $createdGroup,
            'devices_considered' => $devices->count(),
            'memberships_added' => $added,
            'memberships_existing' => $already,
            'connected_only' => $assignConnectedOnly,
        ], $request->user()?->id);

        return $this->replyBack(
            $request,
            $result,
            $chat,
            'Group '.$groupLabel.' '.($createdGroup ? 'created' : 'updated').'. Added '.$added.' device(s)'.($already > 0 ? ' ('.$already.' already members)' : '').'.',
            'Group membership updated successfully.'
        );
    }

    private function looksLikeCreateGroupAndAssignAllDevicesInstruction(string $instruction): bool
    {
        $text = mb_strtolower(trim($instruction));
        if ($text === '' || preg_match('/\bpolicy\b/u', $text) === 1) {
            return false;
        }
        if (preg_match('/\bcreate\b/u', $text) !== 1 || preg_match('/\bgroup\b/u', $text) !== 1) {
            return false;
        }
        if (preg_match('/\b(add|assign|put|include)\b/u', $text) !== 1) {
            return false;
        }

        return preg_match('/\b(all|current|available|connected|online)\b.*\b(devices?|machines?|hosts?|pcs?|computers?)\b/u', $text) === 1
            || preg_match('/\b(devices?|machines?|hosts?|pcs?|computers?)\b.*\b(all|current|available|connected|online)\b/u', $text) === 1;
    }

    /**
     * @param list<array{role:string,message:string,at:string}> $chat
     */
    private function handleAddDeviceToExistingGroupInstruction(
        Request $request,
        string $instruction,
        array $chat,
        AuditLogger $auditLogger
    ): ?RedirectResponse {
        if (! $this->looksLikeAddDeviceToGroupInstruction($instruction)) {
            return null;
        }

        $targets = $this->extractDeviceAndGroupFromAddInstruction($instruction);
        $deviceQuery = trim((string) ($targets['device_query'] ?? ''));
        $groupQuery = trim((string) ($targets['group_query'] ?? ''));

        if ($deviceQuery === '' || $groupQuery === '') {
            $result = [
                'instruction' => $instruction,
                'executed' => false,
                '_suppress_summary' => true,
                'plan' => [
                    'intent' => 'group_membership',
                    'source' => 'ai_direct',
                    'confidence' => 0.35,
                    'target_type' => 'group',
                    'rationale' => 'Ambiguous add-device-to-group instruction.',
                ],
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I want to make sure I understood. Which device should I add to which group? Example: "add device KURSU-ST110 to group lab".',
                'Clarification required for group assignment.'
            );
        }

        $deviceResolution = $this->resolveDevice($deviceQuery);
        if (! (bool) ($deviceResolution['ok'] ?? false) || ! isset($deviceResolution['device'])) {
            $result = [
                'instruction' => $instruction,
                'executed' => false,
                '_suppress_summary' => true,
                'plan' => [
                    'intent' => 'group_membership',
                    'source' => 'ai_direct',
                    'confidence' => 0.40,
                    'target_type' => 'group',
                    'target_query' => $groupQuery,
                    'rationale' => 'Could not resolve requested device for group assignment.',
                ],
                'resolution' => $deviceResolution,
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I am not sure which device you mean. What is the exact hostname or device ID?',
                null,
                (string) ($deviceResolution['error'] ?? 'Unable to resolve target device.')
            );
        }

        /** @var Device $device */
        $device = $deviceResolution['device'];
        $deviceLabel = (string) ($device->hostname ?? $device->id);

        $groupResolution = $this->resolveGroup($groupQuery);
        if (! (bool) ($groupResolution['ok'] ?? false)) {
            $result = [
                'instruction' => $instruction,
                'executed' => false,
                '_suppress_summary' => true,
                'plan' => [
                    'intent' => 'group_membership',
                    'source' => 'ai_direct',
                    'confidence' => 0.45,
                    'target_type' => 'group',
                    'target_query' => $groupQuery,
                    'rationale' => 'Could not resolve requested group for group assignment.',
                ],
                'resolution' => $groupResolution,
            ];

            $groupError = (string) ($groupResolution['error'] ?? 'Unable to resolve target group.');
            if (str_starts_with($groupError, 'No group matched target query:')) {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I could not find group "'.$groupQuery.'". Do you want me to create it and add '.$deviceLabel.' to it? If yes, say: create group "'.$groupQuery.'" and add '.$deviceLabel.' to the group.',
                    null,
                    $groupError
                );
            }

            $matches = is_array($groupResolution['matches'] ?? null) ? $groupResolution['matches'] : [];
            if ($matches !== []) {
                $labels = [];
                foreach (array_slice($matches, 0, 4) as $match) {
                    $label = trim((string) ($match['name'] ?? $match['id'] ?? ''));
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }
                if ($labels !== []) {
                    return $this->replyBack(
                        $request,
                        $result,
                        $chat,
                        'I found multiple groups: '.implode(', ', $labels).'. Which one should I use?',
                        null,
                        $groupError
                    );
                }
            }

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I am not sure which group you mean. What is the exact group name?',
                null,
                $groupError
            );
        }

        $groupId = (string) ($groupResolution['target_id'] ?? '');
        $groupLabel = (string) ($groupResolution['target_label'] ?? $groupQuery);
        if ($groupId === '') {
            return null;
        }

        $membershipExists = DB::table('device_group_memberships')
            ->where('device_group_id', $groupId)
            ->where('device_id', $device->id)
            ->exists();
        if (! $membershipExists) {
            DB::table('device_group_memberships')->insert([
                'device_group_id' => $groupId,
                'device_id' => $device->id,
                'created_at' => now(),
            ]);
        }

        $result = [
            'instruction' => $instruction,
            'executed' => true,
            '_suppress_summary' => true,
            'plan' => [
                'intent' => 'group_membership',
                'source' => 'ai_direct',
                'confidence' => 0.97,
                'target_type' => 'group',
                'target_query' => $groupLabel,
                'rationale' => 'Detected add-device-to-group instruction and applied directly.',
            ],
            'group' => [
                'id' => $groupId,
                'name' => $groupLabel,
                'created' => false,
            ],
            'resolution' => [
                'ok' => true,
                'target_type' => 'group',
                'target_id' => $groupId,
                'target_label' => $groupLabel,
                'device' => [
                    'id' => (string) $device->id,
                    'hostname' => $deviceLabel,
                    'status' => (string) ($device->status ?? ''),
                ],
            ],
        ];

        $auditLogger->log('ai_power.group.assign_device', 'device_group', $groupId, null, [
            'instruction' => $instruction,
            'group_name' => $groupLabel,
            'device_id' => $device->id,
            'membership_created' => ! $membershipExists,
        ], $request->user()?->id);

        return $this->replyBack(
            $request,
            $result,
            $chat,
            $membershipExists
                ? $deviceLabel.' is already in group '.$groupLabel.'.'
                : 'Added '.$deviceLabel.' to group '.$groupLabel.'.',
            'Group membership updated successfully.'
        );
    }

    private function looksLikeAddDeviceToGroupInstruction(string $instruction): bool
    {
        $text = mb_strtolower(trim($instruction));
        if ($text === '') {
            return false;
        }

        if (preg_match('/\bpolicy\b/u', $text) === 1) {
            return false;
        }

        if (preg_match('/\b(add|assign|move|put)\b/u', $text) !== 1) {
            return false;
        }
        if (
            preg_match('/\bcreate\b.*\bgroup\b/u', $text) === 1
            && preg_match('/\b(all|current|available|connected|online)\b.*\b(devices?|machines?|hosts?|pcs?|computers?)\b/u', $text) === 1
        ) {
            return false;
        }

        if (preg_match('/\b(to|into|in)\b/u', $text) !== 1) {
            return false;
        }

        return preg_match('/\bgroup\b/u', $text) === 1
            || preg_match('/\b(add|assign|move|put)\s+(?:device\s+)?[a-z0-9._\-]{2,}\s+(?:to|into|in)\s+[a-z0-9]/iu', $text) === 1;
    }

    /**
     * @return array{device_query:string,group_query:string}
     */
    private function extractDeviceAndGroupFromAddInstruction(string $instruction): array
    {
        $device = '';
        $group = '';

        $patterns = [
            '/\b(?:add|assign|move|put)\s+(?:device\s+)?["\']?([a-z0-9._\-]{2,}|[a-f0-9\-]{36})["\']?\s+(?:to|into|in)\s+(?:the\s+)?group\s+["\']?([^"\']{2,120})["\']?/i',
            '/\b(?:add|assign|move|put)\s+(?:device\s+)?["\']?([a-z0-9._\-]{2,}|[a-f0-9\-]{36})["\']?\s+(?:to|into|in)\s+["\']?([^"\']{2,120})["\']?\s+group\b/i',
            '/\b(?:add|assign|move|put)\s+(?:device\s+)?["\']?([a-z0-9._\-]{2,}|[a-f0-9\-]{36})["\']?\s+(?:to|into|in)\s+["\']?([a-z0-9][a-z0-9._\-\s]{1,80})["\']?(?:[.!?]|$)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $instruction, $match) === 1) {
                $device = trim((string) ($match[1] ?? ''));
                $group = trim((string) ($match[2] ?? ''));
                break;
            }
        }

        $group = trim((string) preg_replace('/\s+/', ' ', trim($group, " \t\n\r\0\x0B\"'")));
        $group = trim((string) preg_replace('/\b(?:devices?|machines?|hosts?|pcs?|computers?)\b.*$/i', '', $group));
        $group = trim((string) preg_replace('/\b(?:group)\b$/i', '', $group));

        return [
            'device_query' => $device,
            'group_query' => trim($group),
        ];
    }

    private function extractGroupNameFromCreateInstruction(string $instruction): string
    {
        $candidate = '';
        if (preg_match('/\bcreate\s+(?:a\s+)?group\s+(?:named|called)\s+["\']?([^"\']{2,120})["\']?(?:\s+and|\s*,|$)/i', $instruction, $m) === 1) {
            $candidate = (string) ($m[1] ?? '');
        } elseif (preg_match('/\bcreate\s+group\s+["\']?([^"\']{2,120})["\']?(?:\s+and|\s*,|$)/i', $instruction, $m) === 1) {
            $candidate = (string) ($m[1] ?? '');
        } elseif (preg_match('/\bcreate\s+["\']?([^"\']{2,120}?)["\']?\s+group\b/i', $instruction, $m) === 1) {
            $candidate = (string) ($m[1] ?? '');
        }

        $candidate = trim((string) preg_replace('/\s+/', ' ', trim($candidate, " \t\n\r\0\x0B\"'")));
        $candidate = preg_replace('/\s+\b(and|with)\b.*$/i', '', $candidate ?? '') ?? $candidate;

        return trim((string) $candidate);
    }

    private function extractDeviceTargetFromGroupAssignInstruction(string $instruction): string
    {
        if (preg_match('/\badd\s+(?:device\s+)?["\']?([a-z0-9._\-]{2,}|[a-f0-9\-]{36})["\']?\s+(?:to|into)\s+(?:the\s+)?group\b/i', $instruction, $m) === 1) {
            return trim((string) ($m[1] ?? ''));
        }

        if (preg_match('/\badd\s+(?:device\s+)?["\']?([a-z0-9._\-]{2,}|[a-f0-9\-]{36})["\']?\b/i', $instruction, $m) === 1) {
            return trim((string) ($m[1] ?? ''));
        }

        return '';
    }

    /**
     * @param list<array{role:string,message:string,at:string}> $chat
     * @param array<string,mixed> $result
     */
    private function replyBack(
        Request $request,
        array $result,
        array $chat,
        string $assistantMessage,
        ?string $status = null,
        ?string $error = null,
        bool $withInput = true
    ): RedirectResponse {
        $assistantMessage = $this->appendStructuredChatSummary($assistantMessage, $result);
        $chat = $this->appendChatMessage($chat, 'assistant', $assistantMessage);
        $request->session()->put('ai_power_chat', $chat);
        $request->session()->put('ai_power_last_result', $this->compactResultForSession($result));
        $result['conversation'] = array_values(array_slice($chat, -10));

        $previousUrl = (string) url()->previous();
        $previousPath = (string) (parse_url($previousUrl, PHP_URL_PATH) ?? '');
        $currentUrl = (string) $request->fullUrl();
        $shouldUseIndex = trim($previousUrl) === ''
            || $previousUrl === $currentUrl
            || $previousPath === '/'
            || $previousPath === '';

        $redirect = $shouldUseIndex ? redirect()->route('admin.ai-power.index') : back();
        if ($withInput) {
            $redirect = $redirect->withInput();
        }
        if ($status !== null && trim($status) !== '') {
            $redirect = $redirect->with('status', $status);
        }
        if ($error !== null && trim($error) !== '') {
            $redirect = $redirect->withErrors(['ai_power' => $error]);
        }

        return $redirect->with('ai_power_result', $result);
    }

    /**
     * @param array<string,mixed> $result
     * @param list<array{role:string,message:string,at:string}> $chat
     */
    private function answerFromPreviousAiListContext(
        Request $request,
        string $instruction,
        array $result,
        array $chat
    ): ?RedirectResponse {
        $wantsName = $this->isDeviceNameFollowUpRequest($instruction);
        $wantsIp = $this->isDeviceIpFollowUpRequest($instruction);
        $wantsBulkIp = $this->isBulkDeviceIpFollowUpRequest($instruction);
        if (! $wantsName && ! $wantsIp) {
            return null;
        }
        if ($wantsIp && $this->looksLikeExplicitGroupOrFleetIpRequest($instruction)) {
            return null;
        }

        $last = $request->session()->get('ai_power_last_result', $request->session()->get('ai_power_result'));
        if (! is_array($last)) {
            return null;
        }

        $aiFunction = is_array($last['ai_function'] ?? null) ? $last['ai_function'] : [];
        if ($aiFunction === []) {
            return null;
        }

        $items = is_array($aiFunction['items'] ?? null) ? $aiFunction['items'] : [];
        $labels = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '' || mb_strtolower($label) === 'unknown-device') {
                continue;
            }
            $labels[] = $label;
        }
        $labels = array_values(array_unique($labels));
        if ($labels === []) {
            $fallbackHost = trim((string) data_get($last, 'resolution.device.hostname'));
            if ($fallbackHost !== '') {
                $labels[] = $fallbackHost;
            }
        }
        if ($labels === []) {
            return null;
        }

        if ($wantsIp) {
            if (count($labels) > 1 && ! $wantsBulkIp) {
                $result['_suppress_summary'] = true;
                $shown = array_slice($labels, 0, 5);
                $suffix = count($labels) > count($shown) ? ' and '.(count($labels) - count($shown)).' more' : '';

                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I found multiple devices from the last result: '.implode(', ', $shown).$suffix.'. Please tell me which one to show IP for.',
                    'AI follow-up needs device selection.'
                );
            }

            if ($wantsBulkIp && count($labels) > 1) {
                $rows = [];
                $max = 10;
                foreach (array_slice($labels, 0, $max) as $hostname) {
                    $resolution = $this->resolveDevice((string) $hostname);
                    if (! (bool) ($resolution['ok'] ?? false) || ! isset($resolution['device'])) {
                        continue;
                    }

                    /** @var Device $device */
                    $device = $resolution['device'];
                    $ip = $this->extractDevicePrimaryIp($device);
                    $statusText = $this->effectiveDeviceStatus($device);
                    $rows[] = [
                        'hostname' => (string) ($device->hostname ?? $device->id),
                        'ip' => $ip !== '' ? $ip : 'unknown',
                        'status' => $statusText,
                    ];
                }

                if ($rows === []) {
                    return null;
                }

                $formatted = array_map(
                    fn (array $row): string => ((string) $row['hostname']).': '.((string) $row['ip']).' ('.((string) $row['status']).')',
                    $rows
                );
                $remaining = max(0, count($labels) - count($rows));
                $suffix = $remaining > 0 ? ' | and '.$remaining.' more' : '';

                $result['_suppress_summary'] = true;
                $result['ai_follow_up'] = [
                    'kind' => 'device_ips',
                    'source' => 'previous_ai_result',
                    'count' => count($rows),
                    'labels' => array_map(fn (array $row): string => (string) $row['hostname'], $rows),
                ];

                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'Device IPs: '.implode(' | ', $formatted).$suffix.'.',
                    'AI follow-up IP list generated.'
                );
            }

            $hostname = (string) ($labels[0] ?? '');
            if ($hostname === '') {
                return null;
            }

            $resolution = $this->resolveDevice($hostname);
            if (! (bool) ($resolution['ok'] ?? false) || ! isset($resolution['device'])) {
                $result['_suppress_summary'] = true;

                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I could not resolve that device from the previous result. Please provide exact hostname.',
                    null,
                    (string) ($resolution['error'] ?? 'Unable to resolve device from previous result.')
                );
            }

            /** @var Device $device */
            $device = $resolution['device'];
            $ip = $this->extractDevicePrimaryIp($device);
            $statusText = $this->effectiveDeviceStatus($device);
            $lastSeen = $device->last_seen_at?->toDateTimeString() ?? 'never';
            $ipText = $ip !== '' ? $ip : 'unknown';

            $result['_suppress_summary'] = true;
            $result['resolution'] = [
                'ok' => true,
                'device' => [
                    'id' => (string) $device->id,
                    'hostname' => (string) ($device->hostname ?? ''),
                    'status' => (string) ($device->status ?? ''),
                ],
            ];
            $result['device_status'] = [
                'device_id' => (string) $device->id,
                'hostname' => (string) ($device->hostname ?? ''),
                'status' => $statusText,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'ip_address' => $ip,
                'network_interfaces' => $this->extractDeviceNetworkInterfaces($device),
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'Device '.($device->hostname ?? $device->id).' IP is '.$ipText.'. Status: '.$statusText.'. Last seen '.$lastSeen.'.',
                'AI follow-up IP answer generated.'
            );
        }

        $domain = mb_strtolower(trim((string) ($aiFunction['domain'] ?? '')));
        $summary = mb_strtolower(trim((string) ($aiFunction['summary'] ?? '')));
        $isHealthUnhealthy = $domain === 'health'
            && (str_contains($summary, 'unhealthy') || str_contains($summary, 'degraded'));

        $shown = array_slice($labels, 0, 6);
        $suffix = count($labels) > count($shown) ? ' and '.(count($labels) - count($shown)).' more' : '';
        $prefix = count($labels) === 1 ? 'Device' : 'Devices';
        if ($isHealthUnhealthy) {
            $prefix = count($labels) === 1 ? 'Unhealthy device' : 'Unhealthy devices';
        }

        $result['_suppress_summary'] = true;
        $result['ai_follow_up'] = [
            'kind' => 'device_names',
            'source' => 'previous_ai_result',
            'count' => count($labels),
            'labels' => $labels,
        ];

        return $this->replyBack(
            $request,
            $result,
            $chat,
            $prefix.': '.implode(', ', $shown).$suffix.'.',
            'AI follow-up answer generated.'
        );
    }

    private function isDeviceNameFollowUpRequest(string $instruction): bool
    {
        $text = mb_strtolower(trim($instruction));
        if ($text === '') {
            return false;
        }

        if (preg_match('/\b(name|names|hostname|hostnames)\b/u', $text) !== 1) {
            return false;
        }

        $hasEntityWord = preg_match('/\b(device|devices|computer|computers|machine|machines|host|hosts)\b/u', $text) === 1;
        $isShortNameListAsk = preg_match('/^(show|list|give|tell)\s+(me\s+)?names?$/u', $text) === 1
            || preg_match('/^(names?|hostnames?)$/u', $text) === 1;
        if (! $hasEntityWord && ! $isShortNameListAsk) {
            return false;
        }

        return preg_match('/\b(what|which|show|list|give|tell)\b/u', $text) === 1
            || preg_match('/\b(of\s+the)\b/u', $text) === 1;
    }

    private function isDeviceIpFollowUpRequest(string $instruction): bool
    {
        $text = mb_strtolower(trim($instruction));
        if ($text === '') {
            return false;
        }

        if (preg_match('/\b(ip|ip address|network ip)\b/u', $text) !== 1) {
            return false;
        }

        return preg_match('/\b(this|that|the|same|there|their|them|those|device|machine|computer|host|show|what|which)\b/u', $text) === 1;
    }

    private function isBulkDeviceIpFollowUpRequest(string $instruction): bool
    {
        $text = mb_strtolower(trim($instruction));
        if ($text === '') {
            return false;
        }
        if (preg_match('/\b(ip|ip address|network ip)\b/u', $text) !== 1) {
            return false;
        }

        return preg_match('/\b(all|devices?|machines?|computers?|hosts?|there|their|them|those|list)\b/u', $text) === 1;
    }

    private function looksLikeExplicitGroupOrFleetIpRequest(string $instruction): bool
    {
        $text = mb_strtolower(trim($instruction));
        if ($text === '') {
            return false;
        }
        if (preg_match('/\b(ip|ip address|network ip)\b/u', $text) !== 1) {
            return false;
        }

        return preg_match('/\bgroup\b/u', $text) === 1
            || preg_match('/\ball\b/u', $text) === 1
            || preg_match('/\b(devices|machines|computers|hosts)\b/u', $text) === 1;
    }

    /**
     * @param list<array{role:string,message:string,at:string}> $chat
     * @param array<string,mixed> $result
     */
    private function replyIndex(
        Request $request,
        array $result,
        array $chat,
        string $assistantMessage,
        ?string $status = null
    ): RedirectResponse {
        $assistantMessage = $this->appendStructuredChatSummary($assistantMessage, $result);
        $chat = $this->appendChatMessage($chat, 'assistant', $assistantMessage);
        $request->session()->put('ai_power_chat', $chat);
        $request->session()->put('ai_power_last_result', $this->compactResultForSession($result));
        $result['conversation'] = array_values(array_slice($chat, -10));

        $redirect = redirect()->route('admin.ai-power.index');
        if ($status !== null && trim($status) !== '') {
            $redirect = $redirect->with('status', $status);
        }

        return $redirect->with('ai_power_result', $result);
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function compactResultForSession(array $result): array
    {
        $copy = $result;
        unset($copy['conversation']);

        return $copy;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function appendStructuredChatSummary(string $assistantMessage, array $result): string
    {
        $summary = $this->buildStructuredChatSummary($result);
        if ($summary === '') {
            return $assistantMessage;
        }

        $prefix = trim($assistantMessage);
        if ($prefix === '') {
            return $summary;
        }

        return $prefix."\n\n".$summary;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function buildStructuredChatSummary(array $result): string
    {
        if (! $this->shouldIncludeDetailedSummary($result)) {
            if ((bool) ($result['_suppress_summary'] ?? false)) {
                return '';
            }
            return $this->buildCompactChatSummary($result);
        }

        $lines = [];
        $plan = is_array($result['plan'] ?? null) ? $result['plan'] : [];
        if ($plan !== []) {
            $confidencePct = round(max(0.0, min(1.0, (float) ($plan['confidence'] ?? 0.0))) * 100, 1);
            $lines[] = 'Plan';
            $lines[] = 'Intent: '.((string) ($plan['intent'] ?? 'unknown'));
            if (trim((string) ($plan['command_slug'] ?? '')) !== '') {
                $lines[] = 'Action: '.((string) $plan['command_slug']);
            }
            $targetSummary = trim((string) ($plan['target_query'] ?? ''));
            $targetType = trim((string) ($plan['target_type'] ?? 'device'));
            if ($targetSummary !== '') {
                $lines[] = 'Target: '.$targetSummary.($targetType !== '' ? ' ('.$targetType.')' : '');
            }
            $lines[] = 'Confidence: '.$confidencePct.'%';
            if (trim((string) ($plan['risk_level'] ?? '')) !== '') {
                $lines[] = 'Risk: '.((string) $plan['risk_level']);
            }
            if (array_key_exists('requires_approval', $plan)) {
                $lines[] = 'Requires approval: '.((bool) ($plan['requires_approval'] ?? false) ? 'yes' : 'no');
            }
            if (trim((string) ($plan['rollback_command'] ?? '')) !== '') {
                $lines[] = 'Rollback: '.((string) $plan['rollback_command']);
            }
            if (trim((string) ($plan['policy_name'] ?? '')) !== '') {
                $lines[] = 'Policy name: '.((string) $plan['policy_name']);
            }
            if (trim((string) ($plan['policy_query'] ?? '')) !== '') {
                $lines[] = 'Policy query: '.((string) $plan['policy_query']);
            }
            if (trim((string) ($plan['script'] ?? '')) !== '') {
                $script = mb_substr((string) $plan['script'], 0, 180);
                $lines[] = 'Command: '.$script.(mb_strlen((string) $plan['script']) > 180 ? '...' : '');
            }
            if (trim((string) ($plan['rationale'] ?? '')) !== '') {
                $lines[] = 'Rationale: '.((string) $plan['rationale']);
            }
        }

        $resolution = is_array($result['resolution'] ?? null) ? $result['resolution'] : [];
        if ($resolution !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            if ((bool) ($resolution['ok'] ?? false)) {
                $targetLabel = trim((string) ($resolution['target_label'] ?? ''));
                if ($targetLabel !== '') {
                    $lines[] = 'Target resolved: '.$targetLabel;
                }
            } else {
                $lines[] = 'Target unresolved';
                $lines[] = (string) ($resolution['error'] ?? 'Unknown resolution error.');
            }
        }

        $confirmation = is_array($result['confirmation_required'] ?? null) ? $result['confirmation_required'] : [];
        if ($confirmation !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = 'Confirmation Required';
            $lines[] = 'Scope: '.((string) ($confirmation['scope'] ?? 'bulk'));
            if (isset($confirmation['device_count'])) {
                $lines[] = 'Devices affected: '.((int) $confirmation['device_count']);
            }
            if (trim((string) ($confirmation['confirmation_phrase'] ?? '')) !== '') {
                $lines[] = 'Reply with: "'.((string) $confirmation['confirmation_phrase']).'"';
            }
        }

        $deviceStatus = is_array($result['device_status'] ?? null) ? $result['device_status'] : [];
        if ($deviceStatus !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = 'Device Status';
            $lines[] = 'Device: '.((string) ($deviceStatus['hostname'] ?? $deviceStatus['device_id'] ?? '-'));
            $lines[] = 'Status: '.((string) ($deviceStatus['status'] ?? 'unknown'));
            $ipText = trim((string) ($deviceStatus['ip_address'] ?? ''));
            $lines[] = 'IP: '.($ipText !== '' ? $ipText : 'unknown');
            if (trim((string) ($deviceStatus['last_seen_at'] ?? '')) !== '') {
                $lines[] = 'Last seen: '.((string) ($deviceStatus['last_seen_at'] ?? 'never'));
            }
        }

        $generatedPolicyCommand = is_array($result['policy_command_generated'] ?? null) ? $result['policy_command_generated'] : [];
        if ($generatedPolicyCommand !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $generatedConfidence = round(max(0.0, min(1.0, (float) ($generatedPolicyCommand['confidence'] ?? 0.0))) * 100, 1);
            $lines[] = 'Generated Policy Command';
            $lines[] = 'Confidence: '.$generatedConfidence.'%';
            if (trim((string) ($generatedPolicyCommand['command'] ?? '')) !== '') {
                $command = (string) $generatedPolicyCommand['command'];
                $lines[] = 'Command: '.mb_substr($command, 0, 220).(mb_strlen($command) > 220 ? '...' : '');
            }
        }

        $policyTest = is_array($result['policy_test'] ?? null) ? $result['policy_test'] : [];
        if ($policyTest !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $ok = (bool) ($policyTest['ok'] ?? false);
            $score = round(max(0.0, min(1.0, (float) ($policyTest['score'] ?? 0.0))) * 100, 1);
            $lines[] = 'Policy Command Test '.($ok ? 'Passed' : 'Failed');
            $lines[] = 'Score: '.$score.'%';
            $issues = [];
            if (is_array($policyTest['errors'] ?? null)) {
                foreach ($policyTest['errors'] as $error) {
                    $value = trim((string) $error);
                    if ($value !== '') {
                        $issues[] = $value;
                    }
                }
            }
            if (is_array($policyTest['warnings'] ?? null)) {
                foreach ($policyTest['warnings'] as $warning) {
                    $value = trim((string) $warning);
                    if ($value !== '') {
                        $issues[] = $value;
                    }
                }
            }
            foreach (array_slice($issues, 0, 2) as $issue) {
                $lines[] = '- '.$issue;
            }
        }

        $runCommandTest = is_array($result['run_command_test'] ?? null) ? $result['run_command_test'] : [];
        if ($runCommandTest !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $ok = (bool) ($runCommandTest['ok'] ?? false);
            $score = round(max(0.0, min(1.0, (float) ($runCommandTest['score'] ?? 0.0))) * 100, 1);
            $lines[] = 'Run Command Test '.($ok ? 'Passed' : 'Failed');
            $lines[] = 'Score: '.$score.'%';
            $issues = [];
            if (is_array($runCommandTest['errors'] ?? null)) {
                foreach ($runCommandTest['errors'] as $error) {
                    $value = trim((string) $error);
                    if ($value !== '') {
                        $issues[] = $value;
                    }
                }
            }
            if (is_array($runCommandTest['warnings'] ?? null)) {
                foreach ($runCommandTest['warnings'] as $warning) {
                    $value = trim((string) $warning);
                    if ($value !== '') {
                        $issues[] = $value;
                    }
                }
            }
            foreach (array_slice($issues, 0, 2) as $issue) {
                $lines[] = '- '.$issue;
            }
        }

        $policy = is_array($result['policy'] ?? null) ? $result['policy'] : [];
        if ($policy !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $policyName = trim((string) ($policy['name'] ?? $policy['id'] ?? '-'));
            $policySlug = trim((string) ($policy['slug'] ?? ''));
            $lines[] = 'Policy Result';
            $lines[] = 'Policy: '.$policyName.($policySlug !== '' ? ' ('.$policySlug.')' : '');
            if (isset($policy['version_number'])) {
                $lines[] = 'Version: '.((int) $policy['version_number']);
            }
        }

        $bulkJob = is_array($result['bulk_job'] ?? null) ? $result['bulk_job'] : [];
        if ($bulkJob !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = 'Bulk Job';
            $lines[] = 'Jobs queued: '.((int) ($bulkJob['count'] ?? 0));
            if (trim((string) ($bulkJob['scope'] ?? '')) !== '') {
                $lines[] = 'Scope: '.((string) $bulkJob['scope']);
            }
        }

        $job = is_array($result['job'] ?? null) ? $result['job'] : [];
        if ($job !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = 'Job queued successfully';
            $lines[] = 'Job ID: '.((string) ($job['id'] ?? '-'));
            $lines[] = 'Type: '.((string) ($job['job_type'] ?? '-')).' | Status: '.((string) ($job['status'] ?? '-'));
        }

        $aiFunction = is_array($result['ai_function'] ?? null) ? $result['ai_function'] : [];
        if ($aiFunction !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = 'AI Analysis';
            $lines[] = 'Domain: '.((string) ($aiFunction['domain'] ?? 'general')).' | Topic: '.((string) ($aiFunction['topic'] ?? 'overview'));
            $metrics = is_array($aiFunction['metrics'] ?? null) ? $aiFunction['metrics'] : [];
            foreach (array_slice($metrics, 0, 4) as $metric) {
                $label = trim((string) ($metric['label'] ?? ''));
                $value = trim((string) ($metric['value'] ?? ''));
                if ($label !== '') {
                    $lines[] = $label.': '.($value !== '' ? $value : '-');
                }
            }
            $items = is_array($aiFunction['items'] ?? null) ? $aiFunction['items'] : [];
            foreach (array_slice($items, 0, 3) as $item) {
                $label = trim((string) ($item['label'] ?? '-'));
                $detail = trim((string) ($item['detail'] ?? ''));
                $lines[] = '- '.$label.($detail !== '' ? ' | '.$detail : '');
            }
            $recommendations = is_array($aiFunction['recommendations'] ?? null) ? $aiFunction['recommendations'] : [];
            foreach (array_slice($recommendations, 0, 2) as $rec) {
                $value = trim((string) $rec);
                if ($value !== '') {
                    $lines[] = 'Recommendation: '.$value;
                }
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param array<string,mixed> $result
     */
    private function shouldIncludeDetailedSummary(array $result): bool
    {
        if ((bool) ($result['_force_details'] ?? false)) {
            return true;
        }

        $instruction = trim((string) ($result['instruction'] ?? ''));
        if ($instruction === '') {
            return false;
        }

        return preg_match('/\b(details?|detailed|full|explain|debug|why|show plan|confidence|rationale|diagnostics?)\b/u', mb_strtolower($instruction)) === 1;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function buildCompactChatSummary(array $result): string
    {
        $lines = [];

        $resolution = is_array($result['resolution'] ?? null) ? $result['resolution'] : [];
        if ($resolution !== [] && ! (bool) ($resolution['ok'] ?? false)) {
            $lines[] = 'Issue: '.((string) ($resolution['error'] ?? 'Unknown resolution error.'));
        }

        $confirmation = is_array($result['confirmation_required'] ?? null) ? $result['confirmation_required'] : [];
        if ($confirmation !== []) {
            $count = (int) ($confirmation['device_count'] ?? 0);
            $phrase = trim((string) ($confirmation['confirmation_phrase'] ?? ''));
            $lines[] = 'Confirmation needed for '.$count.' device(s).';
            if ($phrase !== '') {
                $lines[] = 'Reply: "'.$phrase.'"';
            }
        }

        $deviceStatus = is_array($result['device_status'] ?? null) ? $result['device_status'] : [];
        $ipLookup = is_array($result['ip_lookup'] ?? null) ? $result['ip_lookup'] : [];
        if ($ipLookup === []) {
            $resolutionLookup = data_get($result, 'resolution.lookup');
            $ipLookup = is_array($resolutionLookup) ? $resolutionLookup : [];
        }
        if ($ipLookup !== []) {
            $lookupQuery = trim((string) ($ipLookup['query'] ?? ''));
            $lookupMatch = trim((string) ($ipLookup['match'] ?? ''));
            $lookupMatchedIp = trim((string) ($ipLookup['matched_ip'] ?? ''));
            $host = (string) ($deviceStatus['hostname'] ?? data_get($result, 'resolution.device.hostname') ?? 'device');
            if ($lookupQuery !== '' && $host !== '') {
                if ($lookupMatch === 'exact') {
                    $lines[] = 'IP lookup: '.$lookupQuery.' belongs to '.$host.'.';
                } elseif ($lookupMatch === 'prefix') {
                    $detail = $lookupMatchedIp !== '' ? ' ('.$lookupMatchedIp.')' : '';
                    $lines[] = 'IP lookup: '.$lookupQuery.' matched '.$host.$detail.'.';
                }
            }
        }

        if ($deviceStatus !== []) {
            $host = (string) ($deviceStatus['hostname'] ?? $deviceStatus['device_id'] ?? 'device');
            $status = (string) ($deviceStatus['status'] ?? 'unknown');
            $ip = trim((string) ($deviceStatus['ip_address'] ?? ''));
            $line = 'Device status: '.$host.' is '.$status;
            if ($ip !== '') {
                $line .= ', IP '.$ip;
            }
            $lines[] = $line.'.';
        }

        $policyTest = is_array($result['policy_test'] ?? null) ? $result['policy_test'] : [];
        if ($policyTest !== []) {
            $score = round(max(0.0, min(1.0, (float) ($policyTest['score'] ?? 0.0))) * 100, 1);
            $lines[] = 'Policy Command Test '.((bool) ($policyTest['ok'] ?? false) ? 'Passed' : 'Failed').': '.$score.'%.';
        }

        $runCommandTest = is_array($result['run_command_test'] ?? null) ? $result['run_command_test'] : [];
        if ($runCommandTest !== []) {
            $score = round(max(0.0, min(1.0, (float) ($runCommandTest['score'] ?? 0.0))) * 100, 1);
            $lines[] = 'Run Command Test '.((bool) ($runCommandTest['ok'] ?? false) ? 'Passed' : 'Failed').': '.$score.'%.';
        }

        $policy = is_array($result['policy'] ?? null) ? $result['policy'] : [];
        if ($policy !== []) {
            $name = trim((string) ($policy['name'] ?? $policy['slug'] ?? $policy['id'] ?? 'policy'));
            $lines[] = 'Policy Result: '.$name.'.';
        }

        $bulkJob = is_array($result['bulk_job'] ?? null) ? $result['bulk_job'] : [];
        if ($bulkJob !== []) {
            $lines[] = 'Bulk jobs queued: '.((int) ($bulkJob['count'] ?? 0)).'.';
        }

        $job = is_array($result['job'] ?? null) ? $result['job'] : [];
        if ($job !== []) {
            $lines[] = 'Job queued: '.((string) ($job['id'] ?? '-')).'.';
        }

        if ($lines !== [] && $this->hasDetailedDataAvailable($result)) {
            $lines[] = 'Reply "details" for full plan and diagnostics.';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param array<string,mixed> $result
     */
    private function hasDetailedDataAvailable(array $result): bool
    {
        $keys = ['plan', 'resolution', 'policy_test', 'run_command_test', 'ai_function', 'policy_command_generated'];
        foreach ($keys as $key) {
            if (is_array($result[$key] ?? null) && ($result[$key] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    private function extractDevicePrimaryIp(Device $device): string
    {
        $tags = is_array($device->tags) ? $device->tags : [];
        $runtime = is_array($tags['runtime_diagnostics'] ?? null) ? $tags['runtime_diagnostics'] : [];

        return $this->readTextValue($runtime, [
            'ip_address',
            'network.ip',
            'network.primary_ip',
            'network.ip_address',
            'primary_ip',
        ]);
    }

    /**
     * @return list<string>
     */
    private function extractDeviceNetworkInterfaces(Device $device): array
    {
        $tags = is_array($device->tags) ? $device->tags : [];
        $runtime = is_array($tags['runtime_diagnostics'] ?? null) ? $tags['runtime_diagnostics'] : [];

        $candidates = [
            data_get($runtime, 'network.interfaces'),
            data_get($runtime, 'interfaces'),
            data_get($runtime, 'network.adapters'),
            data_get($runtime, 'ip_config.interfaces'),
        ];

        $interfaces = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            foreach ($candidate as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? $row['interface'] ?? $row['adapter'] ?? ''));
                $ip = trim((string) ($row['ip'] ?? $row['ip_address'] ?? $row['ipv4'] ?? ''));
                $mac = trim((string) ($row['mac'] ?? $row['mac_address'] ?? ''));
                $parts = [];
                if ($name !== '') {
                    $parts[] = $name;
                }
                if ($ip !== '') {
                    $parts[] = 'IP '.$ip;
                }
                if ($mac !== '') {
                    $parts[] = 'MAC '.$mac;
                }
                if ($parts === []) {
                    continue;
                }
                $interfaces[] = implode(' ', $parts);
                if (count($interfaces) >= 8) {
                    return array_values(array_unique($interfaces));
                }
            }
        }

        return array_values(array_unique($interfaces));
    }

    /**
     * @return list<string>
     */
    private function extractDeviceIpCandidates(Device $device): array
    {
        $tags = is_array($device->tags) ? $device->tags : [];
        $runtime = is_array($tags['runtime_diagnostics'] ?? null) ? $tags['runtime_diagnostics'] : [];
        $inventory = is_array($tags['inventory'] ?? null) ? $tags['inventory'] : [];

        $values = [];
        $paths = [
            'ip_address',
            'primary_ip',
            'network.ip',
            'network.ip_address',
            'network.primary_ip',
            'network.public_ip',
            'network.private_ip',
            'current_ip',
            'agent.ip_address',
        ];
        foreach ($paths as $path) {
            $value = data_get($runtime, $path);
            if (is_scalar($value)) {
                $values[] = (string) $value;
            }
        }

        $interfaceSets = [
            data_get($runtime, 'network.interfaces'),
            data_get($runtime, 'interfaces'),
            data_get($runtime, 'network.adapters'),
            data_get($runtime, 'ip_config.interfaces'),
            data_get($inventory, 'network.interfaces'),
            data_get($inventory, 'interfaces'),
        ];
        foreach ($interfaceSets as $rows) {
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach (['ip', 'ip_address', 'ipv4', 'ipv6', 'address'] as $field) {
                    $value = $row[$field] ?? null;
                    if (is_scalar($value)) {
                        $values[] = (string) $value;
                    }
                }
            }
        }

        $blob = json_encode([$runtime, $inventory], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($blob) && $blob !== '') {
            $values[] = $blob;
        }

        $ips = [];
        foreach ($values as $value) {
            foreach ($this->extractIpv4Tokens($value) as $ip) {
                $ips[$ip] = true;
            }
        }

        return array_values(array_keys($ips));
    }

    /**
     * @return list<string>
     */
    private function extractIpv4Tokens(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b/', $text, $matches);
        if (! is_array($matches) || ! is_array($matches[0] ?? null)) {
            return [];
        }

        return array_values(array_unique(array_map(fn ($v): string => mb_strtolower(trim((string) $v)), $matches[0])));
    }

    private function effectiveDeviceStatus(Device $device): string
    {
        $raw = mb_strtolower(trim((string) ($device->status ?? 'unknown')));
        if ($raw === 'offline') {
            return 'offline';
        }

        $isFresh = $device->last_seen_at !== null
            && $device->last_seen_at->gte(now()->subMinutes($this->deviceOnlineWindowMinutes()));

        if ($raw === 'online') {
            return $isFresh ? 'online' : 'offline';
        }

        if ($isFresh) {
            return 'online';
        }

        return $raw !== '' ? $raw : 'unknown';
    }

    private function deviceOnlineWindowMinutes(): int
    {
        $fallback = max(1, (int) config('services.openai.ai_power_online_window_minutes', 2));
        $configured = $this->settingInt('jobs.online_window_minutes', $fallback);

        return max(1, min(120, $configured));
    }

    /**
     * @param array<string,mixed> $source
     * @param array<int,string> $paths
     */
    private function readTextValue(array $source, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($source, $path);
            if (! is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $plan
     */
    private function isAllDevicesTarget(array $plan, string $instruction): bool
    {
        $targetQuery = mb_strtolower(trim((string) ($plan['target_query'] ?? '')));
        if (in_array($targetQuery, ['all', 'all-devices', 'all_devices', 'every', 'everyone', '*'], true)) {
            return true;
        }

        $text = mb_strtolower($instruction);
        $hasAllWord = preg_match('/\b(all|every|everyone)\b/u', $text) === 1;
        $hasDeviceWord = preg_match('/\b(device|devices|endpoints|hosts|machines)\b/u', $text) === 1;

        return $hasAllWord && $hasDeviceWord;
    }

    private function isConnectedOnlyTargetRequest(string $instruction): bool
    {
        $text = mb_strtolower($instruction);

        return preg_match('/\b(connected|connetd|connectd|online|active)\b/u', $text) === 1;
    }

    /**
     * @param array<string,mixed> $plan
     */
    private function planRequiresExplicitApproval(array $plan): bool
    {
        $risk = mb_strtolower(trim((string) ($plan['risk_level'] ?? '')));
        if ($risk === 'high') {
            return true;
        }

        return (bool) ($plan['requires_approval'] ?? false);
    }

    private function hasApprovalConfirmation(string $instruction): bool
    {
        $text = mb_strtolower($instruction);

        return preg_match('/\b(confirm|approved?|approve|yes|proceed|execute)\b/u', $text) === 1;
    }

    /**
     * @param array<string,mixed> $plan
     */
    private function approvalConfirmationPhrase(array $plan, string $scopeLabel): string
    {
        $intent = (string) ($plan['intent'] ?? 'run_command_device');
        $slug = trim((string) ($plan['command_slug'] ?? ''));
        if ($slug === '' || $slug === 'run_command') {
            return 'confirm run command on '.$scopeLabel;
        }

        $action = str_replace('_', ' ', $slug);
        if ($intent === 'reboot_device') {
            $action = 'restart';
        } elseif ($intent === 'uninstall_agent_device') {
            $action = 'uninstall agent';
        }

        return 'confirm '.$action.' on '.$scopeLabel;
    }

    private function hasBulkConfirmation(string $instruction, string $intent): bool
    {
        $text = mb_strtolower($instruction);
        if (preg_match('/\b(confirm|yes|proceed|execute)\b/u', $text) !== 1) {
            return false;
        }

        $hasAll = preg_match('/\b(all|every)\b/u', $text) === 1;
        if (! $hasAll) {
            return false;
        }

        if ($intent === 'reboot_device') {
            return preg_match('/\b(reboot|restart|restrt)\b/u', $text) === 1;
        }
        if ($intent === 'uninstall_agent_device') {
            return preg_match('/\b(uninstall|remove)\b/u', $text) === 1;
        }
        if ($intent === 'run_command_device') {
            return preg_match('/\b(command|script|run)\b/u', $text) === 1;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $result
     * @param list<array{role:string,message:string,at:string}> $chat
     */
    private function executeAllDevicesIntent(
        Request $request,
        array $plan,
        array $result,
        bool $executeNow,
        string $instruction,
        AuditLogger $auditLogger,
        NaturalLanguageCommandService $interpreter,
        float $confidence,
        float $minConfidence,
        array $chat
    ): RedirectResponse {
        $intent = (string) ($plan['intent'] ?? 'unknown');
        $connectedOnly = $this->isConnectedOnlyTargetRequest($instruction);

        $deviceQuery = Device::query();
        if ($connectedOnly) {
            $deviceQuery
                ->whereRaw('LOWER(status) = ?', ['online'])
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '>=', now()->subMinutes($this->deviceOnlineWindowMinutes()));
        }
        $devices = $deviceQuery
            ->orderBy('hostname')
            ->get(['id', 'hostname', 'status', 'last_seen_at']);

        $result['resolution'] = [
            'ok' => true,
            'target_type' => 'fleet',
            'target_id' => 'all',
            'target_label' => $connectedOnly ? 'all connected devices' : 'all devices',
            'count' => $devices->count(),
            'sample' => $devices->take(5)->map(fn (Device $d): array => [
                'id' => (string) $d->id,
                'hostname' => (string) ($d->hostname ?? ''),
                'status' => $this->effectiveDeviceStatus($d),
            ])->values()->all(),
        ];

        if ($devices->isEmpty()) {
            return $this->replyBack(
                $request,
                $result,
                $chat,
                $connectedOnly
                    ? 'There are no connected devices right now. Try again when devices are online.'
                    : 'There are no devices available to target right now.',
                null,
                $connectedOnly
                    ? 'No connected devices found.'
                    : 'No devices found.'
            );
        }

        if ($intent === 'get_device_status') {
            $online = $devices->filter(fn (Device $d): bool => $this->effectiveDeviceStatus($d) === 'online')->count();
            $offline = $devices->filter(fn (Device $d): bool => $this->effectiveDeviceStatus($d) === 'offline')->count();
            $other = max(0, $devices->count() - $online - $offline);

            $result['fleet_status'] = [
                'total' => $devices->count(),
                'online' => $online,
                'offline' => $offline,
                'other' => $other,
                'connected_scope' => $connectedOnly,
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'Fleet status: '.$online.' online, '.$offline.' offline, '.$other.' other (total '.$devices->count().').',
                'Fleet status returned for '.$devices->count().' device(s).'
            );
        }

        if ($intent === 'run_command_device') {
            $script = trim((string) ($plan['script'] ?? ''));
            if ($script === '') {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I need the exact command to run before executing fleet actions.',
                    null,
                    'run_command requires a non-empty script.'
                );
            }

            $runAs = (string) ($plan['run_as'] ?? 'default');
            $timeoutSeconds = (int) ($plan['timeout_seconds'] ?? 300);
            $commandTest = $interpreter->testPolicyCommand($script, $runAs, $timeoutSeconds, $instruction);
            $result['run_command_test'] = $commandTest;
            if (! (bool) ($commandTest['ok'] ?? false)) {
                $errors = is_array($commandTest['errors'] ?? null) ? $commandTest['errors'] : [];
                $errorText = count($errors) > 0 ? implode(' | ', array_map(fn ($e): string => (string) $e, array_slice($errors, 0, 3))) : 'Run command preflight test failed.';

                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I tested this command and it failed safety preflight checks. Please revise the command.',
                    null,
                    'Run command test failed: '.$errorText
                );
            }
        }

        if ($executeNow && $confidence < $minConfidence) {
            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I resolved a fleet target ('.$devices->count().' devices), but confidence is low. Please rephrase explicitly.',
                null,
                'Plan confidence is too low for a fleet action.'
            );
        }

        if ($executeNow && $this->planRequiresExplicitApproval($plan) && ! $this->hasApprovalConfirmation($instruction)) {
            $scopeLabel = $connectedOnly ? 'all connected devices' : 'all devices';
            $confirmationPhrase = $this->approvalConfirmationPhrase($plan, $scopeLabel);
            $result['confirmation_required'] = [
                'scope' => $connectedOnly ? 'all_connected' : 'all_devices',
                'device_count' => $devices->count(),
                'confirmation_phrase' => $confirmationPhrase,
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'This is a high-risk action across '.$devices->count().' devices. To continue, reply: "'.$confirmationPhrase.'"',
                'Confirmation required for high-risk fleet action.'
            );
        }

        if ($executeNow && $devices->count() > 1 && ! $this->hasBulkConfirmation($instruction, $intent)) {
            $confirmationPhrase = match ($intent) {
                'reboot_device' => $connectedOnly ? 'confirm restart all connected devices' : 'confirm restart all devices',
                'uninstall_agent_device' => $connectedOnly ? 'confirm uninstall agent on all connected devices' : 'confirm uninstall agent on all devices',
                'run_command_device' => $connectedOnly ? 'confirm run command on all connected devices' : 'confirm run command on all devices',
                default => $connectedOnly ? 'confirm apply to all connected devices' : 'confirm apply to all devices',
            };
            $result['confirmation_required'] = [
                'scope' => $connectedOnly ? 'all_connected' : 'all_devices',
                'device_count' => $devices->count(),
                'confirmation_phrase' => $confirmationPhrase,
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'This will affect '.$devices->count().' devices. If you want to continue, reply: "'.$confirmationPhrase.'"',
                'Confirmation required for fleet action.'
            );
        }

        if (! $executeNow) {
            return $this->replyBack(
                $request,
                $result,
                $chat,
                'Fleet action plan prepared for '.$devices->count().' devices.',
                'AI fleet plan generated.'
            );
        }

        $createdJobs = [];
        foreach ($devices as $device) {
            [$jobType, $payload] = $this->buildJobPayload($plan, $instruction, $request->user());
            if ($jobType === '' || ! is_array($payload)) {
                continue;
            }

            if ($jobType === 'run_command') {
                $payload = $this->normalizeRunCommandPayload($payload, $request->user()?->id);
                if (trim((string) ($payload['script'] ?? '')) === '') {
                    continue;
                }
            }

            $job = DmsJob::query()->create([
                'id' => (string) Str::uuid(),
                'job_type' => $jobType,
                'status' => 'queued',
                'priority' => (int) ($plan['priority'] ?? 100),
                'payload' => $payload,
                'target_type' => 'device',
                'target_id' => $device->id,
                'created_by' => $request->user()?->id,
            ]);

            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $device->id,
                'status' => 'pending',
                'next_retry_at' => null,
            ]);

            $createdJobs[] = $job;
        }

        if ($createdJobs === []) {
            return $this->replyBack(
                $request,
                $result,
                $chat,
                'No jobs were queued due to validation constraints.',
                null,
                'No jobs were queued for the fleet target.'
            );
        }

        $result['executed'] = true;
        $result['bulk_job'] = [
            'count' => count($createdJobs),
            'sample_job_ids' => array_map(
                fn (DmsJob $job): string => (string) $job->id,
                array_slice($createdJobs, 0, 5)
            ),
            'intent' => $intent,
            'scope' => $connectedOnly ? 'all_connected' : 'all_devices',
        ];
        $result['job'] = [
            'id' => (string) $createdJobs[0]->id,
            'job_type' => (string) $createdJobs[0]->job_type,
            'status' => (string) $createdJobs[0]->status,
            'target_id' => (string) $createdJobs[0]->target_id,
        ];

        $auditLogger->log('ai_power.command.execute_fleet', 'job', (string) $createdJobs[0]->id, null, [
            'instruction' => $instruction,
            'plan' => $plan,
            'fleet_count' => count($createdJobs),
            'scope' => $connectedOnly ? 'all_connected' : 'all_devices',
        ], $request->user()?->id);

        return $this->replyIndex(
            $request,
            $result,
            $chat,
            'Queued '.$intent.' for '.count($createdJobs).' device(s).',
            'Fleet action queued for '.count($createdJobs).' device(s).'
        );
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $result
     * @param list<array{role:string,message:string,at:string}> $chat
     */
    private function executeGroupDevicesIntent(
        Request $request,
        array $plan,
        array $result,
        bool $executeNow,
        string $instruction,
        AuditLogger $auditLogger,
        NaturalLanguageCommandService $interpreter,
        float $confidence,
        float $minConfidence,
        array $chat
    ): RedirectResponse {
        $intent = (string) ($plan['intent'] ?? 'unknown');
        $groupQuery = trim((string) ($plan['target_query'] ?? ''));
        $groupResolution = $this->resolveGroup($groupQuery);
        if (! ($groupResolution['ok'] ?? false)) {
            $result['resolution'] = $groupResolution;

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I could not resolve that group. Please provide exact group name or ID.',
                null,
                (string) ($groupResolution['error'] ?? 'Unable to resolve target group.')
            );
        }

        $groupId = (string) ($groupResolution['target_id'] ?? '');
        $groupLabel = (string) ($groupResolution['target_label'] ?? $groupId);
        $deviceIds = $this->groupDeviceIds($groupId);
        $devices = Device::query()
            ->whereIn('id', $deviceIds)
            ->orderBy('hostname')
            ->get(['id', 'hostname', 'status', 'last_seen_at']);

        $result['resolution'] = [
            'ok' => true,
            'target_type' => 'group',
            'target_id' => $groupId,
            'target_label' => $groupLabel,
            'count' => $devices->count(),
            'sample' => $devices->take(5)->map(fn (Device $d): array => [
                'id' => (string) $d->id,
                'hostname' => (string) ($d->hostname ?? ''),
                'status' => $this->effectiveDeviceStatus($d),
            ])->values()->all(),
        ];

        if ($devices->isEmpty()) {
            return $this->replyBack(
                $request,
                $result,
                $chat,
                'Group '.$groupLabel.' has no devices to target.',
                null,
                'No devices found in target group: '.$groupLabel
            );
        }

        if ($intent === 'get_device_status') {
            $online = $devices->filter(fn (Device $d): bool => $this->effectiveDeviceStatus($d) === 'online')->count();
            $offline = $devices->filter(fn (Device $d): bool => $this->effectiveDeviceStatus($d) === 'offline')->count();
            $other = max(0, $devices->count() - $online - $offline);

            $result['fleet_status'] = [
                'total' => $devices->count(),
                'online' => $online,
                'offline' => $offline,
                'other' => $other,
                'group' => $groupLabel,
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'Group '.$groupLabel.' status: '.$online.' online, '.$offline.' offline, '.$other.' other (total '.$devices->count().').',
                'Group status returned for '.$devices->count().' device(s).'
            );
        }

        if ($intent === 'run_command_device') {
            $script = trim((string) ($plan['script'] ?? ''));
            if ($script === '') {
                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I need the exact command to run before executing group actions.',
                    null,
                    'run_command requires a non-empty script.'
                );
            }

            $runAs = (string) ($plan['run_as'] ?? 'default');
            $timeoutSeconds = (int) ($plan['timeout_seconds'] ?? 300);
            $commandTest = $interpreter->testPolicyCommand($script, $runAs, $timeoutSeconds, $instruction);
            $result['run_command_test'] = $commandTest;
            if (! (bool) ($commandTest['ok'] ?? false)) {
                $errors = is_array($commandTest['errors'] ?? null) ? $commandTest['errors'] : [];
                $errorText = count($errors) > 0 ? implode(' | ', array_map(fn ($e): string => (string) $e, array_slice($errors, 0, 3))) : 'Run command preflight test failed.';

                return $this->replyBack(
                    $request,
                    $result,
                    $chat,
                    'I tested this command and it failed safety preflight checks. Please revise the command.',
                    null,
                    'Run command test failed: '.$errorText
                );
            }
        }

        if ($executeNow && $confidence < $minConfidence) {
            return $this->replyBack(
                $request,
                $result,
                $chat,
                'I resolved group '.$groupLabel.' ('.$devices->count().' devices), but confidence is low. Please rephrase explicitly.',
                null,
                'Plan confidence is too low for a group action.'
            );
        }

        if ($executeNow && $this->planRequiresExplicitApproval($plan) && ! $this->hasApprovalConfirmation($instruction)) {
            $confirmationPhrase = $this->approvalConfirmationPhrase($plan, 'group '.$groupLabel);
            $result['confirmation_required'] = [
                'scope' => 'group',
                'device_count' => $devices->count(),
                'confirmation_phrase' => $confirmationPhrase,
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'This is a high-risk action for group '.$groupLabel.' ('.$devices->count().' devices). To continue, reply: "'.$confirmationPhrase.'"',
                'Confirmation required for high-risk group action.'
            );
        }

        if ($executeNow && $devices->count() > 1 && ! $this->hasBulkConfirmation($instruction, $intent)) {
            $confirmationPhrase = match ($intent) {
                'reboot_device' => 'confirm restart all devices in group '.$groupLabel,
                'uninstall_agent_device' => 'confirm uninstall agent on all devices in group '.$groupLabel,
                'run_command_device' => 'confirm run command on all devices in group '.$groupLabel,
                default => 'confirm apply to all devices in group '.$groupLabel,
            };
            $result['confirmation_required'] = [
                'scope' => 'group',
                'device_count' => $devices->count(),
                'confirmation_phrase' => $confirmationPhrase,
            ];

            return $this->replyBack(
                $request,
                $result,
                $chat,
                'This will affect '.$devices->count().' devices in group '.$groupLabel.'. If you want to continue, reply: "'.$confirmationPhrase.'"',
                'Confirmation required for group action.'
            );
        }

        if (! $executeNow) {
            return $this->replyBack(
                $request,
                $result,
                $chat,
                'Group action plan prepared for '.$devices->count().' devices in '.$groupLabel.'.',
                'AI group plan generated.'
            );
        }

        $createdJobs = [];
        foreach ($devices as $device) {
            [$jobType, $payload] = $this->buildJobPayload($plan, $instruction, $request->user());
            if ($jobType === '' || ! is_array($payload)) {
                continue;
            }

            if ($jobType === 'run_command') {
                $payload = $this->normalizeRunCommandPayload($payload, $request->user()?->id);
                if (trim((string) ($payload['script'] ?? '')) === '') {
                    continue;
                }
            }

            $job = DmsJob::query()->create([
                'id' => (string) Str::uuid(),
                'job_type' => $jobType,
                'status' => 'queued',
                'priority' => (int) ($plan['priority'] ?? 100),
                'payload' => $payload,
                'target_type' => 'device',
                'target_id' => $device->id,
                'created_by' => $request->user()?->id,
            ]);

            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $device->id,
                'status' => 'pending',
                'next_retry_at' => null,
            ]);

            $createdJobs[] = $job;
        }

        if ($createdJobs === []) {
            return $this->replyBack(
                $request,
                $result,
                $chat,
                'No jobs were queued due to validation constraints.',
                null,
                'No jobs were queued for group target: '.$groupLabel
            );
        }

        $result['executed'] = true;
        $result['bulk_job'] = [
            'count' => count($createdJobs),
            'sample_job_ids' => array_map(
                fn (DmsJob $job): string => (string) $job->id,
                array_slice($createdJobs, 0, 5)
            ),
            'intent' => $intent,
            'scope' => 'group',
            'group' => $groupLabel,
        ];
        $result['job'] = [
            'id' => (string) $createdJobs[0]->id,
            'job_type' => (string) $createdJobs[0]->job_type,
            'status' => (string) $createdJobs[0]->status,
            'target_id' => (string) $createdJobs[0]->target_id,
        ];

        $auditLogger->log('ai_power.command.execute_group', 'job', (string) $createdJobs[0]->id, null, [
            'instruction' => $instruction,
            'plan' => $plan,
            'group_id' => $groupId,
            'group_label' => $groupLabel,
            'group_count' => count($createdJobs),
        ], $request->user()?->id);

        return $this->replyIndex(
            $request,
            $result,
            $chat,
            'Queued '.$intent.' for '.count($createdJobs).' device(s) in group '.$groupLabel.'.',
            'Group action queued for '.count($createdJobs).' device(s).'
        );
    }

    /**
     * @return array{
     *   ok:bool,
     *   error?:string,
     *   matches?:array<int,array{id:string,hostname:string,status:string,ip?:string}>,
     *   device?:Device,
     *   lookup?:array{type:string,match:string,query:string,matched_ip?:string}
     * }
     */
    private function resolveDevice(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [
                'ok' => false,
                'error' => 'No target device found in instruction. Example: "reboot device LAB-01".',
            ];
        }

        if (Str::isUuid($query)) {
            $device = Device::query()->find($query);
            if ($device) {
                return ['ok' => true, 'device' => $device];
            }
        }

        if ($this->looksLikeIpQuery($query)) {
            return $this->resolveDeviceByIp($query);
        }

        $exact = Device::query()
            ->whereRaw('LOWER(hostname) = ?', [mb_strtolower($query)])
            ->limit(3)
            ->get(['id', 'hostname', 'status']);
        if ($exact->count() === 1) {
            return [
                'ok' => true,
                'device' => Device::query()->findOrFail((string) $exact->first()->id),
            ];
        }

        $matches = Device::query()
            ->where(function ($builder) use ($query) {
                $builder
                    ->where('hostname', 'like', '%'.$query.'%')
                    ->orWhere('id', 'like', '%'.$query.'%');
            })
            ->orderBy('hostname')
            ->limit(5)
            ->get(['id', 'hostname', 'status']);

        if ($matches->count() === 1) {
            return [
                'ok' => true,
                'device' => Device::query()->findOrFail((string) $matches->first()->id),
            ];
        }

        if ($matches->isEmpty()) {
            return [
                'ok' => false,
                'error' => 'No device matched target query: '.$query,
            ];
        }

        return [
            'ok' => false,
            'error' => 'Multiple devices matched. Use exact hostname or device id.',
            'matches' => $matches->map(fn (Device $d): array => [
                'id' => (string) $d->id,
                'hostname' => (string) ($d->hostname ?? ''),
                'status' => (string) ($d->status ?? ''),
            ])->values()->all(),
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   error?:string,
     *   matches?:array<int,array{id:string,hostname:string,status:string,ip?:string}>,
     *   device?:Device,
     *   lookup?:array{type:string,match:string,query:string,matched_ip?:string}
     * }
     */
    private function resolveDeviceByIp(string $query): array
    {
        $needle = mb_strtolower(trim($query));
        $devices = Device::query()
            ->orderBy('hostname')
            ->get(['id', 'hostname', 'status', 'last_seen_at', 'tags']);

        /** @var array<string,array{device:Device,ip:string}> $exactMatches */
        $exactMatches = [];
        /** @var array<string,array{device:Device,ip:string}> $prefixMatches */
        $prefixMatches = [];
        foreach ($devices as $device) {
            $ips = $this->extractDeviceIpCandidates($device);
            foreach ($ips as $ip) {
                if ($ip === $needle) {
                    $exactMatches[(string) $device->id] = ['device' => $device, 'ip' => $ip];
                }
                if (str_starts_with($ip, $needle)) {
                    $prefixMatches[(string) $device->id] = ['device' => $device, 'ip' => $ip];
                }
            }
        }

        if (count($exactMatches) === 1) {
            $match = array_values($exactMatches)[0];
            /** @var Device $device */
            $device = $match['device'];

            return [
                'ok' => true,
                'device' => $device,
                'lookup' => [
                    'type' => 'ip',
                    'match' => 'exact',
                    'query' => $query,
                    'matched_ip' => (string) ($match['ip'] ?? ''),
                ],
            ];
        }

        if (count($exactMatches) > 1) {
            $matches = array_values($exactMatches);

            return [
                'ok' => false,
                'error' => 'Multiple devices reported IP '.$query.'. Use exact hostname or device id.',
                'matches' => array_map(fn (array $entry): array => [
                    'id' => (string) $entry['device']->id,
                    'hostname' => (string) ($entry['device']->hostname ?? ''),
                    'status' => (string) ($entry['device']->status ?? ''),
                    'ip' => (string) ($entry['ip'] ?? ''),
                ], $matches),
            ];
        }

        if (count($prefixMatches) === 1) {
            $match = array_values($prefixMatches)[0];
            /** @var Device $device */
            $device = $match['device'];

            return [
                'ok' => true,
                'device' => $device,
                'lookup' => [
                    'type' => 'ip',
                    'match' => 'prefix',
                    'query' => $query,
                    'matched_ip' => (string) ($match['ip'] ?? ''),
                ],
            ];
        }

        if (count($prefixMatches) > 1) {
            $matches = array_values($prefixMatches);

            return [
                'ok' => false,
                'error' => 'Multiple devices matched IP prefix '.$query.'. Use full IP or hostname.',
                'matches' => array_map(fn (array $entry): array => [
                    'id' => (string) $entry['device']->id,
                    'hostname' => (string) ($entry['device']->hostname ?? ''),
                    'status' => (string) ($entry['device']->status ?? ''),
                    'ip' => (string) ($entry['ip'] ?? ''),
                ], $matches),
            ];
        }

        return [
            'ok' => false,
            'error' => 'No device reported IP: '.$query,
        ];
    }

    private function looksLikeIpQuery(string $query): bool
    {
        $value = trim($query);
        if ($value === '') {
            return false;
        }

        return preg_match('/^\d{1,3}(?:\.\d{1,3}){2,3}$/', $value) === 1;
    }

    /**
     * @return array{ok:bool,error?:string,matches?:array<int,array{id:string,name:string}>,target_id?:string,target_label?:string,target_type?:string}
     */
    private function resolveTarget(string $targetType, string $query): array
    {
        if ($targetType === 'group') {
            return $this->resolveGroup($query);
        }

        $deviceResolution = $this->resolveDevice($query);
        if (! ($deviceResolution['ok'] ?? false)) {
            return $deviceResolution;
        }

        /** @var Device $device */
        $device = $deviceResolution['device'];

        return [
            'ok' => true,
            'target_type' => 'device',
            'target_id' => (string) $device->id,
            'target_label' => (string) ($device->hostname ?? $device->id),
            'device' => [
                'id' => (string) $device->id,
                'hostname' => (string) ($device->hostname ?? ''),
                'status' => (string) ($device->status ?? ''),
            ],
        ];
    }

    /**
     * @return array{ok:bool,error?:string,matches?:array<int,array{id:string,name:string}>,target_id?:string,target_label?:string,target_type?:string}
     */
    private function resolveGroup(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [
                'ok' => false,
                'error' => 'No target group found in instruction.',
            ];
        }

        if (Str::isUuid($query)) {
            $group = DeviceGroup::query()->find($query);
            if ($group) {
                return [
                    'ok' => true,
                    'target_type' => 'group',
                    'target_id' => (string) $group->id,
                    'target_label' => (string) ($group->name ?? $group->id),
                ];
            }
        }

        $exact = DeviceGroup::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($query)])
            ->limit(3)
            ->get(['id', 'name']);
        if ($exact->count() === 1) {
            $group = $exact->first();

            return [
                'ok' => true,
                'target_type' => 'group',
                'target_id' => (string) ($group->id ?? ''),
                'target_label' => (string) ($group->name ?? ''),
            ];
        }

        $matches = DeviceGroup::query()
            ->where(function ($builder) use ($query) {
                $builder
                    ->where('name', 'like', '%'.$query.'%')
                    ->orWhere('id', 'like', '%'.$query.'%');
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name']);
        if ($matches->count() === 1) {
            $group = $matches->first();

            return [
                'ok' => true,
                'target_type' => 'group',
                'target_id' => (string) ($group->id ?? ''),
                'target_label' => (string) ($group->name ?? ''),
            ];
        }

        if ($matches->isEmpty()) {
            return [
                'ok' => false,
                'error' => 'No group matched target query: '.$query,
            ];
        }

        return [
            'ok' => false,
            'error' => 'Multiple groups matched. Use exact group name or id.',
            'matches' => $matches->map(fn (DeviceGroup $g): array => [
                'id' => (string) $g->id,
                'name' => (string) ($g->name ?? ''),
            ])->values()->all(),
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   error?:string,
     *   policy_id?:string,
     *   policy_name?:string,
     *   policy_slug?:string,
     *   policy_version?:PolicyVersion
     * }
     */
    private function resolvePolicyVersion(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [
                'ok' => false,
                'error' => 'Policy query is empty.',
            ];
        }

        if (Str::isUuid($query)) {
            $version = PolicyVersion::query()->find($query);
            if ($version) {
                $policy = Policy::query()->find($version->policy_id);

                return [
                    'ok' => true,
                    'policy_id' => (string) ($policy?->id ?? ''),
                    'policy_name' => (string) ($policy?->name ?? ''),
                    'policy_slug' => (string) ($policy?->slug ?? ''),
                    'policy_version' => $version,
                ];
            }

            $policyById = Policy::query()->find($query);
            if ($policyById) {
                $version = PolicyVersion::query()
                    ->where('policy_id', $policyById->id)
                    ->where('status', 'active')
                    ->orderByDesc('version_number')
                    ->first()
                    ?? PolicyVersion::query()
                        ->where('policy_id', $policyById->id)
                        ->orderByDesc('version_number')
                        ->first();
                if (! $version) {
                    return [
                        'ok' => false,
                        'error' => 'Policy exists but has no versions: '.$policyById->name,
                    ];
                }

                return [
                    'ok' => true,
                    'policy_id' => (string) $policyById->id,
                    'policy_name' => (string) ($policyById->name ?? ''),
                    'policy_slug' => (string) ($policyById->slug ?? ''),
                    'policy_version' => $version,
                ];
            }
        }

        $exact = Policy::query()
            ->whereRaw('LOWER(slug) = ?', [mb_strtolower($query)])
            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($query)])
            ->limit(4)
            ->get(['id', 'name', 'slug']);
        if ($exact->count() === 1) {
            $policy = $exact->first();
            $version = PolicyVersion::query()
                ->where('policy_id', (string) $policy->id)
                ->where('status', 'active')
                ->orderByDesc('version_number')
                ->first()
                ?? PolicyVersion::query()
                    ->where('policy_id', (string) $policy->id)
                    ->orderByDesc('version_number')
                    ->first();
            if (! $version) {
                return [
                    'ok' => false,
                    'error' => 'Policy has no versions: '.(string) ($policy->name ?? ''),
                ];
            }

            return [
                'ok' => true,
                'policy_id' => (string) ($policy->id ?? ''),
                'policy_name' => (string) ($policy->name ?? ''),
                'policy_slug' => (string) ($policy->slug ?? ''),
                'policy_version' => $version,
            ];
        }

        $matches = Policy::query()
            ->where('name', 'like', '%'.$query.'%')
            ->orWhere('slug', 'like', '%'.$query.'%')
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'slug']);
        if ($matches->count() !== 1) {
            return [
                'ok' => false,
                'error' => $matches->isEmpty()
                    ? 'No policy matched query: '.$query
                    : 'Multiple policies matched. Use exact policy slug or id.',
            ];
        }

        $policy = $matches->first();
        $version = PolicyVersion::query()
            ->where('policy_id', (string) $policy->id)
            ->where('status', 'active')
            ->orderByDesc('version_number')
            ->first()
            ?? PolicyVersion::query()
                ->where('policy_id', (string) $policy->id)
                ->orderByDesc('version_number')
                ->first();
        if (! $version) {
            return [
                'ok' => false,
                'error' => 'Policy has no versions: '.(string) ($policy->name ?? ''),
            ];
        }

        return [
            'ok' => true,
            'policy_id' => (string) ($policy->id ?? ''),
            'policy_name' => (string) ($policy->name ?? ''),
            'policy_slug' => (string) ($policy->slug ?? ''),
            'policy_version' => $version,
        ];
    }

    /**
     * @return array{
     *   summary:array<string,int>,
     *   areas:list<array{area:string,route_count:int,sample_routes:list<string>}>,
     *   routes:list<array{name:string,methods:list<string>,uri:string}>,
     *   values:array<string,int>,
     *   settings:list<array{key:string,value:mixed,updated_at:string|null}>
     * }
     */
    private function buildProjectInventory(): array
    {
        $routes = $this->adminRouteCatalog();

        $areasMap = [];
        foreach ($routes as $route) {
            $name = (string) ($route['name'] ?? '');
            $parts = explode('.', $name);
            $area = trim((string) ($parts[1] ?? 'other'));
            if ($area === '') {
                $area = 'other';
            }

            if (! isset($areasMap[$area])) {
                $areasMap[$area] = [
                    'area' => $area,
                    'route_count' => 0,
                    'sample_routes' => [],
                ];
            }
            $areasMap[$area]['route_count']++;
            if (count($areasMap[$area]['sample_routes']) < 5) {
                $areasMap[$area]['sample_routes'][] = $name;
            }
        }

        $areas = array_values($areasMap);
        usort($areas, function (array $a, array $b): int {
            $cmp = (int) ($b['route_count'] ?? 0) <=> (int) ($a['route_count'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($a['area'] ?? ''), (string) ($b['area'] ?? ''));
        });

        $values = [
            'devices_total' => Device::query()->count(),
            'devices_online' => Device::query()->whereRaw('LOWER(status) = ?', ['online'])->count(),
            'groups_total' => DeviceGroup::query()->count(),
            'packages_total' => PackageModel::query()->count(),
            'policies_total' => Policy::query()->count(),
            'policy_versions_total' => PolicyVersion::query()->count(),
            'jobs_total' => DmsJob::query()->count(),
            'jobs_queued' => DmsJob::query()->where('status', 'queued')->count(),
            'jobs_running' => DmsJob::query()->where('status', 'running')->count(),
            'jobs_failed' => DmsJob::query()->where('status', 'failed')->count(),
            'job_runs_total' => JobRun::query()->count(),
            'agent_releases_total' => AgentRelease::query()->count(),
            'audit_logs_total' => AuditLog::query()->count(),
            'admin_notes_total' => AdminNote::query()->count(),
            'control_plane_settings_total' => ControlPlaneSetting::query()->count(),
        ];

        $settings = ControlPlaneSetting::query()
            ->orderBy('key')
            ->get(['key', 'value', 'updated_at'])
            ->map(function (ControlPlaneSetting $setting): array {
                $raw = is_array($setting->value) ? ($setting->value['value'] ?? $setting->value) : $setting->value;

                return [
                    'key' => (string) $setting->key,
                    'value' => $raw,
                    'updated_at' => $setting->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'summary' => [
                'total_admin_routes' => count($routes),
                'areas_total' => count($areas),
                'settings_total' => count($settings),
            ],
            'areas' => $areas,
            'routes' => $routes,
            'values' => $values,
            'settings' => $settings,
        ];
    }

    /**
     * @return list<array{name:string,methods:list<string>,uri:string}>
     */
    private function adminRouteCatalog(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) ($route->getName() ?? '');
            if ($name === '' || ! Str::startsWith($name, 'admin.')) {
                continue;
            }

            $methods = array_values(array_filter($route->methods(), fn (string $method): bool => $method !== 'HEAD'));
            sort($methods);

            $routes[] = [
                'name' => $name,
                'methods' => $methods,
                'uri' => '/'.ltrim((string) $route->uri(), '/'),
            ];
        }

        usort($routes, fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return array_values($routes);
    }

    private function nextUniquePolicySlug(string $baseSlug): string
    {
        $slug = trim($baseSlug);
        if ($slug === '') {
            $slug = 'ai-policy';
        }

        $candidate = $slug;
        $counter = 2;
        while (Policy::query()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$counter;
            $counter++;
            if ($counter > 5000) {
                $candidate = $slug.'-'.Str::lower(Str::random(8));
                break;
            }
        }

        return $candidate;
    }

    /**
     * @param array<string,mixed>|null $generatedPolicyCommand
     */
    private function effectiveCreatePolicyConfidence(
        float $baseConfidence,
        array $policyTest,
        ?array $generatedPolicyCommand,
        string $policyName,
        string $policyCommand
    ): float {
        $confidence = max(0.0, min(1.0, $baseConfidence));
        $hasEssentials = trim($policyName) !== '' && trim($policyCommand) !== '';
        $testPass = (bool) ($policyTest['ok'] ?? false);
        $testScore = max(0.0, min(1.0, (float) ($policyTest['score'] ?? 0.0)));
        $generatedConfidence = is_array($generatedPolicyCommand)
            ? max(0.0, min(1.0, (float) ($generatedPolicyCommand['confidence'] ?? 0.0)))
            : 0.0;

        if ($hasEssentials && $testPass) {
            $confidence = max($confidence, $testScore, $generatedConfidence, 0.72);
        }

        return round(max(0.0, min(1.0, $confidence)), 4);
    }

    private function normalizeAiPolicyName(string $policyName, string $instruction, string $policyCommand): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($policyName));
        $name = is_string($name) ? $name : trim($policyName);
        $name = trim((string) preg_replace('/^(to|ti|that|a|an)\s+/iu', '', $name));

        $combined = mb_strtolower(trim($instruction.' '.$policyName.' '.$policyCommand));
        $disableUsbHints = [
            'disable usb',
            'disbale usb',
            'disble usb',
            'diable usb',
            'usb disable',
            'usb disbale',
            'usb disble',
            'block usb',
        ];
        foreach ($disableUsbHints as $hint) {
            if (str_contains($combined, $hint)) {
                return 'Disable USB Policy';
            }
        }

        if ($name === '' || mb_strlen($name) < 3) {
            return 'AI Policy '.now()->format('Ymd-His');
        }

        return mb_substr($name, 0, 120);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildCommandRuleConfig(string $command, string $runAs, int $timeoutSeconds): array
    {
        $runAs = mb_strtolower(trim($runAs));
        if (! in_array($runAs, ['default', 'elevated', 'system'], true)) {
            $runAs = 'system';
        }
        $timeoutSeconds = max(30, min(3600, $timeoutSeconds));

        return [
            'command' => mb_substr($command, 0, 12000),
            'run_as' => $runAs,
            'timeout_seconds' => $timeoutSeconds,
        ];
    }

    private function createPolicyAssignment(string $policyVersionId, string $targetType, string $targetId): bool
    {
        $existing = DB::table('policy_assignments')
            ->where('policy_version_id', $policyVersionId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->exists();
        if ($existing) {
            return false;
        }

        DB::table('policy_assignments')->insert([
            'id' => (string) Str::uuid(),
            'policy_version_id' => $policyVersionId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'rollout_strategy' => 'immediate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * @param array<string,mixed> $aiMeta
     */
    private function queueApplyPolicyJob(
        PolicyVersion $policyVersion,
        string $targetType,
        string $targetId,
        ?int $createdBy,
        array $aiMeta = []
    ): DmsJob {
        $rules = PolicyRule::query()
            ->where('policy_version_id', $policyVersion->id)
            ->orderBy('order_index')
            ->get()
            ->map(function (PolicyRule $rule): array {
                return [
                    'type' => (string) $rule->rule_type,
                    'config' => is_array($rule->rule_config) ? $rule->rule_config : [],
                    'enforce' => (bool) $rule->enforce,
                ];
            })
            ->values()
            ->all();

        $payload = [
            'policy_version_id' => $policyVersion->id,
            'rules' => $rules,
        ];
        if ($aiMeta !== []) {
            $payload['ai_power'] = $aiMeta + ['generated_at' => now()->toIso8601String()];
        }

        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'apply_policy',
            'status' => 'queued',
            'priority' => 100,
            'payload' => $payload,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'created_by' => $createdBy,
        ]);

        if ($targetType === 'device') {
            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $targetId,
                'status' => 'pending',
                'next_retry_at' => null,
            ]);

            return $job;
        }

        foreach ($this->groupDeviceIds($targetId) as $deviceId) {
            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $deviceId,
                'status' => 'pending',
                'next_retry_at' => null,
            ]);
        }

        return $job;
    }

    /**
     * @return list<string>
     */
    private function groupDeviceIds(string $groupId): array
    {
        return Device::query()
            ->whereIn('id', function ($query) use ($groupId): void {
                $query
                    ->from('device_group_memberships')
                    ->select('device_id')
                    ->where('device_group_id', $groupId);
            })
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $plan
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildJobPayload(array $plan, string $instruction, ?User $user): array
    {
        $intent = (string) ($plan['intent'] ?? 'unknown');
        $baseMeta = [
            'ai_power' => [
                'instruction' => $instruction,
                'intent' => $intent,
                'source' => (string) ($plan['source'] ?? 'unknown'),
                'confidence' => (float) ($plan['confidence'] ?? 0.0),
                'rationale' => (string) ($plan['rationale'] ?? ''),
                'command_slug' => (string) ($plan['command_slug'] ?? ''),
                'risk_level' => (string) ($plan['risk_level'] ?? ''),
                'requires_approval' => (bool) ($plan['requires_approval'] ?? false),
                'rollback_command' => (string) ($plan['rollback_command'] ?? ''),
                'catalog_version' => (string) ($plan['catalog_version'] ?? ''),
                'generated_at' => now()->toIso8601String(),
            ],
        ];

        if ($intent === 'reboot_device') {
            $script = 'shutdown.exe /r /t 0 /f /c "AI Power requested reboot"';

            return [
                'run_command',
                $baseMeta + [
                    'script' => $script,
                    'run_as' => 'system',
                    'timeout_seconds' => 180,
                ],
            ];
        }

        if ($intent === 'run_command_device') {
            return [
                'run_command',
                $baseMeta + [
                    'script' => (string) ($plan['script'] ?? ''),
                    'run_as' => (string) ($plan['run_as'] ?? 'default'),
                    'timeout_seconds' => (int) ($plan['timeout_seconds'] ?? 300),
                ],
            ];
        }

        if ($intent === 'uninstall_agent_device') {
            $ttlMinutes = $this->settingInt('devices.agent_uninstall_confirmation_ttl_minutes', 30);
            $ttlMinutes = max(1, min(240, $ttlMinutes));

            return [
                'uninstall_agent',
                $baseMeta + [
                    'admin_confirmed' => true,
                    'admin_confirmed_at' => now()->toIso8601String(),
                    'admin_confirmed_by_user_id' => $user?->id,
                    'admin_confirmation_ttl_minutes' => $ttlMinutes,
                    'admin_confirmation_nonce' => (string) Str::uuid(),
                ],
            ];
        }

        return ['', []];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeRunCommandPayload(array $payload, ?int $updatedByUserId): array
    {
        $script = (string) ($payload['script'] ?? '');
        $runAs = mb_strtolower(trim((string) ($payload['run_as'] ?? 'default')));
        if (! in_array($runAs, ['default', 'elevated', 'system'], true)) {
            $runAs = 'default';
        }
        $timeout = (int) ($payload['timeout_seconds'] ?? 300);
        $timeout = max(30, min(3600, $timeout));

        $payload['run_as'] = $runAs;
        $payload['timeout_seconds'] = $timeout;
        $payload['script_sha256'] = strtolower(hash('sha256', $script));

        if ($this->settingBool('scripts.auto_allow_run_command_hashes', false)) {
            $allow = array_map('strtolower', $this->settingArray('scripts.allowed_sha256', []));
            if (! in_array($payload['script_sha256'], $allow, true)) {
                $updatedAllow = array_values(array_unique(array_merge($allow, [$payload['script_sha256']])));
                ControlPlaneSetting::query()->updateOrCreate(
                    ['key' => 'scripts.allowed_sha256'],
                    ['value' => ['value' => $updatedAllow], 'updated_by' => $updatedByUserId]
                );
            }
        }

        return $payload;
    }

    /**
     * @param array<int,mixed> $default
     * @return array<int,mixed>
     */
    private function settingArray(string $key, array $default): array
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;

        return is_array($value) ? array_values($value) : $default;
    }

    private function settingBool(string $key, bool $default): bool
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;
        if (is_bool($value)) {
            return $value;
        }

        return filter_var((string) $value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function settingInt(string $key, int $default): int
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;

        return is_numeric($value) ? (int) round((float) $value) : $default;
    }
}
